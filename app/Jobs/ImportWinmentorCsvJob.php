<?php

namespace App\Jobs;

use App\Actions\Winmentor\ImportWinmentorCsvAction;
use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ImportWinmentorCsvJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 200;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $connectionId,
        public ?int $syncRunId = null,
    ) {}

    /**
     * Prevent duplicate imports for same connection.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('import-winmentor-'.$this->connectionId))
                ->expireAfter($this->timeout)
                ->releaseAfter(10),
        ];
    }

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

        (new ImportWinmentorCsvAction())->execute($connection, $run);
    }
}
