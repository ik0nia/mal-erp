<?php

namespace App\Console\Commands\BI;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComputeBiDailyCommand extends Command
{
    protected $signature = 'bi:compute-daily
                            {--day= : Data în format YYYY-MM-DD (implicit: ieri) — pentru rerulări manuale}';

    protected $description = 'Rulează toate agregările BI pentru ieri: KPI → Velocity → Alerts';

    public function handle(): int
    {
        $day = $this->option('day')
            ? Carbon::parse($this->option('day'))->toDateString()
            : Carbon::yesterday()->toDateString();

        $this->line("=== BI Daily Compute — <info>{$day}</info> ===");

        // Sanity check global: dacă nu există date pentru ziua țintă, nu scriem nimic
        $sourceCount = DB::table('daily_stock_metrics')->where('day', $day)->count();
        if ($sourceCount === 0) {
            $this->warn("Nicio dată în daily_stock_metrics pentru {$day}. Nu se rulează agregările.");
            Log::warning('bi:compute-daily: no source data for yesterday', ['day' => $day]);
            return self::SUCCESS;
        }

        $this->line("  Sursă: {$sourceCount} rânduri în daily_stock_metrics pentru {$day}.");
        $this->newLine();

        // Pas 1: KPI global
        $this->line('→ [1/3] bi:compute-kpi');
        $r1 = $this->call('bi:compute-kpi', ['--day' => $day]);
        $this->newLine();

        // Pas 2: Velocity (trebuie înainte de Alerts)
        $this->line('→ [2/3] bi:compute-velocity');
        $r2 = $this->call('bi:compute-velocity', ['--day' => $day]);
        $this->newLine();

        // Pas 3: Alerts (depinde de Velocity)
        $this->line('→ [3/3] bi:compute-alerts');
        $r3 = $this->call('bi:compute-alerts', ['--day' => $day]);
        $this->newLine();

        if ($r1 !== self::SUCCESS || $r2 !== self::SUCCESS || $r3 !== self::SUCCESS) {
            $this->error('Unul sau mai mulți pași BI au eșuat.');
            Log::error('bi:compute-daily: one or more steps failed', [
                'day' => $day, 'kpi' => $r1, 'velocity' => $r2, 'alerts' => $r3,
            ]);
            return self::FAILURE;
        }

        $this->info("=== BI Daily Compute complet pentru {$day} ===");
        return self::SUCCESS;
    }
}
