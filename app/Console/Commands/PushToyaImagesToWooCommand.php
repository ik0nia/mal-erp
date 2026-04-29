<?php

namespace App\Console\Commands;

use App\Jobs\PushToyaImageToWooJob;
use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;

class PushToyaImagesToWooCommand extends Command
{
    protected $signature = 'toya:push-images-to-woo
                            {--limit= : Limita produse}
                            {--dry-run : Arată câte joburi ar fi dispatch-ate, fără a dispatch}';

    protected $description = 'Verifică WooCommerce direct pentru produse fără imagini, dispatch joburi doar pentru acelea';

    public function handle(): int
    {
        $this->info('Pas 1: Preluare produse fără imagini din WooCommerce...');

        $conn   = IntegrationConnection::where('provider', 'woocommerce')->firstOrFail();
        $client = new WooClient($conn);

        // Collect all WooCommerce product IDs that have NO images
        $wooIdsWithoutImages = $this->fetchWooIdsWithoutImages($client);

        $this->info('Produse fără imagini în WooCommerce: ' . count($wooIdsWithoutImages));

        if (empty($wooIdsWithoutImages)) {
            $this->info('Nicio diferență — toate produsele au imagini în WooCommerce.');
            return self::SUCCESS;
        }

        // Cross-reference with ERP: Toya products that have local images AND are in the "no image" set
        $query = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNotNull('woo_id')
            ->whereIn('woo_id', $wooIdsWithoutImages)
            ->whereNotNull('main_image_url')
            ->where('main_image_url', 'not like', '%pim.toya.pl%')
            ->where('main_image_url', '!=', '');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $total = $query->count();
        $this->info("Produse Toya cu imagine locală dar fără imagine în WooCommerce: {$total}");

        if ($total === 0) {
            $this->info('Nicio imagine de actualizat.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('[dry-run] S-ar dispatch ' . $total . ' joburi.');
            return self::SUCCESS;
        }

        // Spațiem câte 1 job la 12 secunde — WooCommerce face sideload imagine (lent)
        $dispatched = 0;
        $query->select(['id', 'woo_id', 'main_image_url'])->chunkById(500, function ($products) use (&$dispatched) {
            foreach ($products as $product) {
                PushToyaImageToWooJob::dispatch($product->id, $product->woo_id)
                    ->onQueue('default')
                    ->delay(now()->addSeconds($dispatched * 12));
                $dispatched++;
            }
            $this->line("  Dispatched: {$dispatched}");
        });

        $this->info("Done! {$dispatched} joburi în coadă.");

        return self::SUCCESS;
    }

    /**
     * Paginate all WooCommerce published products and return woo_ids where images array is empty.
     *
     * @return array<int>
     */
    private function fetchWooIdsWithoutImages(WooClient $client): array
    {
        $noImage = [];
        $page    = 1;

        $this->output->write('  Pagini WooCommerce: ');

        while (true) {
            $products = $client->getProductImages($page, 100);

            if (empty($products)) {
                break;
            }

            foreach ($products as $product) {
                if (empty($product['images'])) {
                    $noImage[] = (int) $product['id'];
                }
            }

            $this->output->write($page . ' ');
            $page++;

            if (count($products) < 100) {
                break;
            }
        }

        $this->newLine();

        return $noImage;
    }
}
