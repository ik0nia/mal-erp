<?php

namespace App\Jobs;

use App\Models\PurchaseOrder;
use App\Services\Winmentor\PushComenziFurnizoriService;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PushBatchComenziFurnizoriToWinmentorJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 600;

    public function __construct(
        public readonly array $purchaseOrderIds,
    ) {}

    public function handle(): void
    {
        $orders = PurchaseOrder::with(['supplier', 'items.product'])
            ->whereIn('id', $this->purchaseOrderIds)
            ->get();

        if ($orders->isEmpty()) {
            Log::channel('daily')->warning('[WinMentor BatchComenziFurnizori] Niciun PO găsit', ['ids' => $this->purchaseOrderIds]);
            return;
        }

        $service = new PushComenziFurnizoriService(new WinmentorBridgeClient());
        $service->pushBatch($orders);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('daily')->error('[WinMentor BatchComenziFurnizori] Job eșuat', [
            'purchase_order_ids' => $this->purchaseOrderIds,
            'error'              => $e->getMessage(),
        ]);

        PurchaseOrder::whereIn('id', $this->purchaseOrderIds)->update([
            'winmentor_sync_status' => PurchaseOrder::WINMENTOR_FAILED,
            'winmentor_sync_error'  => 'Job eșuat: ' . $e->getMessage(),
        ]);
    }
}
