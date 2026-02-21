<?php

namespace App\Filament\App\Resources\OfferResource\Pages;

use App\Filament\App\Resources\OfferResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditOffer extends EditRecord
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Preview ofertă')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => OfferResource::getUrl('view', ['record' => $this->record]))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();

        if ($user instanceof User && ! $user->isSuperAdmin()) {
            $data['location_id'] = $this->record->location_id;
        }

        if (blank($data['location_id'] ?? null)) {
            throw ValidationException::withMessages([
                'location_id' => 'Magazinul este obligatoriu.',
            ]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->recalculateTotals();
    }
}
