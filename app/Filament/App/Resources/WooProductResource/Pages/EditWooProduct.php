<?php

namespace App\Filament\App\Resources\WooProductResource\Pages;

use App\Filament\App\Resources\WooProductResource;
use App\Models\WooProduct;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWooProduct extends EditRecord
{
    protected static string $resource = WooProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var WooProduct $product */
        $product = $this->getRecord();

        $data['suppliers_data'] = $product->suppliers->map(function ($supplier): array {
            return [
                'supplier_id'    => $supplier->id,
                'supplier_sku'   => $supplier->pivot->supplier_sku,
                'purchase_price' => $supplier->pivot->purchase_price,
                'currency'       => $supplier->pivot->currency ?? 'RON',
                'lead_days'      => $supplier->pivot->lead_days,
                'min_order_qty'  => $supplier->pivot->min_order_qty,
                'is_preferred'   => (bool) $supplier->pivot->is_preferred,
                'notes'          => $supplier->pivot->notes,
            ];
        })->values()->all();

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var WooProduct $product */
        $product = $this->getRecord();

        $suppliersData = $this->data['suppliers_data'] ?? [];

        $syncData = [];
        foreach ($suppliersData as $row) {
            $supplierId = (int) ($row['supplier_id'] ?? 0);

            if ($supplierId <= 0) {
                continue;
            }

            $syncData[$supplierId] = [
                'supplier_sku'   => $row['supplier_sku'] ?? null,
                'purchase_price' => $row['purchase_price'] ?? null,
                'currency'       => $row['currency'] ?? 'RON',
                'lead_days'      => $row['lead_days'] ?? null,
                'min_order_qty'  => $row['min_order_qty'] ?? null,
                'is_preferred'   => (bool) ($row['is_preferred'] ?? false),
                'notes'          => $row['notes'] ?? null,
            ];
        }

        $product->suppliers()->sync($syncData);
    }
}
