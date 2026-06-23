<?php

namespace App\Filament\Resources\BreachIncidentResource\Pages;

use App\Filament\Resources\BreachIncidentResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateBreachIncident extends CreateRecord
{
    protected static string $resource = BreachIncidentResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;
        $admins = User::where('is_super_admin', true)->get();

        Notification::make()
            ->title('Breșă de securitate raportată')
            ->body($record->title.' — termen notificare ANSPDCP: '
                .($record->authority_notify_due_at?->format('d.m.Y H:i') ?? '—'))
            ->danger()
            ->sendToDatabase($admins);
    }
}
