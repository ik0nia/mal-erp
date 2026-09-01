<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rulează schedulat la fiecare 15 minute (luni–sâmbătă 08:00–17:30).
 * Detectează vânzări noi față de ce avem în staging și le salvează.
 */
class WatchWinmentorVanzariCommand extends Command
{
    protected $signature = 'winmentor:watch-vanzari
                            {--firma=MAL2019 : Firma de monitorizat}';

    protected $description = 'Detectează vânzări noi în WinMentor și le salvează (rulat din cron la 15 min)';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma = $this->option('firma');

        $health = $bridge->health();
        if (! ($health['data']['comConnected'] ?? false)) {
            return self::SUCCESS;
        }

        // Previne rulări concurente cu fetch-ul de backfill
        if (Cache::has("winmentor_fetch_vanzari_{$firma}")) {
            return self::SUCCESS;
        }

        $conn = \App\Models\IntegrationConnection::find(5);
        $an   = $conn->bridgeAn();
        $luna = $conn->bridgeLuna();

        try {
            $bridge->selectFirmaForMonth($an, $luna, $firma);
            sleep(3); // pauză după selectFirma

            // /vanzari/luna: facturi + avize cu date bogate (denArticol, discount, etc.)
            $lunaData = $bridge->getVanzariLuna();

            // /vanzari/ext: include bonurile de casă (tipDocument S), date de bază
            $extData = $bridge->getVanzari();

            // Merge: lunaData cu tipDocument precis (AE/F) + emulare bonuri (S)
            $vanzari = $this->mergeVanzariSources($lunaData, $extData, $bridge);
        } catch (\Throwable $e) {
            Log::channel('winmentor_sync')->warning("[WinMentor WatchVanzari] Eroare fetch: {$e->getMessage()}");
            return self::SUCCESS;
        }

        if (empty($vanzari)) return self::SUCCESS;

        $now  = now();
        $rows = [];

