<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;

class WinmentorOferetePage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-oferte';

    protected static ?string $navigationLabel = 'Oferte';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';
    protected static ?int    $navigationSort  = 93;
    protected static ?string $title = 'Oferte WinMentor';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
