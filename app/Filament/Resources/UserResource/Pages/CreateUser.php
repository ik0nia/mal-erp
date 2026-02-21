<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $currentUser = auth()->user();

        if (! $currentUser instanceof User || ! $currentUser->isSuperAdmin()) {
            $data['is_admin'] = false;
            $data['is_super_admin'] = false;
        }

        if (($data['is_super_admin'] ?? false) === true) {
            $data['is_admin'] = true;
        }

        if (! ($data['is_admin'] ?? false) && ! ($data['is_super_admin'] ?? false) && blank($data['location_id'] ?? null)) {
            throw ValidationException::withMessages([
                'location_id' => 'Magazinul este obligatoriu pentru utilizatorii operaționali.',
            ]);
        }

        return $data;
    }
}
