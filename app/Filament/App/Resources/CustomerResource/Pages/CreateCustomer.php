<?php

namespace App\Filament\App\Resources\CustomerResource\Pages;

use App\Filament\App\Resources\CustomerResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
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
