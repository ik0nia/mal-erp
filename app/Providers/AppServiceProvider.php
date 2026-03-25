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
        // Override SMTP config from DB (fallback pe .env)
        $this->overrideMailConfig();

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

    private function overrideMailConfig(): void
    {
        try {
            $host = \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_SMTP_HOST);
            if (blank($host)) {
                return; // no DB settings, use .env defaults
            }

            config([
                'mail.mailers.smtp.host'       => $host,
                'mail.mailers.smtp.port'       => (int) \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_SMTP_PORT, '465'),
                'mail.mailers.smtp.username'   => \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_SMTP_USERNAME),
                'mail.mailers.smtp.password'   => \App\Models\AppSetting::getEncrypted(\App\Models\AppSetting::KEY_SMTP_PASSWORD) ?? config('mail.mailers.smtp.password'),
                'mail.mailers.smtp.encryption' => \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_SMTP_ENCRYPTION, 'ssl'),
                'mail.from.address'            => \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_SMTP_FROM_ADDRESS) ?? config('mail.from.address'),
                'mail.from.name'               => \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_SMTP_FROM_NAME) ?? config('mail.from.name'),
            ]);
        } catch (\Throwable) {
            // DB not available yet (migrations, etc.) — use .env
        }
    }
}
