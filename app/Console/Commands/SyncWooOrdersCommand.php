<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooOrder;
use App\Services\WooCommerce\WooClient;
use App\Services\WooCommerce\WooOrderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWooOrdersCommand extends Command
{
    protected $signature = 'woo:sync-orders
                            {--connection= : ID conexiune specifică (dacă lipsește, toate conexiunile WooCommerce active)}
                            {--pages=5 : Număr pagini de fetch (100 comenzi/pagină)}
                            {--status= : Filtrează după status (ex: processing)}';

    protected $description = 'Sincronizează comenzile WooCommerce local';

    public function handle(): int
    {
        $connections = $this->resolveConnections();

        if ($connections->isEmpty()) {
            $this->warn('Nu există conexiuni WooCommerce active.');

            return self::SUCCESS;
        }

        $pages  = max(1, (int) $this->option('pages'));
        $status = (string) ($this->option('status') ?? '');

        foreach ($connections as $connection) {
            $this->info("Conexiune: {$connection->name} (#{$connection->id})");
            $this->syncConnection($connection, $pages, $status);
        }

        return self::SUCCESS;
    }

    private function syncConnection(IntegrationConnection $connection, int $pages, string $status): void
    {
        $client      = new WooClient($connection);
        $service     = new WooOrderSyncService();
        $locationId  = $connection->location_id;
        $totalSynced = 0;

        $params = [];
        if ($status !== '') {
            $params['status'] = $status;
        }

        $localCount = WooOrder::where('connection_id', $connection->id)->count();

        for ($page = 1; $page <= $pages; $page++) {
            try {
                $orders = $client->getOrders($page, 100, $params);
            } catch (Throwable $e) {
                $this->error("  Pagina {$page}: {$e->getMessage()}");
                break;
            }

            if (empty($orders)) {
                // SAFEGUARD: Dacă prima pagină returnează 0 comenzi dar avem local mai mult de 10,
                // WooCommerce poate fi down sau răspunsul e incomplet — nu continuăm.
                if ($page === 1 && $localCount > 10 && $status === '') {
                    $message = "WooOrderSyncService: safeguard activat — WooCommerce a returnat 0 comenzi (pagina 1) dar avem {$localCount} local. Sync oprit.";
                    $this->warn("  {$message}");
                    Log::warning($message, ['connection_id' => $connection->id]);
                }
                break;
            }

            // SAFEGUARD: Dacă totalul returnat pe prima pagină e cu >50% mai mic decât localCount, alertăm.
            if ($page === 1 && $localCount > 0 && count($orders) < $localCount * 0.5 && $status === '') {
                $message = "WooOrderSyncService: discrepanță mare — remote prima pagină: " . count($orders) . ", local total: {$localCount}. Verifică sincronizarea.";
                $this->warn("  {$message}");
                Log::warning($message, ['connection_id' => $connection->id]);
                // Nu blocăm — discrepanța e normală când localCount include mai multe pagini
            }

            foreach ($orders as $raw) {
                $service->upsertOrder($connection->id, $locationId, $raw);
                $totalSynced++;
            }

            $this->line("  Pagina {$page}: ".count($orders).' comenzi');

            if (count($orders) < 100) {
                break;
            }
        }

        $this->info("  Total sincronizate: {$totalSynced}");
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, IntegrationConnection>
     */
    private function resolveConnections(): \Illuminate\Database\Eloquent\Collection
    {
        $connectionId = $this->option('connection');

        $query = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
            ->where('is_active', true);

        if ($connectionId) {
            $query->where('id', (int) $connectionId);
        }

        return $query->get();
    }
}
