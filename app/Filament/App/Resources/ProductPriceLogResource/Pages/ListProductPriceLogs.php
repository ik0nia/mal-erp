<?php

namespace App\Filament\App\Resources\ProductPriceLogResource\Pages;

use App\Filament\App\Resources\ProductPriceLogResource;
use Filament\Resources\Pages\ListRecords;

class ListProductPriceLogs extends ListRecords
{
    protected static string $resource = ProductPriceLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
