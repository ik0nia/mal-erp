<?php

namespace App\Filament\App\Resources\PurchaseOrderResource\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequestItem;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => $this->record->status === PurchaseOrder::STATUS_DRAFT),

            // Plasează comanda
            Actions\Action::make('place')
                ->label('Plasează comanda')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn (): bool => in_array($this->record->status, [
                    PurchaseOrder::STATUS_DRAFT,
                    PurchaseOrder::STATUS_APPROVED,
                ]))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->refresh()->loadMissing(['supplier', 'items']);

                    if ($this->record->needsApproval()) {
                        $this->record->update(['status' => PurchaseOrder::STATUS_PENDING_APPROVAL]);
                        Notification::make()->warning()
                            ->title('Comanda necesită aprobare.')
                            ->body('A fost trimisă spre aprobare.')
                            ->send();
                    } else {
                        $this->record->update([
                            'status'      => PurchaseOrder::STATUS_APPROVED,
                            'approved_at' => now(),
                            'approved_by' => auth()->id(),
                        ]);
                        Notification::make()->success()
                            ->title('Comanda a fost aprobată automat.')
                            ->send();
                    }

                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Aprobă
            Actions\Action::make('approve')
                ->label('Aprobă')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool =>
                    $this->record->status === PurchaseOrder::STATUS_PENDING_APPROVAL
                    && PurchaseOrderResource::canApprove($this->record)
                )
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status'      => PurchaseOrder::STATUS_APPROVED,
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                    ]);
                    Notification::make()->success()->title('Comanda a fost aprobată.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Respinge
            Actions\Action::make('reject')
                ->label('Respinge')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool =>
                    $this->record->status === PurchaseOrder::STATUS_PENDING_APPROVAL
                    && PurchaseOrderResource::canApprove($this->record)
                )
                ->form([
                    Textarea::make('rejection_reason')
                        ->label('Motiv respingere')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'status'           => PurchaseOrder::STATUS_REJECTED,
                        'rejected_by'      => auth()->id(),
                        'rejected_at'      => now(),
                        'rejection_reason' => $data['rejection_reason'],
                    ]);
                    Notification::make()->danger()->title('Comanda a fost respinsă.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Marchează trimis
            Actions\Action::make('mark_sent')
                ->label('Marchează trimis')
                ->icon('heroicon-o-envelope')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === PurchaseOrder::STATUS_APPROVED)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status'  => PurchaseOrder::STATUS_SENT,
                        'sent_at' => now(),
                    ]);

                    $this->markRequestItemsAsOrdered();

                    Notification::make()->success()->title('Comanda a fost marcată ca trimisă.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),

            // Anulează
            Actions\Action::make('cancel')
                ->label('Anulează')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->visible(fn (): bool => ! in_array($this->record->status, [
                    PurchaseOrder::STATUS_SENT,
                    PurchaseOrder::STATUS_CANCELLED,
                ]))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
                    Notification::make()->success()->title('Comanda a fost anulată.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),
        ];
    }

    private function markRequestItemsAsOrdered(): void
    {
        $affectedRequestIds = [];

        foreach ($this->record->items as $orderItem) {
            if (! $orderItem->purchase_request_item_id) continue;

            $requestItem = PurchaseRequestItem::find($orderItem->purchase_request_item_id);
            if (! $requestItem) continue;

            $requestItem->update(['status' => PurchaseRequestItem::STATUS_ORDERED]);
            $affectedRequestIds[] = $requestItem->purchase_request_id;
        }

        foreach (array_unique($affectedRequestIds) as $requestId) {
            $request = \App\Models\PurchaseRequest::find($requestId);
            $request?->recalculateStatus();
        }
    }
}
