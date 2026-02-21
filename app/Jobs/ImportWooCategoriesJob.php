<?php

namespace App\Jobs;

use App\Actions\WooCommerce\ImportWooCategoriesAction;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportWooCategoriesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $connectionId) {}

    /**
     * Execute the job.
     */
    public function handle(ImportWooCategoriesAction $action): void
    {
        $connection = IntegrationConnection::query()->findOrFail($this->connectionId);
        $action->execute($connection);
    }
}
