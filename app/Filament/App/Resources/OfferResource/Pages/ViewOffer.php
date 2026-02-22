<?php

namespace App\Filament\App\Resources\OfferResource\Pages;

use App\Filament\App\Resources\OfferResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewOffer extends ViewRecord
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('Print ofertă')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => OfferResource::getUrl('print', ['record' => $this->record]))
                ->openUrlInNewTab(),
            Actions\EditAction::make(),
        ];
    }
}
