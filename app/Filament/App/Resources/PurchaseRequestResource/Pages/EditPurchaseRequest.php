<?php

namespace App\Filament\App\Resources\PurchaseRequestResource\Pages;

use App\Filament\App\Resources\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseRequest extends EditRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    public array $purchaseItems = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing(['items.customer', 'items.offer']);
        $this->purchaseItems = $this->record->items->map(fn ($item) => [
            'id'             => $item->id,
            'woo_product_id' => $item->woo_product_id,
            'product_label'  => ($item->sku ? "[{$item->sku}] " : '') . ($item->product_name ?? ''),
            'quantity'       => (float) $item->quantity,
            'needed_by'      => $item->needed_by?->format('Y-m-d') ?? '',
            'is_urgent'      => (bool) $item->is_urgent,
            'is_reserved'    => (bool) $item->is_reserved,
            'customer_id'    => $item->customer_id,
            'customer_label' => $item->customer?->name ?? '',
            'offer_id'       => $item->offer_id,
            'offer_label'    => $item->offer ? ($item->offer->number . ' — ' . $item->offer->client_name) : '',
            'notes'          => $item->notes ?? '',
        ])->all();

        return $data;
    }

    protected function afterSave(): void
    {
        $items   = $this->purchaseItems;
        $keepIds = [];

        foreach ($items as $item) {
            if (empty($item['woo_product_id'])) {
                continue;
            }

            $attrs = [
                'woo_product_id' => $item['woo_product_id'],
                'quantity'       => max(0.001, (float) ($item['quantity'] ?? 1)),
                'needed_by'      => $item['needed_by'] ?: null,
                'is_urgent'      => (bool) ($item['is_urgent'] ?? false),
                'is_reserved'    => (bool) ($item['is_reserved'] ?? false),
                'customer_id'    => $item['customer_id'] ?? null,
                'offer_id'       => $item['offer_id'] ?? null,
                'notes'          => $item['notes'] ?? null,
            ];

            if (! empty($item['id'])) {
                $existing = $this->record->items()->find($item['id']);
                if ($existing && $existing->status === \App\Models\PurchaseRequestItem::STATUS_PENDING) {
                    $existing->update($attrs);
                    $keepIds[] = $existing->id;
                } elseif ($existing) {
                    $keepIds[] = $existing->id; // comandat/anulat — nu atingem
                }
            } else {
                $new       = $this->record->items()->create($attrs);
                $keepIds[] = $new->id;
            }
        }

        // Șterge doar itemele PENDING care au fost eliminate din tabel
        $this->record->items()
            ->whereNotIn('id', $keepIds)
            ->where('status', \App\Models\PurchaseRequestItem::STATUS_PENDING)
            ->delete();

        $this->record->recalculateStatus();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('submit')
                ->label('Trimite spre buyer')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn () => $this->record->status === PurchaseRequest::STATUS_DRAFT)
                ->requiresConfirmation()
                ->modalHeading('Trimiți necesarul spre buyer?')
                ->modalDescription('După trimitere nu mai poți edita necesarul.')
                ->modalSubmitActionLabel('Da, trimite')
                ->action(function (): void {
                    $this->record->update(['status' => PurchaseRequest::STATUS_SUBMITTED]);
                    Notification::make()->success()->title('Necesarul a fost trimis spre buyer.')->send();
                    $this->redirect(PurchaseRequestResource::getUrl('view', ['record' => $this->record->id]));
                }),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn (): bool => PurchaseRequestResource::canDelete($this->record))
                ->requiresConfirmation(),
        ];
    }
}
