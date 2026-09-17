<?php

namespace App\Console\Commands;

use App\Models\ErrorEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Marchează automat ca „rezolvate" erorile deschise care nu au mai apărut de X ore.
 * Sigur: ErrorReporter le redeschide automat (status → open) dacă vreuna reapare.
 */
class AutoResolveStaleErrorsCommand extends Command
{
    protected $signature = 'errors:auto-resolve {--hours=24 : Ore fără reapariție după care eroarea e considerată rezolvată}';

    protected $description = 'Auto-rezolvă erorile deschise care nu au mai apărut de X ore (se redeschid singure dacă revin).';

    public function handle(): int
    {
        $hours     = max(1, (int) $this->option('hours'));
        $threshold = now()->subHours($hours);

        $stale = ErrorEvent::query()
            ->where('status', 'open')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $threshold)
            ->get();

        if ($stale->isEmpty()) {
            $this->info("Nicio eroare deschisă fără reapariție de peste {$hours}h.");
            return self::SUCCESS;
        }

        foreach ($stale as $e) {
            $e->update(['status' => 'resolved', 'resolved_at' => now()]);
            $this->line("✓ #{$e->id} ({$e->count}×, ultima {$e->last_seen_at}) — " . Str::limit((string) $e->message, 60));
        }

        $this->info("Auto-rezolvate: {$stale->count()} erori (fără reapariție de {$hours}h).");
        Log::info("[errors:auto-resolve] {$stale->count()} erori auto-rezolvate (>{$hours}h fără reapariție).");

        return self::SUCCESS;
    }
}
