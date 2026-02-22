<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentView;
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
        FilamentView::registerRenderHook(
            'panels::head.end',
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
