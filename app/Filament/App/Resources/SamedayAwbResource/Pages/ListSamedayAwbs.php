<?php

namespace App\Filament\App\Resources\SamedayAwbResource\Pages;

use App\Filament\App\Resources\SamedayAwbResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSamedayAwbs extends ListRecords
{
    protected static string $resource = SamedayAwbResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
