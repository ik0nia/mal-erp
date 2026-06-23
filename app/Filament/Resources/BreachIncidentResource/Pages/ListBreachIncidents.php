<?php

namespace App\Filament\Resources\BreachIncidentResource\Pages;

use App\Filament\Resources\BreachIncidentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBreachIncidents extends ListRecords
{
    protected static string $resource = BreachIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Raportează breșă'),
        ];
    }
}
