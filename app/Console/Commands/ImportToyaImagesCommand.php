<?php

namespace App\Console\Commands;

use App\Jobs\ImportToyaImagesJob;
use App\Models\ProductImage;
use App\Models\WooProduct;
use Illuminate\Console\Command;

class ImportToyaImagesCommand extends Command
{
    protected $signature = 'toya:import-images
                            {--sku= : Importă doar pentru un SKU specific}
                            {--limit=0 : Limitează numărul de produse procesate (0 = toate)}
                            {--sync : Rulează sincron (fără queue), util pentru debugging}';

    protected $description = 'Importă imaginile suplimentare Toya din câmpul data[images_additional] în tabelul product_images.';

    public function handle(): int
    {
        $sku   = $this->option('sku');
        $limit = (int) $this->option('limit');
        $sync  = $this->option('sync');

        $query = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNotNull('data')
            ->where('data', 'like', '%images_additional%')
            ->where('data', 'not like', '%"images_additional":[]%');

        if ($sku) {
            $query->where('sku', $sku);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('Niciun produs Toya cu imagini suplimentare găsit.');

            return self::SUCCESS;
        }

        $this->info("Găsite {$total} produse Toya cu imagini suplimentare.");

        if ($limit > 0) {
            $query->limit($limit);
            $this->info("Limitat la primele {$limit} produse.");
        }

        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->start();

        $dispatched = 0;
        $skipped    = 0;

        $query->select('id', 'sku')->chunk(100, function ($products) use (&$dispatched, &$skipped, $sync, $bar) {
            foreach ($products as $product) {
                // Verificăm dacă produsul are deja imagini Toya importate
                if (! $sync) {
                    $hasImages = ProductImage::where('woo_product_id', $product->id)
                        ->where('source', 'toya')
                        ->exists();

                    if ($hasImages) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    ImportToyaImagesJob::dispatch($product->id);
                } else {
                    (new ImportToyaImagesJob($product->id))->handle();
                }

                $dispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        if ($sync) {
            $this->info("Procesate sincron: {$dispatched} produse, sărite (deja importate): {$skipped}.");
        } else {
            $this->info("Trimise în queue: {$dispatched} job-uri. Sărite (deja importate): {$skipped}.");
            $this->info('Verifică Horizon pentru progres: https://erp.malinco.ro/horizon');
        }

        return self::SUCCESS;
    }
}
