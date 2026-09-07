<?php

namespace App\Jobs;

use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Împinge SKU-ul din ERP pe WooCommerce când se schimbă în ERP.
 * ERP-ul e sursa de adevăr pentru SKU (afișat pe site ca „EAN").
 */
class PushProductSkuToWooJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $productId,
    ) {}

    public function handle(): void
    {
        $product = WooProduct::find($this->productId);

        if (! $product || ! $product->woo_id || ! filled($product->sku) || $product->is_placeholder) {
            return;
        }

        $connection = $product->connection;

        if (! $connection || ! $connection->isWooCommerce() || ! $connection->is_active) {
            return;
        }

        try {
            $client = new WooClient($connection);
            $client->updateProduct((int) $product->woo_id, ['sku' => (string) $product->sku]);

            Log::info('[SkuSync] SKU împins pe site', [
                'product_id' => $product->id,
                'woo_id'     => $product->woo_id,
                'sku'        => $product->sku,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SkuSync] Eroare push SKU WooCommerce', [
                'product_id' => $product->id,
                'sku'        => $product->sku,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
