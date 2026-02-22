<?php

namespace App\Filament\App\Resources\OfferResource\Pages;

use App\Filament\App\Resources\OfferResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateOffer extends CreateRecord
{
    protected static string $resource = OfferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $data['user_id'] = $user->id;

            if (! $user->isSuperAdmin()) {
                $data['location_id'] = $user->location_id;
            }
        }

        if (blank($data['location_id'] ?? null)) {
            throw ValidationException::withMessages([
                'location_id' => 'Magazinul este obligatoriu.',
            ]);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->recalculateTotals();
    }
}