        foreach ($vanzari as $row) {
            if (! is_array($row) || count($row) < 16) continue;

            $nrFactura = trim($row['prefixDoc'] ?? '');
            $sku       = trim($row['nrDoc'] ?? '');
            $partId    = trim($row['partID'] ?? '');
            $zi        = is_numeric($row['zi'] ?? '') ? (int) $row['zi'] : null;
            // WinMentor trimite zecimalele cu virgulă ("5,5") — normalizăm înainte de is_numeric
            $cantStr   = str_replace(',', '.', trim($row['artID'] ?? ''));
            $pretStr   = trim($row['denUM'] ?? '');
            $valStr    = trim($row['valAchizitie'] ?? '');

            $rows[] = [
                'firma'               => $firma,
                'an'                  => $an,
                'luna'                => $luna,
                'zi'                  => $zi,
                'part_id'             => $partId ?: null,
                'nr_factura'          => $nrFactura ?: null,
                'sku'                 => $sku ?: null,
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

        if (empty($rows)) return self::SUCCESS;

        // Sync incremental: păstrăm created_at original (momentul primei detectări).
        // Cheie de unicitate: firma+an+luna+nr_factura+sku+zi (un rând per linie document).
        DB::transaction(function () use ($firma, $an, $luna, $rows, $now) {
            // Indexăm rândurile existente ca să păstrăm created_at
            $existing = DB::table('winmentor_vanzari_raw')
                ->where('firma', $firma)->where('an', $an)->where('luna', $luna)
                ->get()
                ->keyBy(fn ($r) => implode('|', [$r->nr_factura, $r->sku, $r->zi]));

            // Ștergem luna și reinsertăm — dar cu created_at original acolo unde exista
            DB::table('winmentor_vanzari_raw')
                ->where('firma', $firma)->where('an', $an)->where('luna', $luna)
                ->delete();

            foreach ($rows as &$row) {
                $key = implode('|', [$row['nr_factura'], $row['sku'], $row['zi']]);
                if (isset($existing[$key])) {
                    $row['created_at'] = $existing[$key]->created_at; // păstrăm ora originală
                }
            }
            unset($row);

            collect($rows)->chunk(500)->each(fn ($c) => DB::table('winmentor_vanzari_raw')->insert($c->all()));
        });

        Log::channel('winmentor_sync')->info("[WinMentor WatchVanzari] {$luna}/{$an} firma={$firma}: " . count($rows) . " linii (refill complet)");

        DB::table('winmentor_vanzari_sync')->upsert([
            'firma'        => $firma,
            'an'           => $an,
            'luna'         => $luna,
            'rows_fetched' => count($vanzari),
            'fetched_at'   => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ], ['firma', 'an', 'luna'], ['rows_fetched', 'fetched_at', 'updated_at']);

        return self::SUCCESS;
    }

    /**
     * Merge datele din /vanzari/luna (facturi+avize, date bogate) cu /vanzari/ext (bonuri de casă).
     * /luna dă tipDocument AE/F cu denArticol, discount, observatii.
     * /ext dă tipDocument =/S — S = bonuri de casă.
     * Rezultat: format unificat compatibil cu consumer-ul existent (chei shifted).
     */
    private function mergeVanzariSources(array $lunaData, array $extData, WinmentorBridgeClient $bridge): array
    {
        $result = [];

        // Identificăm facturi/avize plătite cash: nr_factura apare cu tipDocument S în /ext
        $cashPaidDocs = [];
        foreach ($extData as $row) {
            if (($row['tipDocument'] ?? '') === 'S') {
                $nr = $row['prefixDoc'] ?? '';
                if ($nr && ! preg_match('/^2\d{5}$/', $nr)) {
                    $cashPaidDocs[$nr] = true; // factura/aviz plătit(ă) cash
                }
            }
        }

        // 1. Facturi + avize din /luna (date bogate, tipDocument precis: AE/F)
        $lunaKeys = [];
        foreach ($lunaData as $row) {
            $key = ($row['nrFactura'] ?? '') . '|' . ($row['codArticol'] ?? '') . '|' . ($row['zi'] ?? '');
            $lunaKeys[$key] = true;

            $result[] = [
                'partID'              => $row['idPartener'] ?? '',
                'zi'                  => $row['zi'] ?? '',
                'prefixDoc'           => $row['nrFactura'] ?? '',
                'nrDoc'               => $row['codArticol'] ?? '',
                'artID'               => $row['cant'] ?? '',
                'cant'                => $row['denUM'] ?? '',
                'denUM'               => $row['pret'] ?? '',
                'pret'                => '',
                'adresa'              => '',
                'codFiscal'           => $row['adresaClient'] ?? '',
                'marcaAgent'          => $row['marcaAgent'] ?? '',
                'valAchizitie'        => $row['valoareFactura'] ?? '0',
                'clasaArticol'        => '',
                'tipDocument'         => $row['tipDocument'] ?? '',
                'denArticol'          => $row['denArticol'] ?? '',
                'discount'            => $row['discount'] ?? '',
                'serieDocument'       => $row['serieDocument'] ?? '',
                'observatiiFactura'   => $row['observatiiFactura'] ?? '',
                'localitateClient'    => $row['localitateClient'] ?? '',
                'valoareFactura'      => $row['valoareFactura'] ?? '',
                'dataScadenta'        => $row['dataScadenta'] ?? '',
                'dataEmitere'         => $row['dataEmitere'] ?? '',
                'cotaTVA'             => $row['cotaTVA'] ?? '',
            ];
        }

        // 2. Bonuri de casă din /vanzari/emulare (date bogate: denArticol, numeClient, nr bon zilnic)
        try {
            $emulare = $bridge->getVanzariEmulare();

            // Grupăm per (data, idBon) ca să calculăm nr bon zilnic
            $byDay = [];
            foreach ($emulare as $row) {
                $byDay[$row['data'] ?? ''][$row['idBon'] ?? ''] = true;
            }
            // Calculăm rang per zi
            $bonRank = [];
            foreach ($byDay as $data => $bons) {
                $sorted = array_keys($bons);
                sort($sorted, SORT_NUMERIC);
                foreach ($sorted as $idx => $idBon) {
                    $bonRank["{$data}|{$idBon}"] = $idx + 1;
                }
            }

            foreach ($emulare as $row) {
                $data   = $row['data'] ?? '';
                $idBon  = $row['idBon'] ?? '';
                $zi     = 0;
                if (preg_match('/^(\d{2})\./', $data, $m)) $zi = (int) $m[1];

                $dailyNr = $bonRank["{$data}|{$idBon}"] ?? 0;

                $result[] = [
                    'partID'              => '',
                    'zi'                  => (string) $zi,
                    'prefixDoc'           => $idBon,              // nr bon global
                    'nrDoc'               => $row['codArticol'] ?? '',
                    // ATENȚIE semantică emulare (verificat 2026-07-12): cantitatea reală
                    // vândută e în 'cantVanduta'; câmpul 'cantitate' conține poziția pe bon.
                    'artID'               => $row['cantVanduta'] ?? '',
                    'cant'                => '',
                    'denUM'               => $row['pret'] ?? '',
                    'pret'                => $row['denGestiune'] ?? '',
                    'adresa'              => '',
                    'codFiscal'           => '',
                    'marcaAgent'          => '',
                    'valAchizitie'        => $row['valoare'] ?? '0',
                    'clasaArticol'        => '',
                    'tipDocument'         => 'S',
                    'denArticol'          => $row['denArticol'] ?? '',
                    'discount'            => '',
                    'serieDocument'       => 'Bon #' . $dailyNr . ' / Casa ' . ($row['nrComanda'] ?? ''),
                    'observatiiFactura'   => trim($row['numeClient'] ?? '') ?: null,
                    'localitateClient'    => '',
                ];
            }
        } catch (\Throwable $e) {
            // Fallback: bonuri din /ext dacă emulare eșuează
            foreach ($extData as $row) {
                $key = ($row['prefixDoc'] ?? '') . '|' . ($row['nrDoc'] ?? '') . '|' . ($row['zi'] ?? '');
                if (isset($lunaKeys[$key])) continue;
                $result[] = $row;
            }

            return $result;
        }

        // 3. Bonuri native Mentor (seria 2xxxxx) din /ext — flux SEPARAT de emulare
        //    (casa din Mentor, nu casa Magazin Practic). Fără acest bloc se pierdeau
        //    complet începând cu mai 2026 (~500 linii/lună).
        foreach ($extData as $row) {
            if (($row['tipDocument'] ?? '') !== 'S') continue;
            $nr = trim($row['prefixDoc'] ?? '');
            if (! preg_match('/^2\d{5}$/', $nr)) continue;
            $result[] = $row;
        }

        return $result;
    }
}
