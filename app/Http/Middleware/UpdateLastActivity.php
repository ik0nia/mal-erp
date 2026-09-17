<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Actualizează users.last_activity_at pentru utilizatorul autentificat, pe orice request
 * (inclusiv PWA — rulează pe grupul web). Throttled la cel mult o scriere pe minut.
 */
class UpdateLastActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->last_activity_at || $user->last_activity_at->lt(now()->subMinute()))) {
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
