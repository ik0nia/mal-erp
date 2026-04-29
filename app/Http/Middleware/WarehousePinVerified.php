<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WarehousePinVerified
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->session()->get('wh_pin_verified')) {
            return redirect()->route('warehouse.pin');
        }

        return $next($request);
    }
}
