<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Sincronizează căsuțele Easybox din tabelul pluginului Sameday de pe site
 * (wp_sameday_locker) în ERP — pentru selectorul din editorul de transport.
 */
class SyncSamedayLockersCommand extends Command
{
    protected $signature = 'sameday:sync-lockers';

    protected $description = 'Sincronizează căsuțele Easybox de pe site (wp_sameday_locker) în ERP';

    public function handle(): int
    {
        $home = posix_getpwuid(posix_geteuid())['dir'] ?? '/home/erp';
        $key  = $home.'/.ssh/id_ed25519';

        $result = Process::timeout(60)->run(
            "ssh -i {$key} -o StrictHostKeyChecking=no root@malinco.ro ".
            "\"wp --path=/var/www/malinco db query 'SELECT locker_id, name, county, city, address, postal_code FROM wp_sameday_locker WHERE is_testing=0' --allow-root --skip-column-names\""
        );

        if (! $result->successful()) {
            $this->error('SSH/wp eșuat: '.$result->errorOutput());
            return self::FAILURE;
        }

        $now  = now();
        $rows = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            $f = explode("\t", $line);
            if (count($f) < 6 || ! is_numeric($f[0])) continue;

            $rows[] = [
                'locker_id'   => (int) $f[0],
                'name'        => $f[1],
                'county'      => $f[2] ?: null,
                'city'        => $f[3] ?: null,
                'address'     => $f[4] ?: null,
                'postal_code' => $f[5] ?: null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        if (count($rows) < 100) {
            $this->error('Prea puține căsuțe primite ('.count($rows).') — nu ating datele existente.');
            return self::FAILURE;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('sameday_lockers')->upsert($chunk, ['locker_id'], ['name', 'county', 'city', 'address', 'postal_code', 'updated_at']);
        }

        // Căsuțe dispărute de pe site
        DB::table('sameday_lockers')->whereNotIn('locker_id', array_column($rows, 'locker_id'))->delete();

        $this->info('✓ '.count($rows).' căsuțe Easybox sincronizate.');
        return self::SUCCESS;
    }
}
