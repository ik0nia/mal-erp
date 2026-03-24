<?php

namespace App\Filament\App\Resources\PurchaseRequestResource\Pages;

use App\Filament\App\Forms\Components\PurchaseItemsTable;
use App\Filament\App\Resources\PurchaseRequestResource;
use App\Models\User;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    public array $purchaseItems = [];

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Adaugă necesar';
    }

    public function getBreadcrumb(): string
    {
        return 'Adaugă necesar';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Produse solicitate')
                ->columnSpanFull()
                ->schema([
                    PurchaseItemsTable::make('items')->label(''),
                ]),

            Textarea::make('notes')
                ->label('Observații')
                ->rows(2)
                ->placeholder('Observații suplimentare…'),
        ]);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Salvează necesar'),
            $this->getCancelFormAction()->label('Renunță'),
        ];
    }

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

    protected function afterCreate(): void
    {
        foreach ($this->purchaseItems as $item) {
            if (empty($item['woo_product_id'])) {
                continue;
            }

            $this->record->items()->create([
                'woo_product_id' => $item['woo_product_id'],
                'quantity'       => max(0.001, (float) ($item['quantity'] ?? 1)),
                'needed_by'      => $item['needed_by'] ?: null,
                'is_urgent'      => (bool) ($item['is_urgent'] ?? false),
                'is_reserved'    => (bool) ($item['is_reserved'] ?? false),
                'customer_id'    => $item['customer_id'] ?? null,
                'offer_id'       => $item['offer_id'] ?? null,
                'notes'          => $item['notes'] ?? null,
            ]);
        }
    }
}
