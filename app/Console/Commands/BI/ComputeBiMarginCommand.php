<?php

namespace App\Console\Commands\BI;

use App\Models\ProductPriceLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComputeBiMarginCommand extends Command
{
    protected $signature = 'bi:compute-margin
                            {--day= : Data in format YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Calculează marjele per produs și agregat în bi_inventory_kpi_daily';

    public function handle(): int
    {
        $day = $this->resolveDay();
        $this->line("Margin → <info>{$day}</info>");

        // ----------------------------------------------------------------
        // 1. Preia produsele cu stoc din daily_stock_metrics (doar shop)
        // ----------------------------------------------------------------
        $products = DB::table('daily_stock_metrics as dsm')
            ->where('dsm.day', $day)
            ->where('dsm.closing_total_qty', '>', 0)
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'dsm.reference_product_id')
            ->whereRaw("COALESCE(wp.product_type, 'shop') = 'shop'")
            ->select([
                'dsm.reference_product_id',
                'dsm.woo_product_id',
                'dsm.closing_total_qty as stock_qty',
                'dsm.closing_sell_price as sell_price',
                'wp.price as woo_price',
            ])
            ->get();

        if ($products->isEmpty()) {
            $this->warn("  Nicio dată cu stoc > 0 pentru {$day}. Skip.");
            Log::warning('bi:compute-margin: no products with stock', ['day' => $day]);
            return self::SUCCESS;
        }

        $this->info("  {$products->count()} produse cu stoc.");

        // ----------------------------------------------------------------
        // 2. Preîncarcă date furnizori (preferred + fallback)
        // ----------------------------------------------------------------
        $wooProductIds = $products->pluck('woo_product_id')->filter()->unique()->values()->all();

        // Preferred suppliers: is_preferred = true
        $preferredSuppliers = DB::table('product_suppliers as ps')
            ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->whereIn('ps.woo_product_id', $wooProductIds)
            ->where('ps.is_preferred', true)
            ->select([
                'ps.woo_product_id',
                'ps.purchase_price',
                'ps.last_purchase_price',
                'ps.supplier_id',
                's.name as supplier_name',
            ])
            ->get()
            ->keyBy('woo_product_id');

        // Fallback suppliers: any with last_purchase_price (cel mai recent)
        $fallbackSuppliers = DB::table('product_suppliers as ps')
            ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->whereIn('ps.woo_product_id', $wooProductIds)
            ->whereNotNull('ps.last_purchase_price')
            ->where('ps.last_purchase_price', '>', 0)
            ->select([
                'ps.woo_product_id',
                'ps.purchase_price',
                'ps.last_purchase_price',
                'ps.supplier_id',
                's.name as supplier_name',
            ])
            ->orderByDesc('ps.last_purchase_date')
            ->get()
            ->unique('woo_product_id')
            ->keyBy('woo_product_id');

        // ----------------------------------------------------------------
        // 3. Preîncarcă ultimele prețuri din product_price_logs
        // ----------------------------------------------------------------
        $latestPriceLogs = DB::table('product_price_logs')
            ->whereIn('woo_product_id', $wooProductIds)
            ->where('source', 'winmentor_lista')
            ->where('changed_at', '<=', $day . ' 23:59:59')
            ->select(['woo_product_id', 'new_price', 'changed_at'])
            ->orderByDesc('changed_at')
            ->get()
            ->unique('woo_product_id')
            ->keyBy('woo_product_id');

        // ----------------------------------------------------------------
        // 4. Calculează marja per produs
        // ----------------------------------------------------------------
        $marginRows = [];
        $now = now();

        foreach ($products as $product) {
            $refId = $product->reference_product_id;
            $wooId = $product->woo_product_id;
            $stockQty = (float) $product->stock_qty;

            // Selling price: prefer woo_price, fallback to closing_sell_price
            $sellingPrice = (float) ($product->woo_price ?? $product->sell_price ?? 0);
            if ($sellingPrice <= 0) {
                $sellingPrice = (float) ($product->sell_price ?? 0);
            }

            // Purchase price resolution
            $purchasePrice = null;
            $purchasePriceSource = 'none';
            $supplierId = null;
            $supplierName = null;

            // Priority 1: ProductPriceLog (winmentor_lista)
            $priceLog = $latestPriceLogs->get($wooId);
            if ($priceLog && (float) $priceLog->new_price > 0) {
                $purchasePrice = (float) $priceLog->new_price;
                $purchasePriceSource = 'purchase_log';
            }

            // Priority 2: Preferred supplier → purchase_price
            if ($purchasePrice === null && $wooId) {
                $preferred = $preferredSuppliers->get($wooId);
                if ($preferred && (float) ($preferred->purchase_price ?? 0) > 0) {
                    $purchasePrice = (float) $preferred->purchase_price;
                    $purchasePriceSource = 'product_supplier';
                    $supplierId = (int) $preferred->supplier_id;
                    $supplierName = $preferred->supplier_name;
                }
            }

            // Priority 3: Any supplier → last_purchase_price
            if ($purchasePrice === null && $wooId) {
                $fallback = $fallbackSuppliers->get($wooId);
                if ($fallback && (float) ($fallback->last_purchase_price ?? 0) > 0) {
                    $purchasePrice = (float) $fallback->last_purchase_price;
                    $purchasePriceSource = 'product_supplier';
                    $supplierId = (int) $fallback->supplier_id;
                    $supplierName = $fallback->supplier_name;
                }
            }

            // Set supplier info from preferred if we got price from purchase_log
            if ($purchasePriceSource === 'purchase_log' && $wooId) {
                $preferred = $preferredSuppliers->get($wooId);
                if ($preferred) {
                    $supplierId = (int) $preferred->supplier_id;
                    $supplierName = $preferred->supplier_name;
                }
            }

            // Compute margins
            $marginAmount = $sellingPrice - ($purchasePrice ?? $sellingPrice);
            $marginPct = $sellingPrice > 0
                ? round($marginAmount / $sellingPrice * 100, 2)
                : 0;

            $stockValueRetail = round($stockQty * $sellingPrice, 4);
            $stockValueCost = $purchasePrice !== null
                ? round($stockQty * $purchasePrice, 4)
                : null;
            $stockMarginTotal = $stockValueCost !== null
                ? round($stockValueRetail - $stockValueCost, 4)
                : null;

            $marginRows[] = [
                'reference_product_id' => $refId,
                'calculated_for_day'   => $day,
                'selling_price'        => $sellingPrice,
                'purchase_price'       => $purchasePrice,
                'purchase_price_source' => $purchasePriceSource,
                'margin_amount'        => round($marginAmount, 4),
                'margin_pct'           => $marginPct,
                'stock_qty'            => $stockQty,
                'stock_value_retail'   => $stockValueRetail,
                'stock_value_cost'     => $stockValueCost,
                'stock_margin_total'   => $stockMarginTotal,
                'supplier_id'          => $supplierId,
                'supplier_name'        => $supplierName,
                'updated_at'           => $now,
            ];
        }

        // ----------------------------------------------------------------
        // 5. Șterge rândurile existente și inserează în bulk
        // ----------------------------------------------------------------
        DB::table('bi_product_margin_current')
            ->where('calculated_for_day', $day)
            ->delete();

        // Insert in chunks
        foreach (array_chunk($marginRows, 500) as $chunk) {
            DB::table('bi_product_margin_current')->insert($chunk);
        }

        $this->info(sprintf('  %d rânduri inserate în bi_product_margin_current.', count($marginRows)));

        // ----------------------------------------------------------------
        // 6. Agregare → actualizare bi_inventory_kpi_daily
        // ----------------------------------------------------------------
        $withCost = collect($marginRows)->filter(fn ($r) => $r['stock_value_cost'] !== null);
        $productsWithCost = $withCost->count();

        $inventoryValueCost = $withCost->sum('stock_value_cost');
        $grossMarginTotal = $withCost->sum('stock_margin_total');

        // Weighted average margin: sum(margin_total) / sum(retail_value) * 100
        $retailValueWithCost = $withCost->sum('stock_value_retail');
        $grossMarginPct = $retailValueWithCost > 0
            ? round($grossMarginTotal / $retailValueWithCost * 100, 2)
            : null;

        DB::table('bi_inventory_kpi_daily')
            ->where('day', $day)
            ->update([
                'inventory_value_cost_closing_total' => round($inventoryValueCost, 4),
                'gross_margin_total'                 => round($grossMarginTotal, 4),
                'gross_margin_pct'                   => $grossMarginPct,
                'products_with_cost_data'            => $productsWithCost,
                'updated_at'                         => $now,
            ]);

        $this->info(sprintf(
            '  Cost stoc: %.0f RON | Marjă: %.0f RON (%.1f%%) | %d produse cu cost',
            $inventoryValueCost,
            $grossMarginTotal,
            $grossMarginPct ?? 0,
            $productsWithCost,
        ));

        return self::SUCCESS;
    }

    private function resolveDay(): string
    {
        $opt = $this->option('day');
        return $opt ? Carbon::parse($opt)->toDateString() : Carbon::yesterday()->toDateString();
    }
}
