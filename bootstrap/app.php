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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Redirecționează utilizatorii neautentificați spre Filament App login
        $middleware->redirectGuestsTo('/login');

        // Security headers (CSP, X-Frame-Options, etc.) — doar pe rute web
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
