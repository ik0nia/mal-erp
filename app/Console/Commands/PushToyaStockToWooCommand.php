<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PushToyaStockToWooCommand extends Command
{
    protected $signature = 'toya:push-stock-woo {--batch=10 : Produse per request WooCommerce}';

    protected $description = 'Pushează stoc + backorders Toya din DB local la WooCommerce';

    public function handle(): int
    {
        $batchSize  = (int) $this->option('batch');
        $connection = IntegrationConnection::where('provider', 'woocommerce')->first();

        if (! $connection) {
            $this->error('Nu există conexiune WooCommerce.');
            return self::FAILURE;
        }

        $woo = new WooClient($connection);

        $total = DB::table('woo_products')
            ->where('source', 'toya_api')
            ->where('status', 'publish')
            ->whereNotNull('woo_id')
            ->count();

        $pushed = 0;
        $errors = 0;

        $this->info("Total produse Toya de pushuat stoc: {$total} (batch={$batchSize})");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $buffer = [];

        $flush = function () use (&$buffer, &$pushed, &$errors, $woo, $batchSize, $bar): void {
            if (empty($buffer)) return;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $woo->updateProductsBatch($buffer);
                    $pushed += count($buffer);
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
            $bar->advance(count($buffer));
            $buffer = [];
        };

        DB::table('woo_products')
            ->where('source', 'toya_api')
            ->where('status', 'publish')
            ->whereNotNull('woo_id')
            ->select('woo_id', 'stock_status')
            ->orderBy('id')
            ->chunk(500, function ($chunk) use (&$buffer, $batchSize, $flush) {
                foreach ($chunk as $p) {
                    $buffer[] = [
                        'id'             => $p->woo_id,
                        'manage_stock'   => true,
                        'stock_quantity' => 0,
                        'backorders'     => $p->stock_status === 'outofstock' ? 'no' : 'yes',
                    ];
                    if (count($buffer) >= $batchSize) {
                        $flush();
                    }
                }
            });

        $flush(); // ultimul batch parțial

        $bar->finish();
        $this->newLine();
        $this->info("Gata. Pushed: {$pushed}, erori batch: {$errors}");

        return self::SUCCESS;
    }
}
