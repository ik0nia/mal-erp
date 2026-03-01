<?php

namespace App\Filament\App\Resources\PurchaseRequestResource\Pages;

use App\Filament\App\Resources\PurchaseRequestResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $data['user_id'] = $user->id;

            if (! $user->isSuperAdmin() && ! isset($data['location_id'])) {
                $data['location_id'] = $user->location_id;
            }
        }

        return $data;
    }
}
