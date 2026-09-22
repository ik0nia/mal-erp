<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

                // Jurnal de navigare: ce pagini deschide (doar accesări reale de pagini).
                $this->logPageVisit($request, $user, $now);

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

    /**
     * Înregistrează o vizită de pagină în `user_page_visits`. Prinde DOAR navigări reale:
     * GET care acceptă HTML, exclude asset-uri / API / polling Livewire / descărcări.
     * Throttle 5s pe (user, path) ca să nu dubleze din redirecturi sau refire.
     */
    private function logPageVisit(Request $request, $user, Carbon $now): void
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->hasHeader('X-Livewire')) {
            return;
        }
        if (! $request->acceptsHtml()) {
            return;
        }

        $path = $request->path();

        // Exclude rute de sistem / asset-uri / descărcări.
        if (preg_match('#^(livewire|filament|storage|vendor|build|assets|js|css|images?|img|fonts|api|horizon|webhooks|health|up|awb|broadcasting)(/|$)#', $path)) {
            return;
        }
        if (preg_match('#\.(js|css|png|jpe?g|svg|gif|ico|webp|woff2?|ttf|map|pdf|xml|txt|zip|json)$#i', $path)) {
            return;
        }

        // Anti-duplicat: aceeași pagină, același user, în 5s → o singură înregistrare.
        if (! Cache::add('pv:'.$user->id.':'.md5($path), 1, 5)) {
            return;
        }

        DB::table('user_page_visits')->insert([
            'user_id'    => $user->id,
            'method'     => 'GET',
            'path'       => Str::limit($path, 500, ''),
            'route_name' => $request->route()?->getName(),
            'title'      => null,
            'visited_at' => $now,
        ]);
    }
}
