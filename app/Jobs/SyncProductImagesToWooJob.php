<?php

namespace App\Jobs;

use App\Models\ProductImage;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sincronizează galeria de imagini a produsului în WooCommerce: imaginea
 * principală prima (devine featured), restul în ordinea din ERP. Imaginile
 * deja urcate se trimit prin {id} (fără re-sideload); cele noi prin {src}
 * (URL public), iar ID-urile de media primite se salvează pentru data viitoare.
 */
class SyncProductImagesToWooJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 180; // sideload-ul pe site poate dura

    public function __construct(public readonly int $productId)
    {
    }

    public function handle(): void
    {
        $product = WooProduct::find($this->productId);

        if (! $product || ! $product->woo_id || $product->is_placeholder || ! $product->connection) {
            return;
        }

        $images = ProductImage::where('woo_product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $payload = $images->map(function (ProductImage $img) {
            return $img->woo_media_id
                ? ['id' => (int) $img->woo_media_id]
                : ['src' => $img->url];
        })->values()->all();

        try {
            $client   = new WooClient($product->connection);
            $response = $client->updateProduct((int) $product->woo_id, ['images' => $payload]);

            // Mapăm înapoi ID-urile de media (răspunsul păstrează ordinea trimisă)
            $respImages = collect($response['images'] ?? []);
            foreach ($images->values() as $i => $img) {
                $wooImg = $respImages->get($i);
                if ($wooImg && empty($img->woo_media_id) && ! empty($wooImg['id'])) {
                    $img->update(['woo_media_id' => (int) $wooImg['id']]);
                }
            }

            // Oglinda locală: featured + lista din răspunsul Woo
            $data = is_array($product->data) ? $product->data : (json_decode((string) $product->data, true) ?: []);
            $data['images'] = $response['images'] ?? $data['images'] ?? [];

            WooProduct::where('id', $product->id)->update([
                'main_image_url' => $respImages->first()['src'] ?? $product->main_image_url,
                'data'           => $data,
            ]);

            Log::info('[ImageSync] Galerie sincronizată pe site', [
                'product' => $product->id, 'woo_id' => $product->woo_id, 'imagini' => count($payload),
            ]);
        } catch (\Throwable $e) {
            Log::error('[ImageSync] Eroare sync imagini', [
                'product' => $product->id, 'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
