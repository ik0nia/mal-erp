<?php

namespace App\Filament\App\Resources\EanAssociationRequestResource\Pages;

use App\Filament\App\Resources\EanAssociationRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListEanAssociationRequests extends ListRecords
{
    protected static string $resource = EanAssociationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
