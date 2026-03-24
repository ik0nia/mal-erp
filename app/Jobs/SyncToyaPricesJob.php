<?php

namespace App\Jobs;

use App\Models\SupplierFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncToyaPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    private const API_BASE = 'https://pim.toya.pl/dataapi';

    public function __construct(
        private readonly int $feedId
    ) {}

    public function handle(): void
    {
        $feed = SupplierFeed::with('supplier')->find($this->feedId);

        if (! $feed || ! $feed->is_active) {
            Log::error("[SyncToyaPrices] Feed ID={$this->feedId} nu există sau e inactiv.");
            return;
        }

        $feed->update(['last_sync_status' => 'running', 'last_sync_at' => now()]);

        $apiKey = $feed->getApiKey();
        if (! $apiKey) {
            $this->fail($feed, 'API key lipsește din configurarea feed-ului.');
            return;
        }

        $supplierId = $feed->supplier_id;
        $discount   = $feed->getDiscount();
        $markup     = $feed->getMarkup();
        $vat        = $feed->getVat();

        // Formula: preț_feed × (1 − discount%) × (1 + adaos%) × (1 + TVA%)
        $discountFactor = round(1 - $discount / 100, 10);
        $sellMultiplier = round((1 + $markup / 100) * (1 + $vat / 100), 10);

        Log::info("[SyncToyaPrices] Feed={$this->feedId} — discount={$discount}%, adaos={$markup}%, TVA={$vat}%");

        // 1. Preia prețuri și stocuri RO
        $prices = $this->fetchBulk($apiKey, 'getPricesRo');
        if (empty($prices)) {
            $this->fail($feed, 'Nu s-au putut prelua prețurile din API.');
            return;
        }

        $stocks = $this->fetchBulk($apiKey, 'getStocksRo');
        Log::info('[SyncToyaPrices] Prețuri: ' . count($prices) . ' | Stocuri: ' . count($stocks));

        // 2. Produsele furnizorului cu supplier_sku
        $rows = DB::table('product_suppliers as ps')
            ->join('woo_products as wp', 'wp.id', '=', 'ps.woo_product_id')
            ->where('ps.supplier_id', $supplierId)
            ->whereNotNull('ps.supplier_sku')
            ->select('ps.id as ps_id', 'ps.supplier_sku', 'ps.purchase_price',
                     'wp.id as product_id', 'wp.regular_price', 'wp.woo_id', 'wp.status')
            ->get()
            ->keyBy('supplier_sku');

        $stats        = ['updated' => 0, 'unchanged' => 0, 'missing' => 0, 'price_pushed' => 0];
        $priceLogRows = [];
        $now          = now();

        foreach ($prices as $code => $priceData) {
            $netPrice = (float) ($priceData['netPrice'] ?? 0);
            if ($netPrice <= 0) {
                continue;
            }

            $row = $rows->get($code);
            if (! $row) {
                $stats['missing']++;
                continue;
            }

            $purchasePrice = round($netPrice * $discountFactor, 4);
            $newSellPrice  = round($purchasePrice * $sellMultiplier, 2);
            $oldSellPrice  = (float) $row->regular_price;

            $stockFlag   = $stocks[$code]['stock'] ?? null;
            $stockStatus = match ($stockFlag) {
                'LARGE QUANTITY', 'MEDIUM QUANTITY', 'SMALL QUANTITY' => 'instock',
                'OUT OF STOCK' => 'outofstock',
                default        => null,
            };

            DB::table('product_suppliers')
                ->where('id', $row->ps_id)
                ->update(['purchase_price' => $purchasePrice, 'updated_at' => $now]);

            $wpUpdate = ['updated_at' => $now];
            if ($stockStatus !== null) {
                $wpUpdate['stock_status'] = $stockStatus;
            }

            if (abs($newSellPrice - $oldSellPrice) >= 0.01) {
                $wpUpdate['regular_price'] = $newSellPrice;
                $wpUpdate['price']         = $newSellPrice;

                $priceLogRows[] = [
                    'woo_product_id' => $row->product_id,
                    'location_id'    => 1,
                    'source'         => 'toya_api',
                    'old_price'      => $oldSellPrice,
                    'new_price'      => $newSellPrice,
                    'changed_at'     => $now,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];

                $stats['updated']++;

                if ($row->woo_id && $row->status === 'publish') {
                    PushWinmentorPricesToWooJob::dispatch($row->product_id, $newSellPrice);
                    $stats['price_pushed']++;
                }
            } else {
                $stats['unchanged']++;
            }

            if (! empty($wpUpdate)) {
                DB::table('woo_products')->where('id', $row->product_id)->update($wpUpdate);
            }
        }

        if (! empty($priceLogRows)) {
            foreach (array_chunk($priceLogRows, 500) as $chunk) {
                DB::table('product_price_logs')->insert($chunk);
            }
        }

        $summary = "Actualizate: {$stats['updated']}, neschimbate: {$stats['unchanged']}, lipsă în ERP: {$stats['missing']}, push Woo: {$stats['price_pushed']}";
        $feed->update([
            'last_sync_status'  => 'ok',
            'last_sync_summary' => $summary,
        ]);

        Log::info("[SyncToyaPrices] Gata. {$summary}");
    }

    private function fail(SupplierFeed $feed, string $reason): void
    {
        $feed->update(['last_sync_status' => 'error', 'last_sync_summary' => $reason]);
        Log::error("[SyncToyaPrices] {$reason}");
    }

    private function fetchBulk(string $apiKey, string $action): array
    {
        $response = Http::withoutVerifying()
            ->timeout(60)
            ->get(self::API_BASE, ['key' => $apiKey, 'action' => $action]);

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();
        return is_array($data) ? $data : [];
    }
}
