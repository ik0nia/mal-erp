<?php

namespace App\Filament\App\Pages;

use App\Models\AppSetting;
use App\Models\Offer;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OfferSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static string|\UnitEnum|null $navigationGroup = 'Vânzări';
    protected static ?string $navigationLabel = 'Setări oferte';
    protected static ?string $title = 'Setări oferte';
    protected static ?int $navigationSort = 12;
    protected string $view = 'filament.app.pages.offer-settings';

    public array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isAdmin() || $user->isSuperAdmin());
    }

    public function mount(): void
    {
        $this->form->fill([
            'offer_series'        => AppSetting::get(AppSetting::KEY_OFFER_SERIES, 'OFF'),
            'offer_start_number'  => AppSetting::get(AppSetting::KEY_OFFER_START_NUMBER, '1'),
            'offer_vat_rate'      => AppSetting::get(AppSetting::KEY_OFFER_VAT_RATE, '21'),
            'def_discount_cond'   => AppSetting::get(AppSetting::KEY_OFFER_DEF_DISCOUNT_COND, Offer::DISCOUNT_PER_LINE),
            'def_transport'       => AppSetting::get(AppSetting::KEY_OFFER_DEF_TRANSPORT, Offer::TRANSPORT_NOT_INCLUDED),
            'def_payment'         => AppSetting::get(AppSetting::KEY_OFFER_DEF_PAYMENT),
            'def_delivery'        => AppSetting::get(AppSetting::KEY_OFFER_DEF_DELIVERY),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Numerotare')
                    ->columns(2)
                    ->schema([
                        TextInput::make('offer_series')
                            ->label('Serie')
                            ->placeholder('OFF')
                            ->maxLength(20),
                        TextInput::make('offer_start_number')
                            ->label('Număr de start')
                            ->numeric()->minValue(1)->placeholder('1'),
                    ]),
                Section::make('TVA')
                    ->schema([
                        TextInput::make('offer_vat_rate')
                            ->label('Cota TVA implicită')
                            ->numeric()->minValue(0)->maxValue(100)->step(0.01)
                            ->suffix('%')
                            ->required()
                            ->helperText('Aplicată automat pe liniile noi. RO standard: 21%. Editabilă per linie în ofertă.'),
                    ]),
                Section::make('Condiții implicite')
                    ->description('Prefill pentru ofertele noi — pot fi modificate per ofertă.')
                    ->columns(2)
                    ->schema([
                        Select::make('def_discount_cond')
                            ->label('Aplicare discount')
                            ->options(Offer::discountConditionOptions())
                            ->native(false),
                        Select::make('def_transport')
                            ->label('Transport')
                            ->options(Offer::transportModeOptions())
                            ->native(false),
                        TextInput::make('def_payment')
                            ->label('Termen de plată')
                            ->placeholder('ex: 100% la livrare'),
                        TextInput::make('def_delivery')
                            ->label('Termen de livrare')
                            ->placeholder('ex: 3-5 zile lucrătoare din stoc'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        AppSetting::set(AppSetting::KEY_OFFER_SERIES, filled($state['offer_series'] ?? null) ? strtoupper(trim($state['offer_series'])) : 'OFF');
        AppSetting::set(AppSetting::KEY_OFFER_START_NUMBER, (string) max(1, (int) ($state['offer_start_number'] ?? 1)));
        AppSetting::set(AppSetting::KEY_OFFER_VAT_RATE, (string) max(0, (float) ($state['offer_vat_rate'] ?? 21)));
        AppSetting::set(AppSetting::KEY_OFFER_DEF_DISCOUNT_COND, $state['def_discount_cond'] ?? Offer::DISCOUNT_PER_LINE);
        AppSetting::set(AppSetting::KEY_OFFER_DEF_TRANSPORT, $state['def_transport'] ?? Offer::TRANSPORT_NOT_INCLUDED);
        AppSetting::set(AppSetting::KEY_OFFER_DEF_PAYMENT, filled($state['def_payment'] ?? null) ? trim($state['def_payment']) : null);
        AppSetting::set(AppSetting::KEY_OFFER_DEF_DELIVERY, filled($state['def_delivery'] ?? null) ? trim($state['def_delivery']) : null);

        Notification::make()->title('Setări oferte salvate')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Salvează')
                ->action('save')
                ->color('primary'),
        ];
    }
}
