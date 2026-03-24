<?php

namespace App\Console\Commands;

use App\Jobs\GenerateToyaDescriptionsJob;
use App\Models\WooProduct;
use Illuminate\Console\Command;

class GenerateToyaDescriptionsCommand extends Command
{
    protected $signature = 'toya:generate-descriptions
                            {--limit=0        : Limitează numărul de produse (0 = toate)}
                            {--regenerate     : Re-generează și produsele care deja au descriere}
                            {--batch-size=5   : Produse per job (max 5 recomandat)}';

    protected $description = 'Generează descrieri AI (Claude Haiku) pentru produsele Toya fără descriere';

    public function handle(): int
    {
        $limit       = (int) $this->option('limit');
        $batchSize   = max(1, min(10, (int) $this->option('batch-size')));
        $regenerate  = (bool) $this->option('regenerate');

        $query = WooProduct::query()
            ->where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNotNull('name')
            ->select('id');

        if (! $regenerate) {
            $query->whereNull('description')->whereNull('short_description');
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $ids = $query->pluck('id')->all();
        $total = count($ids);

        if ($total === 0) {
            $this->info('Nicio descriere de generat.');
            return self::SUCCESS;
        }

        $chunks = array_chunk($ids, $batchSize);
        $jobCount = count($chunks);

        $this->info("Produse de procesat: {$total}");
        $this->info("Joburi dispatched: {$jobCount} (câte {$batchSize} produse/job)");

        foreach ($chunks as $chunk) {
            GenerateToyaDescriptionsJob::dispatch($chunk);
        }

        $this->info("Gata! {$jobCount} joburi adăugate în queue.");

        return self::SUCCESS;
    }
}
