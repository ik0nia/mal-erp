<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads external product images and saves them to local storage.
 *
 * Images are saved to storage/app/public/product-images/{product_id}.{ext}
 * and become accessible at https://erp.malinco.ro/storage/product-images/{id}.jpg
 *
 * This ensures image URLs are stable and don't depend on third-party CDNs.
 * When placeholder products are later synced to WooCommerce, the local URL
 * will be used and WooCommerce will sideload it.
 */
class DownloadProductImagesToLocalCommand extends Command
{
    protected $signature = 'images:download-to-local
                            {--limit=        : Max number of products to process}
                            {--force         : Re-download even if image is already local}
                            {--worker=1      : Worker index (1-based)}
                            {--workers=1     : Total number of parallel workers}';

    protected $description = 'Download external product images to local storage (erp.malinco.ro)';

    private string $localHost = 'erp.malinco.ro';

    public function handle(): int
    {
        $limit   = $this->option('limit') ? (int) $this->option('limit') : null;
        $force   = (bool) $this->option('force');
        $worker  = max(1, (int) $this->option('worker'));
        $workers = max(1, (int) $this->option('workers'));

        $appUrl = rtrim(config('app.url', 'https://erp.malinco.ro'), '/');

        $this->info("Download imagini locale — {$appUrl}" . ($workers > 1 ? ", worker: {$worker}/{$workers}" : ''));

        $query = DB::table('woo_products')
            ->whereNotNull('main_image_url')
            ->where('main_image_url', '!=', '');

        if (! $force) {
            // Only process products with external images (not already on this server)
            $query->where('main_image_url', 'NOT LIKE', "%{$this->localHost}%");
        }

        if ($workers > 1) {
            $query->whereRaw('(id % ?) = ?', [$workers, $worker - 1]);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->select('id', 'name', 'slug', 'main_image_url')->get();
        $total    = $products->count();

        if ($total === 0) {
            $this->info('Niciun produs de procesat — toate imaginile sunt deja locale.');
            return self::SUCCESS;
        }

        $this->info("Produse de procesat: {$total}");

        // Ensure directory exists
        Storage::disk('public')->makeDirectory('product-images');

        $done   = 0;
        $ok     = 0;
        $failed = 0;
        $skip   = 0;

        foreach ($products as $product) {
            $done++;

            $externalUrl = $product->main_image_url;

            // Download the image
            $imageData = $this->downloadImage($externalUrl);

            if ($imageData === null) {
                $this->warn("[{$done}/{$total}] #{$product->id} — download fail: " . basename(parse_url($externalUrl, PHP_URL_PATH)));
                $failed++;
                continue;
            }

            [$body, $ext] = $imageData;

            // Use slug as filename; generate from name if slug is missing
            if (!empty($product->slug)) {
                $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($product->slug));
            } else {
                $slug = \Illuminate\Support\Str::slug($product->name) ?: (string) $product->id;
            }
            $filename = "product-images/{$slug}.{$ext}";
            Storage::disk('public')->put($filename, $body);

            $localUrl = $appUrl . '/storage/' . $filename;

            DB::table('woo_products')
                ->where('id', $product->id)
                ->update(['main_image_url' => $localUrl, 'updated_at' => now()]);

            $this->line("[{$done}/{$total}] #{$product->id} ✓ → {$filename}");
            $ok++;
        }

        $this->newLine();
        $this->info("Gata. Total: {$total} | Salvate: {$ok} | Eșuate: {$failed}");

        return self::SUCCESS;
    }

    /**
     * Download an image URL and return [body, extension], or null on failure.
     *
     * @return array{string, string}|null
     */
    private function downloadImage(string $url): ?array
    {
        if (empty($url)) {
            return null;
        }

        $host    = parse_url($url, PHP_URL_HOST) ?? '';
        $referer = 'https://' . $host . '/';

        // Encode any unencoded spaces/special chars in URL path/query
        $encodedUrl = preg_replace_callback(
            '/[^a-zA-Z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/',
            fn ($m) => rawurlencode($m[0]),
            $url
        );

        $opts = [
            CURLOPT_URL            => $encodedUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language: ro-RO,ro;q=0.9,en;q=0.8',
                'Referer: ' . $referer,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        $ch   = curl_init();
        curl_setopt_array($ch, $opts);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error  = curl_error($ch);
        curl_close($ch);

        // Retry with SSL verification disabled for sites with invalid certificates
        if ($error || $status !== 200 || empty($body) || strlen($body) < 500) {
            $ch = curl_init();
            curl_setopt_array($ch, $opts);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
        }

        if ($status !== 200 || empty($body) || strlen($body) < 500) {
            return null;
        }

        // Determine extension from content-type or URL
        $ext = match (true) {
            str_contains($ctype, 'png')  => 'png',
            str_contains($ctype, 'gif')  => 'gif',
            str_contains($ctype, 'webp') => 'webp',
            default                      => 'jpg',
        };

        // Fallback: check URL extension
        if ($ext === 'jpg') {
            $urlPath = parse_url($url, PHP_URL_PATH) ?? '';
            if (preg_match('/\.(png|gif|webp)(\?.*)?$/i', $urlPath, $m)) {
                $ext = strtolower($m[1]);
            }
        }

        return [$body, $ext];
    }
}
