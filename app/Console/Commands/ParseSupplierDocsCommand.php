<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSupplierDocReportJob;
use App\Jobs\ParseEmailAttachmentJob;
use App\Models\EmailMessage;
use App\Models\EmailParsedDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ParseSupplierDocsCommand extends Command
{
    protected $signature = 'email:parse-supplier-docs
                            {--fresh : Șterge procesările anterioare și reprocesează tot}
                            {--supplier= : Procesează doar furnizorul cu ID-ul specificat}
                            {--limit= : Limitează numărul de emailuri procesate}
                            {--no-report : Nu genera raportul PDF la final}
                            {--recipient=codrut@ikonia.ro : Adresa de email pentru raport}';

    protected $description = 'Parsează documentele PDF/XLSX din emailurile furnizorilor și generează raport comparativ cu WinMentor';

    public function handle(): int
    {
        $this->info('═══════════════════════════════════════════════════════');
        $this->info(' Parsare documente furnizori (PDF/XLSX/body)');
        $this->info('═══════════════════════════════════════════════════════');

        if ($this->option('fresh')) {
            $deleted = EmailParsedDocument::whereYear('created_at', now()->year)->delete();
            $this->warn("→ Șterse {$deleted} înregistrări anterioare (--fresh)");
        }

        // ── Găsim emailurile de procesat ───────────────────────────────────
        $query = EmailMessage::where('sent_at', '>=', now()->startOfYear())
            ->whereNotNull('supplier_id')
            ->where(function ($q) {
                $q->whereRaw('JSON_SEARCH(attachments, "one", "%.pdf", NULL, "$[*].name") IS NOT NULL')
                  ->orWhereRaw('JSON_SEARCH(attachments, "one", "%.xlsx", NULL, "$[*].name") IS NOT NULL')
                  ->orWhereRaw('JSON_SEARCH(attachments, "one", "%.xls", NULL, "$[*].name") IS NOT NULL')
                  ->orWhere(function ($q2) {
                      // Emailuri fără atașamente dar cu subiect relevant
                      $q2->whereNull('attachments')
                         ->orWhere('attachments', '[]')
                         ->where(function ($q3) {
                             $q3->where('subject', 'like', '%aviz%')
                                ->orWhere('subject', 'like', '%factur%')
                                ->orWhere('subject', 'like', '%comand%')
                                ->orWhere('subject', 'like', '%confirmar%');
                         });
                  });
            });

        if ($this->option('supplier')) {
            $query->where('supplier_id', $this->option('supplier'));
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $emailIds = $query->pluck('id');
        $total    = $emailIds->count();

        if ($total === 0) {
            $this->warn('Niciun email de procesat.');
            return self::SUCCESS;
        }

        $this->info("→ {$total} emailuri de procesat cu până la 10 workeri în paralel");

        // ── Construim batch-ul de job-uri ──────────────────────────────────
        $jobs     = $emailIds->map(fn($id) => new ParseEmailAttachmentJob($id))->all();
        $noReport = $this->option('no-report');
        $recipient = $this->option('recipient');

        $batch = Bus::batch($jobs)
            ->name('parse-supplier-docs-' . now()->format('Y-m-d-His'))
            ->allowFailures()
            ->onQueue('default')
            ->then(function () use ($noReport, $recipient) {
                if ($noReport) {
                    Log::info('ParseSupplierDocsCommand: procesare finalizată, raport omis (--no-report)');
                    return;
                }

                Log::info('ParseSupplierDocsCommand: batch finalizat, dispatch raport PDF');
                GenerateSupplierDocReportJob::dispatch($recipient)->onQueue('default');
            })
            ->catch(function ($batch, $e) {
                Log::error('ParseSupplierDocsCommand batch error: ' . $e->getMessage());
            })
            ->dispatch();

        $this->info("✓ Batch dispatched: {$batch->id}");
        $this->info("  Job-uri în batch: {$total}");
        $this->info("  Monitor: https://erp.malinco.ro/horizon");
        $this->newLine();
        $this->info('→ Raportul PDF va fi trimis la <' . $recipient . '> după finalizare.');

        Log::info("ParseSupplierDocsCommand: batch {$batch->id} cu {$total} job-uri dispatched");

        return self::SUCCESS;
    }
}
