<?php

namespace App\Jobs;

use App\Actions\WooCommerce\ImportWooProductsAction;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportWooProductsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $connectionId) {}

    /**
     * Execute the job.
     */
    public function handle(ImportWooProductsAction $action): void
    {
        $connection = IntegrationConnection::query()->findOrFail($this->connectionId);
        $action->execute($connection);
    }
}
