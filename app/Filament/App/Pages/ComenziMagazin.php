<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;

class ComenziMagazin extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Comenzi';

    protected static ?string $navigationLabel = 'Comenzi Magazin';

    protected static ?string $title = 'Comenzi Magazin';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.app.pages.comenzi-magazin';
}
