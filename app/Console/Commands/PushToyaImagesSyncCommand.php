<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PushToyaImagesSyncCommand extends Command
{
    protected $signature = 'toya:push-images-sync
                            {--delay=15 : Secunde intre fiecare produs}
                            {--limit=  : Limita produse}
                            {--offset= : Sari primele N produse (pentru rulare paralela)}';

    protected $description = 'Pushează imaginile la produsele Toya publicate, unul câte unul cu pauză';

    public function handle(): int
    {
        $delay  = (int) $this->option('delay');
        $limit  = $this->option('limit')  ? (int) $this->option('limit')  : null;
        $offset = $this->option('offset') ? (int) $this->option('offset') : 0;

        $conn   = IntegrationConnection::where('provider', 'woocommerce')->firstOrFail();
        $client = new WooClient($conn);
        $disk   = Storage::disk('public');
        $appUrl = config('app.url');

        $query = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNotNull('woo_id')
            ->where('main_image_url', 'like', '%' . $appUrl . '%')
            ->orderBy('id');

        $total = $query->count();

        if ($offset > 0) {
            $query->skip($offset);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $this->info("Produse totale: {$total} | Offset: {$offset} | Delay: {$delay}s");

        $done    = 0;
        $failed  = 0;
        $skipped = 0;

        $query->chunkById(100, function ($products) use (
            $client, $disk, $appUrl, $delay, $limit, &$done, &$failed, &$skipped
        ) {
            foreach ($products as $p) {
                if ($limit && ($done + $failed + $skipped) >= $limit) {
                    return false;
                }

                // Verify main image exists on disk
                $mainPath = str_replace($appUrl . '/storage/', '', $p->main_image_url);
                if (! $disk->exists($mainPath)) {
                    $skipped++;
                    continue;
                }

                // Collect all images (main + gallery, deduped)
                $seen   = [];
                $images = [];

                $images[]                 = ['src' => $p->main_image_url];
                $seen[$p->main_image_url] = true;

                $galleryUrls = DB::table('product_images')
                    ->where('woo_product_id', $p->id)
                    ->where('source', 'toya')
                    ->whereNotNull('local_path')
                    ->where('local_path', 'like', '%' . $appUrl . '%')
                    ->orderBy('sort_order')
                    ->pluck('local_path')
                    ->toArray();

                foreach ($galleryUrls as $url) {
                    if (isset($seen[$url])) {
                        continue;
                    }
                    $diskPath = str_replace($appUrl . '/storage/', '', $url);
                    if ($disk->exists($diskPath)) {
                        $images[]   = ['src' => $url];
                        $seen[$url] = true;
                    }
                }

                try {
                    $client->updateProduct($p->woo_id, ['images' => $images]);
                    $done++;
                    $this->line("  [+{$done}] woo_id={$p->woo_id} | " . count($images) . " poze ✓");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("  FAIL woo_id={$p->woo_id}: " . substr($e->getMessage(), 0, 80));
                    sleep(5); // pauza extra dupa fail — da timp serverului sa se elibereze
                }

                if ($delay > 0) {
                    sleep($delay);
                }
            }
        });

        $this->info("\nFinalizat. Pushat: {$done} | Eșuat: {$failed} | Sărit: {$skipped}");

        return self::SUCCESS;
    }
}
