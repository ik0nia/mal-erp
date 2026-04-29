<?php

namespace App\Jobs;

use App\Models\ProductImage;
use App\Models\WooProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportToyaImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int $productId,
    ) {}

    public function handle(): void
    {
        $product = WooProduct::find($this->productId);

        if (! $product) {
            Log::warning("[ImportToyaImages] Produs ID={$this->productId} nu există.");

            return;
        }

        $raw  = $product->getRawOriginal('data') ?? '{}';
        $data = json_decode($raw, true);
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }

        $imagesAdditional = $data['images_additional'] ?? [];

        if (empty($imagesAdditional) || ! is_array($imagesAdditional)) {
            Log::info("[ImportToyaImages] SKU={$product->sku} — nicio imagine suplimentară în date Toya.");

            return;
        }

        $imported = 0;

        // 1. Asigurăm că imaginea principală există în product_images
        if (filled($product->main_image_url)) {
            $primaryExists = ProductImage::where('woo_product_id', $product->id)
                ->where('is_primary', true)
                ->exists();

            if (! $primaryExists) {
                ProductImage::create([
                    'woo_product_id' => $product->id,
                    'url'            => $product->main_image_url,
                    'sort_order'     => 0,
                    'is_primary'     => true,
                    'source'         => ProductImage::SOURCE_TOYA,
                ]);
                $imported++;
            }
        }

        // 2. Importăm imaginile suplimentare (folosim URL-ul high)
        $maxOrder = ProductImage::where('woo_product_id', $product->id)->max('sort_order') ?? 0;

        foreach ($imagesAdditional as $imgData) {
            $url = $imgData['high'] ?? $imgData['low'] ?? null;

            if (! is_string($url) || empty($url)) {
                continue;
            }

            // Evităm duplicate
            $exists = ProductImage::where('woo_product_id', $product->id)
                ->where('url', $url)
                ->exists();

            if ($exists) {
                continue;
            }

            $maxOrder++;

            ProductImage::create([
                'woo_product_id' => $product->id,
                'url'            => $url,
                'sort_order'     => $maxOrder,
                'is_primary'     => false,
                'source'         => ProductImage::SOURCE_TOYA,
            ]);

            $imported++;
        }

        Log::info("[ImportToyaImages] SKU={$product->sku} — importate {$imported} imagini noi.");
    }
}
