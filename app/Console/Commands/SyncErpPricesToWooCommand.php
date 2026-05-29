<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincronizează prețurile din ERP pe WooCommerce pentru toate produsele
 * unde regular_price din ERP diferă de prețul stocat în câmpul `data` (ultima sincronizare).
 *
 * Folosire:
 *   php artisan woo:sync-erp-prices           # Dry-run (afișează diferențele)
 *   php artisan woo:sync-erp-prices --push     # Push efectiv pe WooCommerce
 *   php artisan woo:sync-erp-prices --push --connection=1
 */
class SyncErpPricesToWooCommand extends Command
{
    protected $signature = 'woo:sync-erp-prices
                            {--push : Împinge prețurile pe WooCommerce (fără flag = dry-run)}
                            {--connection= : ID conexiune WooCommerce (implicit: prima conexiune activă)}
                            {--batch=100 : Câte produse per request WooCommerce (max 100)}';

    protected $description = 'Sincronizează prețurile ERP → WooCommerce pentru produsele cu prețuri diferite';

    public function handle(): int
    {
        $connectionId = $this->option('connection');
        $push         = (bool) $this->option('push');
        $batchSize    = min(100, (int) ($this->option('batch') ?? 100));

        $connection = $connectionId
            ? IntegrationConnection::find($connectionId)
            : IntegrationConnection::where('provider', 'woocommerce')->where('is_active', true)->first();

        if (! $connection) {
            $this->error('Nicio conexiune WooCommerce activă găsită.');
            return self::FAILURE;
        }

        $this->info("Conexiune: {$connection->name} (ID {$connection->id})");
        $this->info($push ? '🚀 MOD PUSH — prețurile vor fi actualizate pe site' : '🔍 MOD DRY-RUN — fără modificări reale');
        $this->newLine();

        $client = $push ? new WooClient($connection) : null;

        $mismatches  = 0;
        $pushed      = 0;
        $errors      = 0;
        $batchBuffer = [];   // [{id, regular_price, product_id, erpPrice}]

        $flush = function () use ($client, &$batchBuffer, &$pushed, &$errors) {
            if (empty($batchBuffer)) {
                return;
            }

            $updates = array_map(fn ($item) => ['id' => $item['woo_id'], 'regular_price' => $item['erpPrice']], $batchBuffer);

            try {
                $client->updateProductPricesBatch($updates);

                // Actualizăm câmpul `data` local pentru fiecare produs din batch
                WooProduct::$skipPricePush = true;

                foreach ($batchBuffer as $item) {
                    $product = WooProduct::find($item['product_id']);
                    if ($product) {
                        $data = $product->data;
                        if (is_string($data)) {
                            $data = json_decode($data, true) ?? [];
                        }
                        if (!is_array($data)) {
                            $data = [];
                        }
                        $data['regular_price'] = $item['erpPrice'];
                        $data['price']         = $item['erpPrice'];
                        $product->update(['price' => $product->regular_price, 'data' => $data]);
                    }
                }

                WooProduct::$skipPricePush = false;
                $pushed += count($batchBuffer);
            } catch (\Throwable $e) {
                $errors += count($batchBuffer);
                $this->warn("  ⚠ Eroare batch de " . count($batchBuffer) . " produse: {$e->getMessage()}");
                Log::error('[SyncErpPrices] Eroare batch push', ['error' => $e->getMessage()]);
            }

            $batchBuffer = [];
        };

        WooProduct::where('connection_id', $connection->id)
            ->whereNotNull('woo_id')
            ->whereNotNull('regular_price')
            ->where('regular_price', '>', 0)
            ->chunkById(500, function ($products) use ($client, $push, $batchSize, &$mismatches, &$batchBuffer, $flush) {
                foreach ($products as $product) {
                    $erpPrice  = number_format((float) $product->regular_price, 2, '.', '');
                    $data      = $product->data ?? [];
                    $sitePrice = isset($data['regular_price']) && $data['regular_price'] !== ''
                        ? number_format((float) $data['regular_price'], 2, '.', '')
                        : null;

                    if ($sitePrice === $erpPrice) {
                        continue;
                    }

                    $mismatches++;

                    $this->line(sprintf(
                        '  [woo:%d] SKU %-20s  ERP: %8s lei  Site: %8s lei',
                        $product->woo_id,
                        $product->sku,
                        $erpPrice,
                        $sitePrice ?? 'N/A',
                    ));

                    if (! $push || ! $client) {
                        continue;
                    }

                    $batchBuffer[] = [
                        'woo_id'     => $product->woo_id,
                        'product_id' => $product->id,
                        'erpPrice'   => $erpPrice,
                    ];

                    if (count($batchBuffer) >= $batchSize) {
                        $flush();
                    }
                }
            });

        // Flush rămășița
        if ($push && $client) {
            $flush();
        }

        $this->newLine();
        $this->info("Total diferențe găsite: {$mismatches}");

        if ($push) {
            $this->info("Împinse cu succes: {$pushed}");
            if ($errors > 0) {
                $this->warn("Erori: {$errors}");
            }
        } else {
            $this->comment('Rulați cu --push pentru a aplica modificările.');
        }

        return self::SUCCESS;
    }
}
