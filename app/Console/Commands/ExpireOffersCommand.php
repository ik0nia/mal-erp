<?php

namespace App\Console\Commands;

use App\Models\Offer;
use Illuminate\Console\Command;

class ExpireOffersCommand extends Command
{
    protected $signature = 'offers:expire';

    protected $description = 'Marchează ofertele trimise cu valabilitatea depășită ca expirate';

    public function handle(): int
    {
        // Doar ofertele TRIMISE expiră — draft-urile rămân editabile.
        $count = Offer::query()
            ->where('status', Offer::STATUS_SENT)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->update(['status' => Offer::STATUS_EXPIRED]);

        $this->info("Oferte expirate: {$count}");

        return self::SUCCESS;
    }
}
