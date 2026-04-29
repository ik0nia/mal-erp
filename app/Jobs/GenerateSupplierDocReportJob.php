<?php

namespace App\Jobs;

use App\Models\EmailMessage;
use App\Models\EmailParsedDocument;
use App\Models\Supplier;
use App\Services\SupplierDocMatcherService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class GenerateSupplierDocReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(
        private readonly string $recipientEmail = 'codrut@ikonia.ro'
    ) {}

    public function handle(): void
    {
        Log::info('GenerateSupplierDocReportJob: start generare raport');

        $docs = EmailParsedDocument::with(['supplier', 'emailMessage'])
            ->whereYear('created_at', now()->year)
            ->orderBy('supplier_id')
            ->orderBy('doc_date')
            ->get();

        // ── Statistici generale ────────────────────────────────────────────
        $totalEmails = EmailMessage::where('sent_at', '>=', now()->startOfYear())
            ->whereNotNull('supplier_id')
            ->whereRaw('JSON_SEARCH(attachments, "one", "%.pdf", NULL, "$[*].name") IS NOT NULL
                        OR JSON_SEARCH(attachments, "one", "%.xlsx", NULL, "$[*].name") IS NOT NULL')
            ->count();

        $stats = [
            'total_emails' => $totalEmails,
            'total_docs'   => $docs->count(),
            'matched'      => $docs->where('match_status', 'matched')->count(),
            'partial'      => $docs->where('match_status', 'partial')->count(),
            'unmatched'    => $docs->whereIn('match_status', ['unmatched', 'no_entry'])->count(),
            'errors'       => $docs->where('processing_status', 'error')->count(),
        ];

        // ── Statistici per furnizor ────────────────────────────────────────
        $supplierStats = $docs->groupBy('supplier_id')->map(function ($group) {
            $supplier = $group->first()?->supplier;
            $discCount = $group->sum(fn($d) => count($d->discrepancies ?? []));

            return [
                'name'             => $supplier?->name ?? 'Necunoscut',
                'total'            => $group->count(),
                'aviz'             => $group->where('doc_type', 'aviz')->count(),
                'factura'          => $group->where('doc_type', 'factura')->count(),
                'other'            => $group->whereNotIn('doc_type', ['aviz', 'factura'])->count(),
                'matched'          => $group->where('match_status', 'matched')->count(),
                'partial'          => $group->where('match_status', 'partial')->count(),
                'unmatched'        => $group->whereIn('match_status', ['unmatched', 'no_entry'])->count(),
                'errors'           => $group->where('processing_status', 'error')->count(),
                'discrepancy_count' => $discCount,
            ];
        })->sortByDesc('total')->values();

        // ── Documente fără match WM ────────────────────────────────────────
        $unmatchedDocs = $docs->whereIn('match_status', ['unmatched', 'no_entry'])
            ->where('processing_status', 'done')
            ->whereIn('doc_type', ['aviz', 'factura', 'comanda'])
            ->values();

        // ── Discrepanțe grupate pe furnizor ───────────────────────────────
        $discrepanciesBySupplier = [];
        foreach ($docs->where('processing_status', 'done') as $doc) {
            if (empty($doc->discrepancies)) {
                continue;
            }
            $supplierName = $doc->supplier?->name ?? 'Necunoscut';
            $discrepanciesBySupplier[$supplierName][] = [
                'doc_type'     => $doc->doc_type,
                'doc_number'   => $doc->doc_number,
                'doc_date'     => $doc->doc_date?->format('d.m.Y'),
                'wm_doc'       => $doc->winmentor_doc_nr,
                'discrepancies' => $doc->discrepancies,
            ];
        }
        ksort($discrepanciesBySupplier);

        // ── Erori ─────────────────────────────────────────────────────────
        $errorDocs = $docs->where('processing_status', 'error')->values();

        // ── Shared: produse ERP indexate după SKU ──────────────────────────
        $productBySkuRaw = DB::table('woo_products')
            ->whereNotNull('sku')
            ->get(['id', 'sku', 'name', 'winmentor_name'])
            ->keyBy(fn($p) => ltrim($p->sku, '0'));

        // ── Comparație Aviz vs Recepție WinMentor ─────────────────────────
        $receptiiComparison = [];

        foreach ($docs->where('processing_status', 'done')->whereIn('match_status', ['matched', 'partial']) as $doc) {
            if (! $doc->winmentor_doc_nr || empty($doc->products)) continue;
            $supplier = $doc->supplier;
            if (! $supplier?->winmentor_id) continue;

            $wmRows = DB::table('winmentor_intrari_raw')
                ->where('part_id', $supplier->winmentor_id)
                ->where('nr_doc', $doc->winmentor_doc_nr)
                ->get();

            if ($wmRows->isEmpty()) continue;

            $wmSkuIdx    = $wmRows->keyBy(fn($r) => ltrim($r->sku, '0'));
            $wmForQty    = $wmRows->map(fn($r) => ['row' => $r, 'used' => false])->values()->all();
            $wmUsedSkus  = [];
            $rows        = [];

            foreach ($doc->products as $prod) {
                $gtin    = ltrim($prod['gtin'] ?? '', '0');
                $cod     = ltrim($prod['cod_furnizor'] ?? '', '0');
                $cantDoc = round((float) ($prod['cantitate'] ?? 0), 3);
                $skuFurn = $gtin ?: $cod;

                $wmRow = null;

                if ($gtin && $wmSkuIdx->has($gtin)) {
                    $wmRow = $wmSkuIdx[$gtin];
                } elseif ($cod && $wmSkuIdx->has($cod)) {
                    $wmRow = $wmSkuIdx[$cod];
                } elseif ($cantDoc > 0) {
                    foreach ($wmForQty as $i => &$item) {
                        if (! $item['used'] && abs(round((float) $item['row']->cantitate, 3) - $cantDoc) < 0.001) {
                            $wmRow = $item['row'];
                            $item['used'] = true;
                            break;
                        }
                    }
                    unset($item);
                }

                if ($wmRow) {
                    $wmUsedSkus[$wmRow->sku] = true;
                    $cantWm  = round((float) $wmRow->cantitate, 3);
                    $erpProd = $productBySkuRaw->get(ltrim($wmRow->sku, '0'));
                    $status  = abs($cantDoc - $cantWm) > 0.001 ? 'cantitate_diferita' : 'ok';
                    $rows[] = [
                        'sku_furnizor' => $skuFurn,
                        'den_furnizor' => $prod['denumire'] ?? '',
                        'cant_doc'     => $cantDoc,
                        'sku_wm'       => $wmRow->sku,
                        'den_wm'       => $erpProd?->winmentor_name ?: $erpProd?->name,
                        'cant_wm'      => $cantWm,
                        'status'       => $status,
                    ];
                } else {
                    $rows[] = [
                        'sku_furnizor' => $skuFurn,
                        'den_furnizor' => $prod['denumire'] ?? '',
                        'cant_doc'     => $cantDoc,
                        'sku_wm'       => null,
                        'den_wm'       => null,
                        'cant_wm'      => null,
                        'status'       => 'lipsa_wm',
                    ];
                }
            }

            // Intrări WM care nu apar în aviz
            foreach ($wmRows as $wmRow) {
                if (isset($wmUsedSkus[$wmRow->sku])) continue;
                $erpProd = $productBySkuRaw->get(ltrim($wmRow->sku, '0'));
                $rows[] = [
                    'sku_furnizor' => null,
                    'den_furnizor' => null,
                    'cant_doc'     => null,
                    'sku_wm'       => $wmRow->sku,
                    'den_wm'       => $erpProd?->winmentor_name ?: $erpProd?->name,
                    'cant_wm'      => round((float) $wmRow->cantitate, 3),
                    'status'       => 'intrare_fara_aviz',
                ];
            }

            $supplierName = $supplier->name ?? 'Necunoscut';
            $receptiiComparison[$supplierName][] = [
                'doc_type'    => $doc->doc_type,
                'doc_number'  => $doc->doc_number,
                'doc_date'    => $doc->doc_date?->format('d.m.Y'),
                'wm_doc'      => $doc->winmentor_doc_nr,
                'match_status' => $doc->match_status,
                'rows'        => $rows,
            ];
        }
        ksort($receptiiComparison);

        // ── Sugestii AI mapare SKU (doar pentru raport) ───────────────────
        $aiSuggestions = [];
        $matcher = app(SupplierDocMatcherService::class);

        $unmatchedBySupplier = $docs->where('processing_status', 'done')
            ->where('match_status', 'unmatched')
            ->whereIn('doc_type', ['aviz', 'factura', 'comanda'])
            ->filter(fn($d) => ! empty($d->products) && $d->supplier?->winmentor_id)
            ->groupBy('supplier_id');

        foreach ($unmatchedBySupplier as $supplierId => $supplierDocs) {
            $supplier = $supplierDocs->first()->supplier;

            // Produse unice peste toate documentele nematch-uite ale furnizorului
            $allProducts = [];
            $seen = [];
            foreach ($supplierDocs as $doc) {
                foreach (($doc->products ?? []) as $prod) {
                    $key = ltrim($prod['gtin'] ?? $prod['cod_furnizor'] ?? '', '0');
                    if ($key && ! isset($seen[$key])) {
                        $seen[$key] = true;
                        $allProducts[] = $prod;
                    }
                }
            }

            if (empty($allProducts)) continue;

            $suggestions = $matcher->getAiSuggestionsForReport($allProducts, $supplier, $productBySkuRaw);

            if (! empty($suggestions)) {
                $aiSuggestions[$supplier->name] = $suggestions;
            }
        }
        ksort($aiSuggestions);

        // ── Generare PDF ──────────────────────────────────────────────────
        $allDiscrepancies = collect($discrepanciesBySupplier)->flatten(1);

        $pdf = Pdf::loadView('pdf.supplier-docs-report', compact(
            'stats',
            'supplierStats',
            'unmatchedDocs',
            'discrepanciesBySupplier',
            'allDiscrepancies',
            'errorDocs',
            'receptiiComparison',
            'aiSuggestions'
        ))->setPaper('a4', 'landscape')
          ->setOption('dpi', 96)
          ->setOption('defaultFont', 'DejaVu Sans');

        $pdfContent = $pdf->output();
        $pdfPath    = storage_path('app/raport-furnizori-' . now()->format('Y-m-d-His') . '.pdf');
        file_put_contents($pdfPath, $pdfContent);

        // ── Compresie dacă > 24MB ──────────────────────────────────────────
        $sizeMb = filesize($pdfPath) / 1024 / 1024;
        Log::info("GenerateSupplierDocReportJob: PDF generat, {$sizeMb} MB");

        if ($sizeMb > 24) {
            $compressed = $this->compressPdf($pdfPath);
            if ($compressed && file_exists($compressed)) {
                unlink($pdfPath);
                $pdfPath = $compressed;
                $sizeMb  = filesize($pdfPath) / 1024 / 1024;
                Log::info("GenerateSupplierDocReportJob: după compresie: {$sizeMb} MB");
            }
        }

        // ── Trimitere email ────────────────────────────────────────────────
        $htmlBody  = view('emails.supplier-docs-report-body', compact('stats'))->render();
        $pdfName   = 'Raport-Furnizori-' . now()->format('Y-m-d') . '.pdf';
        $recipient = $this->recipientEmail;

        Mail::html($htmlBody, function ($message) use ($pdfPath, $pdfName, $recipient) {
            $message->to($recipient)
                ->subject('Raport Documente Furnizori ' . now()->format('d.m.Y'))
                ->attach($pdfPath, [
                    'as'   => $pdfName,
                    'mime' => 'application/pdf',
                ]);
        });

        Log::info("GenerateSupplierDocReportJob: raport trimis la {$this->recipientEmail} ({$sizeMb} MB)");

        // Curățăm PDF-ul local după trimitere
        @unlink($pdfPath);
    }

    private function compressPdf(string $inputPath): ?string
    {
        $outputPath = str_replace('.pdf', '-compressed.pdf', $inputPath);

        // Verificăm dacă ghostscript e disponibil
        exec('which gs 2>/dev/null', $out);
        if (empty($out)) {
            Log::warning('GenerateSupplierDocReportJob: ghostscript indisponibil, PDF necomprimat');
            return null;
        }

        $cmd = sprintf(
            'gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 '
            . '-dPDFSETTINGS=/ebook -sOutputFile=%s %s 2>&1',
            escapeshellarg($outputPath),
            escapeshellarg($inputPath)
        );

        exec($cmd, $output, $code);

        if ($code !== 0 || ! file_exists($outputPath)) {
            Log::warning('GenerateSupplierDocReportJob: compresie gs eșuată: ' . implode(' ', $output));
            return null;
        }

        return $outputPath;
    }
}
