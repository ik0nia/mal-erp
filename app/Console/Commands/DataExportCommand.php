<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Export complet al datelor (portabilitate, contract Art. 7) — dump SQL standard, gzip.
 * STRICT READ-ONLY: mysqldump execută doar SELECT-uri; nu modifică nicio dată.
 */
class DataExportCommand extends Command
{
    protected $signature = 'data:export {--tables= : listă de tabele separate prin virgulă (implicit: toate)}';

    protected $description = 'Exportă datele într-un dump SQL standard (gzip) pentru portabilitate (Art. 7)';

    public function handle(): int
    {
        $db = config('database.connections.mysql');
        $dir = storage_path('app/data-export');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $path = $dir.'/export-'.now()->format('Ymd-His').'.sql.gz';
        $errFile = tempnam(sys_get_temp_dir(), 'dumperr');

        $tables = '';
        if ($t = $this->option('tables')) {
            $tables = ' '.collect(explode(',', $t))->map(fn ($x) => escapeshellarg(trim($x)))->implode(' ');
        }

        $cmd = sprintf(
            'MYSQL_PWD=%s mysqldump --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4 -h%s -P%s -u%s %s%s 2>%s | gzip > %s',
            escapeshellarg((string) $db['password']),
            escapeshellarg((string) $db['host']),
            escapeshellarg((string) $db['port']),
            escapeshellarg((string) $db['username']),
            escapeshellarg((string) $db['database']),
            $tables,
            escapeshellarg($errFile),
            escapeshellarg($path),
        );

        $this->info('Generez exportul... (read-only)');
        exec($cmd, $out, $code);

        $err = trim((string) @file_get_contents($errFile));
        @unlink($errFile);

        if ($code !== 0 || ! is_file($path) || filesize($path) < 100) {
            @unlink($path);
            $this->error('Export eșuat (cod '.$code.').'.($err ? ' '.$err : ''));

            return self::FAILURE;
        }

        $sizeMb = round(filesize($path) / 1048576, 1);
        $this->info("Export gata: {$path} ({$sizeMb} MB)");
        if ($err) {
            $this->warn('Avertismente mysqldump: '.$err);
        }

        return self::SUCCESS;
    }
}
