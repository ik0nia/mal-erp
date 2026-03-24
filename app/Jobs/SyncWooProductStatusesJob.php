<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWooProductStatusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 600;

    public function __construct(
        public readonly int $connectionId,
        public readonly int $startPage,
        public readonly int $endPage,
        public readonly int $workerIndex,
    ) {}

    public function handle(): void
    {
        $connection = IntegrationConnection::find($this->connectionId);
        if (! $connection) {
            Log::warning("SyncWooStatuses[W{$this->workerIndex}]: connection {$this->connectionId} not found");
            return;
        }

        $client  = new WooClient($connection);
        $updated = 0;
        $checked = 0;

        for ($page = $this->startPage; $page <= $this->endPage; $page++) {
            try {
                $products = $client->getProductStatuses($page);
            } catch (Throwable $e) {
                Log::error("SyncWooStatuses[W{$this->workerIndex}]: page {$page} error: " . $e->getMessage());
                break;
            }

            if (empty($products)) {
                break; // depășit ultima pagină
            }

            // woo_id => status
            $wooStatuses = [];
            foreach ($products as $p) {
                $id = (int) ($p['id'] ?? 0);
                if ($id > 0) {
                    $wooStatuses[$id] = (string) ($p['status'] ?? '');
                }
            }

            $checked += count($wooStatuses);

            WooProduct::where('connection_id', $this->connectionId)
                ->whereIn('woo_id', array_keys($wooStatuses))
                ->whereNotIn('status', ['trash']) // nu atingem produse șterse
                ->get(['id', 'woo_id', 'status'])
                ->each(function (WooProduct $local) use ($wooStatuses, &$updated): void {
                    $newStatus = $wooStatuses[(int) $local->woo_id] ?? null;
                    if ($newStatus && $local->status !== $newStatus) {
                        $local->update(['status' => $newStatus]);
                        $updated++;
                    }
                });

            if (count($products) < 100) {
                break; // ultima pagină parțială
            }
        }

        Log::info(sprintf(
            'SyncWooStatuses[W%d]: pages %d–%d | checked %d | updated %d',
            $this->workerIndex,
            $this->startPage,
            $this->endPage,
            $checked,
            $updated,
        ));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(class_basename(static::class) . ' failed', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }
}
