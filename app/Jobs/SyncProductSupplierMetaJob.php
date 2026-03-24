<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincronizează furnizorul preferat al unui produs în meta-datele WooCommerce
 * via WooCommerce REST API standard (meta_data).
 *
 * Meta scrisă: _furnizor_nume, _furnizor_sku
 */
class SyncProductSupplierMetaJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(public readonly int $wooProductId) {}

    public function handle(): void
    {
        $product = WooProduct::with(['suppliers' => fn ($q) => $q->wherePivot('is_preferred', true)])
            ->find($this->wooProductId);

        if (! $product || ! $product->woo_id || $product->is_placeholder) {
            return;
        }

        $connection = IntegrationConnection::find($product->connection_id);
        if (! $connection) {
            return;
        }

        $preferred = $product->suppliers->first();

        try {
            $client = new WooClient($connection);
            $client->updateProduct((int) $product->woo_id, [
                'meta_data' => [
                    ['key' => '_furnizor_nume', 'value' => $preferred?->name ?? ''],
                    ['key' => '_furnizor_sku',  'value' => $preferred?->pivot->supplier_sku ?? ''],
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('SyncProductSupplierMetaJob: eroare WooCommerce API', [
                'woo_product_id' => $this->wooProductId,
                'woo_id'         => $product->woo_id,
                'error'          => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(class_basename(static::class) . ' failed', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }
}
