<?php

namespace App\Filament\App\Resources\WooCategoryResource\Pages;

use App\Filament\App\Resources\WooCategoryResource;
use Filament\Resources\Pages\ListRecords;

class ListWooCategories extends ListRecords
{
    protected static string $resource = WooCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
