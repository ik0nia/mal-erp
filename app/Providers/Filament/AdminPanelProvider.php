<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\IntegrationImportStatusWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Red,
            ])
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn () => new \Illuminate\Support\HtmlString('<style>
/* ── Sidebar negru (Filament 4) ── */
.fi-sidebar { background-color: #111827 !important; border-right-color: #1f2937 !important; }
.fi-sidebar-header { background-color: #ffffff !important; border-bottom-color: #e5e7eb !important; }
.fi-sidebar-header *, .fi-sidebar-header a, .fi-sidebar-header span, .fi-sidebar-header p, .fi-sidebar-header div { color: #111827 !important; }
.fi-sidebar-group-label { color: rgba(255,255,255,0.45) !important; }
.fi-sidebar-item-label { color: rgba(255,255,255,0.85) !important; }
.fi-sidebar-item-icon { color: rgba(255,255,255,0.6) !important; }
.fi-sidebar-item-btn:hover { background-color: rgba(255,255,255,0.18) !important; }
.fi-sidebar-item-btn:hover .fi-sidebar-item-label, .fi-sidebar-item-btn:hover .fi-sidebar-item-icon { color: #ffffff !important; }
.fi-sidebar-item.fi-active .fi-sidebar-item-btn, .fi-sidebar-item.fi-active .fi-sidebar-item-btn:hover { background-color: #ffffff !important; }
.fi-sidebar-item.fi-active .fi-sidebar-item-label, .fi-sidebar-item.fi-active .fi-sidebar-item-btn:hover .fi-sidebar-item-label { color: #111827 !important; font-weight: 600; }
.fi-sidebar-item.fi-active .fi-sidebar-item-icon, .fi-sidebar-item.fi-active .fi-sidebar-item-btn:hover .fi-sidebar-item-icon { color: #111827 !important; }
.fi-sidebar-item-badge-ctn .fi-badge { background-color: #dc2626 !important; color: #ffffff !important; }
.fi-sidebar-group-collapse-btn { color: rgba(255,255,255,0.4) !important; }
.fi-sidebar-group-collapse-btn:hover { background-color: rgba(255,255,255,0.08) !important; color: #ffffff !important; }
.fi-sidebar-group-btn { color: rgba(255,255,255,0.45) !important; }
[data-slot="icon"]:not([class*="w-"]):not([class*="h-"]):not([class*="size-"]):not(.fi-icon) { width: 1.25rem; height: 1.25rem; display: inline-block; }
</style>'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                IntegrationImportStatusWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
