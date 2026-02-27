<?php

namespace App\Console\Commands\BI;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillBiCommand extends Command
{
    protected $signature = 'bi:backfill
                            {--from=  : Data de start YYYY-MM-DD (obligatoriu)}
                            {--to=    : Data de final YYYY-MM-DD (implicit: ieri)}
                            {--skip-restore : Nu restora velocity la ieri după backfill}';

    protected $description = 'Backfill agregări BI pentru un interval de date (KPI + Velocity + Alerts per zi)';

    public function handle(): int
    {
        $fromOpt = $this->option('from');
        if (! $fromOpt) {
            $this->error('--from este obligatoriu. Exemplu: php artisan bi:backfill --from=2026-02-01');
            return self::FAILURE;
        }

        $fromDate = Carbon::parse($fromOpt)->startOfDay();
        $toDate   = $this->option('to')
            ? Carbon::parse($this->option('to'))->startOfDay()
            : Carbon::yesterday()->startOfDay();

        if ($fromDate->gt($toDate)) {
            $this->error('--from trebuie să fie înainte de --to.');
            return self::FAILURE;
        }

        $totalDays = (int) $fromDate->diffInDays($toDate) + 1;
        $yesterday = Carbon::yesterday()->toDateString();

        $this->line(sprintf(
            'Backfill BI: <info>%s</info> → <info>%s</info> (%d zile)',
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $totalDays
        ));
        $this->newLine();

        $current    = $fromDate->copy();
        $dayCounter = 0;
        $failed     = 0;

        while ($current->lte($toDate)) {
            $dayCounter++;
            $dateStr = $current->toDateString();

            $this->line(sprintf('[%d/%d] %s', $dayCounter, $totalDays, $dateStr));

            // Ordinea este importantă: KPI → Velocity → Alerts
            $r1 = $this->call('bi:compute-kpi',      ['--day' => $dateStr]);
            $r2 = $this->call('bi:compute-velocity', ['--day' => $dateStr]);
            $r3 = $this->call('bi:compute-alerts',   ['--day' => $dateStr]);

            if ($r1 !== self::SUCCESS || $r2 !== self::SUCCESS || $r3 !== self::SUCCESS) {
                $this->warn("  ⚠ Eroare la una din comenzi pentru {$dateStr}.");
                $failed++;
            }

            $this->newLine();
            $current->addDay();
        }

        // Restorează velocity la "ieri" dacă backfill-ul s-a terminat pe o zi mai veche.
        // Altfel velocity rămâne pe ultima zi din backfill, ceea ce ar afecta cron-ul următor.
        $lastProcessed = $toDate->toDateString();
        if (! $this->option('skip-restore') && $lastProcessed !== $yesterday) {
            $this->line("Restorare velocity la ieri (<info>{$yesterday}</info>)...");
            $this->call('bi:compute-velocity', ['--day' => $yesterday]);
            $this->newLine();
        }

        if ($failed > 0) {
            $this->warn("Backfill finalizat cu {$failed} erori din {$dayCounter} zile.");
            return self::FAILURE;
        }

        $this->info("Backfill complet: {$dayCounter} zile procesate fără erori.");
        return self::SUCCESS;
    }
}
