<?php

namespace App\Console\Commands\BI;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComputeBiVelocityCommand extends Command
{
    protected $signature = 'bi:compute-velocity
                            {--day= : Data în format YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Calculează bi_product_velocity_current (1 rând/produs) la o dată dată';

    /**
     * Articole speciale WinMentor (plăți/încasări, nu marfă) — excluse din rulaj.
     * Vezi memoria vanzari_raw_analysis_caveats.
     */
    private const EXCLUDED_SKUS = [
        '594008496559910',
        '594008496559378',
        '594008496559911',
        '594008496559525',
        '594008496560735',
    ];

    /** part_id "OFERTE CLIENTI" — avize-ofertă, nu livrări. */
    private const EXCLUDED_PART_ID = '728020531';

    /** Minim de zile de disponibilitate folosit ca divizor (evită rate absurde din spike-uri de 1-2 zile). */
    private const MIN_AVAIL_DAYS = 3;

    public function handle(): int
    {
        $day         = $this->resolveDay();
        $windowStart = Carbon::parse($day)->subDays(89)->toDateString(); // fereastră 90 zile

        $this->line("Velocity → <info>{$day}</info> (fereastră {$windowStart} → {$day})");

        // 1. Vânzări REALE per produs/zi din winmentor_vanzari_raw (documente + bonuri casă,
        //    deja consolidate la import). Cantitatea netă a zilei e tăiată la 0 (retururile
        //    unei zile nu anulează vânzările altor zile). Filtrăm doar produse tip 'shop'.
        $skuPlaceholders = implode(',', array_fill(0, count(self::EXCLUDED_SKUS), '?'));

        $rows = collect(DB::select("
            SELECT
                t.sku AS reference_product_id,
                SUM(CASE WHEN t.sale_day >= DATE_SUB(?, INTERVAL  6 DAY) THEN t.day_qty ELSE 0 END) AS out_qty_7d,
                SUM(CASE WHEN t.sale_day >= DATE_SUB(?, INTERVAL 29 DAY) THEN t.day_qty ELSE 0 END) AS out_qty_30d,
                SUM(t.day_qty)                                                                      AS out_qty_90d,
                MAX(CASE WHEN t.day_qty > 0 THEN t.sale_day END)                                    AS last_movement_day
            FROM (
                SELECT
                    v.sku,
                    STR_TO_DATE(CONCAT(v.an, '-', v.luna, '-', v.zi), '%Y-%m-%d') AS sale_day,
                    GREATEST(0, SUM(v.cantitate)) AS day_qty
                FROM winmentor_vanzari_raw v
                WHERE v.sku IS NOT NULL AND v.sku <> ''
                  AND v.sku NOT IN ({$skuPlaceholders})
                  AND (v.part_id IS NULL OR v.part_id <> ?)
                  AND STR_TO_DATE(CONCAT(v.an, '-', v.luna, '-', v.zi), '%Y-%m-%d') BETWEEN ? AND ?
                GROUP BY v.sku, sale_day
            ) t
            LEFT JOIN woo_products wp ON wp.sku = t.sku
            WHERE COALESCE(wp.product_type, 'shop') = 'shop'
            GROUP BY t.sku
        ", [$day, $day, ...self::EXCLUDED_SKUS, self::EXCLUDED_PART_ID, $windowStart, $day]));

        if ($rows->isEmpty()) {
            $this->warn("  Nicio vânzare în winmentor_vanzari_raw pentru fereastra {$windowStart} → {$day}. Skip.");
            Log::warning('bi:compute-velocity: no source data', ['day' => $day, 'window_start' => $windowStart]);
            return self::SUCCESS;
        }

        $this->line("  {$rows->count()} produse cu vânzări procesate...");

        // 2. Zile de ruptură de stoc per produs/fereastră (din snapshot-urile zilnice).
        //    O zi cu stoc 0 și FĂRĂ vânzări nu poate genera vânzări — o scoatem din divizor,
        //    altfel un produs uitat 2 săptămâni fără stoc pare cu rulaj mic și nu mai e recomandat.
        //    Excepție: produsele vândute fără stoc (livrare directă/palet la comandă) — pentru ele
        //    stocul 0 nu limitează vânzarea, deci nu ajustăm (altfel rata se umflă artificial).
        $stockoutRows = DB::table('daily_stock_metrics as dsm')
            ->leftJoin(DB::raw("(
                SELECT v.sku,
                       STR_TO_DATE(CONCAT(v.an, '-', v.luna, '-', v.zi), '%Y-%m-%d') AS sale_day,
                       GREATEST(0, SUM(v.cantitate)) AS day_qty
                FROM winmentor_vanzari_raw v
                WHERE v.sku IS NOT NULL AND v.sku <> ''
                GROUP BY v.sku, sale_day
            ) s"), function ($join) {
                $join->on('s.sku', '=', 'dsm.reference_product_id')
                     ->on('s.sale_day', '=', 'dsm.day');
            })
            ->whereBetween('dsm.day', [$windowStart, $day])
            ->selectRaw('
                dsm.reference_product_id,
                SUM(CASE WHEN dsm.`day` >= DATE_SUB(?, INTERVAL  6 DAY)
                         AND dsm.closing_available_qty <= 0 AND COALESCE(s.day_qty, 0) = 0 THEN 1 ELSE 0 END) AS so_7d,
                SUM(CASE WHEN dsm.`day` >= DATE_SUB(?, INTERVAL 29 DAY)
                         AND dsm.closing_available_qty <= 0 AND COALESCE(s.day_qty, 0) = 0 THEN 1 ELSE 0 END) AS so_30d,
                SUM(CASE WHEN dsm.closing_available_qty <= 0 AND COALESCE(s.day_qty, 0) = 0 THEN 1 ELSE 0 END) AS so_90d,
                SUM(CASE WHEN dsm.closing_available_qty <= 0 THEN COALESCE(s.day_qty, 0) ELSE 0 END) AS zero_stock_sales_90d
            ', [$day, $day])
            ->groupBy('dsm.reference_product_id')
            ->get()
            ->keyBy('reference_product_id');

        $now       = now();
        $dayCarbon = Carbon::parse($day);

        $upsertRows = $rows->map(function ($row) use ($day, $now, $dayCarbon, $stockoutRows) {
            $so = $stockoutRows[$row->reference_product_id] ?? null;

            // Produs vândut preponderent fără stoc (direct/palet) → disponibilitatea nu limitează
            $sellsWithoutStock = $so !== null
                && (float) $row->out_qty_90d > 0
                && ((float) $so->zero_stock_sales_90d / (float) $row->out_qty_90d) > 0.5;

            if ($so === null || $sellsWithoutStock) {
                $avail7 = 7; $avail30 = 30; $avail90 = 90;
            } else {
                $avail7  = max(self::MIN_AVAIL_DAYS, 7  - (int) $so->so_7d);
                $avail30 = max(self::MIN_AVAIL_DAYS, 30 - (int) $so->so_30d);
                $avail90 = max(9, 90 - (int) $so->so_90d);
            }

            return [
                'reference_product_id'     => $row->reference_product_id,
                'calculated_for_day'       => $day,
                'out_qty_7d'               => round((float) $row->out_qty_7d, 3),
                'out_qty_30d'              => round((float) $row->out_qty_30d, 3),
                'out_qty_90d'              => round((float) $row->out_qty_90d, 3),
                'avg_out_qty_7d'           => round((float) $row->out_qty_7d  / $avail7, 4),
                'avg_out_qty_30d'          => round((float) $row->out_qty_30d / $avail30, 4),
                'avg_out_qty_90d'          => round((float) $row->out_qty_90d / $avail90, 4),
                'last_movement_day'        => $row->last_movement_day ?: null,
                'days_since_last_movement' => $row->last_movement_day
                    ? (int) Carbon::parse($row->last_movement_day)->diffInDays($dayCarbon)
                    : null,
                'updated_at'               => $now,
            ];
        })->all();

        $updateCols = [
            'calculated_for_day', 'out_qty_7d', 'out_qty_30d', 'out_qty_90d',
            'avg_out_qty_7d', 'avg_out_qty_30d', 'avg_out_qty_90d',
            'last_movement_day', 'days_since_last_movement', 'updated_at',
        ];

        foreach (array_chunk($upsertRows, 500) as $chunk) {
            DB::table('bi_product_velocity_current')->upsert($chunk, ['reference_product_id'], $updateCols);
        }

        // 3. Produsele fără nicio vânzare în fereastră (neatinse de upsert) — rulaj zero,
        //    altfel rămân cu valori vechi pe termen nelimitat.
        $stale = DB::table('bi_product_velocity_current')
            ->where('calculated_for_day', '<', $day)
            ->update([
                'calculated_for_day' => $day,
                'out_qty_7d'         => 0, 'out_qty_30d' => 0, 'out_qty_90d' => 0,
                'avg_out_qty_7d'     => 0, 'avg_out_qty_30d' => 0, 'avg_out_qty_90d' => 0,
                'days_since_last_movement' => DB::raw("IF(last_movement_day IS NULL, NULL, DATEDIFF('{$day}', last_movement_day))"),
                'updated_at'         => $now,
            ]);

        $this->info("  ✓ {$rows->count()} produse cu rulaj + {$stale} aduse la zero în bi_product_velocity_current.");

        return self::SUCCESS;
    }

    private function resolveDay(): string
    {
        $opt = $this->option('day');
        return $opt ? Carbon::parse($opt)->toDateString() : Carbon::yesterday()->toDateString();
    }
}
