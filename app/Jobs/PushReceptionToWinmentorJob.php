<?php

namespace App\Jobs;

use App\Models\PurchaseOrderReception;
use App\Services\Winmentor\PushComenziFurnizoriService;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PushReceptionToWinmentorJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 600;

    public function __construct(
        public readonly int $receptionId,
    ) {}

    public function handle(): void
    {
        $reception = PurchaseOrderReception::with([
            'purchaseOrder.supplier',
            'purchaseOrder.items.product',
            'items',
        ])->find($this->receptionId);

        if (! $reception) {
            Log::channel('daily')->warning('[WinMentor Recepție] Recepție nu a fost găsită', ['id' => $this->receptionId]);
            return;
        }

        if ($reception->winmentor_sync_status === PurchaseOrderReception::WINMENTOR_SYNCED) {
            Log::channel('daily')->info('[WinMentor Recepție] Deja sincronizat, skip', ['id' => $this->receptionId]);
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
