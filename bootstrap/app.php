<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Webhook routes — only SubstituteBindings, no CSRF/session/auth
            \Illuminate\Support\Facades\Route::middleware([
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ])->group(base_path('routes/webhooks.php'));

            // Warehouse PWA routes — sesiune extinsă (30 zile) pentru PWA
            \Illuminate\Support\Facades\Route::middleware(['web', \App\Http\Middleware\WarehouseLongSession::class])
                ->group(base_path('routes/warehouse.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Redirecționează utilizatorii neautentificați — PWA warehouse spre /wh/login, restul spre Filament
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request): string {
            // PWA (recepție /wh, hub /app) → login-ul PWA
            if (str_starts_with($request->path(), 'wh')
                || str_starts_with($request->path(), 'app')) {
                return route('warehouse.login');
            }
            return '/login';
        });

        // Security headers (CSP, X-Frame-Options, etc.) — doar pe rute web
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Gate pe matricea RolePermission (rute non-Filament: PWA, exporturi)
        $middleware->alias([
            'perm' => \App\Http\Middleware\EnsureRolePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Livewire stale snapshot: componenta nu mai are proprietatea din snapshot-ul vechi al browserului
        // Returnăm 419 → Livewire afișează "Page Expired" și utilizatorul reîncarcă pagina
        $exceptions->renderable(function (
            \Livewire\Exceptions\PublicPropertyNotFoundException $e,
            \Illuminate\Http\Request $request
        ) {
            if ($request->hasHeader('X-Livewire')) {
                return response('', 419);
            }
            return null;
        });

        $exceptions->renderable(function (
            \Symfony\Component\HttpKernel\Exception\HttpException $e,
            \Illuminate\Http\Request $request
        ) {
            $code = $e->getStatusCode();

            if (! in_array($code, [403, 404])) {
                return null;
            }

            // Doar pentru browsere (nu XHR/API), utilizatori autentificați
            if ($request->expectsJson() || $request->isXmlHttpRequest()) {
                return null;
            }

            if (! auth()->check()) {
                return null;
            }

            // Redirecționează în panelul App (nu admin)
            if (str_starts_with($request->path(), 'admin')) {
                return null;
            }

            $url = route('filament.app.pages.error-page', ['code' => $code]);
            return new \Illuminate\Http\RedirectResponse($url);
        });
    })->create();
