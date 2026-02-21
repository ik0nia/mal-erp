<?php

namespace App\Console\Commands;

use App\Actions\WooCommerce\ImportWooCategoriesAction;
use App\Models\IntegrationConnection;
use Illuminate\Console\Command;
use Throwable;

class WooImportCategoriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woo:import-categories {connectionId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import WooCommerce categories for a connection';

    /**
     * Execute the console command.
     */
    public function handle(ImportWooCategoriesAction $action): int
    {
        $connection = IntegrationConnection::query()->find($this->argument('connectionId'));

        if (! $connection) {
            $this->error('Connection not found.');

            return self::FAILURE;
        }

        try {
            $run = $action->execute($connection);
        } catch (Throwable $exception) {
            $this->error('Import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $stats = $run->stats ?? [];

        $this->info("Run #{$run->id} completed with status {$run->status}.");
        $this->line('Created: '.(int) ($stats['created'] ?? 0));
        $this->line('Updated: '.(int) ($stats['updated'] ?? 0));
        $this->line('Pages: '.(int) ($stats['pages'] ?? 0));

        return self::SUCCESS;
    }
}
