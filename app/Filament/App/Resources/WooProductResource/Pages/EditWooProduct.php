<?php

namespace App\Filament\App\Resources\WooProductResource\Pages;

use App\Filament\App\Resources\WooProductResource;
use App\Jobs\SyncProductSupplierMetaJob;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
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

        // Procesează modul "La comandă"
        $isOnDemand = (bool) ($this->data['is_on_demand'] ?? false);
        $newType    = $isOnDemand ? WooProduct::PROCUREMENT_ON_DEMAND : WooProduct::PROCUREMENT_STOCK;

        if ($product->procurement_type !== $newType) {
            $product->update([
                'procurement_type' => $newType,
                'on_demand_label'  => $isOnDemand ? ($this->data['on_demand_label'] ?? null) : null,
            ]);

            // Push backorders la WooCommerce dacă produsul are woo_id
            if ($product->woo_id) {
                try {
                    $connection = $product->connection;
                    $client     = new WooClient($connection);
                    $client->updateProduct($product->woo_id, $isOnDemand
                        ? ['manage_stock' => true, 'stock_quantity' => 0, 'backorders' => 'yes']
                        : ['backorders' => 'no']
                    );
                } catch (\Throwable) {
                    // Nu blocăm salvarea dacă WooCommerce nu răspunde
                }
            }
        } elseif ($isOnDemand) {
            // Actualizează label-ul chiar dacă tipul nu s-a schimbat
            $product->update(['on_demand_label' => $this->data['on_demand_label'] ?? null]);
        }

        // Sincronizează furnizorul preferat în meta WooCommerce via plugin
        if ($product->woo_id && ! $product->is_placeholder) {
            SyncProductSupplierMetaJob::dispatch($product->id);
        }
    }
}
