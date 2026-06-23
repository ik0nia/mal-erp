<?php

namespace App\Console\Commands;

use App\Services\Gdpr\DataSubjectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Export GDPR — dreptul de acces / portabilitate. STRICT READ-ONLY: scrie doar un fișier
 * de export, nu modifică nicio dată existentă.
 */
class GdprExportCommand extends Command
{
    protected $signature = 'gdpr:export {identifier : email sau telefon al persoanei vizate}';

    protected $description = 'Exportă toate datele cu caracter personal ale unei persoane vizate (acces/portabilitate)';

    public function handle(DataSubjectService $svc): int
    {
        $identifier = (string) $this->argument('identifier');
        $located = $svc->locate($identifier);
        $count = $svc->countRecords($located);

        $this->info("Căutare după {$located['searched_by']}: {$identifier}");
        foreach ($located['records'] as $table => $rows) {
            if (count($rows) > 0) {
                $this->line("  {$table}: ".count($rows));
            }
        }

        if ($count === 0) {
            $this->warn('Nicio înregistrare găsită. Nu s-a generat niciun fișier.');

            return self::SUCCESS;
        }

        $name = 'gdpr-export/'.Str::slug($identifier).'-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($name, json_encode($located, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("Total {$count} înregistrări. Export: ".Storage::disk('local')->path($name));

        return self::SUCCESS;
    }
}
