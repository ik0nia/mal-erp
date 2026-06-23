<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupReceptionDraftsCommand extends Command
{
    protected $signature = 'wh:cleanup-reception-drafts';
    protected $description = 'Șterge draft-urile de recepție ale comenzilor la care recepția cantitativă s-a finalizat (nu mai sunt în SENT/PARTIALLY_RECEIVED)';

    public function handle(): int
    {
        // Draftul are sens doar cât comanda e în recepție. Dacă a fost recepționată
        // complet (received), anulată etc. → draftul e rezidual și se curăță.
        $deleted = DB::table('purchase_order_reception_drafts')
            ->whereNotIn('purchase_order_id', function ($q) {
                $q->select('id')
                    ->from('purchase_orders')
                    ->whereIn('status', [
                        PurchaseOrder::STATUS_SENT,
                        PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                    ]);
            })
            ->delete();

        $this->info("Draft-uri recepție curățate (recepție finalizată): {$deleted}.");

        return self::SUCCESS;
    }
}
