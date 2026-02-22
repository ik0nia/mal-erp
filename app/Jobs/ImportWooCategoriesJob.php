<?php

namespace App\Jobs;

use App\Actions\WooCommerce\ImportWooCategoriesAction;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ImportWooCategoriesJob implements ShouldQueue
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
            (new WithoutOverlapping('import-woo-categories-'.$this->connectionId))
                ->expireAfter($this->timeout)
                ->dontRelease(),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(ImportWooCategoriesAction $action): void
    {
        $connection = IntegrationConnection::query()->findOrFail($this->connectionId);
        $action->execute($connection);
    }
}
