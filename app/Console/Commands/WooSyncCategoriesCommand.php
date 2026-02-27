<?php

namespace App\Console\Commands;

use App\Actions\WooCommerce\ImportWooCategoriesAction;
use App\Models\IntegrationConnection;
use Illuminate\Console\Command;
use Throwable;

class WooSyncCategoriesCommand extends Command
{
    protected $signature = 'woo:sync-categories';

    protected $description = 'Sincronizează categoriile WooCommerce pentru toate conexiunile active';

    public function handle(ImportWooCategoriesAction $action): int
    {
        $connections = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
            ->where('is_active', true)
            ->get();

        if ($connections->isEmpty()) {
            $this->warn('Nicio conexiune WooCommerce activă.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $this->info("Conexiune: {$connection->name} (#{$connection->id})");

            try {
                $run   = $action->execute($connection);
                $stats = $run->stats ?? [];
                $this->line('  Creat: '.(int) ($stats['created'] ?? 0).', Actualizat: '.(int) ($stats['updated'] ?? 0));
            } catch (Throwable $e) {
                $this->error("  Eroare: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
