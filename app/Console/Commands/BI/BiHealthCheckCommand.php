<?php

namespace App\Console\Commands\BI;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification;
use Filament\Actions\Action as NotificationAction;

/**
 * Watchdog BI — rulat zilnic la 09:00.
 *
 * Verifică dacă bi:compute-daily a rulat corect pentru ultimele 7 zile.
 * Dacă lipsesc date, le reprocessează automat (self-healing).
 * Trimite notificare Filament admin-ilor dacă a fost necesar un backfill.
 */
class BiHealthCheckCommand extends Command
{
    protected $signature = 'bi:health-check
                            {--days=7 : Câte zile în urmă să verifice (implicit: 7)}
                            {--silent : Nu trimite notificări Filament}';

    protected $description = 'Watchdog BI: detectează și repară automat datele lipsă din bi_inventory_kpi_daily';

    public function handle(): int
    {
        $lookback  = max(1, (int) $this->option('days'));
        $today     = Carbon::today();
        $repaired  = [];
        $skipped   = [];

        $this->line("=== BI Health Check — ultimele <info>{$lookback}</info> zile ===");

        for ($i = 1; $i <= $lookback; $i++) {
            $day = $today->copy()->subDays($i)->toDateString();

            $hasKpi = DB::table('bi_inventory_kpi_daily')->where('day', $day)->exists();

            if ($hasKpi) {
                $this->line("  <info>✓</info> {$day} — date prezente");
                continue;
            }

            // Verificăm dacă există date sursă pentru ziua respectivă
            $sourceCount = DB::table('daily_stock_metrics')->where('day', $day)->count();

            if ($sourceCount === 0) {
                $this->warn("  ✗ {$day} — lipsă date sursă (daily_stock_metrics gol) — skip");
                $skipped[] = $day;
                Log::warning('bi:health-check: no source data for day', ['day' => $day]);
                continue;
            }

            // Backfill automat
            $this->warn("  ✗ {$day} — DATE LIPSĂ — rulăm backfill automat...");
            Log::warning('bi:health-check: missing BI data detected, running backfill', [
                'day'          => $day,
                'source_count' => $sourceCount,
            ]);

            $exitCode = $this->call('bi:compute-daily', ['--day' => $day]);

            if ($exitCode === self::SUCCESS) {
                $repaired[] = $day;
                $this->info("    → Backfill {$day} complet.");
                Log::info('bi:health-check: backfill success', ['day' => $day]);
            } else {
                $this->error("    → Backfill {$day} EȘUAT (exit {$exitCode}).");
                Log::error('bi:health-check: backfill failed', ['day' => $day, 'exit' => $exitCode]);
            }
        }

        $this->newLine();

        if (empty($repaired) && empty($skipped)) {
            $this->info('Toate datele BI sunt prezente. Nicio acțiune necesară.');
            return self::SUCCESS;
        }

        // Notificăm super_admins dacă ceva a lipsit
        if (! $this->option('silent') && (! empty($repaired) || ! empty($skipped))) {
            $this->sendAdminNotification($repaired, $skipped);
        }

        $this->summary($repaired, $skipped);

        return self::SUCCESS;
    }

    private function summary(array $repaired, array $skipped): void
    {
        if (! empty($repaired)) {
            $this->info('Backfill efectuat pentru: ' . implode(', ', $repaired));
        }

        if (! empty($skipped)) {
            $this->warn('Zile fără date sursă (nu s-a putut repara): ' . implode(', ', $skipped));
        }
    }

    private function sendAdminNotification(array $repaired, array $skipped): void
    {
        $admins = User::whereIn('role', [
            User::ROLE_SUPER_ADMIN,
            User::ROLE_MANAGER,
        ])->get();

        if ($admins->isEmpty()) {
            return;
        }

        if (! empty($repaired)) {
            $body = 'Backfill automat efectuat pentru: ' . implode(', ', $repaired) . '. Datele sunt acum complete.';

            Notification::make()
                ->title('BI Watchdog: Date reparate automat')
                ->body($body)
                ->warning()
                ->actions([
                    NotificationAction::make('dashboard')
                        ->label('Deschide Dashboard BI')
                        ->url('/app')
                        ->button(),
                ])
                ->sendToDatabase($admins);
        }

        if (! empty($skipped)) {
            $body = 'Zile fără date sursă (stocuri nesincronizate?): ' . implode(', ', $skipped);

            Notification::make()
                ->title('BI Watchdog: Zile fără date sursă')
                ->body($body)
                ->danger()
                ->sendToDatabase($admins);
        }

        Log::info('bi:health-check: Filament notifications sent', [
            'repaired' => $repaired,
            'skipped'  => $skipped,
            'admins'   => $admins->pluck('id')->toArray(),
        ]);
    }
}
