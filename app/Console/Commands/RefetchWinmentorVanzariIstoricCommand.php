<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refetch istoric vânzări 2019–2024 prin MentorAPI, în format tipizat (AE/F/S).
 *
 * Istoricul a fost importat pe 14.04.2026 prin vechiul WinMentor Bridge, care nu
 * expunea tipul documentului — toate rândurile au tip_document='='. Comanda asta
 * rescrie lunile vechi folosind exact pipeline-ul watch-ului (moștenit):
 * /vanzari/luna (AE/F cu date bogate) + /vanzari/ext (bonuri) + fallback emulare.
 *
 * SIGURANȚĂ:
 * - refuză anii >= 2025 (deja tipizați; ștergerea ar pierde bonurile de emulare îmbinate)
 * - backup complet există în winmentor_vanzari_raw_backup_20260907
 * - delete+insert per lună în tranzacție (crash la mijloc nu lasă luni goale)
 * - același Cache lock ca fetch/emulare (se exclud reciproc)
 * - la final (și la eroare) restaurează luna de lucru curentă pe COM
 * - de rulat DOAR în afara ferestrei 08:00–17:30 (sync-urile de stoc/watch schimbă luna COM)
 */
class RefetchWinmentorVanzariIstoricCommand extends WatchWinmentorVanzariCommand
{
    protected $signature = 'winmentor:refetch-vanzari-istoric
                            {--firma=MAL2019 : Firma WinMentor}
                            {--an= : Doar anul specificat (implicit 2019-2024, descrescător)}
                            {--luna= : Doar luna specificată (necesită --an)}
                            {--delay=15 : Secunde pauză între luni}';

    protected $description = 'Rescrie istoricul de vânzări 2019-2024 în format tipizat (AE/F/S) prin MentorAPI';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma      = $this->option('firma');
        $delay      = max(10, (int) $this->option('delay'));
        $anFiltru   = $this->option('an') ? (int) $this->option('an') : null;
        $lunaFiltru = $this->option('luna') ? (int) $this->option('luna') : null;

        if ($anFiltru !== null && ($anFiltru < 2019 || $anFiltru > 2024)) {
            $this->error('Doar anii 2019-2024 sunt permiși — 2025+ sunt deja tipizați (ștergerea ar pierde emularea îmbinată).');
            return self::FAILURE;
        }

        if (! ($bridge->health()['data']['comConnected'] ?? false)) {
            $this->error('WinMentor Bridge COM nu este conectat.');
            return self::FAILURE;
        }

        $lockKey = "winmentor_fetch_vanzari_{$firma}";
        if (Cache::has($lockKey)) {
            $this->warn('Un alt fetch vanzari/emulare rulează — ieșire.');
            return self::SUCCESS;
        }
        Cache::put($lockKey, true, now()->addHours(4));

        $conn = \App\Models\IntegrationConnection::find(5);

