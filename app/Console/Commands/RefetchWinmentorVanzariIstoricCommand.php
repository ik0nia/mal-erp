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

        // 2025 permis explicit (cu --an): mergeVanzariSources re-aduce emularea per lună
        // (fallback pe /ext), deci nu se pierde nimic; datele 2025 populate sub vechiul
        // format au bonurile S sub-reprezentate ~10× (constatat 2026-09-08).
        // Anul curent: permis DOAR pe luni deja încheiate (documente introduse
        // târziu/retroactiv după ultima citire a lunii de către watch); luna
        // curentă rămâne exclusiv a watch-ului.
        $azi = now('Europe/Bucharest');
        if ($anFiltru !== null && ($anFiltru < 2019 || $anFiltru > $azi->year)) {
            $this->error('Doar anii 2019-' . $azi->year . ' sunt permiși.');
            return self::FAILURE;
        }
        if ($anFiltru === (int) $azi->year && (! $lunaFiltru || $lunaFiltru >= $azi->month)) {
            $this->error('Pentru anul curent e obligatorie --luna, strict înaintea lunii curente (luna curentă e a watch-ului).');
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

                app(\App\Services\Winmentor\VanzariNetService::class)->recomputeAn($an, $firma);

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
