<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PushToyaImageToWooJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;
    public int $backoff = 60;

    public function __construct(
        public readonly int     $productId,
        public readonly int     $wooId,
        public readonly ?string $imageUrl = null, // kept for backwards compatibility
    ) {}

    public function handle(): void
    {
        $conn   = IntegrationConnection::where('provider', 'woocommerce')->firstOrFail();
        $client = new WooClient($conn);

        $product = WooProduct::find($this->productId);
        if (! $product) {
            return;
        }

        // Collect all images: main first, then gallery in sort_order (deduped by URL)
        $seen   = [];
        $images = [];

        $appUrl  = config('app.url');
        $mainUrl = $product->main_image_url ?? $this->imageUrl;
        if ($mainUrl && str_contains($mainUrl, $appUrl) && ! isset($seen[$mainUrl])) {
            $images[]       = ['src' => $mainUrl];
            $seen[$mainUrl] = true;
        }

        $galleryUrls = DB::table('product_images')
            ->where('woo_product_id', $this->productId)
            ->where('source', 'toya')
            ->whereNotNull('local_path')
            ->where('local_path', '!=', '')
            ->orderBy('sort_order')
            ->pluck('local_path')
            ->toArray();

        $disk = Storage::disk('public');
        foreach ($galleryUrls as $url) {
            if (! str_contains($url, $appUrl) || isset($seen[$url])) {
                continue;
            }
            $diskPath = str_replace($appUrl . '/storage/', '', $url);
            if (! $disk->exists($diskPath)) {
                continue;
            }
            $images[]   = ['src' => $url];
            $seen[$url] = true;
        }

        if (empty($images)) {
            Log::info('[ToyaImagePush] No local images ready yet', [
                'product_id' => $this->productId,
                'woo_id'     => $this->wooId,
            ]);
            return;
        }

        try {
            $client->updateProduct($this->wooId, ['images' => $images]);
        } catch (\Throwable $e) {
            Log::warning('[ToyaImagePush] Failed', [
                'product_id' => $this->productId,
                'woo_id'     => $this->wooId,
                'images'     => count($images),
                'error'      => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }
}
