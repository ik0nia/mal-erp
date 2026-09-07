<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WooDirectSqlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sincronizează căsuțele Easybox din tabelul pluginului Sameday de pe site
 * (wp_sameday_locker) în ERP — pentru selectoarele Easybox (comandă + AWB).
 */
class SyncSamedayLockersCommand extends Command
{
    protected $signature = 'sameday:sync-lockers';

    protected $description = 'Sincronizează căsuțele Easybox de pe site (wp_sameday_locker) în ERP';

    public function handle(WooDirectSqlService $site): int
    {
        try {
            $lockers = $site->querySite(
                'SELECT locker_id, name, county, city, address, postal_code FROM wp_sameday_locker WHERE is_testing = 0'
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (count($lockers) < 100) {
            $this->error('Prea puține căsuțe primite ('.count($lockers).') — nu ating datele existente.');
            return self::FAILURE;
        }

        $now  = now();
        $rows = collect($lockers)
            ->filter(fn ($l) => is_numeric($l['locker_id'] ?? null) && filled($l['name'] ?? null))
            ->map(fn ($l) => [
                'locker_id'   => (int) $l['locker_id'],
                'name'        => $l['name'],
                'county'      => $l['county'] ?: null,
                'city'        => $l['city'] ?: null,
                'address'     => $l['address'] ?: null,
                'postal_code' => $l['postal_code'] ?: null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ])
            ->values();

        $rows->chunk(500)->each(fn ($chunk) => DB::table('sameday_lockers')
            ->upsert($chunk->all(), ['locker_id'], ['name', 'county', 'city', 'address', 'postal_code', 'updated_at']));

        DB::table('sameday_lockers')->whereNotIn('locker_id', $rows->pluck('locker_id'))->delete();

        $this->info('✓ '.$rows->count().' căsuțe Easybox sincronizate.');
        return self::SUCCESS;
    }
}
