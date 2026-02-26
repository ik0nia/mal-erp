<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Downloads external product images via WooCommerce sideload mechanism.
 *
 * For each product whose main_image_url points to an external domain (not malinco.ro),
 * it calls the WooCommerce REST API to sideload the image into the WooCommerce media library.
 * WooCommerce downloads the image itself and returns the new malinco.ro URL.
 * The local main_image_url is then updated to the new URL.
 */
class UploadProductImagesToWooCommand extends Command
{
    protected $signature = 'images:upload-to-woo
                            {--connection=1   : IntegrationConnection ID for WooCommerce}
                            {--limit=         : Max number of products to process}
                            {--placeholder    : Only process placeholder (WinMentor) products}
                            {--worker=1       : Worker index (1-based)}
                            {--workers=1      : Total number of parallel workers}';

    protected $description = 'Sideload external product images into WooCommerce media library';

    public function handle(): int
    {
        $connectionId = (int) $this->option('connection');
        $limit        = $this->option('limit') ? (int) $this->option('limit') : null;
        $placeholder  = (bool) $this->option('placeholder');
        $worker       = max(1, (int) $this->option('worker'));
        $workers      = max(1, (int) $this->option('workers'));

        $connection = IntegrationConnection::find($connectionId);

        if (! $connection || ! $connection->is_active) {
            $this->error("IntegrationConnection #{$connectionId} not found or inactive.");
            return self::FAILURE;
        }

        $wooClient = new WooClient($connection);
        $host      = parse_url($connection->base_url, PHP_URL_HOST);

        $this->info("Upload imagini pe {$host}" . ($workers > 1 ? ", worker: {$worker}/{$workers}" : ''));

        $query = DB::table('woo_products')
            ->whereNotNull('main_image_url')
            ->where('main_image_url', '!=', '')
            ->where('main_image_url', 'NOT LIKE', "%{$host}%")
            ->whereNotNull('woo_id')
            ->where('woo_id', '>', 0);

        if ($placeholder) {
            $query->where('is_placeholder', true)->where('source', 'winmentor_csv');
        }

        if ($workers > 1) {
            $query->whereRaw('(id % ?) = ?', [$workers, $worker - 1]);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->select('id', 'woo_id', 'name', 'main_image_url')->get();
        $total    = $products->count();

        if ($total === 0) {
            $this->info('Niciun produs de procesat (toate au deja imagini pe WooCommerce).');
            return self::SUCCESS;
        }

        $this->info("Produse de procesat: {$total}");

        $done   = 0;
        $ok     = 0;
        $failed = 0;

        foreach ($products as $product) {
            $done++;
            $this->info("[{$done}/{$total}] #{$product->id} woo#{$product->woo_id}: \"{$product->name}\"");

            try {
                $newUrl = $wooClient->sideloadProductImage((int) $product->woo_id, $product->main_image_url);

                if (empty($newUrl)) {
                    $this->warn('  WooCommerce returned empty image URL — skip.');
                    $failed++;
                    continue;
                }

                DB::table('woo_products')
                    ->where('id', $product->id)
                    ->update(['main_image_url' => $newUrl, 'updated_at' => now()]);

                $this->line("  ✓ " . parse_url($newUrl, PHP_URL_HOST) . parse_url($newUrl, PHP_URL_PATH));
                $ok++;
            } catch (\Throwable $e) {
                $this->warn('  Eroare: ' . $e->getMessage());
                Log::warning("UploadImagesToWoo #{$product->id}: " . $e->getMessage());
                $failed++;

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                    $this->warn('  Rate limit — aștept 10s...');
                    sleep(10);
                }
            }

            if ($done < $total) {
                usleep(300_000); // 0.3s între cereri
            }
        }

        $this->newLine();
        $this->info("Gata. Total: {$total} | Uploadate: {$ok} | Eșuate: {$failed}");

        return self::SUCCESS;
    }
}
