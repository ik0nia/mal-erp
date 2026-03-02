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
                ->visible(fn (): bool => PurchaseRequestResource::canDelete($this->record)),
        ];
    }
}
