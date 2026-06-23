<?php

namespace App\Console\Commands;

use App\Jobs\PushComenziFurnizoriToWinmentorJob;
use App\Models\PurchaseOrder;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;

class RetryFailedWinmentorPoSyncCommand extends Command
{
    protected $signature = 'winmentor:retry-failed-po-sync';

    protected $description = 'Retrimite PO-urile failed sau nesincronizate (NULL) la WinMentor';

    public function handle(): int
    {
        $failed = PurchaseOrder::where(function ($q) {
                $q->where('winmentor_sync_status', PurchaseOrder::WINMENTOR_FAILED)
                  ->orWhereNull('winmentor_sync_status');
            })
            // Doar PO-uri recepționate: comanda furnizori în WinMentor se construiește
            // din cantitatea recepționată. Cele doar SENT (0 recepționat) ar pica mereu.
            ->whereIn('status', [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED])
            ->whereHas('items', fn ($q) => $q->where('received_quantity', '>', 0))
            ->get();

        if ($failed->isEmpty()) {
            $this->info('Niciun PO de (re)sincronizat.');

            return self::SUCCESS;
        }

        // Check bridge health + firma selection before retrying
        try {
            $client = app(WinmentorBridgeClient::class);
            if (! $client->isReachable()) {
                $this->warn('MentorAPI indisponibil sau COM not connected — skip retry.');

                return self::SUCCESS;
            }

            // Clear cached firma selection so selectFirma actually tests the connection
            \Illuminate\Support\Facades\Cache::forget("winmentor_firma_selected_MAL2019_" . now()->year . '_' . now()->month);
            $client->selectFirma();
        } catch (\Throwable $e) {
            $this->warn('Bridge indisponibil: ' . $e->getMessage());

            return self::SUCCESS;
        }

        $this->info("Bridge OK — retrimitem {$failed->count()} PO-uri...");

        foreach ($failed as $po) {
            // updateQuietly: hook-ul din model dispatchează și el la trecerea pe PENDING —
            // cu update() normal jobul pleca de două ori per retry
            $po->updateQuietly([
                'winmentor_sync_status' => PurchaseOrder::WINMENTOR_PENDING,
                'winmentor_sync_error'  => null,
            ]);
            PushComenziFurnizoriToWinmentorJob::dispatch($po->id)->afterCommit();
            $this->line("  → {$po->number} ({$po->status}) dispatch");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
