<?php

namespace App\Filament\Resources\WooProductResource\Pages;

use App\Filament\Resources\WooProductResource;
use Filament\Resources\Pages\ListRecords;

class ListWooProducts extends ListRecords
{
    protected static string $resource = WooProductResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
