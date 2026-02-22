<?php

namespace App\Filament\App\Resources\CustomerResource\Pages;

use App\Filament\App\Resources\CustomerResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $data['location_id'] = $user->location_id;
        }

        if (blank($data['location_id'] ?? null)) {
            throw ValidationException::withMessages([
                'location_id' => 'Utilizatorul nu are setat un magazin.',
            ]);
        }

        return $data;
    }
}
