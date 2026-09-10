<?php

namespace App\Jobs;

use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Împinge conținutul editorial (nume, descriere scurtă, descriere completă)
 * din ERP pe WooCommerce când se schimbă în ERP. ERP-ul e sursa de adevăr
 * pentru conținutul produsului.
 */
class PushProductContentToWooJob implements ShouldQueue
{
    use Queueable;

    /** Coduri de unitate din importuri (Toya=poloneză, WinMentor=prescurtări) → afișare pe site */
    private const UNIT_DISPLAY = [
        'pac' => 'pachet',
        'opa' => 'pachet',
        'paa' => 'pereche',
    ];

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $productId,
    ) {}

    public function handle(): void
    {
        $product = WooProduct::find($this->productId);

        if (! $product || ! $product->woo_id || $product->is_placeholder) {
            return;
        }

        $connection = $product->connection;

        if (! $connection || ! $connection->isWooCommerce() || ! $connection->is_active) {
            return;
        }

        $payload = ['name' => (string) $product->name];

        // Nu golim descrierile de pe site dacă în ERP sunt necompletate
        if (filled($product->short_description)) {
            $payload['short_description'] = (string) $product->short_description;
        }
        if (filled($product->description)) {
            $payload['description'] = (string) $product->description;
        }

        // Unitatea de măsură — tema citește meta woodmart_price_unit_of_measure
        if (filled($product->unit)) {
            $payload['meta_data'][] = [
                'key'   => 'woodmart_price_unit_of_measure',
                'value' => self::UNIT_DISPLAY[$product->unit] ?? $product->unit,
            ];
        }

        try {
            $client = new WooClient($connection);
            $client->updateProduct((int) $product->woo_id, $payload);

            (new \App\Services\WooCommerce\WooPluginClient())->flushCache();

            Log::info('[ContentSync] Conținut împins pe site', [
                'product_id' => $product->id,
                'woo_id'     => $product->woo_id,
                'fields'     => array_keys($payload),
            ]);
        } catch (\Throwable $e) {
            Log::error('[ContentSync] Eroare push conținut WooCommerce', [
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
