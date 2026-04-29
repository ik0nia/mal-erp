<?php

namespace App\Console\Commands;

use App\Jobs\SyncToyaPricesJob;
use App\Models\SupplierFeed;
use Illuminate\Console\Command;

class SyncToyaPricesCommand extends Command
{
    protected $signature = 'toya:sync-prices';

    protected $description = 'Sincronizează prețurile de achiziție Toya și trimite alerte la modificări semnificative';

    public function handle(): int
    {
        $feeds = SupplierFeed::where('provider', SupplierFeed::PROVIDER_TOYA_API)
            ->where('is_active', true)
            ->get();

        if ($feeds->isEmpty()) {
            $this->warn('Niciun feed Toya API activ găsit.');
            return self::SUCCESS;
        }

        foreach ($feeds as $feed) {
            SyncToyaPricesJob::dispatch($feed->id);
            $this->info("Dispatched SyncToyaPricesJob pentru feed ID={$feed->id} (supplier_id={$feed->supplier_id}).");
        }

        return self::SUCCESS;
    }
}