        try {
            return $this->doRefetch($bridge, $firma, $delay, $anFiltru, $lunaFiltru);
        } finally {
            // Restaurăm luna de lucru curentă pe COM, orice s-ar întâmpla
            try {
                $bridge->selectFirmaForMonth($conn->bridgeAn(), $conn->bridgeLuna(), $firma);
            } catch (\Throwable $e) {
                Log::channel('winmentor_sync')->error("[RefetchIstoric] NU am putut restaura luna curentă: {$e->getMessage()}");
            }
            Cache::forget($lockKey);
        }
    }

    private function doRefetch(WinmentorBridgeClient $bridge, string $firma, int $delay, ?int $anFiltru, ?int $lunaFiltru): int
    {
        $ani = $anFiltru ? [$anFiltru] : [2024, 2023, 2022, 2021, 2020, 2019];

        foreach ($ani as $an) {
            $luni = $lunaFiltru ? [$lunaFiltru] : range(12, 1);

            foreach ($luni as $luna) {
                $oldCount = DB::table('winmentor_vanzari_raw')
                    ->where('firma', $firma)->where('an', $an)->where('luna', $luna)->count();

                if ($oldCount === 0 && ! $lunaFiltru) {
                    $this->line("  [{$luna}/{$an}] fără date vechi — skip");
                    continue;
                }

                $this->line("  [{$luna}/{$an}] selectFirma + fetch ({$oldCount} rânduri vechi)...");

                $this->ctxFirma = $firma;
                $this->ctxAn    = $an;
                $this->ctxLuna  = $luna;

                try {
                    $bridge->selectFirmaForMonth($an, $luna, $firma);
                    sleep(3);

                    $lunaData = $bridge->getVanzariLuna();
                    $extData  = $this->resolveDittoTypes($bridge->getVanzari());
                    $vanzari  = $this->fixDittoBySeries($this->mergeVanzariSources($lunaData, $extData, $bridge));
                } catch (\Throwable $e) {
                    $this->warn("  [{$luna}/{$an}] EROARE fetch: {$e->getMessage()} — luna rămâne neatinsă");
                    Log::channel('winmentor_sync')->warning("[RefetchIstoric] {$luna}/{$an}: {$e->getMessage()}");
                    sleep($delay);
                    continue;
                }

                if (empty($vanzari)) {
                    $this->warn("  [{$luna}/{$an}] fetch gol — luna rămâne neatinsă (vechile {$oldCount} rânduri păstrate)");
                    sleep($delay);
                    continue;
                }

                $rows = $this->mapVanzari($vanzari, $firma, $an, $luna);

                if (empty($rows)) {
                    $this->warn("  [{$luna}/{$an}] 0 rânduri mapate — luna rămâne neatinsă");
                    sleep($delay);
                    continue;
                }

                DB::transaction(function () use ($firma, $an, $luna, $rows) {
                    DB::table('winmentor_vanzari_raw')
                        ->where('firma', $firma)->where('an', $an)->where('luna', $luna)
                        ->delete();
                    collect($rows)->chunk(500)->each(fn ($c) => DB::table('winmentor_vanzari_raw')->insert($c->all()));
                });

                $tipuri = collect($rows)->countBy('tip_document')->map(fn ($v, $k) => "{$k}:{$v}")->implode(', ');
                $this->info("  [{$luna}/{$an}] ✓ {$oldCount} → " . count($rows) . " rânduri ({$tipuri})");
                Log::channel('winmentor_sync')->info("[RefetchIstoric] {$luna}/{$an}: {$oldCount} → " . count($rows) . " ({$tipuri})");

                sleep($delay);
            }
        }

        $this->info('Refetch istoric finalizat.');
        return self::SUCCESS;
    }

    /**
     * Rezolvă tipurile „ditto" din /ext: exportul DLL scrie tipul documentului doar la
     * primul document dintr-o serie consecutivă de același tip; „=" înseamnă „același
     * tip ca documentul precedent". Forward-fill pe ordinea de export (verificat empiric
     * pe 1/2019: seriile F/AE/S nu se suprapun, doar 3/711 documente „=" împart numărul
     * cu un document tipizat).
     */
    private function resolveDittoTypes(array $extData): array
    {
        $lastType = '';

        foreach ($extData as &$row) {
            $tip = trim($row['tipDocument'] ?? '');
            if ($tip === '=' || $tip === '') {
                if ($lastType !== '') {
                    $row['tipDocument'] = $lastType;
                    $row['_ditto']      = true;
                }
            } else {
                $lastType = $tip;
            }
        }
        unset($row);

        return $extData;
    }

    /**
     * Corectează atribuirile ditto greșite de la granițele dintre serii: fiecare tip
     * de document are plaja lui de numere (F: 23xxx, AE: 53xxx, S: 158xxx în 2019);
     * un document ditto al cărui număr cade în plaja explicită a ALTUI tip primește
     * tipul plajei. Plajele se calculează per lună doar din documentele tipizate explicit.
     */
    private function fixDittoBySeries(array $vanzari): array
    {
        $ranges = [];

        foreach ($vanzari as $row) {
            if (! empty($row['_ditto'])) continue;
            $tip = $row['tipDocument'] ?? '';
            $nr  = $row['prefixDoc'] ?? '';
            if (! in_array($tip, ['AE', 'F', 'S'], true) || ! ctype_digit((string) $nr)) continue;
            $n = (int) $nr;
            $ranges[$tip] = [
                min($ranges[$tip][0] ?? $n, $n),
                max($ranges[$tip][1] ?? $n, $n),
            ];
        }

        foreach ($vanzari as &$row) {
            if (empty($row['_ditto'])) continue;
            $nr = $row['prefixDoc'] ?? '';
            if (! ctype_digit((string) $nr)) continue;
            $n       = (int) $nr;
            $current = $row['tipDocument'] ?? '';

            $inOwn = isset($ranges[$current]) && $n >= $ranges[$current][0] && $n <= $ranges[$current][1];
            if ($inOwn) continue;

            $matches = [];
            foreach ($ranges as $tip => [$lo, $hi]) {
                if ($tip !== $current && $n >= $lo && $n <= $hi) {
                    $matches[] = $tip;
                }
            }
            if (count($matches) === 1) {
                $row['tipDocument'] = $matches[0];
            }
        }
        unset($row);

        // Curățăm markerul intern înainte de salvare (să nu ajungă în raw_row)
        foreach ($vanzari as &$row) {
            unset($row['_ditto']);
        }
        unset($row);

        return $vanzari;
    }

    /**
     * Aceeași mapare ca watch-ul (format merge unificat → coloane winmentor_vanzari_raw).
     */
    private function mapVanzari(array $vanzari, string $firma, int $an, int $luna): array
    {
        $now  = now();
        $rows = [];

        foreach ($vanzari as $row) {
            if (! is_array($row) || count($row) < 16) continue;

            $cantStr = str_replace(',', '.', trim($row['artID'] ?? ''));
            $pretStr = trim($row['denUM'] ?? '');
            $valStr  = trim($row['valAchizitie'] ?? '');

            $rows[] = [
                'firma'               => $firma,
                'an'                  => $an,
                'luna'                => $luna,
                'zi'                  => is_numeric($row['zi'] ?? '') ? (int) $row['zi'] : null,
                'part_id'             => trim($row['partID'] ?? '') ?: null,
                'nr_factura'          => trim($row['prefixDoc'] ?? '') ?: null,
                'sku'                 => trim($row['nrDoc'] ?? '') ?: null,
                'cantitate'           => $cantStr !== '' && is_numeric($cantStr) ? (float) $cantStr : null,
                'uom'                 => trim($row['cant'] ?? '') ?: null,
                'pret'                => $pretStr !== '' ? (float) str_replace(',', '.', $pretStr) : null,
                'den_gestiune'        => trim($row['pret'] ?? '') ?: null,
                'cod_fiscal_client'   => trim($row['adresa'] ?? '') ?: null,
                'adresa_client'       => trim($row['codFiscal'] ?? '') ?: null,
                'marca_agent'         => trim($row['marcaAgent'] ?? '') ?: null,
                'valoare_totala'      => $valStr !== '' ? (float) str_replace(',', '.', $valStr) : null,
                'clasa_articol'       => trim($row['clasaArticol'] ?? '') ?: null,
                'tip_document'        => trim($row['tipDocument'] ?? '') ?: null,
                'den_articol'         => trim($row['denArticol'] ?? '') ?: null,
                'discount'            => trim($row['discount'] ?? '') ?: null,
                'serie_document'      => trim($row['serieDocument'] ?? '') ?: null,
                'observatii_factura'  => mb_substr(trim($row['observatiiFactura'] ?? ''), 0, 500) ?: null,
                'localitate_client'   => trim($row['localitateClient'] ?? '') ?: null,
                'valoare_factura'     => ($vf = str_replace(',', '.', trim($row['valoareFactura'] ?? ''))) !== '' && is_numeric($vf) ? (float) $vf : null,
                'data_scadenta'       => trim($row['dataScadenta'] ?? '') ?: null,
                'data_emitere'        => trim($row['dataEmitere'] ?? '') ?: null,
                'cota_tva'            => trim($row['cotaTVA'] ?? '') ?: null,
                'raw_row'             => json_encode($row),
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        return $rows;
    }
}
