<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PushToyaPricesToWooCommand extends Command
{
    protected $signature = 'toya:push-prices-woo {--batch=10 : Produse per request WooCommerce}';

    protected $description = 'Pushează prețurile de vânzare Toya din DB local la WooCommerce';

    public function handle(): int
    {
        $batchSize  = (int) $this->option('batch');
        $connection = IntegrationConnection::where('provider', 'woocommerce')->first();

        if (! $connection) {
            $this->error('Nu există conexiune WooCommerce.');
            return self::FAILURE;
        }

        $woo = new WooClient($connection);

        $products = DB::table('woo_products')
            ->where('source', 'toya_api')
            ->where('status', 'publish')
            ->whereNotNull('woo_id')
            ->where('regular_price', '>', 0)
            ->select('woo_id', 'regular_price', 'sku')
            ->get();

        $total    = $products->count();
        $pushed   = 0;
        $errors   = 0;
        $chunks   = $products->chunk($batchSize);

        $this->info("Total produse Toya de pushuat: {$total} (batch={$batchSize})");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($chunks as $chunk) {
            $batch = $chunk->map(fn ($p) => [
                'id'            => $p->woo_id,
                'regular_price' => (string) $p->regular_price,
            ])->values()->all();

            $ok = false;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $woo->updateProductPricesBatch($batch);
                    $pushed += count($batch);
                    $ok = true;
                    break;
                } catch (\Throwable $e) {
                    if ($attempt < 3) {
                        sleep($attempt * 2);
                    } else {
                        $errors++;
                        $this->newLine();
                        $this->warn("Batch abandonat după 3 încercări: " . $e->getMessage());
                    }
                }
            }

            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->newLine();
        $this->info("Gata. Pushed: {$pushed}, erori batch: {$errors}");

        return self::SUCCESS;
    }
}
