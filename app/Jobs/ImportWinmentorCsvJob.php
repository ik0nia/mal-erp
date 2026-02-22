<?php

namespace App\Jobs;

use App\Actions\Winmentor\ImportWinmentorCsvAction;
use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportWinmentorCsvJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $connectionId,
        public ?int $syncRunId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $connection = IntegrationConnection::query()->findOrFail($this->connectionId);
        $run = null;

        if ($this->syncRunId) {
            $run = SyncRun::query()->find($this->syncRunId);
        }

        Log::info('Winmentor import queue job picked by worker', [
            'connection_id' => $this->connectionId,
            'sync_run_id' => $this->syncRunId,
            'resolved_run_status' => $run?->status,
        ]);

        (new ImportWinmentorCsvAction())->execute($connection, $run);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Winmentor import queue job failed', [
            'connection_id' => $this->connectionId,
            'sync_run_id' => $this->syncRunId,
            'error' => $exception->getMessage(),
        ]);

        if (! $this->syncRunId) {
            return;
        }

        $run = SyncRun::query()->find($this->syncRunId);

        if (! $run) {
            return;
        }

        $stats = is_array($run->stats) ? $run->stats : [];
        $stats['phase'] = 'failed';
        $stats['last_heartbeat_at'] = now()->toIso8601String();

        $errors = is_array($run->errors) ? $run->errors : [];
        if (count($errors) < 200) {
            $errors[] = [
                'message' => 'ImportWinmentorCsvJob failed: '.$exception->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ];
        }

        $run->update([
            'status' => SyncRun::STATUS_FAILED,
            'finished_at' => now(),
            'stats' => $stats,
            'errors' => $errors,
        ]);
    }
}
