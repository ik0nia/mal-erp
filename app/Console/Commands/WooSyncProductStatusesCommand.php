<?php

namespace App\Console\Commands;

use App\Jobs\SyncWooProductStatusesJob;
use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use Illuminate\Console\Command;

class WooSyncProductStatusesCommand extends Command
{
    protected $signature = 'woo:sync-product-statuses';

    protected $description = 'Sync product statuses from WooCommerce using 3 parallel workers';

    public function handle(): int
    {
        $connections = IntegrationConnection::where('is_active', true)
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
            ->get();

        if ($connections->isEmpty()) {
            $this->error('Nicio conexiune WooCommerce activă găsită.');
            return self::FAILURE;
        }

        foreach ($connections as $connection) {
            $localCount = WooProduct::where('connection_id', $connection->id)
                ->where('is_placeholder', false)
                ->whereNotNull('woo_id')
                ->count();

            // Estimare pagini cu 20% buffer pentru produse extra în Woo
            $totalPages = max(3, (int) ceil($localCount / 100 * 1.2));
            $perWorker  = (int) ceil($totalPages / 3);

            $this->info("[{$connection->name}] ~{$localCount} produse locale → ~{$totalPages} pagini → 3 workeri × {$perWorker} pagini");

            for ($i = 0; $i < 3; $i++) {
                $start = $i * $perWorker + 1;
                $end   = ($i + 1) * $perWorker;

                SyncWooProductStatusesJob::dispatch($connection->id, $start, $end, $i + 1);

                $this->line("  Worker " . ($i + 1) . ": pagini {$start}–{$end}");
            }
        }

        $this->info('Jobs dispatchate. Asigură-te că rulează queue workers.');
        return self::SUCCESS;
    }
}
