<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\WooCommerce\WooClient;
use App\Services\WooCommerce\WooOrderSyncService;
use Illuminate\Console\Command;
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

        for ($page = 1; $page <= $pages; $page++) {
            try {
                $orders = $client->getOrders($page, 100, $params);
            } catch (Throwable $e) {
                $this->error("  Pagina {$page}: {$e->getMessage()}");
                break;
            }

            if (empty($orders)) {
                break;
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
