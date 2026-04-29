<?php

namespace App\Filament\App\Resources\SocialPostResource\Pages;

use App\Filament\App\Resources\SocialPostResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSocialPosts extends ListRecords
{
    protected static string $resource = SocialPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Postare nouă'),
        ];
    }
}
