<?php

namespace App\Filament\App\Resources\PurchaseRequestResource\Pages;

use App\Filament\App\Resources\PurchaseRequestResource;
use App\Models\EmailMessage;
use App\Models\PurchaseRequest;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
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

            Actions\DeleteAction::make()
                ->visible(fn (): bool => PurchaseRequestResource::canDelete($this->record))
                ->requiresConfirmation(),
        ];
    }

    /**
     * Returnează ultimele emailuri de la furnizorii din acest necesitar.
     */
    public function getSupplierEmails(): \Illuminate\Support\Collection
    {
        $supplierIds = $this->record->items()
            ->whereNotNull('supplier_id')
            ->pluck('supplier_id')
            ->unique()
            ->values();

        if ($supplierIds->isEmpty()) {
            return collect();
        }

        return EmailMessage::with('supplier')
            ->whereIn('supplier_id', $supplierIds)
            ->whereIn('imap_folder', ['INBOX', 'INBOX.Sent'])
            ->orderByDesc('sent_at')
            ->limit(8)
            ->get();
    }
}
