<?php

namespace App\Console\Commands;

use App\Jobs\DownloadToyaImageJob;
use App\Models\WooProduct;
use Illuminate\Console\Command;

class DownloadToyaImagesCommand extends Command
{
    protected $signature = 'toya:download-images
                            {--re-download : Re-descarcă chiar dacă URL-ul e deja local}
                            {--limit= : Limitare număr produse}';

    protected $description = 'Descarcă imaginile Toya din pim.toya.pl local (dispatchez jobs prin Horizon)';

    public function handle(): int
    {
        $reDownload = (bool) $this->option('re-download');
        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;

        $query = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNotNull('main_image_url')
            ->where('main_image_url', '!=', '');

        if (! $reDownload) {
            // Only products that still point to pim.toya.pl
            $query->where('main_image_url', 'like', '%pim.toya.pl%');
        }

        if ($limit) {
            $query->limit($limit);
        }

        $total = $query->count();
        $this->info("Dispatch {$total} joburi de download imagini Toya → Horizon");

        $dispatched = 0;
        $query->select(['id', 'main_image_url'])->chunkById(500, function ($products) use (&$dispatched) {
            foreach ($products as $product) {
                DownloadToyaImageJob::dispatch($product->id, $product->main_image_url)
                    ->onQueue('default');
                $dispatched++;
            }
            $this->line("  Dispatched: {$dispatched}");
        });

        $this->info("Done! {$dispatched} joburi în coadă. Urmărește în Horizon: https://erp.malinco.ro/horizon");

        return self::SUCCESS;
    }
}
