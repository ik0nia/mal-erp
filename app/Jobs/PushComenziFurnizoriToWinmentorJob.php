<?php

namespace App\Jobs;

use App\Models\PurchaseOrder;
use App\Services\Winmentor\PushComenziFurnizoriService;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PushComenziFurnizoriToWinmentorJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 600; // 300 articole noi × ~300ms creare + fetch bulk

    public function __construct(
        public readonly int $purchaseOrderId,
    ) {}

    public function handle(): void
    {
        $po = PurchaseOrder::with(['supplier', 'items.product'])->find($this->purchaseOrderId);

        if (! $po) {
            Log::channel('daily')->warning('[WinMentor ComenziFurnizori] PO nu a fost găsit', ['id' => $this->purchaseOrderId]);
            return;
        }

        // Dacă a fost deja sincronizat (retry după succes), nu re-trimitem
        if ($po->winmentor_sync_status === PurchaseOrder::WINMENTOR_SYNCED) {
            Log::channel('daily')->info('[WinMentor ComenziFurnizori] PO deja sincronizat, skip', ['po' => $po->number]);
            return;
        }

        $service = new PushComenziFurnizoriService(new WinmentorBridgeClient());
        $service->push($po);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('daily')->error('[WinMentor ComenziFurnizori] Job eșuat', [
            'purchase_order_id' => $this->purchaseOrderId,
            'error'             => $e->getMessage(),
        ]);

        $po = PurchaseOrder::find($this->purchaseOrderId);
        $po?->update([
            'winmentor_sync_status' => PurchaseOrder::WINMENTOR_FAILED,
            'winmentor_sync_error'  => 'Job eșuat: ' . $e->getMessage(),
        ]);
    }
}
