<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSupplierDocReportJob;
use App\Models\EmailParsedDocument;
use App\Services\SupplierDocMatcherService;
use Illuminate\Console\Command;

class RematchSupplierDocsCommand extends Command
{
    protected $signature   = 'email:rematch-supplier-docs
                              {--recipient=codrut@ikonia.ro : Email pentru raport}
                              {--no-report : Nu genera raport}';
    protected $description = 'Re-rulează matching WinMentor pe documentele deja parsate și regenerează raportul';

    public function handle(): int
    {
        $docs = EmailParsedDocument::where('processing_status', 'done')
            ->whereNotNull('doc_date')
            ->whereNotNull('products')
            ->whereIn('doc_type', ['aviz', 'factura', 'comanda'])
            ->get();

        $this->info("Re-matching {$docs->count()} documente...");

        $matcher = app(SupplierDocMatcherService::class);
        $bar     = $this->output->createProgressBar($docs->count());
        $bar->start();

        $matched = 0; $partial = 0; $unmatched = 0; $noEntry = 0;

        foreach ($docs as $doc) {
            $matcher->match($doc);
            match($doc->fresh()->match_status) {
                'matched'   => $matched++,
                'partial'   => $partial++,
                'unmatched' => $unmatched++,
                default     => $noEntry++,
            };
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Matched: $matched | Partial: $partial | Unmatched: $unmatched | No entry: $noEntry");

        if (! $this->option('no-report')) {
            $recipient = $this->option('recipient');
            GenerateSupplierDocReportJob::dispatch($recipient);
            $this->info("Raport dispatched → $recipient");
        }

        return self::SUCCESS;
    }

}
