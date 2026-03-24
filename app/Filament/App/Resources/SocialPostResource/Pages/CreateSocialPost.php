<?php

namespace App\Filament\App\Resources\SocialPostResource\Pages;

use App\Filament\App\Resources\SocialPostResource;
use App\Jobs\GenerateSocialPostJob;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateSocialPost extends CreateRecord
{
    protected static string $resource = SocialPostResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function afterCreate(): void
    {
        GenerateSocialPostJob::dispatch($this->record->id);

        Notification::make()
            ->title('Generare pornită')
            ->body('Claude și Gemini generează caption-ul și imaginea. Pagina se actualizează automat.')
            ->info()
            ->send();
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null; // Notificarea custom e trimisă în afterCreate
    }
}
