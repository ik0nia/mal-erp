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
                    $data = CustomerResource::wmFinanceCached($this->record);
                    Notification::make()
                        ->title($data !== null ? 'Date reîmprospătate (local)' : 'Client neasociat în WinMentor')
                        ->body($data !== null ? ('Sold: ' . ($data['sold'] ?? '—')) : null)
                        ->{$data !== null ? 'success' : 'warning'}()
                        ->send();
                    $this->redirect(CustomerResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\EditAction::make(),
        ];
    }
}
