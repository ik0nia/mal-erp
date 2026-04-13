<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rulează la fiecare 5 minute (prin scheduler).
 * Detectează intrări noi în WinMentor față de ce avem în staging,
 * le salvează și le procesează imediat.
 */
class WatchWinmentorIntrariCommand extends Command
{
    protected $signature = 'winmentor:watch-intrari
                            {--firma=MAL2019 : Firma de monitorizat}';

    protected $description = 'Detectează intrări noi în WinMentor și le procesează automat (rulat din cron la 5 min)';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma = $this->option('firma');

        // Verifică COM
        $health = $bridge->health();
        if (! ($health['data']['comConnected'] ?? false)) {
            // Nu logăm eroare — e normal dacă WinMentor e închis noaptea
            return self::SUCCESS;
        }

        $conn = \App\Models\IntegrationConnection::find(5);
        $an   = $conn->bridgeAn();
        $luna = $conn->bridgeLuna();

        try {
            $bridge->selectFirmaForMonth($an, $luna, $firma);
            $intrari = $bridge->getReceptii();
        } catch (\Throwable $e) {
            Log::channel('winmentor_sync')->warning("[WinMentor WatchIntrari] Eroare fetch: {$e->getMessage()}");
            return self::SUCCESS;
        }

        if (empty($intrari)) return self::SUCCESS;

        // Construiește un set de chei existente în staging pentru luna curentă
        $existing = DB::table('winmentor_intrari_raw')
            ->where('firma', $firma)
            ->where('an', $an)
            ->where('luna', $luna)
            ->select(['nr_doc', 'sku', 'part_id', 'data_intrare'])
            ->get()
            ->map(fn($r) => "{$r->nr_doc}|{$r->sku}|{$r->part_id}|{$r->data_intrare}")
            ->flip()
            ->all();

        $newRows = [];
        $now = now();

        foreach ($intrari as $row) {
            if (! is_array($row) || count($row) < 14) continue;

            // Receptii: [5]=part_id, [6]=nr_factura, [7]=data_factura, [8]=sku
            $nrDoc   = trim($row[6] ?? '');
            $sku     = trim($row[8] ?? '');
            $partId  = trim($row[5] ?? '');
            $dateStr = trim($row[7] ?? '');

            $date = null;
            try {
                $date = \Carbon\Carbon::createFromFormat('d.m.Y', $dateStr)->toDateString();
            } catch (\Throwable) { continue; }

            $key = "{$nrDoc}|{$sku}|{$partId}|{$date}";
            if (isset($existing[$key])) continue;

            $pretStr     = trim($row[14] ?? '');
            $cantStr     = trim($row[13] ?? '');
            $pretVanzStr = trim($row[15] ?? '');

            $newRows[] = [
                'firma'         => $firma,
                'an'            => $an,
                'luna'          => $luna,
                'part_id'       => $partId ?: null,
                'den_furnizor'  => trim($row[4] ?? '') ?: null,
                'data_intrare'  => $date,
                'nr_doc'        => $nrDoc ?: null,
                'nr_receptie'   => trim($row[2] ?? '') ?: null,
                'sku'           => $sku ?: null,
                'den_articol'   => trim($row[9] ?? '') ?: null,
                'cantitate'     => $cantStr !== '' ? (float) str_replace(',', '.', $cantStr) : null,
                'uom'           => trim($row[11] ?? '') ?: null,
                'pret'          => $pretStr !== '' ? (float) str_replace(',', '.', $pretStr) : null,
                'pret_vanzare'  => $pretVanzStr !== '' ? (float) str_replace(',', '.', $pretVanzStr) : null,
                'den_gestiune'  => trim($row[1] ?? '') ?: null,
                'id_comanda_wm' => trim($row[17] ?? '') ?: null,
                'raw_row'       => json_encode($row),
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (empty($newRows)) return self::SUCCESS;

        // Salvează noile rânduri
        collect($newRows)->chunk(500)->each(fn($c) => DB::table('winmentor_intrari_raw')->insert($c->all()));

        Log::channel('winmentor_sync')->info("[WinMentor WatchIntrari] {$luna}/{$an} firma={$firma}: " . count($newRows) . " intrări noi detectate");

        // Procesează imediat noile rânduri
        $this->call('winmentor:process-intrari', ['--firma' => $firma, '--batch' => '200']);

        // Actualizează sync log
        DB::table('winmentor_intrari_sync')->upsert([
            'firma'        => $firma,
            'an'           => $an,
            'luna'         => $luna,
            'rows_fetched' => count($intrari),
            'fetched_at'   => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ], ['firma', 'an', 'luna'], ['rows_fetched', 'fetched_at', 'updated_at']);

        return self::SUCCESS;
    }
}
