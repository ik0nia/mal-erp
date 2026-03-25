<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\App\Search\AppGlobalSearchProvider;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use App\Models\AppSetting;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
            ->login(\App\Filament\App\Pages\Auth\Login::class)
            ->maxContentWidth(Width::Full)
            ->globalSearch(AppGlobalSearchProvider::class)
            ->globalSearchKeyBindings(['mod+k'])
            ->globalSearchDebounce('300ms')
            ->colors([
                'primary' => Color::Red,
            ])
            ->brandName(function () {
                try {
                    return AppSetting::get(AppSetting::KEY_BRAND_NAME, 'Malinco ERP');
                } catch (\Throwable $e) {
                    return config('app.name', 'Malinco ERP');
                }
            })
            ->brandLogo(function (): ?string {
                try {
                    $path = AppSetting::get(AppSetting::KEY_LOGO_PATH);
                    return $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
                } catch (\Throwable $e) {
                    return null;
                }
            })
            ->brandLogoHeight('40px')
            ->navigationGroups(
                (function () {
                    try {
                        return collect(AppSetting::NAV_GROUPS)
                            ->map(fn ($meta, $key) => [
                                'label' => $meta['label'],
                                'sort'  => (int) AppSetting::get($key, (string) $meta['default']),
                            ])
                            ->sortBy('sort')
                            ->map(fn ($item) => NavigationGroup::make($item['label']))
                            ->values()
                            ->all();
                    } catch (\Throwable $e) {
                        return [];
                    }
                })()
            )
            ->navigationItems([
                NavigationItem::make('Editor Vizual')
                    ->icon('heroicon-o-paint-brush')
                    ->group('Social Media')
                    ->sort(13)
                    ->url(fn () => route('template-editor.show', \App\Models\GraphicTemplate::first()?->id ?? 1))
                    ->openUrlInNewTab()
                    ->hidden(fn () => ! \App\Models\RolePermission::check(
                        \App\Filament\App\Pages\GraphicTemplateVisualEditorPage::class, 'can_access'
                    )),
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([])
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn () => new \Illuminate\Support\HtmlString('<style>
/* ── Global Search (v4) ── */
.fi-topbar {
    width: 100% !important;
}
.fi-topbar-end {
    flex-grow: 1 !important;
    flex-shrink: 1 !important;
    min-width: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
}
.fi-global-search-ctn {
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
    width: max(100%, min(calc(100% + 400px), 90vw)) !important;
    max-width: none !important;
}
.erp-user-info {
    margin-left: auto !important;
    text-align: right !important;
    align-items: flex-end !important;
    flex-shrink: 0 !important;
}
.fi-topbar .fi-user-menu {
    flex-shrink: 0 !important;
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
/* Items normale — v4: fi-sidebar-item-btn (nu fi-sidebar-item-button) */
.fi-sidebar-item-label {
    color: rgba(255,255,255,0.85) !important;
}
.fi-sidebar-item-icon {
    color: rgba(255,255,255,0.6) !important;
}
/* Hover */
.fi-sidebar-item-btn:hover {
    background-color: rgba(255,255,255,0.18) !important;
}
.fi-sidebar-item-btn:hover .fi-sidebar-item-label,
.fi-sidebar-item-btn:hover .fi-sidebar-item-icon {
    color: #ffffff !important;
}
/* Item activ — fi-active e pe <li> */
.fi-sidebar-item.fi-active .fi-sidebar-item-btn,
.fi-sidebar-item.fi-active .fi-sidebar-item-btn:hover {
    background-color: #ffffff !important;
}
.fi-sidebar-item.fi-active .fi-sidebar-item-label,
.fi-sidebar-item.fi-active .fi-sidebar-item-btn:hover .fi-sidebar-item-label {
    color: #111827 !important;
    font-weight: 600;
}
.fi-sidebar-item.fi-active .fi-sidebar-item-icon,
.fi-sidebar-item.fi-active .fi-sidebar-item-btn:hover .fi-sidebar-item-icon {
    color: #111827 !important;
}
/* Badge-uri navigație */
.fi-sidebar-item-badge-ctn .fi-badge {
    background-color: #dc2626 !important;
    color: #ffffff !important;
}
/* v4: fi-sidebar-group-collapse-btn (nu fi-sidebar-group-collapse-button) */
.fi-sidebar-group-collapse-btn {
    color: rgba(255,255,255,0.4) !important;
}
.fi-sidebar-group-collapse-btn:hover {
    background-color: rgba(255,255,255,0.08) !important;
    color: #ffffff !important;
}
/* Group btn hover (săgeată collapse grup) */
.fi-sidebar-group-btn {
    color: rgba(255,255,255,0.45) !important;
}
/* Filament v4 icon safety net: prevent unsized SVG icons from appearing huge */
[data-slot="icon"]:not([class*="w-"]):not([class*="h-"]):not([class*="size-"]):not(.fi-icon) {
    width: 1.25rem;
    height: 1.25rem;
    display: inline-block;
}
/* ── Purchase Request items table ── */
.erp-pr-row {
    display: grid;
    grid-template-columns: 1fr 64px 126px 72px 82px 32px 32px;
    gap: 5px;
    align-items: center;
}
.erp-pr-item .erp-act-btn { opacity: 0; transition: opacity 0.12s ease; }
.erp-pr-item:hover .erp-act-btn { opacity: 1; }
/* ── Butoane infolist mai mari pe mobil ── */
@media (max-width: 767px) {
    .fi-in-actions .fi-btn {
        padding-top: 0.625rem !important;
        padding-bottom: 0.625rem !important;
        padding-left: 1rem !important;
        padding-right: 1rem !important;
        font-size: 0.875rem !important;
        min-height: 2.75rem !important;
        gap: 0.4rem !important;
    }
    .fi-in-actions .fi-btn-icon {
        width: 1.1rem !important;
        height: 1.1rem !important;
    }
    .fi-in-actions {
        gap: 0.5rem !important;
        flex-wrap: wrap !important;
    }
    /* Reverificare + Asociează furnizor — jumătate ecran fiecare */
    .erp-main-actions .fi-in-actions {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 0.5rem !important;
        width: 100% !important;
    }
    .erp-main-actions .fi-btn {
        width: 100% !important;
        justify-content: center !important;
        min-height: 3rem !important;
        font-size: 0.9rem !important;
    }
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
                    . '<style>@media(max-width:767px){.fi-header-heading,.fi-breadcrumbs{display:none!important;}}</style>'
                    . '<script>if(window.innerWidth<1024){document.querySelectorAll("[x-data]").forEach(function(el){if(el.__x&&el.__x.$data&&"sidebarOpen" in el.__x.$data){el.__x.$data.sidebarOpen=false;}});try{localStorage.setItem("sidebarOpen","false");}catch(e){}}</script>'
                ),
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::CONTENT_START,
                fn () => view('filament.components.necesar-draft-reminder'),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
