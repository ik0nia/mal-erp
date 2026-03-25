<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DocumentSeriesSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Serii documente';
    protected static ?string $title           = 'Setări serii documente';
    protected static ?int    $navigationSort  = 99;
    protected string  $view            = 'filament.pages.document-series-settings';

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'offer_series'       => AppSetting::get(AppSetting::KEY_OFFER_SERIES, 'OFF'),
            'offer_start_number' => AppSetting::get(AppSetting::KEY_OFFER_START_NUMBER, '1'),
            'pnr_series'         => AppSetting::get(AppSetting::KEY_PNR_SERIES, 'PNR'),
            'pnr_start_number'   => AppSetting::get(AppSetting::KEY_PNR_START_NUMBER, '1'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Oferte')
                    ->columns(2)
                    ->schema([
                        TextInput::make('offer_series')
                            ->label('Serie')
                            ->placeholder('OFF')
                            ->maxLength(20)
                            ->helperText('Ex: OFF, OFR — apare în prefixul numărului.'),

                        TextInput::make('offer_start_number')
                            ->label('Număr de start')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('1')
                            ->helperText('Următoarea ofertă va primi cel puțin acest număr.'),
                    ]),

                Section::make('Necesar de marfă')
                    ->columns(2)
                    ->schema([
                        TextInput::make('pnr_series')
                            ->label('Serie')
                            ->placeholder('PNR')
                            ->maxLength(20)
                            ->helperText('Ex: PNR, NEC — apare în prefixul numărului.'),

                        TextInput::make('pnr_start_number')
                            ->label('Număr de start')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('1')
                            ->helperText('Următorul necesar va primi cel puțin acest număr.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        AppSetting::set(AppSetting::KEY_OFFER_SERIES, filled($state['offer_series'] ?? null) ? strtoupper(trim($state['offer_series'])) : 'OFF');
        AppSetting::set(AppSetting::KEY_OFFER_START_NUMBER, (string) max(1, (int) ($state['offer_start_number'] ?? 1)));

        AppSetting::set(AppSetting::KEY_PNR_SERIES, filled($state['pnr_series'] ?? null) ? strtoupper(trim($state['pnr_series'])) : 'PNR');
        AppSetting::set(AppSetting::KEY_PNR_START_NUMBER, (string) max(1, (int) ($state['pnr_start_number'] ?? 1)));

        Notification::make()
            ->title('Serii documente salvate')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Salvează setările')
                ->action('save')
                ->color('primary'),
        ];
    }
}
