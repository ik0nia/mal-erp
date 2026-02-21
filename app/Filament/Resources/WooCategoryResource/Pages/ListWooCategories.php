<?php

namespace App\Filament\Resources\WooCategoryResource\Pages;

use App\Filament\Resources\WooCategoryResource;
use Filament\Resources\Pages\ListRecords;

class ListWooCategories extends ListRecords
{
    protected static string $resource = WooCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
