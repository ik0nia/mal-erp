<?php

namespace App\Console\Commands;

use App\Services\Gdpr\DataSubjectService;
use App\Services\Gdpr\ErasureService;
use Illuminate\Console\Command;

/**
 * Ștergere GDPR („dreptul de a fi uitat"). IMPLICIT DRY-RUN — nu modifică nimic.
 * Anonimizează DOAR cu --apply + confirmare prin tastarea identificatorului. Niciodată programată.
 */
class GdprEraseCommand extends Command
{
    protected $signature = 'gdpr:erase {identifier : email/telefon} {--apply : Aplică efectiv anonimizarea (implicit: doar raport)}';

    protected $description = 'Anonimizează datele personale ale unei persoane vizate (dreptul de a fi uitat)';

    public function handle(DataSubjectService $locator, ErasureService $eraser): int
    {
        $identifier = (string) $this->argument('identifier');
        $apply = (bool) $this->option('apply');

        $located = $locator->locate($identifier);
        $count = $locator->countRecords($located);

        $this->info("Persoană vizată ({$located['searched_by']}): {$identifier}");
        foreach ($located['records'] as $table => $rows) {
            if (count($rows) > 0) {
                $this->line('  '.$table.': '.count($rows));
            }
        }
        if (($users = count($located['records']['users'] ?? [])) > 0) {
            $this->warn("  Atenție: {$users} cont(uri) de utilizator — NU se anonimizează automat (cont de personal). Tratează manual dacă e cazul.");
        }

        if ($count === 0) {
            $this->warn('Nicio înregistrare găsită. Nimic de făcut.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $summary = $eraser->erase($located, dryRun: true);
            $this->newLine();
            $this->info('RAPORT (dry-run) — NIMIC modificat. Ar fi anonimizate:');
            foreach ($summary as $t => $n) {
                if ($n > 0) {
                    $this->line("  {$t}: {$n}");
                }
            }
            $this->comment('Rulează cu --apply pentru a anonimiza efectiv.');

            return self::SUCCESS;
        }

        // --apply: dublă confirmare
        $this->newLine();
        $this->error('ATENȚIE: anonimizare IREVERSIBILĂ a datelor personale de mai sus.');
        $typed = (string) $this->ask("Pentru a confirma, tastează exact identificatorul ({$identifier})");
        if ($typed !== $identifier) {
            $this->warn('Confirmare incorectă — anulat, nimic modificat.');

            return self::SUCCESS;
        }

        $summary = $eraser->erase($located, dryRun: false);
        $total = array_sum($summary);

        activity('audit')->event('gdpr_erase')
            ->withProperties(['identifier' => $identifier, 'summary' => $summary])
            ->log("Ștergere GDPR aplicată pentru {$identifier}: {$total} înregistrări anonimizate");

        $this->newLine();
        $this->info("Gata: {$total} înregistrări anonimizate.");
        foreach ($summary as $t => $n) {
            if ($n > 0) {
                $this->line("  {$t}: {$n}");
            }
        }

        return self::SUCCESS;
    }
}
