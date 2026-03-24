<?php

namespace App\Filament\App\Pages;
use App\Models\RolePermission;
use App\Filament\App\Concerns\HasDynamicNavSort;

use Filament\Pages\Page;

class ComenziMagazin extends Page
{
    use HasDynamicNavSort;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|\UnitEnum|null $navigationGroup = 'Comenzi';

    protected static ?string $navigationLabel = 'Comenzi Magazin';

    protected static ?string $title = 'Comenzi Magazin';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.app.pages.comenzi-magazin';

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return RolePermission::check(static::class, 'can_access');
    }

    public function bootGuardAccess(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }
}
