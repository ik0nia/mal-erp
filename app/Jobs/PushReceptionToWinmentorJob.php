<?php

namespace App\Jobs;

use App\Models\PurchaseOrderReception;
use App\Services\Winmentor\PushComenziFurnizoriService;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PushReceptionToWinmentorJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 600;

    public int $uniqueFor = 660;

    public function __construct(
        public readonly int $receptionId,
    ) {}

    public function uniqueId(): string
    {
        return 'reception-' . $this->receptionId;
    }

    public function handle(): void
    {
        $reception = PurchaseOrderReception::find($this->receptionId);

        if (! $reception) {
            Log::channel('daily')->warning('[WinMentor Recepție] Recepție nu a fost găsită', ['id' => $this->receptionId]);
            return;
        }

        // Lock partajat cu PushComenziFurnizoriToWinmentorJob — același document în WinMentor
        $lock = Cache::lock("winmentor-po-push-{$reception->purchase_order_id}", 660);

        if (! $lock->get()) {
            Log::channel('daily')->info('[WinMentor Recepție] Push deja în curs pentru acest PO, skip', ['id' => $this->receptionId]);
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
        $reception = PurchaseOrderReception::with([
            'purchaseOrder.supplier',
            'purchaseOrder.items.product',
            'items',
        ])->find($this->receptionId);

        if ($reception->winmentor_sync_status === PurchaseOrderReception::WINMENTOR_SYNCED) {
            Log::channel('daily')->info('[WinMentor Recepție] Deja sincronizat, skip', ['id' => $this->receptionId]);
            return;
        }

        // Dacă PO-ul a fost deja importat ca document (job PO sau retry), nu-l duplicăm
        $po = $reception->purchaseOrder;
        if ($po && $po->winmentor_sync_status === \App\Models\PurchaseOrder::WINMENTOR_SYNCED
            && (int) $reception->reception_number === 1) {
            $reception->update([
                'winmentor_sync_status' => PurchaseOrderReception::WINMENTOR_SYNCED,
                'winmentor_sync_error'  => null,
                'winmentor_synced_at'   => $po->winmentor_synced_at ?? now(),
            ]);
            Log::channel('daily')->info('[WinMentor Recepție] Document deja importat la nivel de PO, marcat synced', ['id' => $this->receptionId]);
            return;
        }

        $service = new PushComenziFurnizoriService(new WinmentorBridgeClient());
        $service->pushReception($reception);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('daily')->error('[WinMentor Recepție] Job eșuat', [
            'reception_id' => $this->receptionId,
            'error'        => $e->getMessage(),
        ]);

        PurchaseOrderReception::find($this->receptionId)?->update([
            'winmentor_sync_status' => PurchaseOrderReception::WINMENTOR_FAILED,
            'winmentor_sync_error'  => 'Job eșuat: ' . $e->getMessage(),
        ]);
    }
}
