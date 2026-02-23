<?php

namespace App\Console\Commands;

use App\Jobs\ImportWinmentorCsvJob;
use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use Illuminate\Console\Command;

class DispatchScheduledWinmentorImportsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:dispatch-scheduled-winmentor {--connectionId=} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue WinMentor imports based on per-connection schedule settings';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionIdOption = $this->option('connectionId');
        $force = (bool) $this->option('force');

        $query = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WINMENTOR_CSV)
            ->where('is_active', true);

        if (is_numeric($connectionIdOption)) {
            $query->whereKey((int) $connectionIdOption);
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->line('No active WinMentor connections found.');

            return self::SUCCESS;
        }

        $queuedCount = 0;
        $skippedCount = 0;

        foreach ($connections as $connection) {
            if (! $force && ! $connection->shouldAutoScheduleWinmentorImport()) {
                $skippedCount++;
                continue;
            }

            $existingQueueOrRunning = SyncRun::query()
                ->where('connection_id', $connection->id)
                ->whereIn('status', [SyncRun::STATUS_QUEUED, SyncRun::STATUS_RUNNING])
                ->latest('id')
                ->first();

            if ($existingQueueOrRunning instanceof SyncRun) {
                $skippedCount++;
                continue;
            }

            $intervalMinutes = $connection->resolveWinmentorSyncIntervalMinutes();

            if (! $force) {
                $latestRun = SyncRun::query()
                    ->where('connection_id', $connection->id)
                    ->where('type', SyncRun::TYPE_WINMENTOR_STOCK)
                    ->latest('started_at')
                    ->first();

                if ($latestRun?->started_at && $latestRun->started_at->gt(now()->subMinutes($intervalMinutes))) {
                    $skippedCount++;
                    continue;
                }
            }

            $run = SyncRun::query()->create([
                'provider' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                'location_id' => $connection->location_id,
                'connection_id' => $connection->id,
                'type' => SyncRun::TYPE_WINMENTOR_STOCK,
                'status' => SyncRun::STATUS_QUEUED,
                'started_at' => now(),
                'finished_at' => null,
                'stats' => [
                    'phase' => 'queued',
                    'pages' => 1,
                    'created' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'processed' => 0,
                    'matched_products' => 0,
                    'missing_products' => 0,
                    'price_changes' => 0,
                    'name_mismatches' => 0,
                    'site_price_updates' => 0,
                    'site_price_update_failures' => 0,
                    'site_price_push_jobs' => 0,
                    'site_price_push_queued' => 0,
                    'site_price_push_processed' => 0,
                    'created_placeholders' => 0,
                    'local_started_at' => null,
                    'local_finished_at' => null,
                    'push_started_at' => null,
                    'push_finished_at' => null,
                    'last_heartbeat_at' => now()->toIso8601String(),
                    'missing_skus_sample' => [],
                    'name_mismatch_sample' => [],
                ],
                'errors' => [],
            ]);

            ImportWinmentorCsvJob::dispatch($connection->id, (int) $run->id);
            $queuedCount++;

            $this->line("Queued WinMentor import run #{$run->id} for connection #{$connection->id}.");
        }

        $this->info("Done. queued={$queuedCount}, skipped={$skippedCount}.");

        return self::SUCCESS;
    }
}
