<?php

namespace App\Filament\App\Resources\SmPostResource\Pages;

use App\Filament\App\Resources\SmPostResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSmPosts extends ListRecords
{
    protected static string $resource = SmPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create')
                ->label('Postare nouă')
                ->icon('heroicon-o-plus')
                ->url(CreateSmPost::getUrl()),
        ];
    }
}
