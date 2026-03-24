<?php

namespace App\Filament\App\Resources\GraphicTemplateResource\Pages;

use App\Filament\App\Resources\GraphicTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGraphicTemplates extends ListRecords
{
    protected static string $resource = GraphicTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
