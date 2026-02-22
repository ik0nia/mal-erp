<?php

namespace App\Jobs;

use App\Actions\WooCommerce\ImportWooProductsAction;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ImportWooProductsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $connectionId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('import-woo-products-'.$this->connectionId))
                ->expireAfter($this->timeout)
                ->dontRelease(),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(ImportWooProductsAction $action): void
    {
        $connection = IntegrationConnection::query()->findOrFail($this->connectionId);
        $action->execute($connection);
    }
}
