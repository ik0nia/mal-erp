<?php

namespace App\Filament\App\Resources\WooProductResource\Pages;

use App\Filament\App\Resources\WooProductResource;
use Filament\Resources\Pages\ListRecords;

class ListWooProducts extends ListRecords
{
    protected static string $resource = WooProductResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
