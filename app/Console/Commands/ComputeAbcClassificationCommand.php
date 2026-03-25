<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ComputeAbcClassificationCommand extends Command
{
    protected $signature = 'erp:compute-abc-classification
                            {--dry-run : Afișează rezultatele fără a salva}
                            {--months=12 : Numărul de luni pentru calcul}';

    protected $description = 'Calculează clasificarea ABC/XYZ pentru produse bazat pe vânzări';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $dryRun = (bool) $this->option('dry-run');
        $since = now()->subMonths($months)->startOfDay();

        $this->info("Calculez clasificarea ABC/XYZ pe ultimele {$months} luni (din {$since->format('Y-m-d')})...");

        // ──────────────────────────────────────────────
        // 1. Revenue per product from woo_order_items + offer_items
        // ──────────────────────────────────────────────
        $this->info('Pas 1: Calculez venituri per produs...');

        // Revenue from WooCommerce orders (woo_order_items.woo_product_id = woo_products.woo_id)
        $wooRevenue = DB::table('woo_order_items')
            ->join('woo_orders', 'woo_orders.id', '=', 'woo_order_items.order_id')
            ->join('woo_products', 'woo_products.woo_id', '=', 'woo_order_items.woo_product_id')
            ->where('woo_orders.order_date', '>=', $since)
            ->whereIn('woo_orders.status', ['processing', 'completed', 'on-hold'])
            ->groupBy('woo_products.id')
            ->select('woo_products.id as product_id', DB::raw('SUM(woo_order_items.total) as revenue'))
            ->pluck('revenue', 'product_id');

        // Revenue from accepted offers (offer_items.woo_product_id = woo_products.id)
        $offerRevenue = DB::table('offer_items')
            ->join('offers', 'offers.id', '=', 'offer_items.offer_id')
            ->whereNotNull('offer_items.woo_product_id')
            ->where('offers.created_at', '>=', $since)
            ->where('offers.status', 'accepted')
            ->groupBy('offer_items.woo_product_id')
            ->select('offer_items.woo_product_id as product_id', DB::raw('SUM(offer_items.line_total) as revenue'))
            ->pluck('revenue', 'product_id');

        // Merge revenue from both sources
        $revenueMap = [];
        foreach ($wooRevenue as $productId => $rev) {
            $revenueMap[$productId] = (float) $rev;
        }
        foreach ($offerRevenue as $productId => $rev) {
            $revenueMap[$productId] = ($revenueMap[$productId] ?? 0) + (float) $rev;
        }

        if (empty($revenueMap)) {
            $this->warn('Nu s-au găsit vânzări. Se încearcă proxy din DailyStockMetric...');

            // Fallback: use negative daily_total_variation * closing_sell_price as revenue proxy
            $revenueMap = DB::table('daily_stock_metrics')
                ->where('day', '>=', $since)
                ->where('daily_total_variation', '<', 0)
                ->whereNotNull('woo_product_id')
                ->groupBy('woo_product_id')
                ->select(
                    'woo_product_id as product_id',
                    DB::raw('SUM(ABS(daily_total_variation) * closing_sell_price) as revenue')
                )
                ->pluck('revenue', 'product_id')
                ->map(fn ($v) => (float) $v)
                ->toArray();
        }

        if (empty($revenueMap)) {
            $this->error('Nu s-au găsit date de vânzări.');
            return self::FAILURE;
        }

        $this->info('  Produse cu venituri: ' . count($revenueMap));

        // ──────────────────────────────────────────────
        // 2. ABC Classification based on cumulative revenue
        // ──────────────────────────────────────────────
        $this->info('Pas 2: Clasificare ABC...');

        arsort($revenueMap);
        $totalRevenue = array_sum($revenueMap);
        $cumulativeRevenue = 0;
        $abcMap = [];

        foreach ($revenueMap as $productId => $revenue) {
            $cumulativeRevenue += $revenue;
            $cumulativePct = $cumulativeRevenue / $totalRevenue;

            if ($cumulativePct <= 0.80) {
                $abcMap[$productId] = 'A';
            } elseif ($cumulativePct <= 0.95) {
                $abcMap[$productId] = 'B';
            } else {
                $abcMap[$productId] = 'C';
            }
        }

        $abcCounts = array_count_values($abcMap);
        $this->info("  A: " . ($abcCounts['A'] ?? 0) . " | B: " . ($abcCounts['B'] ?? 0) . " | C: " . ($abcCounts['C'] ?? 0));

        // ──────────────────────────────────────────────
        // 3. Daily consumption from DailyStockMetric
        // ──────────────────────────────────────────────
        $this->info('Pas 3: Calculez consumul zilnic mediu și clasificarea XYZ...');

        // Get daily consumption stats per product (negative daily_total_variation = outgoing/consumption)
        $consumptionStats = DB::table('daily_stock_metrics')
            ->where('day', '>=', $since)
            ->where('daily_total_variation', '<', 0)
            ->whereNotNull('woo_product_id')
            ->groupBy('woo_product_id')
            ->select(
                'woo_product_id',
                DB::raw('AVG(ABS(daily_total_variation)) as avg_consumption'),
                DB::raw('STDDEV_POP(ABS(daily_total_variation)) as stddev_consumption'),
                DB::raw('COUNT(*) as days_with_consumption')
            )
            ->get()
            ->keyBy('woo_product_id');

        // Total calendar days in period for proper average
        $totalDays = (int) $since->diffInDays(now());

        $avgDailyMap = [];
        $xyzMap = [];

        foreach ($consumptionStats as $productId => $stats) {
            // Average daily consumption across ALL days (not just days with consumption)
            // Total consumed / total days gives true daily average
            $totalConsumed = (float) $stats->avg_consumption * (int) $stats->days_with_consumption;
            $avgDaily = $totalDays > 0 ? $totalConsumed / $totalDays : 0;
            $avgDailyMap[$productId] = round($avgDaily, 4);

            // XYZ: coefficient of variation based on days that had consumption
            $mean = (float) $stats->avg_consumption;
            $stddev = (float) $stats->stddev_consumption;

            if ($mean > 0) {
                $cv = $stddev / $mean;
            } else {
                $cv = 999; // no meaningful data
            }

            if ($cv < 0.5) {
                $xyzMap[$productId] = 'X';
            } elseif ($cv < 1.0) {
                $xyzMap[$productId] = 'Y';
            } else {
                $xyzMap[$productId] = 'Z';
            }
        }

        $xyzCounts = array_count_values($xyzMap);
        $this->info("  X: " . ($xyzCounts['X'] ?? 0) . " | Y: " . ($xyzCounts['Y'] ?? 0) . " | Z: " . ($xyzCounts['Z'] ?? 0));

        // ──────────────────────────────────────────────
        // 4. Preferred supplier lead_days + order_multiple for reorder_qty
        // ──────────────────────────────────────────────
        $this->info('Pas 4: Calculez reorder_qty...');

        $supplierData = DB::table('product_suppliers')
            ->where('is_preferred', true)
            ->whereNotNull('lead_days')
            ->select('woo_product_id', 'lead_days', 'order_multiple')
            ->get()
            ->keyBy('woo_product_id');

        // Fallback: average lead_days per product from all suppliers
        $avgLeadDays = DB::table('product_suppliers')
            ->whereNotNull('lead_days')
            ->where('lead_days', '>', 0)
            ->groupBy('woo_product_id')
            ->select('woo_product_id', DB::raw('AVG(lead_days) as avg_lead'))
            ->pluck('avg_lead', 'woo_product_id');

        // ──────────────────────────────────────────────
        // 5. Update woo_products
        // ──────────────────────────────────────────────
        $this->info('Pas 5: Actualizez produsele...');

        $allProductIds = array_unique(array_merge(
            array_keys($abcMap),
            array_keys($avgDailyMap)
        ));

        $updated = 0;

        if ($dryRun) {
            $this->table(
                ['ID', 'ABC', 'XYZ', 'Avg Daily', 'Lead Days', 'Reorder Qty'],
                collect($allProductIds)->take(50)->map(function ($id) use ($abcMap, $xyzMap, $avgDailyMap, $supplierData, $avgLeadDays) {
                    $leadDays = isset($supplierData[$id])
                        ? (int) $supplierData[$id]->lead_days
                        : (int) ($avgLeadDays[$id] ?? 0);
                    $avgDaily = $avgDailyMap[$id] ?? 0;
                    $reorderQty = $this->computeReorderQty($avgDaily, $leadDays, $supplierData[$id] ?? null);

                    return [
                        $id,
                        $abcMap[$id] ?? '-',
                        $xyzMap[$id] ?? '-',
                        number_format($avgDaily, 4),
                        $leadDays,
                        number_format($reorderQty, 2),
                    ];
                })->toArray()
            );

            $this->info('Dry run — nu s-a salvat nimic.');
            return self::SUCCESS;
        }

        // Batch update using CASE statements for performance
        $chunks = array_chunk($allProductIds, 500);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $productId) {
                $abc = $abcMap[$productId] ?? null;
                $xyz = $xyzMap[$productId] ?? null;
                $avgDaily = $avgDailyMap[$productId] ?? null;

                $leadDays = isset($supplierData[$productId])
                    ? (int) $supplierData[$productId]->lead_days
                    : (int) ($avgLeadDays[$productId] ?? 0);

                $reorderQty = $this->computeReorderQty(
                    $avgDaily ?? 0,
                    $leadDays,
                    $supplierData[$productId] ?? null
                );

                $updateData = array_filter([
                    'abc_classification' => $abc,
                    'xyz_classification' => $xyz,
                    'avg_daily_consumption' => $avgDaily,
                    'reorder_qty' => $reorderQty > 0 ? $reorderQty : null,
                ], fn ($v) => $v !== null);

                if (! empty($updateData)) {
                    DB::table('woo_products')
                        ->where('id', $productId)
                        ->update($updateData);
                    $updated++;
                }
            }
        }

        $this->info("Actualizate {$updated} produse.");

        return self::SUCCESS;
    }

    private function computeReorderQty(float $avgDaily, int $leadDays, ?object $supplier): float
    {
        if ($avgDaily <= 0 || $leadDays <= 0) {
            return 0;
        }

        $reorderQty = $avgDaily * $leadDays;

        // Round up to order_multiple if set
        if ($supplier && ! empty($supplier->order_multiple) && $supplier->order_multiple > 0) {
            $multiple = (float) $supplier->order_multiple;
            $reorderQty = ceil($reorderQty / $multiple) * $multiple;
        } else {
            $reorderQty = ceil($reorderQty);
        }

        return $reorderQty;
    }
}
