<?php

namespace App\Observers;

use App\Jobs\PushProductPriceToWooJob;
use App\Models\WooProduct;

class WooProductObserver
{
    /**
     * Când regular_price se schimbă în ERP, împingem prețul automat pe site.
     * Nu acționăm dacă schimbarea vine din WooWebhookController (evităm loop).
     */
    public function updated(WooProduct $product): void
    {
        if (WooProduct::$skipPricePush) {
            return;
        }

        if (! $product->wasChanged('regular_price')) {
            return;
        }

        if (! $product->woo_id || ! $product->regular_price) {
            return;
        }

        PushProductPriceToWooJob::dispatch($product->id)->onQueue('default');
    }
}
