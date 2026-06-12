<?php

namespace App\Jobs;

use App\Services\WooCommerce\WooDirectSqlService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Golește cache-ul site-ului (nginx fastcgi + Redis object cache) după
 * ce un push de preț/stoc a fost scris efectiv în baza WooCommerce.
 *
 * ShouldBeUnique + delay la dispatch: edit-urile în rafală (bulk update)
 * se coalizează într-un singur flush.
 */
class FlushWooCacheJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public int $uniqueFor = 180;

    public function handle(): void
    {
        (new WooDirectSqlService)->flushCache();
    }
}
