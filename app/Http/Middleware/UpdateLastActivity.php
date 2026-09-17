<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Actualizează users.last_activity_at pentru utilizatorul autentificat (orice request,
 * inclusiv PWA — e pe authMiddleware-ul panelurilor + grupul web). Throttled la 1 scriere/min.
 * Acumulează și timpul activ pe zi în user_activity_daily (gap-ul dintre activități, dacă
 * sesiunea e continuă) — pentru graficul pe 30 de zile.
 *
 * Tracking-ul e „best effort": orice eroare e înghițită, nu trebuie să spargă requestul.
 */
class UpdateLastActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $request->user();

            if ($user) {
                $now  = now();
                $prev = $user->last_activity_at;

                if (! $prev || $prev->lt($now->copy()->subMinute())) {
                    // Acumulează timp activ doar dacă sesiunea e continuă (gap ≤ 10 min, aceeași zi).
                    if ($prev && $prev->isSameDay($now)) {
                        $gap = (int) abs($now->diffInSeconds($prev));
                        if ($gap > 0 && $gap <= 600) {
                            DB::statement(
                                'INSERT INTO user_activity_daily (user_id, day, active_seconds, created_at, updated_at)
                                 VALUES (?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE active_seconds = active_seconds + VALUES(active_seconds), updated_at = VALUES(updated_at)',
                                [$user->id, $now->toDateString(), $gap, $now, $now]
                            );
                            DB::statement(
                                'INSERT INTO user_activity_hourly (user_id, day, hour, active_seconds, created_at, updated_at)
                                 VALUES (?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE active_seconds = active_seconds + VALUES(active_seconds), updated_at = VALUES(updated_at)',
                                [$user->id, $now->toDateString(), (int) $now->hour, $gap, $now, $now]
                            );
                        }
                    }

                    $user->forceFill(['last_activity_at' => $now])->saveQuietly();
                }
            }
        } catch (Throwable) {
            // Tracking-ul de activitate nu trebuie să spargă niciodată requestul.
        }

        return $next($request);
    }
}
