<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $currentUser = auth()->user();

        if (! $currentUser instanceof User || ! $currentUser->isSuperAdmin()) {
            $data['is_admin'] = $this->record->is_admin;
            $data['is_super_admin'] = $this->record->is_super_admin;
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
