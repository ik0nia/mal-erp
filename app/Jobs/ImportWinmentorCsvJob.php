<?php

namespace App\Jobs;

use App\Actions\Winmentor\ImportWinmentorCsvAction;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportWinmentorCsvJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $connectionId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $connection = IntegrationConnection::query()->findOrFail($this->connectionId);

        (new ImportWinmentorCsvAction())->execute($connection);
    }
}
