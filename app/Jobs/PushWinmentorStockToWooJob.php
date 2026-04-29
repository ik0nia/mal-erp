<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use App\Services\WooCommerce\WooClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push stock/backorders la WooCommerce pentru produse WinMentor Bridge.
 *
 * Fiecare entry din $updates: [ woo_id => [manage_stock, stock_quantity, backorders] ]
 */
class PushWinmentorStockToWooJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

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

        $wooConnection = IntegrationConnection::query()->find($this->wooConnectionId);

        if (! $wooConnection instanceof IntegrationConnection || ! $wooConnection->isWooCommerce() || ! $wooConnection->is_active) {
            Log::warning('[WinmentorStockPush] Conexiune WooCommerce indisponibilă', [
                'woo_connection_id' => $this->wooConnectionId,
            ]);
            return;
        }

        $client = new WooClient($wooConnection);
        $batch  = [];

        foreach ($this->updates as $wooId => $data) {
            $batch[] = array_merge(['id' => (int) $wooId], $data);
        }

        try {
            $client->updateProductsBatch($batch);
            Log::info('[WinmentorStockPush] Push stoc WooCommerce reușit', [
                'sync_run_id' => $this->syncRunId,
                'count'       => count($batch),
            ]);
        } catch (Throwable $e) {
            Log::error('[WinmentorStockPush] Eroare push stoc WooCommerce: ' . $e->getMessage(), [
                'sync_run_id' => $this->syncRunId,
                'count'       => count($batch),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[WinmentorStockPush] Job eșuat definitiv', [
            'sync_run_id'       => $this->syncRunId,
            'woo_connection_id' => $this->wooConnectionId,
            'error'             => $exception->getMessage(),
        ]);
    }
}
