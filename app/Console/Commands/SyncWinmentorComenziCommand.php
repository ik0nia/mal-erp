<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WinmentorComanda;
use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncWinmentorComenziCommand extends Command
{
    protected $signature = 'sync:winmentor-comenzi';
    protected $description = 'Sincronizează comenzile clienți non-CM din WinMentor, trackuiește deschis/facturat + euristic factura/avizul';

    public function handle(): int
    {
        $conn = IntegrationConnection::find(5);
        if (! $conn) {
            $this->error('IntegrationConnection #5 (WinMentor Bridge) nu există.');
            return self::FAILURE;
        }

        $this->info('Fetch comenzi nefacturate din Bridge...');

        $raw = $this->fetchComenziNefacturate($conn);
        if ($raw === null) {
            $this->error('Eroare la conectarea cu Bridge API.');
            return self::FAILURE;
        }

        // Toate comenzile EXCEPT seria CM (CM are pagina separată Livrări).
        $lines = collect($raw)->filter(fn($r) => ($r[14] ?? '') !== 'CM')->values();
        $this->info("Găsite {$lines->count()} linii comandă non-CM.");

        $now = Carbon::now();

        // ── Denormalizare nume clienți (partener + fallback sediu) ──
        $parteneriMap = DB::table('winmentor_parteneri')->pluck('denumire', 'wm_id')->toArray();
        $parteneriMap = $this->resolveSediuNames($lines, $parteneriMap);

        $artIds = $lines->pluck(3)->unique()->filter()->toArray();
        $produseMap = empty($artIds) ? [] : WooProduct::whereIn('sku', $artIds)->pluck('name', 'sku')->toArray();

        // ── Upsert linii deschise ──
        $upserted = 0;
        foreach ($lines->chunk(100) as $chunk) {
            foreach ($chunk as $row) {
                $idDoc   = (string) ($row[10] ?? '');
                $pozitie = (string) ($row[12] ?? '');
                $partId  = (string) ($row[0] ?? '');
                $artId   = (string) ($row[3] ?? '');

                $data = [
                    'nr_comanda'   => (string) ($row[2] ?? ''),
                    'serie'        => (string) ($row[14] ?? ''),
                    'data_comanda' => (string) ($row[1] ?? ''),
                    'data_livrare' => (string) ($row[13] ?? ''),
                    'part_id'      => $partId,
                    'client_name'  => $parteneriMap[$partId] ?? null,
                    'art_id'       => $artId,
                    'produs_name'  => $produseMap[$artId] ?? null,
                    'cantitate'    => (float) str_replace(',', '.', $row[4] ?? '0'),
                    'pret'         => (float) str_replace(',', '.', $row[5] ?? '0'),
                    'cant_comanda' => (float) str_replace(',', '.', $row[6] ?? '0'),
                    'gestiune'     => (string) ($row[8] ?? ''),
                    'observatii'   => (string) ($row[9] ?? ''),
                ];

                $comanda = WinmentorComanda::updateOrCreate(
                    ['id_doc' => $idDoc, 'pozitie' => $pozitie],
                    array_merge($data, [
                        'last_seen_at'   => $now,
                        'disappeared_at' => null, // reapărut? redevine deschisă
                    ])
                );

                if (! $comanda->first_seen_at) {
                    $comanda->update(['first_seen_at' => $now]);
                }

                $upserted++;
            }
        }

        // ── Marchează închise: deschise în DB dar absente din API acum ──
        $disappeared = WinmentorComanda::deschise()
            ->where('last_seen_at', '<', $now)
            ->update(['disappeared_at' => $now]);

        $this->info("Upserted: {$upserted}, Închise acum: {$disappeared}");

        // ── Rezolvare euristică factură/aviz pentru liniile închise fără document ──
        $resolved = $this->resolveFacturi($conn);
        $this->info("Facturi/avize potrivite euristic: {$resolved}");

        Cache::forget('wm_comenzi_count');

        return self::SUCCESS;
    }

    /**
     * Pentru liniile închise care nu au încă document atribuit, caută în vânzările lunii
     * o linie cu același partener + articol + cantitate (cea mai apropiată ca dată) și
     * o marchează ca factură/aviz probabil (euristic — WinMentor nu leagă direct).
     */
    private function resolveFacturi(IntegrationConnection $conn): int
    {
        $needResolve = WinmentorComanda::inchise()
            ->whereNull('factura_nr')
            ->get();

        if ($needResolve->isEmpty()) {
            return 0;
        }

        $vanzari = $this->fetchVanzariLuna($conn);
        if (empty($vanzari)) {
            return 0;
        }

        // Index pe "partener|articol" → linii vânzare
        $index = [];
        foreach ($vanzari as $v) {
            $key = ($v['idPartener'] ?? '') . '|' . ($v['codArticol'] ?? '');
            $index[$key][] = $v;
        }

        $resolved = 0;
        foreach ($needResolve as $cmd) {
            $key = $cmd->part_id . '|' . $cmd->art_id;
            $candidates = $index[$key] ?? [];
            if (empty($candidates)) {
                continue;
            }

            $qty = (float) $cmd->cantitate;

            // Sortăm: cantitate exactă întâi, apoi cea mai apropiată cantitate
            usort($candidates, function ($a, $b) use ($qty) {
                $da = abs((float) str_replace(',', '.', $a['cant'] ?? '0') - $qty);
                $db = abs((float) str_replace(',', '.', $b['cant'] ?? '0') - $qty);
                return $da <=> $db;
            });

            $best = $candidates[0];

            $cmd->update([
                'factura_tip'     => $best['tipDocument'] ?? null,
                'factura_nr'      => $best['nrFactura'] ?? null,
                'factura_serie'   => $best['serieDocument'] ?? null,
                'factura_data'    => $best['dataEmitere'] ?? null,
                'factura_estimat' => true,
            ]);

            $resolved++;
        }

        return $resolved;
    }

    private function fetchVanzariLuna(IntegrationConnection $conn): array
    {
        try {
            $r = Http::timeout(90)->withoutVerifying()
                ->withHeaders(['X-API-Key' => $conn->bridgeApiKey()])
                ->get(rtrim($conn->bridgeUrl(), '/') . '/api/vanzari/luna');

            return $r->json()['data'] ?? [];
        } catch (\Throwable $e) {
            $this->warn('Vanzari luna indisponibile: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * part_id poate fi un ID de sediu, nu de partener. Construim mapă sediu→denumire
     * din partenerii WinMentor pentru ID-urile lipsă (la fel ca la livrări).
     */
    private function resolveSediuNames(\Illuminate\Support\Collection $lines, array $parteneriMap): array
    {
        $missingPartIds = $lines->pluck(0)->unique()->filter()
            ->reject(fn($id) => isset($parteneriMap[(string) $id]))
            ->values();

        if ($missingPartIds->isEmpty()) {
            return $parteneriMap;
        }

        try {
            $bridge = app(\App\Services\Winmentor\WinmentorBridgeClient::class);
            $bridge->selectFirma();
            $ref = new \ReflectionClass($bridge);
            $getMethod = $ref->getMethod('get');
            $getMethod->setAccessible(true);

            $sediuMap = [];
            for ($page = 1; $page <= 50; $page++) {
                $r = $getMethod->invoke($bridge, '/api/parteneri', ['page' => $page, 'pageSize' => 500]);
                $items = $r['data']['items'] ?? [];

                foreach ($items as $item) {
                    foreach ($item['denumiriSedii'] ?? [] as $sediuId) {
                        if ($missingPartIds->contains($sediuId)) {
                            $sediuMap[$sediuId] = $item['denumire'] ?? '';
                        }
                    }
                }

                if (! ($r['data']['hasNextPage'] ?? false)) break;
                if ($missingPartIds->every(fn($id) => isset($sediuMap[(string) $id]))) break;
            }

            foreach ($sediuMap as $sediuId => $denumire) {
                if ($denumire !== '') {
                    $parteneriMap[$sediuId] = $denumire;
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Rezolvare sedii eșuată: ' . $e->getMessage());
        }

        return $parteneriMap;
    }

    private function fetchComenziNefacturate(IntegrationConnection $conn): ?array
    {
        try {
            $response = Http::timeout(30)->withoutVerifying()
                ->withHeaders(['X-API-Key' => $conn->bridgeApiKey()])
                ->get(rtrim($conn->bridgeUrl(), '/') . '/api/comenzi/nefacturate');

            $items = $response->json()['data'] ?? [];

            // MentorAPI returnează named fields; consumatorul așteaptă array pozițional.
            return array_map(fn (array $row) => [
                /* [0]  */ $row['idPartener'] ?? '',
                /* [1]  */ $row['dataComanda'] ?? '',
                /* [2]  */ $row['nrComanda'] ?? '',
                /* [3]  */ $row['codArticol'] ?? '',
                /* [4]  */ $row['cantitate'] ?? '',
                /* [5]  */ $row['pret'] ?? '',
                /* [6]  */ $row['cantComanda'] ?? '',
                /* [7]  */ $row['denUM'] ?? '',
                /* [8]  */ $row['marcaAgent'] ?? '',
                /* [9]  */ $row['observatii'] ?? '',
                /* [10] */ $row['nrDocument'] ?? '',
                /* [11] */ $row['codExternAlt'] ?? '',
                /* [12] */ $row['pozitie'] ?? '',
                /* [13] */ $row['dataLivrare'] ?? '',
                /* [14] */ $row['serieDocument'] ?? '',
                /* [15] */ $row['sediuPartener'] ?? '',
                /* [16] */ $row['numePartener'] ?? '',
                /* [17] */ $row['moneda'] ?? '',
            ], $items);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return null;
        }
    }
}
