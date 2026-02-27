<?php

namespace App\Filament\App\Pages;

class Dashboard extends \Filament\Pages\Dashboard
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
