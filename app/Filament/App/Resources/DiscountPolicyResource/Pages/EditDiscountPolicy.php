<?php

namespace App\Filament\App\Resources\DiscountPolicyResource\Pages;

use App\Filament\App\Resources\DiscountPolicyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDiscountPolicy extends EditRecord
{
    protected static string $resource = DiscountPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
