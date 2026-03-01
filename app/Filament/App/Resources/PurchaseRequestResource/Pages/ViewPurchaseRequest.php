<?php

namespace App\Filament\App\Resources\PurchaseRequestResource\Pages;

use App\Filament\App\Resources\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseRequest extends ViewRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => PurchaseRequestResource::canEdit($this->record)),

            Actions\Action::make('submit')
                ->label('Trimite spre buyer')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === PurchaseRequest::STATUS_DRAFT)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => PurchaseRequest::STATUS_SUBMITTED]);
                    Notification::make()->success()->title('Necesarul a fost trimis.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),

            Actions\Action::make('cancel')
                ->label('Anulează')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, [
                    PurchaseRequest::STATUS_DRAFT,
                    PurchaseRequest::STATUS_SUBMITTED,
                ]) && PurchaseRequestResource::canEdit($this->record))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => PurchaseRequest::STATUS_CANCELLED]);
                    Notification::make()->success()->title('Necesarul a fost anulat.')->send();
                    $this->record->refresh();
                    $this->fillForm();
                }),
        ];
    }
}
