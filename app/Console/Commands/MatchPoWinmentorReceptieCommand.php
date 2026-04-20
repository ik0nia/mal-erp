<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asociază automat PO-urile cu intrările din WinMentor.
 *
 * Logică de matching:
 * 1. Furnizor: supplier.winmentor_id === intrare.part_id
 * 2. SKU-uri: cel puțin 50% din produsele PO apar în același nr_doc din intrări
 * 3. Data: data_intrare >= po.sent_at (recepția nu poate fi înainte de trimitere)
 *
 * Salvează pe PO: winmentor_receptie_nr, winmentor_receptie_date, winmentor_receptie_score
 */
class MatchPoWinmentorReceptieCommand extends Command
{
    protected $signature = 'winmentor:match-po-receptie
                            {--firma=MAL2019 : Firma WinMentor}
                            {--min-score=50 : % minim SKU-uri găsite pentru match}
                            {--rematch : Rematch și PO-urile deja asociate}
                            {--dry-run : Afișează fără a salva}';

    protected $description = 'Asociază PO-urile cu recepțiile contabile din WinMentor';

    public function handle(): int
    {
        $firma    = $this->option('firma');
        $minScore = (int) $this->option('min-score');
        $rematch  = $this->option('rematch');
        $dryRun   = $this->option('dry-run');

        // PO-uri candidate: sent sau received, cu furnizor care are winmentor_id
        $query = PurchaseOrder::with(['items.product', 'supplier'])
            ->whereIn('status', ['sent', 'received', 'approved'])
            ->whereHas('supplier', fn ($q) => $q->whereNotNull('winmentor_id'))
            ->whereNotNull('sent_at');

        if (! $rematch) {
            $query->whereNull('winmentor_receptie_matched_at');
        }

        $pos = $query->get();

        $this->info("PO-uri de procesat: {$pos->count()}");
        $this->line(str_repeat('─', 60));

        $matched  = 0;
        $noMatch  = 0;

        foreach ($pos as $po) {
            $result = $this->matchPo($po, $firma, $minScore, $dryRun);

            if ($result) {
                $matched++;
                $this->line("  ✓ {$po->number} → nr_doc={$result['nr_doc']} data={$result['date']} scor={$result['score']}%");
            } else {
                $noMatch++;
                $this->line("  - {$po->number} → nicio potrivire");
                if (! $dryRun) {
                    $po->update(['winmentor_receptie_matched_at' => now()]);
                }
            }
        }

        $this->line(str_repeat('─', 60));
        $this->info("Asociate: {$matched}, Nepotrivite: {$noMatch}");

        Log::channel('winmentor_sync')->info("[MatchPoReceptie] firma={$firma}", compact('matched', 'noMatch'));

        return self::SUCCESS;
    }

    private function updatePricesFromWm(PurchaseOrder $po, string $nrDoc): void
    {
        $wmLines = DB::table('winmentor_intrari_raw')
            ->where('nr_doc', $nrDoc)
            ->whereNotNull('pret')
            ->where('pret', '>', 0)
            ->get(['sku', 'pret', 'data_intrare'])
            ->keyBy('sku');

        if ($wmLines->isEmpty()) return;

        foreach ($po->items as $item) {
            if (! $item->sku || ! $item->woo_product_id) continue;

            $wm = $wmLines->get($item->sku);
            if (! $wm) continue;

            \App\Models\ProductSupplier::where('woo_product_id', $item->woo_product_id)
                ->where('supplier_id', $po->supplier_id)
                ->update([
                    'last_purchase_price' => $wm->pret,
                    'last_purchase_date'  => $wm->data_intrare,
                ]);
        }
    }

    private function matchPo(PurchaseOrder $po, string $firma, int $minScore, bool $dryRun): ?array
    {
        $partId = $po->supplier?->winmentor_id;
        if (! $partId) return null;

        // SKU-urile din PO
        $poSkus = $po->items
            ->filter(fn ($item) => $item->sku)
            ->pluck('sku')
            ->unique()
            ->values()
            ->all();

        if (empty($poSkus)) return null;

        $sentAt = $po->sent_at?->toDateString() ?? $po->created_at->toDateString();

        // Găsește toate documentele (nr_doc) de la același furnizor după data trimiterii
        $candidates = DB::table('winmentor_intrari_raw')
            ->where('firma', $firma)
            ->where('part_id', $partId)
            ->where('data_intrare', '>=', $sentAt)
            ->whereIn('sku', $poSkus)
            ->select('nr_doc', 'data_intrare', DB::raw('COUNT(DISTINCT sku) as sku_count'))
            ->groupBy('nr_doc', 'data_intrare')
            ->orderBy('data_intrare')
            ->get();

        if ($candidates->isEmpty()) return null;

        // Calculează scorul pentru fiecare document candidat
        $totalPoSkus = count($poSkus);
        $best        = null;
        $bestScore   = 0;

        foreach ($candidates as $c) {
            $score = (int) round($c->sku_count / $totalPoSkus * 100);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $c;
            }
        }

        if (! $best || $bestScore < $minScore) return null;

        if (! $dryRun) {
            $updates = [
                'winmentor_receptie_nr'         => $best->nr_doc,
                'winmentor_receptie_date'        => $best->data_intrare,
                'winmentor_receptie_score'       => $bestScore,
                'winmentor_receptie_matched_at'  => now(),
            ];

            // Dacă PO era în status sent și avem o recepție confirmată → trecem pe received
            if ($po->status === 'sent' && $bestScore >= 80) {
                $updates['status']      = 'received';
                $updates['received_at'] = $best->data_intrare;
            }

            // Lead time: zile de la sent_at până la recepția cantitativă
            $receivedAt = $updates['received_at'] ?? $po->received_at?->toDateString();
            if ($po->sent_at && $receivedAt) {
                $updates['lead_time_days'] = (int) \Carbon\Carbon::parse($po->sent_at)->diffInDays($receivedAt);
            }

            // Lag recepție contabilă: zile de la recepția cantitativă până la intrarea în WinMentor
            if ($receivedAt && $best->data_intrare) {
                $updates['receptie_contabila_lag_days'] = (int) abs(
                    \Carbon\Carbon::parse($receivedAt)->diffInDays($best->data_intrare)
                );
            }

            $po->update($updates);

            // Actualizează last_purchase_price pe product_suppliers cu prețurile reale din WM
            $this->updatePricesFromWm($po, $best->nr_doc);
        }

        return [
            'nr_doc' => $best->nr_doc,
            'date'   => $best->data_intrare,
            'score'  => $bestScore,
        ];
    }
}
