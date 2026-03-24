<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentView;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\HtmlString;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limiters pentru endpoint-uri publice
        RateLimiter::for('chat-message', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        RateLimiter::for('chat-contact', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('chat-config', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_END,
            fn (): HtmlString => new HtmlString(<<<'HTML'
                <style>
                    .offer-items-repeater td.table-repeater-column {
                        vertical-align: middle !important;
                    }
                    .offer-items-repeater .table-repeater-row-actions {
                        align-items: center;
                    }
                    .offer-items-repeater .fi-fo-field-wrp {
                        margin-bottom: 0 !important;
                    }
                </style>
            HTML),
        );
    }
}
