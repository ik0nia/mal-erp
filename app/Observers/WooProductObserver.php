<?php

namespace App\Observers;

use App\Jobs\PushProductPriceToWooJob;
use App\Jobs\PushProductSkuToWooJob;
use App\Models\WooProduct;

class WooProductObserver
{
    /**
     * Când regular_price sau sku se schimbă în ERP, împingem valoarea automat pe site.
     * Nu acționăm dacă schimbarea vine din WooWebhookController (evităm loop).
     */
    public function updated(WooProduct $product): void
    {
        if (WooProduct::$skipPricePush) {
            return;
        }

        if (! $product->woo_id) {
            return;
        }

        if ($product->wasChanged('regular_price') && $product->regular_price) {
            PushProductPriceToWooJob::dispatch($product->id)->onQueue('default');
        }

        if ($product->wasChanged('sku') && filled($product->sku)) {
            PushProductSkuToWooJob::dispatch($product->id)->onQueue('default');
        }
    }
}
