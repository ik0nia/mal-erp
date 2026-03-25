<?php

namespace App\Console\Commands\BI;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComputeBiReplenishmentCommand extends Command
{
    protected $signature = 'bi:compute-replenishment
                            {--day= : Data în format YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Calculează sugestii de reaprovizionare pe baza consumului mediu și stocului curent';

    public function handle(): int
    {
        $day = $this->resolveDay();
        $this->line("Replenishment → <info>{$day}</info>");

        // Pas 1: Produse cu consum > 0, tip shop, neîntrerupte, nu on_demand
        $products = DB::table('woo_products as wp')
            ->where('wp.avg_daily_consumption', '>', 0)
            ->whereRaw("COALESCE(wp.product_type, 'shop') = 'shop'")
            ->where(fn ($q) => $q->whereNull('wp.is_discontinued')->orWhere('wp.is_discontinued', false))
            ->where(fn ($q) => $q->whereNull('wp.procurement_type')->orWhere('wp.procurement_type', '!=', 'on_demand'))
            ->select(
                'wp.id',
                'wp.sku',
                'wp.name',
                'wp.avg_daily_consumption',
                'wp.safety_stock',
                'wp.reorder_qty',
                'wp.abc_classification',
                'wp.regular_price',
            )
            ->get();

        if ($products->isEmpty()) {
            $this->warn("  Niciun produs cu consum mediu > 0. Skip.");
            return self::SUCCESS;
        }

        $this->line("  Produse cu consum: <info>{$products->count()}</info>");

        // Pas 2: Stoc curent din daily_stock_metrics
        $stockMap = DB::table('daily_stock_metrics')
            ->where('day', $day)
            ->pluck('closing_total_qty', 'reference_product_id');

        if ($stockMap->isEmpty()) {
            $this->warn("  Nicio dată în daily_stock_metrics pentru {$day}. Skip.");
            return self::SUCCESS;
        }

        // Pas 3: Furnizori preferați
        $supplierMap = DB::table('product_suppliers as ps')
            ->leftJoin('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->select(
                'ps.woo_product_id',
                'ps.supplier_id',
                's.name as supplier_name',
                'ps.lead_days',
                'ps.purchase_price',
                'ps.order_multiple',
                'ps.is_preferred',
            )
            ->orderByDesc('ps.is_preferred')
            ->orderByDesc('ps.updated_at')
            ->get()
            ->groupBy('woo_product_id');

        // Pas 4: Calculăm sugestiile
        $rows = [];
        $now = now();

        foreach ($products as $product) {
            $sku = $product->sku;
            $currentStock = (float) ($stockMap[$sku] ?? 0);
            $avgConsumption = (float) $product->avg_daily_consumption;
            $safetySt = (float) ($product->safety_stock ?? 0);
            $reorderQty = (float) ($product->reorder_qty ?? 0);

            // Furnizor preferat
            $supplierInfo = $supplierMap->get($product->id)?->first();
            $leadDays = $supplierInfo?->lead_days ?? 7;
            $purchasePrice = (float) ($supplierInfo?->purchase_price ?? 0);
            $orderMultiple = (float) ($supplierInfo?->order_multiple ?? 0);
            $supplierId = $supplierInfo?->supplier_id;
            $supplierName = $supplierInfo?->supplier_name;

            // Punct de reaprovizionare
            $reorderPoint = ($avgConsumption * $leadDays) + $safetySt;

            // Verificare dacă trebuie reaprovizionat
            if ($currentStock > $reorderPoint) {
                continue;
            }

            // Zile de stoc rămase
            $daysOfStock = $avgConsumption > 0
                ? round($currentStock / $avgConsumption, 1)
                : 0;

            // Cantitate sugerată
            $suggestedQty = max($reorderQty, $avgConsumption * $leadDays * 1.5);

            // Rotunjire la order_multiple
            if ($orderMultiple > 0) {
                $suggestedQty = ceil($suggestedQty / $orderMultiple) * $orderMultiple;
            }

            // Cost estimat
            $estimatedCost = $suggestedQty * $purchasePrice;

            // Marjă %
            $marginPct = null;
            $sellingPrice = (float) ($product->regular_price ?? 0);
            if ($sellingPrice > 0 && $purchasePrice > 0) {
                $marginPct = round(($sellingPrice - $purchasePrice) / $sellingPrice * 100, 2);
            }

            // Prioritate
            if ($daysOfStock < 7) {
                $priority = 'urgent';
            } elseif ($daysOfStock < 14) {
                $priority = 'soon';
            } else {
                $priority = 'planned';
            }

            $rows[] = [
                'calculated_for_day'     => $day,
                'woo_product_id'         => $product->id,
                'reference_product_id'   => $sku,
                'product_name'           => $product->name,
                'current_stock'          => $currentStock,
                'avg_daily_consumption'  => $avgConsumption,
                'days_of_stock'          => $daysOfStock,
                'lead_days'              => $leadDays,
                'safety_stock'           => $safetySt,
                'reorder_point'          => round($reorderPoint, 3),
                'suggested_qty'          => round($suggestedQty, 3),
                'estimated_cost'         => round($estimatedCost, 4),
                'margin_pct'             => $marginPct,
                'abc_class'              => $product->abc_classification,
                'priority'               => $priority,
                'supplier_id'            => $supplierId,
                'supplier_name'          => $supplierName,
                'created_at'             => $now,
            ];
        }

        // Pas 5: Ștergem datele vechi și inserăm
        DB::table('bi_replenishment_suggestions')
            ->where('calculated_for_day', $day)
            ->delete();

        if (count($rows) > 0) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('bi_replenishment_suggestions')->insert($chunk);
            }
        }

        $urgent  = collect($rows)->where('priority', 'urgent')->count();
        $soon    = collect($rows)->where('priority', 'soon')->count();
        $planned = collect($rows)->where('priority', 'planned')->count();

        $this->info(sprintf(
            '  ✓ %d sugestii (urgent: %d, curând: %d, planificat: %d)',
            count($rows), $urgent, $soon, $planned,
        ));

        return self::SUCCESS;
    }

    private function resolveDay(): string
    {
        $opt = $this->option('day');
        return $opt ? Carbon::parse($opt)->toDateString() : Carbon::yesterday()->toDateString();
    }
}
