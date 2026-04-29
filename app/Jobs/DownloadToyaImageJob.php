<?php

namespace App\Jobs;

use App\Models\WooProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadToyaImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int    $productId,
        public readonly string $imageUrl,
    ) {}

    public function handle(): void
    {
        $product = WooProduct::find($this->productId);
        if (! $product) {
            return;
        }

        $disk = Storage::disk('public');

        // Download main image
        $mainLocalUrl = $this->downloadOne($this->imageUrl, $disk);
        if ($mainLocalUrl) {
            $product->update(['main_image_url' => $mainLocalUrl]);
        }

        // Download all gallery images from product_images table
        $galleryImages = DB::table('product_images')
            ->where('woo_product_id', $this->productId)
            ->where('source', 'toya')
            ->orderBy('sort_order')
            ->get(['id', 'url', 'local_path']);

        foreach ($galleryImages as $img) {
            if ($img->local_path) {
                continue; // already downloaded
            }

            $localUrl = $this->downloadOne($img->url, $disk);
            if ($localUrl) {
                DB::table('product_images')->where('id', $img->id)->update(['local_path' => $localUrl]);
            }
        }

        // If product is already published in WooCommerce, push all images
        $product->refresh();
        if ($product->woo_id) {
            PushToyaImageToWooJob::dispatch($product->id, $product->woo_id)
                ->onQueue('default');
        }
    }

    private function downloadOne(string $url, $disk): ?string
    {
        $url = str_replace('http://', 'https://', $url);
        $basename  = basename(parse_url($url, PHP_URL_PATH));
        $localPath = 'toya-images/' . $basename;

        if ($disk->exists($localPath)) {
            return config('app.url') . '/storage/toya-images/' . $basename;
        }

        $response = Http::timeout(30)
            ->withOptions(['verify' => false]) // pim.toya.pl SSL issues
            ->get($url);

        if (! $response->successful()) {
            Log::warning('[ToyaImage] Download failed', [
                'product_id' => $this->productId,
                'url'        => $url,
                'status'     => $response->status(),
            ]);
            return null;
        }

        $disk->put($localPath, $response->body());

        return config('app.url') . '/storage/toya-images/' . $basename;
    }
}
