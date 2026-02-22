<?php

namespace App\Filament\App\Resources\OfferResource\Pages;

use App\Filament\App\Resources\OfferResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class PrintOffer extends ViewRecord
{
    protected static string $resource = OfferResource::class;

    protected static string $view = 'filament.app.resources.offer-resource.pages.print-offer';

    public function getTitle(): string
    {
        return 'Print ofertă';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('openPreview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => OfferResource::getUrl('view', ['record' => $this->record]))
                ->openUrlInNewTab(),
            Actions\Action::make('printNow')
                ->label('Print')
                ->icon('heroicon-o-printer')
                ->extraAttributes([
                    'onclick' => 'window.print()',
                ])
                ->color('success'),
            Actions\EditAction::make(),
        ];
    }
}
