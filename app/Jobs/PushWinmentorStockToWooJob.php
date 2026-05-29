<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use App\Services\WooCommerce\WooDirectSqlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push stock/backorders la WooCommerce pentru produse WinMentor Bridge.
 * Folosește SQL direct (SSH → MySQL) în loc de API REST.
 *
 * Fiecare entry din $updates: [ woo_id => [manage_stock, stock_quantity, backorders] ]
 */
class PushWinmentorStockToWooJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 30;

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

        $batch = [];
        foreach ($this->updates as $wooId => $data) {
            $batch[] = [
                'id' => (int) $wooId,
                'stock_quantity' => $data['stock_quantity'] ?? null,
                'stock_status' => $data['stock_status'] ?? 'instock',
                'manage_stock' => ($data['manage_stock'] ?? false) === true || ($data['manage_stock'] ?? '') === 'yes',
                'backorders' => $data['backorders'] ?? 'no',
            ];
        }

        try {
            $directSql = new WooDirectSqlService;
            $result = $directSql->updateStock($batch);

            Log::info('[WinmentorStockPush] Push stoc WooCommerce reușit (direct SQL)', [
                'sync_run_id' => $this->syncRunId,
                'updated' => $result['updated'],
                'failed' => $result['failed'],
            ]);

            if ($result['failed'] > 0) {
                Log::warning('[WinmentorStockPush] Unele update-uri au eșuat', [
                    'sync_run_id' => $this->syncRunId,
                    'failed' => $result['failed'],
                ]);
            }
        } catch (Throwable $e) {
            Log::error('[WinmentorStockPush] Eroare push stoc WooCommerce: '.$e->getMessage(), [
                'sync_run_id' => $this->syncRunId,
                'count' => count($batch),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[WinmentorStockPush] Job eșuat definitiv', [
            'sync_run_id' => $this->syncRunId,
            'woo_connection_id' => $this->wooConnectionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
