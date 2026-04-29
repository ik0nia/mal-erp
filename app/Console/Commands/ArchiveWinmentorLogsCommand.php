<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Arhivează logurile WinMentor mai vechi de 30 de zile.
 * Structura:
 *   storage/logs/winmentor/bridge/bridge-YYYY-MM-DD.log  → arhivate în bridge/YYYY-MM/
 *   storage/logs/winmentor/sync/sync-YYYY-MM-DD.log      → arhivate în sync/YYYY-MM/
 *
 * Rulat lunar din scheduler (1 ale lunii la 02:00).
 */
class ArchiveWinmentorLogsCommand extends Command
{
    protected $signature = 'winmentor:archive-logs {--dry-run : Afișează ce ar fi arhivat fără a face modificări}';

    protected $description = 'Arhivează logurile WinMentor mai vechi de 30 de zile în foldere lunare ZIP';

    public function handle(): int
    {
        $dryRun   = $this->option('dry-run');
        $cutoff   = Carbon::now()->subDays(30);
        $baseDir  = storage_path('logs/winmentor');
        $archived = 0;
        $errors   = 0;

        foreach (['bridge', 'sync'] as $category) {
            $dir = "{$baseDir}/{$category}";
            if (! is_dir($dir)) continue;

            $files = glob("{$dir}/*.log");
            if (! $files) continue;

            foreach ($files as $file) {
                $basename = basename($file);

                // Extrage data din numele fișierului (ex: bridge-2026-03-15.log)
                if (! preg_match('/(\d{4}-\d{2}-\d{2})/', $basename, $m)) continue;

                $fileDate = Carbon::createFromFormat('Y-m-d', $m[1]);
                if ($fileDate->greaterThan($cutoff)) continue;

                $yearMonth = $fileDate->format('Y-m');
                $archiveDir = "{$dir}/{$yearMonth}";
                $zipPath    = "{$archiveDir}/{$basename}.gz";

                $this->line("  [{$category}] {$basename} → {$yearMonth}/");

                if ($dryRun) continue;

                if (! is_dir($archiveDir)) {
                    mkdir($archiveDir, 0755, true);
                }

                // Comprimă cu gzip
                $in  = fopen($file, 'rb');
                $out = gzopen($zipPath, 'wb9');

                if (! $in || ! $out) {
                    $this->error("  Nu pot deschide fișierele pentru {$basename}");
                    $errors++;
                    continue;
                }

                while (! feof($in)) {
                    gzwrite($out, fread($in, 65536));
                }

                fclose($in);
                gzclose($out);

                unlink($file);
                $archived++;
            }
        }

        if ($dryRun) {
            $this->info('Dry-run — nicio modificare efectuată.');
        } else {
            $this->info("Arhivat: {$archived} fișiere" . ($errors ? ", {$errors} erori" : '') . '.');
        }

        return self::SUCCESS;
    }
}
