<?php

namespace App\Filament\App\Resources\PurchaseOrderResource\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['items']) && is_array($data['items'])) {
            $data['items'] = array_values(array_filter(
                $data['items'],
                fn ($item) => isset($item['quantity']) && (float) $item['quantity'] > 0
            ));
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }
}
