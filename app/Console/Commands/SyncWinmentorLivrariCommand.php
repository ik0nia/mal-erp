<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WinmentorLivrare;
use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncWinmentorLivrariCommand extends Command
{
    protected $signature = 'sync:winmentor-livrari';
    protected $description = 'Sincronizează livrările CM din WinMentor Bridge și trackuiește istoricul';

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

        // Filtrăm doar CM
        $cmLines = collect($raw)->filter(fn($r) => ($r[14] ?? '') === 'CM')->values();
        $this->info("Găsite {$cmLines->count()} linii CM.");

        $now = Carbon::now();

        // Mapări pentru denormalizare
        $parteneriMap = DB::table('winmentor_parteneri')->pluck('denumire', 'wm_id')->toArray();

        // Fallback: part_id poate fi un ID de sediu, nu de partener.
        // Construim o mapă sediu→denumire din partenerii WinMentor.
        $missingPartIds = $cmLines->pluck(0)->unique()->filter()
            ->reject(fn($id) => isset($parteneriMap[(string) $id]))
            ->values();

        if ($missingPartIds->isNotEmpty()) {
            $bridge = app(\App\Services\Winmentor\WinmentorBridgeClient::class);
            $bridge->selectFirma();
            $ref    = new \ReflectionClass($bridge);
            $getMethod = $ref->getMethod('get');
            $getMethod->setAccessible(true);

            // Scanăm partenerii din Bridge și construim mapă sediu→denumire
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
                // Stop early dacă am rezolvat toate
                if ($missingPartIds->every(fn($id) => isset($sediuMap[(string) $id]))) break;
            }

            foreach ($sediuMap as $sediuId => $denumire) {
                if ($denumire !== '') {
                    $parteneriMap[$sediuId] = $denumire;
                }
            }
        }

        $artIds = $cmLines->pluck(3)->unique()->filter()->toArray();
        $produseMap = empty($artIds) ? [] : WooProduct::whereIn('sku', $artIds)->pluck('name', 'sku')->toArray();

        // Collect toate cheile din API (id_doc + pozitie)
        $seenKeys = [];

        $upserted = 0;
        foreach ($cmLines->chunk(100) as $chunk) {
            $rows = [];
            foreach ($chunk as $row) {
                $idDoc   = (string) ($row[10] ?? '');
                $pozitie = (string) ($row[12] ?? '');
                $partId  = (string) ($row[0] ?? '');
                $artId   = (string) ($row[3] ?? '');
                $key     = $idDoc . '|' . $pozitie;

                $seenKeys[$key] = true;

                $cantitate = (float) str_replace(',', '.', $row[4] ?? '0');
                $pret      = (float) str_replace(',', '.', $row[5] ?? '0');
                $cantFact  = (float) str_replace(',', '.', $row[6] ?? '0');

                $rows[] = [
                    'id_doc'         => $idDoc,
                    'pozitie'        => $pozitie,
                    'nr_comanda'     => (string) ($row[2] ?? ''),
                    'tip_doc'        => (string) ($row[14] ?? 'CM'),
                    'data_comanda'   => (string) ($row[1] ?? ''),
                    'part_id'        => $partId,
                    'client_name'    => $parteneriMap[$partId] ?? null,
                    'art_id'         => $artId,
                    'produs_name'    => $produseMap[$artId] ?? null,
                    'cantitate'      => $cantitate,
                    'pret'           => $pret,
                    'cant_facturata' => $cantFact,
                    'gestiune'       => (string) ($row[8] ?? ''),
                    'observatii'     => (string) ($row[9] ?? ''),
                ];
            }

            foreach ($rows as $data) {
                $livrare = WinmentorLivrare::updateOrCreate(
                    ['id_doc' => $data['id_doc'], 'pozitie' => $data['pozitie']],
                    array_merge($data, [
                        'last_seen_at'   => $now,
                        'disappeared_at' => null, // Reapărut? Resetăm
                    ])
                );

                if (! $livrare->first_seen_at) {
                    $livrare->update(['first_seen_at' => $now]);
                }

                $upserted++;
            }
        }

        // Marchează dispărute: active în DB dar nu în API
        $disappeared = WinmentorLivrare::active()
            ->where('last_seen_at', '<', $now)
            ->update(['disappeared_at' => $now]);

        $this->info("Upserted: {$upserted}, Dispărute acum: {$disappeared}");

        // Invalidate cache badge
        \Illuminate\Support\Facades\Cache::forget('wm_livrari_comenzi_count');

        return self::SUCCESS;
    }

    private function fetchComenziNefacturate(IntegrationConnection $conn): ?array
    {
        try {
            $baseUrl = rtrim($conn->bridgeUrl(), '/');
            $apiKey  = $conn->bridgeApiKey();

            $response = Http::timeout(30)->withoutVerifying()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get($baseUrl . '/api/comenzi/nefacturate');

            $items = $response->json()['data'] ?? [];

            // MentorAPI returnează named fields; consumatorul așteaptă array pozițional.
            // Mapare: [0]=idPartener, [1]=dataComanda, [2]=nrComanda, [3]=codArticol,
            //         [4]=cantitate, [5]=pret, [6]=cantComanda, [7]=denUM,
            //         [8]=marcaAgent, [9]=observatii, [10]=nrDocument, [11]=serieDocument,
            //         [12]=pozitie, [13]=dataLivrare, [14]=serieDocument (=tip doc CM/CC)
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
