<?php

namespace App\Filament\App\Resources\OfferResource\Pages;

use App\Filament\App\Resources\OfferResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditOffer extends EditRecord
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Preview ofertă')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => OfferResource::getUrl('view', ['record' => $this->record]))
                ->openUrlInNewTab(),
            Actions\Action::make('download_pdf')
                ->label('Descarcă PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): \Symfony\Component\HttpFoundation\StreamedResponse {
                    \App\Services\Offers\OfferPdf::invalidate($this->record);
                    $content  = \App\Services\Offers\OfferPdf::get($this->record);
                    $filename = \App\Services\Offers\OfferPdf::filename($this->record);

                    return response()->streamDownload(
                        fn () => print($content),
                        $filename,
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();

        if ($user instanceof User && ! $user->isSuperAdmin()) {
            $data['location_id'] = $this->record->location_id;
        }

        if (blank($data['location_id'] ?? null)) {
            throw ValidationException::withMessages([
                'location_id' => 'Magazinul este obligatoriu.',
            ]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->recalculateTotals();
        $this->record->notifyApproversIfNeeded();
    }
}
