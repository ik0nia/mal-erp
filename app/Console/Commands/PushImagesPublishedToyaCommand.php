<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class PushImagesPublishedToyaCommand extends Command
{
    protected $signature = 'toya:push-images-published
                            {--limit= : Limita produse}';

    protected $description = 'Descarcă și pushează imaginile produselor Toya deja publicate în WooCommerce';

    public function handle(): int
    {
        $disk   = Storage::disk('public');
        $conn   = IntegrationConnection::where('provider', 'woocommerce')->firstOrFail();
        $client = new WooClient($conn);

        $query = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNotNull('woo_id')
            ->where(function ($q) {
                $q->where('main_image_url', 'like', '%pim.toya.pl%')
                  ->orWhereNull('main_image_url');
            })
            ->select(['id', 'woo_id', 'main_image_url', 'data'])
            ->orderBy('id');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $total = $query->count();
        $this->info("Produse publicate fără imagini locale: {$total}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done    = 0;
        $failed  = 0;
        $noImage = 0;

        $query->chunkById(50, function ($products) use ($disk, $client, $bar, &$done, &$failed, &$noImage) {
            foreach ($products as $p) {
                $imageUrl = $p->main_image_url;

                if (! $imageUrl) {
                    $decoded = json_decode($p->data);
                    if (is_string($decoded)) {
                        $decoded = json_decode($decoded);
                    }
                    $firstAdditional = $decoded->images_additional[0]->high ?? null;
                    if (! $firstAdditional) {
                        $noImage++;
                        $bar->advance();
                        continue;
                    }
                    $imageUrl = str_replace('http://', 'https://', $firstAdditional);
                } else {
                    $imageUrl = str_replace('http://', 'https://', $imageUrl);
                }

                // Download main image
                $mainLocalUrl = $this->downloadOne($imageUrl, $disk);
                if (! $mainLocalUrl) {
                    $failed++;
                    $bar->advance();
                    continue;
                }

                // Update DB
                DB::table('woo_products')->where('id', $p->id)->update(['main_image_url' => $mainLocalUrl]);

                // Download gallery images
                $gallery = DB::table('product_images')
                    ->where('woo_product_id', $p->id)
                    ->where('source', 'toya')
                    ->orderBy('sort_order')
                    ->get(['id', 'url', 'local_path']);

                foreach ($gallery as $img) {
                    if ($img->local_path) {
                        continue;
                    }
                    $galleryLocalUrl = $this->downloadOne(str_replace('http://', 'https://', $img->url), $disk);
                    if ($galleryLocalUrl) {
                        DB::table('product_images')->where('id', $img->id)->update(['local_path' => $galleryLocalUrl]);
                    }
                }

                // Build deduplicated images array
                $seen   = [];
                $images = [];

                foreach (array_merge([$mainLocalUrl], DB::table('product_images')
                    ->where('woo_product_id', $p->id)
                    ->where('source', 'toya')
                    ->whereNotNull('local_path')
                    ->orderBy('sort_order')
                    ->pluck('local_path')
                    ->toArray()) as $url) {
                    if ($url && str_contains($url, config('app.url')) && ! isset($seen[$url])) {
                        $images[]   = ['src' => $url];
                        $seen[$url] = true;
                    }
                }

                // Push to WooCommerce
                try {
                    $client->updateProduct($p->woo_id, ['images' => $images]);
                    $done++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->warn("FAIL woo_id={$p->woo_id}: " . substr($e->getMessage(), 0, 100));
                    $failed++;
                }

                usleep(100_000); // 100ms
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done=$done | Failed=$failed | NoImage=$noImage");

        return self::SUCCESS;
    }

    private function downloadOne(string $url, $disk): ?string
    {
        $basename  = basename(parse_url($url, PHP_URL_PATH));
        $localPath = 'toya-images/' . $basename;

        if ($disk->exists($localPath)) {
            return config('app.url') . '/storage/toya-images/' . $basename;
        }

        $response = Http::timeout(30)->withOptions(['verify' => false])->get($url);
        if (! $response->successful()) {
            return null;
        }

        $disk->put($localPath, $response->body());

        return config('app.url') . '/storage/toya-images/' . $basename;
    }
}
