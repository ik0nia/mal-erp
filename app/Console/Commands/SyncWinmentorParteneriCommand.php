<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncWinmentorParteneriCommand extends Command
{
    protected $signature = 'winmentor:sync-parteneri';
    protected $description = 'Sincronizează partenerii din WinMentor Bridge în tabela locală winmentor_parteneri';

    public function handle(): int
    {
        $conn = IntegrationConnection::find(5);
        if (! $conn) {
            $this->error('Conexiunea WinMentor Bridge (ID=5) nu există.');
            return self::FAILURE;
        }

        $baseUrl = rtrim($conn->bridgeUrl(), '/');
        $apiKey  = $conn->bridgeApiKey();

        $this->info('Descărc parteneri din WinMentor Bridge...');

        // Selectăm firma + setăm IdPartField pe CodIntern (nu cod sediu)
        Http::timeout(30)
            ->withHeaders(['X-API-Key' => $apiKey])
            ->post($baseUrl . '/api/firme/select', [
                'firma' => $conn->bridgeFirma(),
                'an'    => $conn->bridgeAn(),
                'luna'  => $conn->bridgeLuna(),
            ]);

        Http::timeout(10)->withoutVerifying()
            ->withHeaders(['X-API-Key' => $apiKey])
            ->post($baseUrl . '/api/config/id-part-field', [
                'fieldName' => 'CodIntern',
            ]);

        $all = [];
        $page = 1;
        do {
            $r = Http::timeout(30)->withoutVerifying()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get($baseUrl . '/api/parteneri', ['page' => $page, 'pageSize' => 5000]);

            $data = $r->json()['data'] ?? [];
            $items = $data['items'] ?? [];
            $all = array_merge($all, $items);

            $hasNext = $data['hasNextPage'] ?? false;
            $this->output->write('.');
            $page++;
        } while ($hasNext && $page <= 20);

        $this->newLine();
        $this->info('Descărcați: ' . count($all) . ' parteneri');

        if (empty($all)) {
            $this->warn('Niciun partener returnat — opresc.');
            return self::SUCCESS;
        }

        $now = now()->toDateTimeString();
        $upserted = 0;

        foreach (array_chunk($all, 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $p) {
                $wmId = $p['idPartener'] ?? null;
                if (! $wmId) continue;

                // wm_id e VARCHAR(20) unique. Un id anormal de lung (probabil câmp Bridge mapat
                // greșit la acel rând) ar pica tot chunk-ul de 500 cu eroarea 1406 — îl sărim.
                if (mb_strlen((string) $wmId) > 20) {
                    $this->warn("  Partener sărit: idPartener prea lung ('{$wmId}', " . mb_strlen((string) $wmId) . ' car.)');
                    continue;
                }

                $agent = trim(($p['numeAgent'] ?? '') . ' ' . ($p['prenumeAgent'] ?? ''));

                $rows[] = [
                    'wm_id'            => $wmId,
                    'denumire'         => mb_substr($p['denumire'] ?? '', 0, 255),
                    'cod_fiscal'       => mb_substr($p['codFiscal'] ?? '', 0, 50),
                    'localitate'       => mb_substr($p['localitate'] ?? '', 0, 255),
                    'adresa'           => mb_substr(trim(explode('~', (string) ($p['adresa'] ?? ''))[0] ?? ''), 0, 500),
                    'telefon'          => mb_substr($p['telefon'] ?? '', 0, 100),
                    'persoana_contact' => mb_substr($p['persoanaContact'] ?? '', 0, 255),
                    'clasa'            => mb_substr($p['simbolClasa'] ?? $p['clasaCaract'] ?? '', 0, 100),
                    'categ_pret'       => mb_substr($p['denCategPret'] ?? '', 0, 100),
                    'agent'            => mb_substr($agent, 0, 255),
                    'discount'         => mb_substr($p['discount'] ?? '', 0, 50),
                    'cod_extern'       => mb_substr($p['codExtern'] ?? '', 0, 50),
                    'blocat'           => in_array($p['partenerBlocat'] ?? '', ['1', 'DA'], true),
                    'moneda'           => mb_substr($p['monedaImplicita'] ?? 'Lei', 0, 10),
                    'tara'             => mb_substr($p['tara'] ?? '', 0, 50),
                    'observatii'       => $p['observatii'] ?? null,
                    'pj_pf'            => mb_substr($p['pfSauPj'] ?? $p['telPersoaneContact'] ?? '', 0, 10),
                    'updated_at'       => $now,
                    'created_at'       => $now,
                ];
            }

            DB::table('winmentor_parteneri')->upsert($rows, ['wm_id'], [
                'denumire', 'cod_fiscal', 'localitate', 'adresa', 'telefon',
                'persoana_contact', 'clasa', 'categ_pret', 'agent', 'discount',
                'cod_extern', 'blocat', 'moneda', 'tara', 'observatii', 'pj_pf', 'updated_at',
            ]);

            $upserted += count($rows);
        }

        $this->info("Sincronizat: {$upserted} parteneri.");

        $this->syncSedii($all, $now);

        // Invalidăm cache-ul vechi
        \Illuminate\Support\Facades\Cache::forget('wm_parteneri_map');

        // ── Reconciliere winmentor_id pe furnizori ERP ──────────────────────────
        $this->reconcileSupplierWinmentorIds();

        return self::SUCCESS;
    }

    /**
     * Verifică toți furnizorii cu winmentor_id setat și corectează ID-urile
     * care nu mai există în Bridge (ex. după reindexare WinMentor).
     * Potrivirea se face prin CUI/CIF normalizat, apoi prin denumire exactă.
     */
    private function reconcileSupplierWinmentorIds(): void
    {
        $suppliers = \App\Models\Supplier::whereNotNull('winmentor_id')
            ->where('winmentor_id', '!=', '')
            ->get();

        if ($suppliers->isEmpty()) {
            return;
        }

        $parteneri = DB::table('winmentor_parteneri')->get();
        $byWmId   = $parteneri->keyBy('wm_id');

        // Index CUI normalizat → parteneri (fără prefix RO, fără spații)
        $byCuiNorm = [];
        foreach ($parteneri as $p) {
            $cuiNorm = preg_replace('/[^0-9]/', '', $p->cod_fiscal ?? '');
            if ($cuiNorm !== '') {
                $byCuiNorm[$cuiNorm][] = $p;
            }
        }

        // Index denumire lowercase → parteneri
        $byName = [];
        foreach ($parteneri as $p) {
            $nameLow = mb_strtolower(trim($p->denumire ?? ''));
            if ($nameLow !== '' && ! str_starts_with($nameLow, 'x')) {
                $byName[$nameLow][] = $p;
            }
        }

        $fixed = 0;

        foreach ($suppliers as $s) {
            // ID-ul curent este valid în Bridge → skip
            if ($byWmId->has($s->winmentor_id)) {
                continue;
            }

            // Căutare prin CUI normalizat
            $cuiNorm   = preg_replace('/[^0-9]/', '', $s->vat_number ?? '');
            $candidates = collect($byCuiNorm[$cuiNorm] ?? []);

            // Căutare suplimentară prin denumire exactă
            $nameLow     = mb_strtolower(trim($s->name));
            $nameMatches = collect($byName[$nameLow] ?? []);
            $candidates  = $candidates->merge($nameMatches)
                ->unique('wm_id')
                ->reject(fn ($p) => str_starts_with(mb_strtolower($p->denumire ?? ''), 'x'));

            if ($candidates->isEmpty()) {
                continue;
            }

            // Preferă potrivire exactă pe denumire
            $best = $candidates->first(fn ($p) =>
                mb_strtolower(trim($p->denumire)) === $nameLow
            ) ?? $candidates->first();

            $oldId = $s->winmentor_id;
            $s->updateQuietly(['winmentor_id' => $best->wm_id]);
            $fixed++;

            $this->line("  ↻ {$s->name}: {$oldId} → {$best->wm_id}");
        }

        if ($fixed > 0) {
            $this->info("Reconciliere: {$fixed} furnizori cu winmentor_id corectat.");
        } else {
            $this->info('Reconciliere: toate ID-urile sunt la zi.');
        }
    }

    /**
     * Extrage sediile/punctele de livrare din câmpurile paralele "~" ale
     * nomenclatorului (denumiriSedii + localitatiSedii/codPostalSedii/emailSedii/
     * infoTipSediu) → winmentor_sedii. Full-replace (nomenclatorul e sursa).
     */
    private function syncSedii(array $parteneri, string $now): void
    {
        $split = fn ($v) => is_array($v) ? array_values($v) : array_values(array_filter(explode('~', (string) $v), fn ($x) => trim($x) !== ''));

        $rows = [];
        foreach ($parteneri as $p) {
            $wmId = $p['idPartener'] ?? null;
            if (! $wmId || mb_strlen((string) $wmId) > 20) continue;

            // denumiriSedii ține de fapt LOCALITĂȚILE ("SANTANDREI BH"); numele real al
            // sediului/șantierului e în câmpul adresa, "~"-separat, aliniat pozițional
            $localitati = $split($p['denumiriSedii'] ?? []);
            if (empty($localitati)) continue;

            $nume    = array_map('trim', explode('~', (string) ($p['adresa'] ?? '')));
            $coduri  = $split($p['codPostalSedii'] ?? '');
            $emailuri = $split($p['emailSedii'] ?? '');
            $tipuri  = $split($p['infoTipSediu'] ?? '');

            foreach ($localitati as $i => $loc) {
                $loc = trim((string) $loc);
                $den = trim((string) ($nume[$i] ?? ''));
                if ($loc === '' && $den === '') continue;

                $rows[] = [
                    'partener_wm_id' => $wmId,
                    'pozitie'        => $i,
                    'denumire'       => mb_substr($den !== '' ? $den : $loc, 0, 255),
                    'localitate'     => mb_substr($loc, 0, 255) ?: null,
                    'cod_postal'     => mb_substr(trim((string) ($coduri[$i] ?? '')), 0, 20) ?: null,
                    'email'          => mb_substr(trim((string) ($emailuri[$i] ?? '')), 0, 255) ?: null,
                    'tip'            => mb_substr(trim((string) ($tipuri[$i] ?? '')), 0, 50) ?: null,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        if (count($rows) < 100) {
            $this->warn('Sedii: prea puține ('.count($rows).') — păstrez datele existente.');
            return;
        }

        DB::transaction(function () use ($rows) {
            DB::table('winmentor_sedii')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('winmentor_sedii')->insert($chunk);
            }
        });

        $this->info('Sedii sincronizate: '.count($rows));
    }
}
