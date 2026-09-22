<?php

namespace App\Filament\Resources\PageVisitResource\Pages;

use App\Filament\Resources\PageVisitResource;
use Filament\Resources\Pages\ListRecords;

class ListPageVisits extends ListRecords
{
    protected static string $resource = PageVisitResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
