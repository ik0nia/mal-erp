<?php

namespace App\Filament\Resources\ProductPriceLogResource\Pages;

use App\Filament\Resources\ProductPriceLogResource;
use Filament\Resources\Pages\ListRecords;

class ListProductPriceLogs extends ListRecords
{
    protected static string $resource = ProductPriceLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
