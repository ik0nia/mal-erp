<?php

namespace App\Filament\App\Resources\CustomerResource\Pages;

use App\Filament\App\Resources\CustomerResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Cache;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('loadWm')
                ->label(fn (): string => CustomerResource::wmFinanceCached($this->record) === null
                    ? 'Încarcă date WinMentor'
                    : 'Reîmprospătează date WinMentor')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    Cache::forget("cust_wm_{$this->record->id}");
                    $data = CustomerResource::loadWmFinance($this->record);
                    Notification::make()
                        ->title(empty($data['eroare']) ? 'Date WinMentor încărcate' : 'Eroare WinMentor')
                        ->body($data['eroare'] ?? ('Sold: ' . ($data['sold'] ?? '—')))
                        ->{empty($data['eroare']) ? 'success' : 'danger'}()
                        ->send();
                    $this->redirect(CustomerResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\EditAction::make(),
        ];
    }
}
