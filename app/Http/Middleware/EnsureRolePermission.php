<?php

namespace App\Http\Middleware;

use App\Models\RolePermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate pe matricea RolePermission pentru rute non-Filament (PWA, exporturi).
 * Folosire: ->middleware('perm:mobile_dispecer') sau 'perm:cheie,can_edit'.
 */
class EnsureRolePermission
{
    public function handle(Request $request, Closure $next, string $key, string $permission = 'can_access'): Response
    {
        abort_unless(RolePermission::check($key, $permission), 403);

        return $next($request);
    }
}
