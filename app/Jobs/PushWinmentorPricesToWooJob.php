<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use App\Services\WooCommerce\WooDirectSqlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushWinmentorPricesToWooJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<int, array{row:int, sku:string, woo_id:int, regular_price:string}>  $updates
     */
    public function __construct(
        public int $syncRunId,
        public int $wooConnectionId,
        public array $updates,
    ) {}

    public function handle(): void
    {
        if ($this->updates === []) {
            return;
        }

        $run = SyncRun::query()->find($this->syncRunId);

        if (! $run || $run->status === SyncRun::STATUS_CANCELLED) {
            Log::warning('Deferred Woo price push skipped: run missing or cancelled', [
                'sync_run_id' => $this->syncRunId,
                'woo_connection_id' => $this->wooConnectionId,
                'updates' => count($this->updates),
            ]);

            return;
        }

        Log::info('Deferred Woo price push job started (direct SQL)', [
            'sync_run_id' => $this->syncRunId,
            'woo_connection_id' => $this->wooConnectionId,
            'updates' => count($this->updates),
        ]);

        $successCount = 0;
        $failureCount = 0;
        $newErrors = [];

        $batchPayload = [];

        foreach ($this->updates as $update) {
            $wooId = (int) ($update['woo_id'] ?? 0);
            $regularPrice = trim((string) ($update['regular_price'] ?? ''));

            if ($wooId <= 0 || $regularPrice === '') {
                $failureCount++;
                $newErrors[] = [
                    'row' => $update['row'] ?? null,
                    'sku' => $update['sku'] ?? null,
                    'message' => 'Invalid price update payload for Woo push.',
                ];

                continue;
            }

            $batchPayload[] = [
                'id' => $wooId,
                'regular_price' => $regularPrice,
            ];
        }

        if ($batchPayload !== []) {
            try {
                $directSql = new WooDirectSqlService;
                $result = $directSql->updatePrices($batchPayload);
                $successCount = $result['updated'];
                $failureCount += $result['failed'];

                if ($result['failed'] > 0) {
                    $newErrors[] = [
                        'message' => "Direct SQL price push: {$result['failed']} failed",
                    ];
                }
            } catch (Throwable $e) {
                $failureCount += count($batchPayload);
                $newErrors[] = [
                    'message' => 'Direct SQL price push failed: '.$e->getMessage(),
                ];

                Log::error('Direct SQL price push failed', [
                    'sync_run_id' => $this->syncRunId,
                    'error' => $e->getMessage(),
                    'batch_size' => count($batchPayload),
                ]);
            }
        }

        $this->recordProgress($successCount, $failureCount, $newErrors);

        Log::info('Deferred Woo price push job finished', [
            'sync_run_id' => $this->syncRunId,
            'woo_connection_id' => $this->wooConnectionId,
            'success' => $successCount,
            'failures' => $failureCount,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Deferred Woo price push job failed', [
            'sync_run_id' => $this->syncRunId,
            'woo_connection_id' => $this->wooConnectionId,
            'updates' => count($this->updates),
            'error' => $exception->getMessage(),
        ]);

        $this->recordProgress(0, count($this->updates), [
            [
                'message' => 'Deferred Woo price push job failed: '.$exception->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $newErrors
     */
    private function recordProgress(int $successCount, int $failureCount, array $newErrors): void
    {
        if ($successCount <= 0 && $failureCount <= 0 && $newErrors === []) {
            return;
        }

        DB::transaction(function () use ($successCount, $failureCount, $newErrors): void {
            $run = SyncRun::query()
                ->lockForUpdate()
                ->find($this->syncRunId);

            if (! $run) {
                return;
            }

            $stats = is_array($run->stats) ? $run->stats : [];
            $stats['site_price_updates'] = (int) ($stats['site_price_updates'] ?? 0) + $successCount;
            $stats['site_price_update_failures'] = (int) ($stats['site_price_update_failures'] ?? 0) + $failureCount;
            $stats['site_price_push_processed'] = (int) ($stats['site_price_push_processed'] ?? 0) + $successCount + $failureCount;
            $stats['site_price_push_queued'] = max(
                0,
                (int) ($stats['site_price_push_queued'] ?? 0) - ($successCount + $failureCount)
            );
            $stats['phase'] = 'pushing_prices';
            $stats['last_heartbeat_at'] = now()->toIso8601String();

            $errors = is_array($run->errors) ? $run->errors : [];

            foreach ($newErrors as $error) {
                if (count($errors) >= 200) {
                    break;
                }

                $errors[] = $error;
            }

            $run->update([
                'stats' => $stats,
                'errors' => $errors,
            ]);

            if ((int) ($stats['site_price_push_queued'] ?? 0) <= 0 && $run->status !== SyncRun::STATUS_CANCELLED) {
                $finalStatus = (int) ($stats['site_price_update_failures'] ?? 0) > 0
                    ? SyncRun::STATUS_FAILED
                    : SyncRun::STATUS_SUCCESS;

                $stats['phase'] = $finalStatus === SyncRun::STATUS_SUCCESS ? 'completed' : 'completed_with_errors';
                $stats['push_finished_at'] = now()->toIso8601String();
                $stats['last_heartbeat_at'] = now()->toIso8601String();

                $run->update([
                    'status' => $finalStatus,
                    'finished_at' => Carbon::now(),
                    'stats' => $stats,
                    'errors' => $errors,
                ]);

                Log::info('Deferred Woo price push stage completed', [
                    'sync_run_id' => $run->id,
                    'status' => $finalStatus,
                    'site_price_updates' => $stats['site_price_updates'] ?? 0,
                    'site_price_update_failures' => $stats['site_price_update_failures'] ?? 0,
                ]);
            }
        });
    }
}
