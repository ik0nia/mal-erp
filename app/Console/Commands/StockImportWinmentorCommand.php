<?php

namespace App\Console\Commands;

use App\Actions\Winmentor\ImportWinmentorCsvAction;
use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Throwable;

class StockImportWinmentorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:import-winmentor {connectionId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import WinMentor CSV stock and prices for a connection';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionId = (int) $this->argument('connectionId');
        $connection = IntegrationConnection::query()->find($connectionId);

        if (! $connection) {
            $this->error("Connection {$connectionId} not found.");

            return self::FAILURE;
        }

        try {
            $run = (new ImportWinmentorCsvAction())->execute($connection);
            $phase = (string) data_get($run->stats, 'phase', '');

            $this->info("Import finished. SyncRun #{$run->id} - {$run->status}");
            $this->line('Stats: '.json_encode($run->stats, JSON_UNESCAPED_SLASHES));

            if ($run->status === SyncRun::STATUS_SUCCESS) {
                return self::SUCCESS;
            }

            if ($run->status === SyncRun::STATUS_RUNNING && $phase === 'pushing_prices') {
                $this->warn('Import local finalizat; push-ul prețurilor în Woo rulează în background.');

                return self::SUCCESS;
            }

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Import failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
