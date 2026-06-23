<?php

namespace App\Console\Commands;

use App\Models\UptimeProbe;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sondează periodic disponibilitatea serviciilor și o persistă în `uptime_probes`,
 * pentru calculul disponibilității lunare și al creditelor de serviciu (contract Art. 8.9).
 */
class MonitoringProbeCommand extends Command
{
    protected $signature = 'monitoring:probe';

    protected $description = 'Sondează disponibilitatea aplicației și a bridge-ului WinMentor';

    public function handle(): int
    {
        $now = now();

        // 1) Aplicație: baza de date + cache (dacă comanda rulează, framework-ul e sus)
        [$appUp, $appMs, $appErr] = $this->measure(function () {
            DB::select('SELECT 1');
            Cache::store()->get('uptime_probe_ping');
        });
        $this->record('app', $appUp, $appMs, $appErr, $now);

        // 2) Bridge WinMentor — DOAR prin client (regula proiectului)
        [$brUp, $brMs, $brErr] = $this->probeBridge();
        $this->record('bridge', $brUp, $brMs, $brErr, $now);

        $this->info("app=".($appUp ? 'UP' : 'DOWN')." ({$appMs}ms) | bridge=".($brUp ? 'UP' : 'DOWN')." ({$brMs}ms)");

        return self::SUCCESS;
    }

    /**
     * Sondează bridge-ul și distinge cauza reală a indisponibilității, ca să nu
     * mai etichetăm totul drept „COM deconectat":
     *   - timeout de rețea (~10s)  → PC-ul cu MentorAPI e oprit/offline sau rețea picată
     *   - connection refused (rapid) → procesul MentorAPI nu rulează pe PC
     *   - HTTP 200 dar comConnected=false → MentorAPI e sus, dar legătura COM cu WinMentor e ruptă
     *
     * @return array{0:bool,1:?int,2:?string}
     */
    private function probeBridge(): array
    {
        $start = microtime(true);
        try {
            $result = app(WinmentorBridgeClient::class)->health();
            $ms     = (int) round((microtime(true) - $start) * 1000);

            if (($result['success'] ?? false) !== true) {
                return [false, $ms, 'MentorAPI a răspuns cu eroare la /health'];
            }

            if (($result['data']['comConnected'] ?? false) !== true) {
                return [false, $ms, 'COM deconectat (MentorAPI sus, dar fără legătură cu WinMentor)'];
            }

            return [true, $ms, null];
        } catch (\Throwable $e) {
            $ms  = (int) round((microtime(true) - $start) * 1000);
            $msg = strtolower($e->getMessage());

            // cURL 28 / „timed out" → SYN fără răspuns: PC oprit/offline sau rețea picată
            if (str_contains($msg, 'timed out') || str_contains($msg, 'error 28') || $ms >= 9000) {
                $err = 'Bridge inaccesibil (timeout rețea — PC MentorAPI oprit/offline sau rețea picată)';
            // cURL 7 / refused → ruta există, dar procesul MentorAPI nu rulează
            } elseif (str_contains($msg, 'refused') || str_contains($msg, 'error 7')) {
                $err = 'Bridge oprit (proces MentorAPI nu rulează pe PC)';
            } else {
                $err = 'Bridge inaccesibil: ' . mb_substr($e->getMessage(), 0, 200);
            }

            return [false, $ms, $err];
        }
    }

    /** @return array{0:bool,1:?int,2:?string} */
    private function measure(callable $check): array
    {
        $start = microtime(true);
        try {
            $check();
            $ms = (int) round((microtime(true) - $start) * 1000);

            return [true, $ms, null];
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);

            return [false, $ms, mb_substr($e->getMessage(), 0, 255)];
        }
    }

    private function record(string $target, bool $up, ?int $ms, ?string $err, \Illuminate\Support\Carbon $at): void
    {
        UptimeProbe::create([
            'target'      => $target,
            'is_up'       => $up,
            'response_ms' => $ms,
            'error'       => $err,
            'checked_at'  => $at,
        ]);
    }
}
