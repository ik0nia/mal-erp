<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\Courier\SamedayAwbService;
use App\Services\WooCommerce\WooDirectSqlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sincronizează căsuțele Easybox în ERP — pentru selectoarele Easybox (comandă + AWB).
 * Sursa primară: API-ul Sameday (live). Fallback: tabelul pluginului de pe site
 * (wp_sameday_locker) — atenție, acela e înghețat când harta live e activă la checkout.
 */
class SyncSamedayLockersCommand extends Command
{
    protected $signature = 'sameday:sync-lockers {--site : Folosește tabelul de pe site în loc de API-ul Sameday}';

    protected $description = 'Sincronizează căsuțele Easybox (API Sameday live; fallback site) în ERP';

    public function handle(WooDirectSqlService $site, SamedayAwbService $sameday): int
    {
        $rows = null;

        if (! $this->option('site')) {
            $connection = IntegrationConnection::query()
                ->where('provider', IntegrationConnection::PROVIDER_SAMEDAY)
                ->where('is_active', true)
                ->first();

            if ($connection) {
                try {
                    $rows = collect($sameday->getAllLockersFromApi($connection));
                    $this->info('Sursă: API Sameday ('.$rows->count().' căsuțe).');
                } catch (\Throwable $e) {
                    $this->warn('API Sameday indisponibil ('.$e->getMessage().') — fallback pe tabelul site-ului.');
                }
            }
        }

        if ($rows === null) {
            try {
                $lockers = $site->querySite(
                    'SELECT locker_id, name, county, city, address, postal_code FROM wp_sameday_locker WHERE is_testing = 0'
                );
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $rows = collect($lockers)->map(fn ($l) => [
                'locker_id'   => (int) ($l['locker_id'] ?? 0),
                'name'        => $l['name'] ?? '',
                'county'      => $l['county'] ?: null,
                'city'        => $l['city'] ?: null,
                'address'     => $l['address'] ?: null,
                'postal_code' => $l['postal_code'] ?: null,
                'lat'         => null,
                'lng'         => null,
            ]);
            $this->info('Sursă: tabel site ('.$rows->count().' căsuțe).');
        }

        $rows = $rows
            ->filter(fn ($l) => ($l['locker_id'] ?? 0) > 0 && filled($l['name'] ?? null))
            ->values();

        if ($rows->count() < 100) {
            $this->error('Prea puține căsuțe primite ('.$rows->count().') — nu ating datele existente.');

            return self::FAILURE;
        }

        $now  = now();
        $rows = $rows->map(fn ($l) => $l + ['created_at' => $now, 'updated_at' => $now]);

        $rows->chunk(500)->each(fn ($chunk) => DB::table('sameday_lockers')
            ->upsert($chunk->all(), ['locker_id'], ['name', 'county', 'city', 'address', 'postal_code', 'lat', 'lng', 'updated_at']));

        DB::table('sameday_lockers')->whereNotIn('locker_id', $rows->pluck('locker_id'))->delete();

        $this->info('✓ '.$rows->count().' căsuțe Easybox sincronizate.');

        return self::SUCCESS;
    }
}
