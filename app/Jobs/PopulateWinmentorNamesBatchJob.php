<?php

namespace App\Jobs;

use App\Models\WooProduct;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PopulateWinmentorNamesBatchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * @param  array<string, string>  $skuToName  [sku => winmentor_name]
     * @param  int[]  $wooConnectionIds
     */
    public function __construct(
        public array $skuToName,
        public array $wooConnectionIds,
    ) {}

    public function handle(): void
    {
        if (empty($this->skuToName) || empty($this->wooConnectionIds)) {
            return;
        }

        $skus = array_keys($this->skuToName);

        $products = WooProduct::query()
            ->whereIn('connection_id', $this->wooConnectionIds)
            ->whereIn('sku', $skus)
            ->get(['id', 'sku']);

        if ($products->isEmpty()) {
            return;
        }

        // Construiește CASE WHEN pentru bulk update
        $cases   = '';
        $ids     = [];
        $bindings = [];

        foreach ($products as $product) {
            $name = $this->skuToName[$product->sku] ?? null;
            if ($name === null || $name === '') {
                continue;
            }

            $cases .= 'WHEN id = ? THEN ? ';
            $bindings[] = $product->id;
            $bindings[] = $name;
            $ids[]      = $product->id;
        }

        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bindings     = array_merge($bindings, $ids);

        DB::statement(
            "UPDATE woo_products SET winmentor_name = CASE {$cases} END WHERE id IN ({$placeholders})",
            $bindings
        );
    }
}
