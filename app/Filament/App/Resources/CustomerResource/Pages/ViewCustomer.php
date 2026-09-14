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
            Actions\Action::make('linkCustomer')
                ->label('Leagă cu alt client')
                ->icon('heroicon-o-link')
                ->color('info')
                ->modalHeading('Leagă cu alt client (același client real)')
                ->modalDescription('Fișele legate împart istoricul: comenzi, facturi, încasări, top produse și activitate se agregă pe toate.')
                ->schema([
                    \Filament\Forms\Components\Select::make('customer_id')
                        ->label('Client de legat')
                        ->searchable()
                        ->required()
                        ->getSearchResultsUsing(fn (string $search): array => \App\Models\Customer::query()
                            ->where('id', '!=', $this->record->id)
                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('cui', 'like', "%{$search}%"))
                            ->limit(20)->get()
                            ->mapWithKeys(fn ($c) => [$c->id => $c->name . ($c->cui ? " · {$c->cui}" : '')])->all()),
                ])
                ->action(function (array $data): void {
                    CustomerResource::linkCustomers($this->record, (int) $data['customer_id']);
                    Notification::make()->title('Clienți legați')->success()->send();
                    $this->redirect(CustomerResource::getUrl('view', ['record' => $this->record]));
                }),

            Actions\Action::make('unlinkCustomer')
                ->label('Dezleagă din grup')
                ->icon('heroicon-o-link-slash')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->customer_group_id))
                ->requiresConfirmation()
                ->action(function (): void {
                    CustomerResource::unlinkCustomer($this->record);
                    Notification::make()->title('Scos din grup')->send();
                    $this->redirect(CustomerResource::getUrl('view', ['record' => $this->record]));
                }),

            Actions\EditAction::make(),
        ];
    }
}
