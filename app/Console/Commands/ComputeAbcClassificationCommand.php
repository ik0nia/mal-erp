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
        // 1. Revenue per product from DailyStockMetric (WinMentor data)
        // ──────────────────────────────────────────────
        $this->info('Pas 1: Calculez venituri per produs (din WinMentor/DailyStockMetric)...');

        // Ieșiri de stoc (daily_total_variation < 0) × preț de vânzare = venit estimat
        $revenueMap = DB::table('daily_stock_metrics')
            ->where('day', '>=', $since)
            ->where('daily_total_variation', '<', 0)
            ->whereNotNull('woo_product_id')
            ->groupBy('woo_product_id')
            ->select(
                'woo_product_id as product_id',
                DB::raw('SUM(ABS(daily_total_variation) * COALESCE(closing_sell_price, 0)) as revenue')
            )
            ->pluck('revenue', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->filter(fn ($v) => $v > 0)
            ->toArray();

        if (empty($revenueMap)) {
            $this->error('Nu s-au găsit date de mișcări stoc în DailyStockMetric.');
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
            $daysWithConsumption = (int) $stats->days_with_consumption;
            $totalConsumed = (float) $stats->avg_consumption * $daysWithConsumption;
            $avgDaily = $totalDays > 0 ? $totalConsumed / $totalDays : 0;
            $avgDailyMap[$productId] = round($avgDaily, 4);

            // XYZ: coefficient of variation including zero-consumption days
            // Mean across ALL days in window (not just days with consumption)
            $mean = $avgDaily; // = totalConsumed / totalDays

            if ($mean > 0 && $totalDays > 0) {
                // Variance = (sum_of_squared_deviations) / totalDays
                // For days WITH consumption: each day's deviation = (day_value - mean)
                // For days WITHOUT consumption (zeroDays): each day's deviation = (0 - mean) = -mean
                // We can reconstruct from the SQL stats:
                //   STDDEV_POP² = E[X²] - E[X]² (over consumption days only)
                //   E[X²]_consumption = STDDEV_POP² + AVG²
                $sqlMean = (float) $stats->avg_consumption;
                $sqlStddev = (float) $stats->stddev_consumption;
                $sumOfSquaresConsumption = ($sqlStddev * $sqlStddev + $sqlMean * $sqlMean) * $daysWithConsumption;

                // Zero-consumption days contribute 0² = 0 to sum of squares
                // Total variance = (sumOfSquares / totalDays) - mean²
                $variance = ($sumOfSquaresConsumption / $totalDays) - ($mean * $mean);
                // Guard against floating-point rounding yielding tiny negative values
                $variance = max(0, $variance);
                $stddev = sqrt($variance);

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
                        number_format($avgDaily, 4, '.', ''),
                        $leadDays,
                        number_format($reorderQty, 2, '.', ''),
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
