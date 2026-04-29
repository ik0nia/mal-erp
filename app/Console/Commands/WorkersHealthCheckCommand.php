<?php

namespace App\Console\Commands;

use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Verifică sănătatea workerilor programați și trimite e-mail la codrut@ikonia.ro
 * dacă detectează probleme:
 *
 *   1. Job-uri noi în failed_jobs față de ultima verificare
 *   2. Workers cu SyncRun care ar fi trebuit să ruleze recent (stoc Bridge, categorii)
 *
 * Se rulează la fiecare 30 de minute. Folosește cache pentru deduplicare
 * (nu retrimite alertă pentru același job eșuat de două ori).
 */
class WorkersHealthCheckCommand extends Command
{
    protected $signature = 'erp:workers-health-check {--force : Ignoră deduplicarea și trimite alertă indiferent}';
    protected $description = 'Verifică sănătatea workerilor și trimite alertă e-mail dacă ceva e picat';

    private const NOTIFY_EMAIL = 'codrut@ikonia.ro';
    private const CACHE_LAST_FAILED_JOB_ID = 'workers_health_last_failed_job_id';

    // Workers monitorizați prin sync_runs — [type => max_minutes_fără_succes_în_program]
    private const SYNC_RUN_WATCHES = [
        'winmentor_bridge_stock' => [
            'label'        => 'WinMentor Bridge — sync stoc/preț',
            'max_gap_min'  => 20,
            'working_hours' => true, // monitorizat doar în program (08:00–17:30, L–S)
        ],
        'categories' => [
            'label'        => 'WooCommerce — sync categorii',
            'max_gap_min'  => 480, // 8 ore
            'working_hours' => false,
        ],
    ];

    public function handle(): int
    {
        $force   = $this->option('force');
        $issues  = [];
        $nowBuch = now()->setTimezone('Europe/Bucharest');

        // ── 1. Failed jobs noi ───────────────────────────────────────────────────
        $lastSeenId = Cache::get(self::CACHE_LAST_FAILED_JOB_ID, 0);
        $newFailed  = DB::table('failed_jobs')
            ->where('id', '>', $lastSeenId)
            ->orderBy('id')
            ->get(['id', 'payload', 'exception', 'failed_at']);

        if ($newFailed->isNotEmpty()) {
            $maxId = $newFailed->max('id');
            if (! $force) {
                Cache::put(self::CACHE_LAST_FAILED_JOB_ID, $maxId, now()->addDays(7));
            }

            foreach ($newFailed as $job) {
                $payload   = json_decode($job->payload, true);
                $jobClass  = $payload['displayName'] ?? 'Job necunoscut';
                $exception = collect(explode("\n", $job->exception ?? ''))->first() ?? 'Excepție necunoscută';

                $issues[] = [
                    'tip'      => 'JOB_EȘUAT',
                    'label'    => $jobClass,
                    'detaliu'  => $exception,
                    'la'       => $job->failed_at,
                ];

                $this->warn("  [JOB EȘUAT] {$jobClass} @ {$job->failed_at}");
            }
        }

        // ── 2. SyncRun freshness ─────────────────────────────────────────────────
        foreach (self::SYNC_RUN_WATCHES as $type => $cfg) {
            // Dacă e monitorizat doar în ore de program și acum e în afara programului, sărim
            if ($cfg['working_hours']) {
                $isWorkDay  = in_array($nowBuch->dayOfWeek, [1, 2, 3, 4, 5, 6]); // L–S
                $isWorkHour = $nowBuch->between(
                    $nowBuch->copy()->setTimeFromTimeString('08:00'),
                    $nowBuch->copy()->setTimeFromTimeString('17:30')
                );
                if (! $isWorkDay || ! $isWorkHour) {
                    continue;
                }
            }

            $lastSuccess = SyncRun::where('type', $type)
                ->where('status', SyncRun::STATUS_SUCCESS)
                ->latest('finished_at')
                ->value('finished_at');

            $gapMinutes = $lastSuccess
                ? now()->diffInMinutes($lastSuccess)
                : PHP_INT_MAX;

            if ($gapMinutes > $cfg['max_gap_min']) {
                $sinceText = $lastSuccess
                    ? 'ultimul succes acum ' . $gapMinutes . ' minute'
                    : 'niciodată reușit';

                $issues[] = [
                    'tip'     => 'WORKER_STALE',
                    'label'   => $cfg['label'],
                    'detaliu' => "Ar fi trebuit să ruleze în ultimele {$cfg['max_gap_min']} min — {$sinceText}.",
                    'la'      => $lastSuccess ?? 'N/A',
                ];

                $this->warn("  [STALE] {$cfg['label']} — {$sinceText}");
            }
        }

        // ── 3. Trimitere alertă ──────────────────────────────────────────────────
        if (empty($issues)) {
            $this->info('Toți workerii sunt OK.');
            return self::SUCCESS;
        }

        $this->sendAlertEmail($issues, $nowBuch->format('d.m.Y H:i'));
        Log::warning('[WorkersHealthCheck] Alertă trimisă', ['issues' => count($issues)]);

        return self::SUCCESS;
    }

    private function sendAlertEmail(array $issues, string $timestamp): void
    {
        $jobsFailed   = count(array_filter($issues, fn ($i) => $i['tip'] === 'JOB_EȘUAT'));
        $workersStale = count(array_filter($issues, fn ($i) => $i['tip'] === 'WORKER_STALE'));

        $parts = [];
        if ($jobsFailed) {
            $parts[] = "{$jobsFailed} job(uri) eșuat(e)";
        }
        if ($workersStale) {
            $parts[] = "{$workersStale} worker(i) înghețat(i)";
        }

        $subject = '[ERP Malinco] Alertă workeri — ' . implode(', ', $parts);

        try {
            Mail::send('mail.workers-health', compact('issues', 'subject'), function ($message) use ($subject) {
                $message->to(self::NOTIFY_EMAIL)->subject($subject);
            });
            $this->info('Alertă e-mail trimisă la ' . self::NOTIFY_EMAIL);
        } catch (\Throwable $e) {
            $this->error('Trimitere e-mail eșuată: ' . $e->getMessage());
            Log::error('[WorkersHealthCheck] Email eșuat: ' . $e->getMessage());
        }
    }
}
