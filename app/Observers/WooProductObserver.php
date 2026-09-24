<?php

namespace App\Observers;

use App\Jobs\PushProductContentToWooJob;
use App\Jobs\PushProductPriceToWooJob;
use App\Jobs\PushProductSkuToWooJob;
use App\Models\WooProduct;

class WooProductObserver
{
    /**
     * Când regular_price, sku sau conținutul (nume/descrieri) se schimbă în ERP,
     * împingem valoarea automat pe site.
     * Nu acționăm dacă schimbarea vine din WooWebhookController (evităm loop).
     */
    public function updated(WooProduct $product): void
    {
        // Audit schimbări de status (publicat↔draft) — MEREU, indiferent de sursă
        // (acțiune manuală, import, sync, webhook). Doar statusul, ca să nu inunde logul.
        if ($product->wasChanged('status')) {
            $this->logStatusChange($product);
        }

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

        if ($product->wasChanged(['name', 'short_description', 'description', 'unit'])) {
            PushProductContentToWooJob::dispatch($product->id)->onQueue('default');
        }
    }

    /** Loghează în jurnalul de audit trecerea publish↔draft a unui produs (cine, când, de la ce la ce). */
    private function logStatusChange(WooProduct $product): void
    {
        $from = (string) $product->getOriginal('status');
        $to   = (string) $product->status;

        $verb = match ($to) {
            'publish' => 'publicat',
            'draft'   => 'trecut în draft (ascuns)',
            'pending' => 'trecut în așteptare',
            'private' => 'făcut privat',
            'trash'   => 'șters (trash)',
            default   => "status → {$to}",
        };

        try {
            activity('audit')
                ->performedOn($product)
                ->causedBy(auth()->user())
                ->withProperties([
                    'attributes'    => ['status' => $to],
                    'old'           => ['status' => $from],
                    'subject_label' => $product->name,
                ])
                ->event('updated')
                ->log("Produs {$verb}: {$product->name}");
        } catch (\Throwable) {
            // auditul nu trebuie să spargă salvarea produsului
        }
    }
}
