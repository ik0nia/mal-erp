<?php

namespace App\Console\Commands;

use App\Services\Winmentor\DailyStockMetricAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SnapshotDailyStockMetricsCommand extends Command
{
    protected $signature = 'stock:snapshot-daily-metrics
                            {--date= : Data în format YYYY-MM-DD (implicit: azi)}';

    protected $description = 'Snapshot zilnic stoc + preț din product_stocks/woo_products → daily_stock_metrics';

    public function handle(DailyStockMetricAggregator $aggregator): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::now();

        $this->info("Snapshot daily_stock_metrics pentru {$date->toDateString()}...");

        $rows = DB::table('woo_products as wp')
            ->leftJoin('product_stocks as ps', 'ps.woo_product_id', '=', 'wp.id')
            ->whereNotNull('wp.sku')
            ->whereNotNull('wp.woo_id')
            ->select([
                'wp.id as woo_product_id',
                'wp.sku',
                DB::raw('COALESCE(ps.quantity, 0) as quantity'),
                'wp.regular_price as sell_price',
            ])
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('Niciun produs găsit.');
            return self::SUCCESS;
        }

        $snapshots = $rows->map(fn ($r) => [
            'reference_product_id' => (string) $r->sku,
            'woo_product_id'       => (int) $r->woo_product_id,
            'quantity'             => (float) $r->quantity,
            'sell_price'           => $r->sell_price !== null ? (float) $r->sell_price : null,
        ])->values()->all();

        $count = $aggregator->recordSnapshots($date, $snapshots);

        $this->info("Done. {$count} produse înregistrate în daily_stock_metrics pentru {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
