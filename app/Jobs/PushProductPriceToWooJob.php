<?php

namespace App\Jobs;

use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PushProductPriceToWooJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $productId,
    ) {}

    public function handle(): void
    {
        // Max 2 request-uri/secundă pe WooCommerce — protejăm serverul site-ului
        $key = 'woo-price-push:' . ($this->productId % 1); // toate în același bucket
        if (RateLimiter::tooManyAttempts('woo-price-push', 2)) {
            $this->release(RateLimiter::availableIn('woo-price-push'));
            return;
        }
        RateLimiter::hit('woo-price-push', 1);

        $product = WooProduct::find($this->productId);

        if (! $product || ! $product->woo_id || ! $product->regular_price || $product->is_placeholder) {
            return;
        }

        $connection = $product->connection;

        if (! $connection || ! $connection->isWooCommerce() || ! $connection->is_active) {
            return;
        }

        $regularPrice = number_format((float) $product->regular_price, 2, '.', '');

        try {
            $client = new WooClient($connection);
            $client->updateProductPrice($product->woo_id, $regularPrice);

            // Actualizăm și câmpul data->regular_price ca să nu mai apară ca diferență
            $data = $product->data;
            if (is_string($data)) {
                $data = json_decode($data, true) ?? [];
            }
            if (! is_array($data)) {
                $data = [];
            }
            $data['regular_price'] = $regularPrice;
            $data['price']         = $regularPrice;

            // Observer nu se declanșează — nu modificăm regular_price, deci e safe
            $product->update([
                'price' => $product->regular_price,
                'data'  => $data,
            ]);

            Log::info('[PriceSync] Preț împins pe site', [
                'product_id'    => $product->id,
                'sku'           => $product->sku,
                'woo_id'        => $product->woo_id,
                'regular_price' => $regularPrice,
            ]);

            // Fără flush, pagina ar servi prețul vechi din cache-ul nginx până la 60 min.
            // Delay + ShouldBeUnique: edit-urile în rafală → un singur flush.
            FlushWooCacheJob::dispatch()->onQueue('default')->delay(now()->addSeconds(15));
        } catch (\Throwable $e) {
            Log::error('[PriceSync] Eroare push preț WooCommerce', [
                'product_id' => $product->id,
                'sku'        => $product->sku,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
