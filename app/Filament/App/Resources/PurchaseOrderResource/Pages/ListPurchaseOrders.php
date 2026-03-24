<?php

namespace App\Filament\App\Resources\PurchaseOrderResource\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Filament\App\Widgets\PendingPurchaseItemsWidget;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createPo')
                ->label('Creează PO')
                ->icon('heroicon-o-shopping-bag')
                ->visible(fn (): bool => PurchaseOrderResource::canCreate())
                ->form([
                    Select::make('supplier_id')
                        ->label('Furnizor')
                        ->options(function (): array {
                            $query = Supplier::query()->where('is_active', true);
                            $user  = auth()->user();
                            if ($user && ! $user->isAdmin()) {
                                $query->whereHas('buyers', fn ($q) => $q->where('users.id', $user->id));
                            }
                            return $query->orderBy('name')->pluck('name', 'id')->all();
                        })
                        ->searchable()
                        ->required()
                        ->placeholder('Selectează furnizorul'),
                ])
                ->modalHeading('Creează PO nou')
                ->modalSubmitActionLabel('Continuă')
                ->action(fn (array $data) => $this->redirect(
                    PurchaseOrderResource::getUrl('create', [
                        'supplier_id' => $data['supplier_id'],
                    ])
                )),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        if (! PendingPurchaseItemsWidget::canView()) {
            return [];
        }

        return [
            PendingPurchaseItemsWidget::class,
        ];
    }
}
