<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DisableBackordersCommand extends Command
{
    protected $signature = 'woo:disable-backorders
                            {--batch=100 : Produse per request batch}
                            {--dry-run : Afișează câte produse ar fi afectate fără să modifice nimic}';

    protected $description = 'Setează backorders=no pe toate produsele din WooCommerce';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $dryRun    = (bool) $this->option('dry-run');

        $connections = IntegrationConnection::where('is_active', true)
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
            ->get();

        if ($connections->isEmpty()) {
            $this->error('Nicio conexiune WooCommerce activă.');
            return self::FAILURE;
        }

        foreach ($connections as $connection) {
            $this->processConnection($connection, $batchSize, $dryRun);
        }

        return self::SUCCESS;
    }

    private function processConnection(IntegrationConnection $connection, int $batchSize, bool $dryRun): void
    {
        $this->info("Conexiune: {$connection->name}");

        $products = WooProduct::where('connection_id', $connection->id)
            ->whereNotNull('woo_id')
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.backorders')) != 'no'")
                  ->orWhereRaw("JSON_EXTRACT(data, '$.backorders') IS NULL");
            })
            ->select(['id', 'woo_id', 'data'])
            ->get();

        $this->info("  Produse cu backorders != no: {$products->count()}");

        if ($dryRun || $products->isEmpty()) {
            return;
        }

        $client  = new WooClient($connection);
        $updated = 0;
        $errors  = 0;

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products->chunk($batchSize) as $chunk) {
            $payload = $chunk->map(fn ($p) => [
                'id'         => $p->woo_id,
                'backorders' => 'no',
            ])->values()->all();

            try {
                $response = $client->updateProductsBatch($payload);

                $results = $response['update'] ?? [];
                $ok  = collect($results)->filter(fn ($r) => ! isset($r['error']))->count();
                $err = count($results) - $ok;

                $updated += $ok;
                $errors  += $err;

                // Actualizează local JSON data
                foreach ($chunk as $product) {
                    $data = is_array($product->data) ? $product->data : json_decode($product->data ?? '{}', true);
                    $data['backorders'] = 'no';
                    DB::table('woo_products')
                        ->where('id', $product->id)
                        ->update(['data' => json_encode($data), 'updated_at' => now()]);
                }
            } catch (\Throwable $e) {
                $errors += count($payload);
                $this->newLine();
                $this->warn("  Eroare batch: {$e->getMessage()}");
                Log::warning('[DisableBackorders] Eroare batch', ['error' => $e->getMessage()]);
            }

            $bar->advance(count($payload));
        }

        $bar->finish();
        $this->newLine();
        $this->info("  ✓ Actualizate: {$updated} | Erori: {$errors}");

        Log::info('[DisableBackorders] Finalizat', [
            'connection' => $connection->name,
            'updated'    => $updated,
            'errors'     => $errors,
        ]);
    }
}
