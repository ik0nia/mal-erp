<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\BreachIncident;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

/**
 * Detectează automat tipare suspecte din jurnalul de audit (brute-force login)
 * și creează o breșă în registru + notifică super_adminii. Praguri configurabile
 * prin AppSetting; cooldown anti-spam. Non-distructiv: doar citește auditul.
 */
class SecurityDetectAnomaliesCommand extends Command
{
    protected $signature = 'security:detect-anomalies';

    protected $description = 'Detectează tipare suspecte (brute-force login) și creează automat breșe';

    public function handle(): int
    {
        $window = (int) AppSetting::get('security_detect_window_minutes', 15);
        $ipThreshold = (int) AppSetting::get('security_bruteforce_ip_threshold', 10);
        $accThreshold = (int) AppSetting::get('security_bruteforce_account_threshold', 6);
        $cooldownH = (int) AppSetting::get('security_detect_cooldown_hours', 6);

        $failed = Activity::where('log_name', 'auth')
            ->where('event', 'failed')
            ->where('created_at', '>=', now()->subMinutes($window))
            ->get();

        if ($failed->isEmpty()) {
            $this->info('Niciun eveniment de analizat.');

            return self::SUCCESS;
        }

        $created = 0;

        // Brute-force după IP
        foreach ($failed->groupBy(fn ($a) => (string) data_get($a->properties, 'ip', '?')) as $ip => $events) {
            if ($ip === '?' || $events->count() < $ipThreshold) {
                continue;
            }
            $accounts = $events->map(fn ($a) => data_get($a->properties, 'email'))->filter()->unique()->take(10)->implode(', ');
            $created += $this->raise(
                "ip:{$ip}",
                "Posibil atac brute-force de login — {$events->count()} tentative eșuate de la IP {$ip}",
                "S-au înregistrat {$events->count()} autentificări eșuate de la adresa IP {$ip} în ultimele {$window} minute. Conturile vizate: {$accounts}.",
                $cooldownH,
            );
        }

        // Brute-force după cont
        foreach ($failed->groupBy(fn ($a) => (string) data_get($a->properties, 'email', '?')) as $email => $events) {
            if ($email === '?' || $events->count() < $accThreshold) {
                continue;
            }
            $ips = $events->map(fn ($a) => data_get($a->properties, 'ip'))->filter()->unique()->take(10)->implode(', ');
            $created += $this->raise(
                "email:{$email}",
                "Posibil atac asupra contului {$email} — {$events->count()} tentative eșuate",
                "Contul {$email} a avut {$events->count()} autentificări eșuate în ultimele {$window} minute, din IP-urile: {$ips}.",
                $cooldownH,
            );
        }

        $this->info($created > 0 ? "Detectate și înregistrate {$created} incidente." : 'Niciun prag depășit.');

        return self::SUCCESS;
    }

    private function raise(string $signature, string $title, string $description, int $cooldownH): int
    {
        $scope = "auto:brute-force:{$signature}";

        // anti-spam: nu re-alerta același IP/cont în fereastra de cooldown
        $recent = BreachIncident::where('affected_scope', $scope)
            ->where('created_at', '>=', now()->subHours($cooldownH))
            ->exists();
        if ($recent) {
            return 0;
        }

        BreachIncident::create([
            'type' => 'security_alert',          // alertă de securitate, NU breșă notificabilă (fără termen 72h)
            'title' => $title,
            'description' => $description."\n\n".'[Detectat automat de security:detect-anomalies. Tentativă fără compromitere confirmată de date — alertă de securitate, nu breșă notificabilă. Dacă atacul a reușit și s-au compromis efectiv date, escaladați manual la tipul Breșă de date personale.]',
            'severity' => 'high',
            'status' => 'open',
            'detected_at' => now(),
            'affected_scope' => $scope,
        ]);

        $admins = User::where('is_super_admin', true)->get();
        Notification::make()
            ->title('⚠ Alertă de securitate (posibil brute-force)')
            ->body($title)
            ->warning()
            ->sendToDatabase($admins);

        return 1;
    }
}
