<?php

namespace App\Filament\Resources\CompanyApiSettingResource\Pages;

use App\Filament\Resources\CompanyApiSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCompanyApiSetting extends EditRecord
{
    protected static string $resource = CompanyApiSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
