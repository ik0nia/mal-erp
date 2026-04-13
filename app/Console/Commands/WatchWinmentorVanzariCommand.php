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
            $vanzari = $bridge->getVanzari();
        } catch (\Throwable $e) {
            Log::channel('winmentor_sync')->warning("[WinMentor WatchVanzari] Eroare fetch: {$e->getMessage()}");
            return self::SUCCESS;
        }

        if (empty($vanzari)) return self::SUCCESS;

        // Construiește set de chei existente pentru luna curentă
        $existing = DB::table('winmentor_vanzari_raw')
            ->where('firma', $firma)->where('an', $an)->where('luna', $luna)
            ->select(['nr_factura', 'sku', 'part_id', 'zi'])
            ->get()
            ->map(fn($r) => "{$r->nr_factura}|{$r->sku}|{$r->part_id}|{$r->zi}")
            ->flip()
            ->all();

        $newRows = [];
        $now = now();

        foreach ($vanzari as $row) {
            if (! is_array($row) || count($row) < 16) continue;

            $nrFactura = trim($row['prefixDoc'] ?? '');
            $sku       = trim($row['nrDoc'] ?? '');
            $partId    = trim($row['partID'] ?? '');
            $zi        = is_numeric($row['zi'] ?? '') ? (int) $row['zi'] : null;

            $key = "{$nrFactura}|{$sku}|{$partId}|{$zi}";
            if (isset($existing[$key])) continue;

            $cantStr = trim($row['artID'] ?? '');
            $pretStr = trim($row['denUM'] ?? '');
            $valStr  = trim($row['valAchizitie'] ?? '');

            $newRows[] = [
                'firma'             => $firma,
                'an'                => $an,
                'luna'              => $luna,
                'zi'                => $zi,
                'part_id'           => $partId ?: null,
                'nr_factura'        => $nrFactura ?: null,
                'sku'               => $sku ?: null,
                'cantitate'         => $cantStr !== '' && is_numeric($cantStr) ? (float) $cantStr : null,
                'uom'               => trim($row['cant'] ?? '') ?: null,
                'pret'              => $pretStr !== '' ? (float) str_replace(',', '.', $pretStr) : null,
                'den_gestiune'      => trim($row['pret'] ?? '') ?: null,
                'cod_fiscal_client' => trim($row['adresa'] ?? '') ?: null,
                'adresa_client'     => trim($row['codFiscal'] ?? '') ?: null,
                'marca_agent'       => trim($row['marcaAgent'] ?? '') ?: null,
                'valoare_totala'    => $valStr !== '' ? (float) str_replace(',', '.', $valStr) : null,
                'clasa_articol'     => trim($row['clasaArticol'] ?? '') ?: null,
                'raw_row'           => json_encode($row),
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        if (empty($newRows)) return self::SUCCESS;

        collect($newRows)->chunk(500)->each(fn($c) => DB::table('winmentor_vanzari_raw')->insert($c->all()));

        Log::channel('winmentor_sync')->info("[WinMentor WatchVanzari] {$luna}/{$an} firma={$firma}: " . count($newRows) . " vânzări noi");

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
}
