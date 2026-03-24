<?php

namespace App\Filament\App\Resources\SupplierResource\Pages;

use App\Filament\App\Resources\SupplierResource;
use App\Filament\App\Resources\SupplierResource\RelationManagers\ContactsRelationManager;
use App\Filament\App\Resources\SupplierResource\RelationManagers\EmailsRelationManager;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            ContactsRelationManager::class,
            EmailsRelationManager::class,
        ];
    }
}
