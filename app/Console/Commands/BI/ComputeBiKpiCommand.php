<?php

namespace App\Console\Commands\BI;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComputeBiKpiCommand extends Command
{
    protected $signature = 'bi:compute-kpi
                            {--day= : Data în format YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Calculează bi_inventory_kpi_daily pentru o zi (implicit: ieri)';

    public function handle(): int
    {
        $day = $this->resolveDay();
        $this->line("KPI → <info>{$day}</info>");

        // Sanity check: există date sursă?
        $sourceCount = DB::table('daily_stock_metrics')->where('day', $day)->count();
        if ($sourceCount === 0) {
            $this->warn("  Nicio dată în daily_stock_metrics pentru {$day}. Skip.");
            Log::warning('bi:compute-kpi: no source data', ['day' => $day]);
            return self::SUCCESS;
        }

        $row = DB::table('daily_stock_metrics')
            ->where('day', $day)
            ->selectRaw('
                day,
                COUNT(*)                                                                       AS products_total,
                SUM(CASE WHEN closing_available_qty > 0  THEN 1 ELSE 0 END)                   AS products_in_stock,
                SUM(CASE WHEN closing_available_qty <= 0 THEN 1 ELSE 0 END)                   AS products_out_of_stock,
                ROUND(SUM(closing_available_qty), 3)                                           AS inventory_qty_closing_total,
                ROUND(SUM(opening_available_qty * COALESCE(opening_sell_price, 0)), 2)         AS inventory_value_opening_total,
                ROUND(SUM(closing_available_qty * COALESCE(closing_sell_price, 0)), 2)         AS inventory_value_closing_total,
                ROUND(SUM(daily_sales_value_variation), 2)                                     AS inventory_value_variation_total,
                SUM(snapshots_count)                                                           AS snapshots_total,
                TIMESTAMPDIFF(MINUTE, MIN(first_snapshot_at), MAX(last_snapshot_at))           AS imports_span_minutes
            ')
            ->groupBy('day')
            ->first();

        if (! $row) {
            $this->warn("  Agregarea nu a returnat rezultat pentru {$day}.");
            return self::SUCCESS;
        }

        $now = now();

        DB::table('bi_inventory_kpi_daily')->upsert(
            [[
                'day'                             => $row->day,
                'products_total'                  => (int) $row->products_total,
                'products_in_stock'               => (int) $row->products_in_stock,
                'products_out_of_stock'           => (int) $row->products_out_of_stock,
                'inventory_qty_closing_total'     => (float) $row->inventory_qty_closing_total,
                'inventory_value_opening_total'   => (float) $row->inventory_value_opening_total,
                'inventory_value_closing_total'   => (float) $row->inventory_value_closing_total,
                'inventory_value_variation_total' => (float) $row->inventory_value_variation_total,
                'snapshots_total'                 => (int) $row->snapshots_total,
                'imports_span_minutes'            => $row->imports_span_minutes !== null ? (int) $row->imports_span_minutes : null,
                'created_at'                      => $now,
                'updated_at'                      => $now,
            ]],
            ['day'],
            [
                'products_total', 'products_in_stock', 'products_out_of_stock',
                'inventory_qty_closing_total', 'inventory_value_opening_total',
                'inventory_value_closing_total', 'inventory_value_variation_total',
                'snapshots_total', 'imports_span_minutes', 'updated_at',
            ]
        );

        $this->info(sprintf(
            '  ✓ %s produse | stoc %.0f RON | variație %.0f RON',
            number_format((int) $row->products_total),
            (float) $row->inventory_value_closing_total,
            (float) $row->inventory_value_variation_total,
        ));

        return self::SUCCESS;
    }

    private function resolveDay(): string
    {
        $opt = $this->option('day');
        return $opt ? Carbon::parse($opt)->toDateString() : Carbon::yesterday()->toDateString();
    }
}
