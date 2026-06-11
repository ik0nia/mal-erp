<?php

namespace App\Jobs;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReception;
use App\Services\Winmentor\PushComenziFurnizoriService;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PushComenziFurnizoriToWinmentorJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 600; // 300 articole noi × ~300ms creare + fetch bulk

    public int $uniqueFor = 660;

    public function __construct(
        public readonly int $purchaseOrderId,
    ) {}

    public function uniqueId(): string
    {
        return 'po-' . $this->purchaseOrderId;
    }

    public function handle(): void
    {
        // Lock partajat cu PushReceptionToWinmentorJob — același document în WinMentor
        $lock = Cache::lock("winmentor-po-push-{$this->purchaseOrderId}", 660);

        if (! $lock->get()) {
            Log::channel('daily')->info('[WinMentor ComenziFurnizori] Push deja în curs pentru acest PO, skip', ['id' => $this->purchaseOrderId]);
            return;
        }

        try {
            $this->push();
        } finally {
            $lock->release();
        }
    }

    private function push(): void
    {
        // Re-fetch SUB lock — alt job poate să fi sincronizat între dispatch și acum
        $po = PurchaseOrder::with(['supplier', 'items.product', 'receptions'])->find($this->purchaseOrderId);

        if (! $po) {
            Log::channel('daily')->warning('[WinMentor ComenziFurnizori] PO nu a fost găsit', ['id' => $this->purchaseOrderId]);
            return;
        }

        // Dacă a fost deja sincronizat (retry după succes), nu re-trimitem
        if ($po->winmentor_sync_status === PurchaseOrder::WINMENTOR_SYNCED) {
            Log::channel('daily')->info('[WinMentor ComenziFurnizori] PO deja sincronizat, skip', ['po' => $po->number]);
            return;
        }

        // Dacă o recepție a importat deja documentul (același NrDoc), nu-l mai trimitem și noi
        $syncedReception = $po->receptions
            ->firstWhere('winmentor_sync_status', PurchaseOrderReception::WINMENTOR_SYNCED);

        if ($syncedReception) {
            $po->update([
                'winmentor_sync_status' => PurchaseOrder::WINMENTOR_SYNCED,
                'winmentor_sync_error'  => null,
                'winmentor_synced_at'   => $syncedReception->winmentor_synced_at ?? now(),
            ]);
            Log::channel('daily')->info('[WinMentor ComenziFurnizori] Document deja importat prin recepție, marcat synced', ['po' => $po->number]);
            return;
        }

        if ($po->receptions->firstWhere('winmentor_sync_status', PurchaseOrderReception::WINMENTOR_PENDING)) {
            Log::channel('daily')->info('[WinMentor ComenziFurnizori] Recepție în curs de sincronizare, skip', ['po' => $po->number]);
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
