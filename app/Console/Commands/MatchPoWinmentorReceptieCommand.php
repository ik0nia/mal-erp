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
                            {--date-tolerance=5 : Zile toleranță înainte de sent_at pentru data_intrare}
                            {--rematch : Rematch și PO-urile deja asociate}
                            {--dry-run : Afișează fără a salva}';

    protected $description = 'Asociază PO-urile cu recepțiile contabile din WinMentor';

    public function handle(): int
    {
        $firma         = $this->option('firma');
        $minScore      = (int) $this->option('min-score');
        $dateTolerance = (int) $this->option('date-tolerance');
        $rematch       = $this->option('rematch');
        $dryRun        = $this->option('dry-run');

        // Resetăm matched_at pentru PO-uri received fără NIR și mai noi de 60 zile
        // — permite re-matching automat când factura apare luna viitoare în WinMentor
        if (! $dryRun) {
            PurchaseOrder::whereIn('status', ['sent', 'received'])
                ->whereNull('winmentor_receptie_nr')
                ->whereNotNull('winmentor_receptie_matched_at')
                ->where(fn ($q) => $q
                    ->whereNull('received_at')
                    ->orWhere('received_at', '>=', now()->subDays(60))
                )
                ->update(['winmentor_receptie_matched_at' => null]);
        }

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
            $result = $this->matchPo($po, $firma, $minScore, $dateTolerance, $dryRun);

            if ($result) {
                $matched++;
                $allNrsStr = count($result['nrs']) > 1 ? implode(', ', $result['nrs']) : $result['nr_doc'];
                $this->line("  ✓ {$po->number} → nr_doc={$allNrsStr} data={$result['date']} scor={$result['score']}%");
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

    private function resolvePaymentTermDays(?\App\Models\Supplier $supplier): int
    {
        if (! $supplier) return 0;

        $conditions = $supplier->conditions ?? [];
        $term       = $conditions['payment']['default_term'] ?? null;
        $netDays    = $conditions['payment']['net_days'] ?? null;

        return match($term) {
            'net_7'         => 7,
            'net_14'        => 14,
            'net_30'        => 30,
            'net_45'        => 45,
            'net_60'        => 60,
            'net_90'        => 90,
            'custom'        => (int) ($netDays ?? 0),
            default         => 0,
        };
    }

    private function updatePricesFromWm(PurchaseOrder $po, array $nrDocs): void
    {
        // nr_doc nu e unic între furnizori — fără filtru part_id am putea prelua prețul
        // altui furnizor cu același nr_doc (același EAN) și am corupe last_purchase_price.
        $partId = $po->supplier?->winmentor_id;

        $wmLines = DB::table('winmentor_intrari_raw')
            ->whereIn('nr_doc', $nrDocs)
            ->when($partId, fn ($q) => $q->where('part_id', $partId))
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

    private function matchPo(PurchaseOrder $po, string $firma, int $minScore, int $dateTolerance, bool $dryRun): ?array
    {
        $partId = $po->supplier?->winmentor_id;
        if (! $partId) return null;

        // Toate SKU-urile din PO (folosite ca numitor principal pentru scor)
        $allPoSkus = $po->items
            ->filter(fn ($item) => $item->sku)
            ->pluck('sku')
            ->unique()
            ->values()
            ->all();

        // SKU-urile efectiv recepționate — fallback când nicio factură nu trece pragul cu numitorul complet
        // (ex: furnizor trimite facturi separate per produs)
        $receivedSkus = $po->items
            ->filter(fn ($item) => $item->sku && (float) $item->received_quantity > 0)
            ->pluck('sku')
            ->unique()
            ->values()
            ->all();

        $poSkus = $allPoSkus;

        if (empty($poSkus)) return null;

        $sentAt = \Carbon\Carbon::parse(
            $po->sent_at?->toDateString() ?? $po->created_at->toDateString()
        )->subDays($dateTolerance)->toDateString();

        $getCandidates = function (array $skus) use ($firma, $partId, $sentAt): \Illuminate\Support\Collection {
            return DB::table('winmentor_intrari_raw')
                ->where('firma', $firma)
                ->where('part_id', $partId)
                ->where('data_intrare', '>=', $sentAt)
                ->whereIn('sku', $skus)
                ->select('nr_doc', 'data_intrare', DB::raw('COUNT(DISTINCT sku) as sku_count'))
                ->groupBy('nr_doc', 'data_intrare')
                ->orderBy('data_intrare')
                ->get();
        };

        $getBest = function (\Illuminate\Support\Collection $candidates, int $total): array {
            $best = null; $bestScore = 0;
            foreach ($candidates as $c) {
                $score = (int) round($c->sku_count / $total * 100);
                if ($score > $bestScore) { $bestScore = $score; $best = $c; }
            }
            return [$best, $bestScore];
        };

        // Trecerea 1: numitor = toate SKU-urile PO
        $candidates = $getCandidates($allPoSkus);
        [$best, $bestScore] = $getBest($candidates, count($allPoSkus));

        // Trecerea 2 (fallback): numitor = doar SKU-urile recepționate fizic
        // Folosit când furnizorul trimite facturi separate per produs și scorul din trecerea 1 e sub prag
        if ((! $best || $bestScore < $minScore) && ! empty($receivedSkus) && $receivedSkus !== $allPoSkus) {
            $candidates2 = $getCandidates($receivedSkus);
            [$best2, $bestScore2] = $getBest($candidates2, count($receivedSkus));
            if ($best2 && $bestScore2 >= $minScore) {
                $best      = $best2;
                $bestScore = $bestScore2;
            }
        }

        if (! $best || $bestScore < $minScore) return null;

        // Colectează TOATE NIR-urile care conțin SKU-uri din acest PO
        // (un PO poate fi acoperit de mai multe facturi separate)
        $allNirs = DB::table('winmentor_intrari_raw')
            ->where('firma', $firma)
            ->where('part_id', $partId)
            ->where('data_intrare', '>=', $sentAt)
            ->whereIn('sku', $allPoSkus)
            ->select('nr_doc', 'data_intrare')
            ->distinct()
            ->orderBy('data_intrare')
            ->pluck('nr_doc')
            ->unique()
            ->values()
            ->all();

        if (! $dryRun) {
            $updates = [
                'winmentor_receptie_nr'         => $best->nr_doc,
                'winmentor_receptie_nrs'         => $allNirs,
                'winmentor_receptie_date'        => $best->data_intrare,
                'winmentor_receptie_score'       => $bestScore,
                'winmentor_receptie_matched_at'  => now(),
            ];

            // Auto-completează nr. factură furnizor dacă nu e deja setat manual
            if (blank($po->invoice_number)) {
                $updates['invoice_number'] = $best->nr_doc;
                $updates['invoice_date']   = $best->data_intrare;
            }

            // Calculează scadența din termenul de plată al furnizorului (independent de invoice_number)
            if (blank($po->invoice_due_date)) {
                $netDays = $this->resolvePaymentTermDays($po->supplier);
                if ($netDays > 0) {
                    $invoiceDate = $updates['invoice_date'] ?? $po->invoice_date ?? $best->data_intrare;
                    $updates['invoice_due_date'] = \Carbon\Carbon::parse($invoiceDate)->addDays($netDays)->toDateString();
                }
            }

            // Dacă PO era în status sent și avem o recepție confirmată → trecem pe received
            if ($po->status === 'sent' && $bestScore >= 80) {
                $updates['status']      = 'received';
                $updates['received_at'] = $best->data_intrare;
            }

            // Lead time: zile calendaristice de la data trimiterii până la data recepției fizice
            $receivedAt = $po->received_at ?? ($updates['received_at'] ?? null);
            if ($po->sent_at && $receivedAt) {
                $updates['lead_time_days'] = (int) \Carbon\Carbon::parse($po->sent_at)
                    ->startOfDay()
                    ->diffInDays(\Carbon\Carbon::parse($receivedAt)->startOfDay());
            }

            // Lag recepție contabilă: zile calendaristice de la recepția fizică până la intrarea în WinMentor
            if ($receivedAt && $best->data_intrare) {
                $updates['receptie_contabila_lag_days'] = (int) abs(
                    \Carbon\Carbon::parse($receivedAt)->startOfDay()
                        ->diffInDays(\Carbon\Carbon::parse($best->data_intrare)->startOfDay())
                );
            }

            $po->update($updates);

            // Actualizează last_purchase_price pe product_suppliers cu prețurile reale din WM
            $this->updatePricesFromWm($po, $allNirs);
        }

        return [
            'nr_doc' => $best->nr_doc,
            'nrs'    => $allNirs,
            'date'   => $best->data_intrare,
            'score'  => $bestScore,
        ];
    }
}
