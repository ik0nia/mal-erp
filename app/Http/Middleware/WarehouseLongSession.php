<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Extinde sesiunea la 30 de zile pentru rutele warehouse PWA.
 * Aplicația stă deschisă toată ziua pe telefoane — nu trebuie să expire.
 */
class WarehouseLongSession
{
    public function handle(Request $request, Closure $next)
    {
        config(['session.lifetime' => 43200]); // 30 zile în minute

        return $next($request);
    }
}
