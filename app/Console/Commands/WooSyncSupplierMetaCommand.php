<?php

namespace App\Console\Commands;

use App\Jobs\SyncProductSupplierMetaJob;
use App\Models\WooProduct;
use Illuminate\Console\Command;

class WooSyncSupplierMetaCommand extends Command
{
    protected $signature = 'woo:sync-supplier-meta
                            {--limit=0 : Limitează numărul de produse procesate (0 = toate)}';

    protected $description = 'Sincronizează furnizorul preferat în meta WooCommerce pentru toate produsele cu asociere furnizor';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $query = WooProduct::query()
            ->whereNotNull('woo_id')
            ->where('is_placeholder', false)
            ->whereHas('suppliers');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        $this->line("Produse de sincronizat: {$total}");

        if ($total === 0) {
            $this->info('Niciun produs de procesat.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->pluck('id')->each(function (int $id) use ($bar): void {
            SyncProductSupplierMetaJob::dispatch($id);
            $bar->advance();
        });

        $bar->finish();
        $this->newLine();
        $this->info("{$total} job-uri dispatch-uite în coadă.");

        return self::SUCCESS;
    }
}
