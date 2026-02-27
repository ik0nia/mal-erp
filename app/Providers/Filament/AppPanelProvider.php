<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\App\Search\AppGlobalSearchProvider;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use App\Models\AppSetting;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('')
            ->homeUrl('/')
            ->login()
            ->maxContentWidth(MaxWidth::Full)
            ->globalSearch(AppGlobalSearchProvider::class)
            ->globalSearchKeyBindings(['ctrl+k', 'cmd+k'])
            ->globalSearchDebounce('300ms')
            ->colors([
                'primary' => Color::Red,
            ])
            ->brandName(fn () => AppSetting::get(AppSetting::KEY_BRAND_NAME, 'Malinco ERP'))
            ->brandLogo(function (): ?string {
                $path = AppSetting::get(AppSetting::KEY_LOGO_PATH);
                return $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
            })
            ->brandLogoHeight('40px')
            ->navigationGroups([
                NavigationGroup::make('Comenzi'),
                NavigationGroup::make('Vânzări'),
                NavigationGroup::make('Achiziții'),
                NavigationGroup::make('Administrare magazin'),
                NavigationGroup::make('Rapoarte'),
                NavigationGroup::make('Livrare'),
                NavigationGroup::make('Produse'),
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([])
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn () => new \Illuminate\Support\HtmlString('<style>
/* ── Global Search ── */
.fi-topbar nav {
    width: 100% !important;
}
.fi-topbar nav > div.ms-auto {
    margin-left: 0.75rem !important;
    flex-grow: 1 !important;
    flex-shrink: 1 !important;
    min-width: 0 !important;
}
.fi-global-search {
    flex-grow: 1 !important;
    flex-shrink: 1 !important;
    min-width: 0 !important;
    width: 100% !important;
}
.fi-global-search-field {
    width: 100% !important;
}
.fi-global-search-field .fi-input-wrp {
    width: 100% !important;
    background-color: #f3f4f6 !important;
    border-color: #d1d5db !important;
    border-radius: 0.5rem !important;
}
.fi-global-search-field .fi-input-wrp:focus-within {
    background-color: #ffffff !important;
    border-color: #dc2626 !important;
    box-shadow: 0 0 0 2px rgba(220,38,38,0.15) !important;
}
.fi-global-search-field input[type="search"] {
    background-color: transparent !important;
    width: 100% !important;
}
.fi-global-search-results-ctn {
    right: auto !important;
    left: 0 !important;
    width: 100% !important;
    max-width: none !important;
}
.erp-user-info {
    margin-left: auto !important;
}
.fi-topbar .fi-user-menu {
    margin-left: 0 !important;
}
/* ── Sidebar negru ── */
.fi-sidebar {
    background-color: #111827 !important;
    border-right-color: #1f2937 !important;
}
.fi-sidebar-header {
    background-color: #ffffff !important;
    border-bottom-color: #e5e7eb !important;
}
/* Brand name negru pe fundal alb */
.fi-sidebar-header *,
.fi-sidebar-header a,
.fi-sidebar-header span,
.fi-sidebar-header p,
.fi-sidebar-header div {
    color: #111827 !important;
}
.fi-sidebar-group-label {
    color: rgba(255,255,255,0.45) !important;
}
/* Items normale */
.fi-sidebar-item-label {
    color: rgba(255,255,255,0.85) !important;
}
.fi-sidebar-item-icon {
    color: rgba(255,255,255,0.6) !important;
}
/* Hover */
.fi-sidebar-item-button:hover {
    background-color: rgba(255,255,255,0.18) !important;
}
.fi-sidebar-item-button:hover .fi-sidebar-item-label,
.fi-sidebar-item-button:hover .fi-sidebar-item-icon {
    color: #ffffff !important;
}
/* Item activ — fi-active e pe <li>, nu pe <a> */
.fi-sidebar-item.fi-active .fi-sidebar-item-button,
.fi-sidebar-item.fi-active .fi-sidebar-item-button:hover {
    background-color: #ffffff !important;
}
.fi-sidebar-item.fi-active .fi-sidebar-item-label,
.fi-sidebar-item.fi-active .fi-sidebar-item-button:hover .fi-sidebar-item-label {
    color: #111827 !important;
    font-weight: 600;
}
.fi-sidebar-item.fi-active .fi-sidebar-item-icon,
.fi-sidebar-item.fi-active .fi-sidebar-item-button:hover .fi-sidebar-item-icon {
    color: #111827 !important;
}
/* Badge-uri navigație */
.fi-sidebar-item-badge {
    background-color: #dc2626 !important;
    color: #ffffff !important;
}
.fi-sidebar-group-collapse-button {
    color: rgba(255,255,255,0.4) !important;
}
.fi-sidebar-group-collapse-button:hover {
    background-color: rgba(255,255,255,0.08) !important;
    color: #ffffff !important;
}
</style>'),
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::USER_MENU_BEFORE,
                fn () => view('filament.components.user-info'),
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::USER_MENU_BEFORE,
                fn () => view('filament.components.barcode-scanner'),
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn () => new \Illuminate\Support\HtmlString(
                    '<script src="https://cdn.jsdelivr.net/npm/@zxing/library@0.18.6/umd/index.min.js" defer></script>'
                    . '<style>@media(max-width:767px){.fi-header{display:none!important;}}</style>'
                ),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
