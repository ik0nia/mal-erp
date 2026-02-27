<?php

namespace App\Filament\App\Resources\WooOrderResource\Pages;

use App\Filament\App\Resources\WooOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListWooOrders extends ListRecords
{
    protected static string $resource = WooOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
