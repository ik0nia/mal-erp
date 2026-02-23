<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\SamedayAwbResource\Pages;
use App\Models\IntegrationConnection;
use App\Models\SamedayAwb;
use App\Models\User;
use App\Services\Courier\SamedayAwbService;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Throwable;

class SamedayAwbResource extends Resource
{
    protected static ?string $model = SamedayAwb::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Livrare';

    protected static ?string $navigationLabel = 'AWB Sameday';

    protected static ?string $modelLabel = 'AWB Sameday';

    protected static ?string $pluralModelLabel = 'AWB-uri Sameday';

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::hasAnyAvailableSamedayConnection();
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return static::hasAnyAvailableSamedayConnection();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Expeditor')
                    ->columns(2)
                    ->schema([
                        Hidden::make('location_id')
                            ->default(fn (): ?int => static::currentUser()?->location_id)
                            ->dehydrated(),
                        Select::make('pickup_point_id')
                            ->label('Pickup point Sameday')
                            ->helperText('Se selectează automat primul pickup point disponibil pentru locația ta.')
                            ->options(fn (): array => static::pickupPointOptionsForCurrentUserLocation())
                            ->default(fn (): ?int => static::defaultPickupPointForCurrentUserLocation())
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $set(
                                    'contact_person_id',
                                    static::defaultContactPersonForCurrentUserLocation((int) ($state ?? 0))
                                );
                            })
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                        Select::make('contact_person_id')
                            ->label('Persoană contact')
                            ->options(fn (Get $get): array => static::contactPersonOptionsForCurrentUserLocation((int) ($get('pickup_point_id') ?? 0)))
                            ->default(fn (Get $get): ?int => static::defaultContactPersonForCurrentUserLocation((int) ($get('pickup_point_id') ?? 0)))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                        Select::make('service_id')
                            ->label('Serviciu Sameday')
                            ->helperText('Dacă lași gol, se folosește serviciul default din cont.')
                            ->default(fn (): ?int => static::defaultServiceForCurrentUserLocation())
                            ->options(fn (): array => static::serviceOptionsForCurrentUserLocation())
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('service_tax_ids', []);
                                $set('delivery_interval_id', null);
                            })
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                        Select::make('service_tax_ids')
                            ->label('Servicii adiționale')
                            ->options(fn (Get $get): array => static::serviceTaxOptionsForCurrentUserLocation((int) ($get('service_id') ?? 0)))
                            ->multiple()
                            ->searchable()
                            ->native(false),
                        Select::make('delivery_interval_id')
                            ->label('Interval livrare')
                            ->helperText('Disponibil doar pentru serviciile care permit intervale dedicate.')
                            ->options(fn (Get $get): array => static::deliveryIntervalOptionsForCurrentUserLocation((int) ($get('service_id') ?? 0)))
                            ->searchable()
                            ->native(false)
                            ->nullable(),
                        Toggle::make('third_party_pickup')
                            ->label('Ridicare de la terț')
                            ->helperText('Activează dacă ridicarea se face de la altă adresă decât pickup point-ul selectat.')
                            ->default(false)
                            ->inline(false),
                        TextInput::make('third_party_name')
                            ->label('Nume terț ridicare')
                            ->visible(fn (Get $get): bool => (bool) $get('third_party_pickup'))
                            ->maxLength(255),
                        TextInput::make('third_party_phone')
                            ->label('Telefon terț ridicare')
                            ->visible(fn (Get $get): bool => (bool) $get('third_party_pickup'))
                            ->maxLength(64),
                        TextInput::make('third_party_county')
                            ->label('Județ terț ridicare')
                            ->visible(fn (Get $get): bool => (bool) $get('third_party_pickup'))
                            ->maxLength(255),
                        TextInput::make('third_party_city')
                            ->label('Oraș terț ridicare')
                            ->visible(fn (Get $get): bool => (bool) $get('third_party_pickup'))
                            ->maxLength(255),
                        TextInput::make('third_party_postal_code')
                            ->label('Cod poștal terț')
                            ->visible(fn (Get $get): bool => (bool) $get('third_party_pickup'))
                            ->maxLength(32),
                        Textarea::make('third_party_address')
                            ->label('Adresă terț ridicare')
                            ->visible(fn (Get $get): bool => (bool) $get('third_party_pickup'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Destinatar')
                    ->columns(2)
                    ->schema([
                        Select::make('recipient_type')
                            ->label('Tip destinatar')
                            ->options([
                                'individual' => 'Persoană fizică',
                                'company' => 'Persoană juridică',
                            ])
                            ->default('individual')
                            ->live()
                            ->native(false),
                        TextInput::make('recipient_name')
                            ->label('Nume destinatar')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('recipient_phone')
                            ->label('Telefon destinatar')
                            ->required()
                            ->maxLength(64),
                        TextInput::make('recipient_company_name')
                            ->label('Companie destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->required(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->maxLength(255),
                        TextInput::make('recipient_company_cui')
                            ->label('CUI destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->maxLength(64),
                        TextInput::make('recipient_company_onrc')
                            ->label('Nr. ONRC destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->maxLength(128),
                        TextInput::make('recipient_company_bank')
                            ->label('Bancă destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->maxLength(128),
                        TextInput::make('recipient_company_iban')
                            ->label('IBAN destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->maxLength(64),
                        TextInput::make('recipient_email')
                            ->label('Email destinatar')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('recipient_postal_code')
                            ->label('Cod poștal')
                            ->maxLength(32),
                        Toggle::make('recipient_manual_locality')
                            ->label('Introdu localitatea manual')
                            ->helperText('Dezactivează pentru a selecta județ și oraș din nomenclatorul Sameday.')
                            ->default(false)
                            ->live()
                            ->inline(false)
                            ->columnSpanFull(),
                        Select::make('recipient_county_id')
                            ->label('Județ (Sameday)')
                            ->options(fn (): array => static::countyOptionsForCurrentUserLocation())
                            ->visible(fn (Get $get): bool => ! (bool) $get('recipient_manual_locality'))
                            ->required(fn (Get $get): bool => ! (bool) $get('recipient_manual_locality'))
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $countyId = (int) ($state ?? 0);
                                $set('recipient_city_id', null);
                                $set('recipient_county', static::countyNameForCurrentUserLocation($countyId) ?? '');
                                $set('recipient_city', '');
                            }),
                        Select::make('recipient_city_id')
                            ->label('Oraș (Sameday)')
                            ->options(fn (Get $get): array => static::cityOptionsForCurrentUserLocation((int) ($get('recipient_county_id') ?? 0)))
                            ->visible(fn (Get $get): bool => ! (bool) $get('recipient_manual_locality'))
                            ->required(fn (Get $get): bool => ! (bool) $get('recipient_manual_locality'))
                            ->disabled(fn (Get $get): bool => (int) ($get('recipient_county_id') ?? 0) <= 0)
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                $countyId = (int) ($get('recipient_county_id') ?? 0);
                                $cityId = (int) ($state ?? 0);
                                $set('recipient_city', static::cityNameForCurrentUserLocation($countyId, $cityId) ?? '');
                            }),
                        TextInput::make('recipient_county')
                            ->label('Județ')
                            ->required()
                            ->readOnly(fn (Get $get): bool => ! (bool) $get('recipient_manual_locality'))
                            ->maxLength(255),
                        TextInput::make('recipient_city')
                            ->label('Oraș')
                            ->required()
                            ->readOnly(fn (Get $get): bool => ! (bool) $get('recipient_manual_locality'))
                            ->maxLength(255),
                        TextInput::make('recipient_street')
                            ->label('Stradă')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('recipient_street_no')
                            ->label('Număr')
                            ->maxLength(50),
                        TextInput::make('recipient_block')
                            ->label('Bloc')
                            ->maxLength(50),
                        TextInput::make('recipient_staircase')
                            ->label('Scară')
                            ->maxLength(50),
                        TextInput::make('recipient_floor')
                            ->label('Etaj')
                            ->maxLength(50),
                        TextInput::make('recipient_apartment')
                            ->label('Apartament')
                            ->maxLength(50),
                        Textarea::make('recipient_address')
                            ->label('Adresă completă (opțional, override)')
                            ->helperText('Dacă lași gol, adresa se compune automat din stradă + număr + bloc + scară + etaj + apartament.')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Colet și opțiuni')
                    ->columns(3)
                    ->schema([
                        Select::make('package_type')
                            ->label('Tip trimitere')
                            ->options([
                                0 => 'Colet',
                                1 => 'Plic',
                                2 => 'Pachet mare',
                            ])
                            ->default(0)
                            ->native(false)
                            ->required(),
                        Select::make('awb_payment_type')
                            ->label('Plata expedierii la')
                            ->options([
                                1 => 'Expeditor',
                            ])
                            ->default(1)
                            ->native(false)
                            ->disabled()
                            ->dehydrated(),
                        TextInput::make('package_count')
                            ->label('Număr colete')
                            ->numeric()
                            ->required()
                            ->default(1)
                            ->minValue(1),
                        TextInput::make('package_weight_kg')
                            ->label('Greutate / colet (kg)')
                            ->numeric()
                            ->required()
                            ->default(fn (): float => static::defaultPackageWeightForCurrentUserLocation())
                            ->minValue(0.01),
                        TextInput::make('cod_amount')
                            ->label('Ramburs (RON)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        TextInput::make('insured_value')
                            ->label('Valoare asigurată (RON)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        TextInput::make('reference')
                            ->label('Referință internă')
                            ->maxLength(255),
                        Textarea::make('price_observation')
                            ->label('Observații cost')
                            ->rows(2),
                        Textarea::make('client_observation')
                            ->label('Observații client')
                            ->rows(2),
                        Textarea::make('observation')
                            ->label('Observații livrare')
                            ->rows(3)
                            ->columnSpanFull(),
                        Repeater::make('parcels')
                            ->label('Detalii colete')
                            ->helperText('Opțional. Dacă adaugi colete aici, acestea se folosesc cu greutate/dimensiuni per colet.')
                            ->schema([
                                TextInput::make('weight_kg')
                                    ->label('Greutate (kg)')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.01),
                                TextInput::make('width_cm')
                                    ->label('Lățime (cm)')
                                    ->numeric()
                                    ->minValue(1),
                                TextInput::make('length_cm')
                                    ->label('Lungime (cm)')
                                    ->numeric()
                                    ->minValue(1),
                                TextInput::make('height_cm')
                                    ->label('Înălțime (cm)')
                                    ->numeric()
                                    ->minValue(1),
                            ])
                            ->columns(4)
                            ->addActionLabel('Adaugă colet')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('awb_number')
                    ->label('AWB')
                    ->searchable()
                    ->placeholder('-')
                    ->copyable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        SamedayAwb::STATUS_CREATED => 'success',
                        SamedayAwb::STATUS_CANCELLED => 'gray',
                        SamedayAwb::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->sortable(),
                Tables\Columns\TextColumn::make('recipient_name')
                    ->label('Destinatar')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('recipient_city')
                    ->label('Oraș')
                    ->searchable(),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Colete')
                    ->numeric(),
                Tables\Columns\TextColumn::make('package_weight_kg')
                    ->label('Kg/colet')
                    ->numeric(),
                Tables\Columns\TextColumn::make('shipping_cost')
                    ->label('Cost')
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? '-' : number_format((float) $state, 2).' RON'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creat la')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        SamedayAwb::STATUS_CREATED => 'created',
                        SamedayAwb::STATUS_CANCELLED => 'cancelled',
                        SamedayAwb::STATUS_FAILED => 'failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('cancel_awb')
                    ->label('Anulează')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Anulează AWB')
                    ->modalDescription('AWB-ul va fi anulat în Sameday. Operația nu poate fi inversată.')
                    ->visible(fn (SamedayAwb $record): bool => $record->status === SamedayAwb::STATUS_CREATED && filled($record->awb_number))
                    ->action(function (SamedayAwb $record): void {
                        $connection = $record->connection;
                        if (! $connection instanceof IntegrationConnection) {
                            $connection = static::resolveSamedayConnectionForLocation((int) $record->location_id);
                        }

                        if (! $connection instanceof IntegrationConnection) {
                            Notification::make()
                                ->warning()
                                ->title('Anulare nereușită')
                                ->body('Nu există conexiune Sameday activă pentru această locație.')
                                ->send();

                            return;
                        }

                        try {
                            $result = app(SamedayAwbService::class)->cancelAwb($connection, (string) $record->awb_number);

                            $responsePayload = is_array($record->response_payload) ? $record->response_payload : [];
                            $responsePayload['cancel_awb'] = [
                                'cancelled_at' => now()->toIso8601String(),
                                'response' => $result['response_payload'] ?? null,
                            ];

                            $record->forceFill([
                                'status' => SamedayAwb::STATUS_CANCELLED,
                                'response_payload' => $responsePayload,
                                'error_message' => null,
                            ])->save();

                            Notification::make()
                                ->success()
                                ->title('AWB anulat')
                                ->body("AWB {$record->awb_number} a fost anulat în Sameday.")
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->warning()
                                ->title('Anulare nereușită')
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('details')
                    ->label('Detalii')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalHeading(fn (SamedayAwb $record): string => "AWB #{$record->id}")
                    ->modalContent(function (SamedayAwb $record): HtmlString {
                        $payload = [
                            'awb_number' => $record->awb_number,
                            'status' => $record->status,
                            'location' => $record->location?->name,
                            'recipient' => [
                                'name' => $record->recipient_name,
                                'phone' => $record->recipient_phone,
                                'email' => $record->recipient_email,
                                'county' => $record->recipient_county,
                                'city' => $record->recipient_city,
                                'address' => $record->recipient_address,
                                'postal_code' => $record->recipient_postal_code,
                            ],
                            'package' => [
                                'count' => $record->package_count,
                                'weight_kg' => $record->package_weight_kg,
                                'cod_amount' => $record->cod_amount,
                                'insured_value' => $record->insured_value,
                                'shipping_cost' => $record->shipping_cost,
                            ],
                            'reference' => $record->reference,
                            'observation' => $record->observation,
                            'error_message' => $record->error_message,
                            'request_payload' => $record->request_payload,
                            'response_payload' => $record->response_payload,
                        ];

                        return new HtmlString(
                            '<pre style="white-space: pre-wrap;">'.e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>'
                        );
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSamedayAwbs::route('/'),
            'create' => Pages\CreateSamedayAwb::route('/create'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['location', 'connection']);
        $user = static::currentUser();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('location_id', $user->operationalLocationIds());
    }

    public static function resolveSamedayConnectionForLocation(int $locationId): ?IntegrationConnection
    {
        if ($locationId <= 0) {
            return null;
        }

        return IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_SAMEDAY)
            ->where('is_active', true)
            ->where('location_id', $locationId)
            ->latest('id')
            ->first();
    }

    public static function defaultPackageWeightForLocation(int $locationId): float
    {
        $connection = static::resolveSamedayConnectionForLocation($locationId);

        if (! $connection) {
            return 1.0;
        }

        $configured = (float) data_get($connection->settings, 'default_package_weight_kg', 1);

        return $configured > 0 ? $configured : 1.0;
    }

    public static function currentUserLocationId(): int
    {
        return (int) (static::currentUser()?->location_id ?? 0);
    }

    public static function defaultPackageWeightForCurrentUserLocation(): float
    {
        return static::defaultPackageWeightForLocation(static::currentUserLocationId());
    }

    public static function defaultServiceForCurrentUserLocation(): ?int
    {
        return static::firstPositiveIntKey(static::serviceOptionsForCurrentUserLocation());
    }

    /**
     * @return array<int, string>
     */
    public static function pickupPointOptionsForLocation(int $locationId): array
    {
        $connection = static::resolveSamedayConnectionForLocation($locationId);
        if (! $connection) {
            return [];
        }

        try {
            return app(SamedayAwbService::class)->getPickupPointOptions($connection);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    public static function pickupPointOptionsForCurrentUserLocation(): array
    {
        return static::pickupPointOptionsForLocation(static::currentUserLocationId());
    }

    public static function defaultPickupPointForCurrentUserLocation(): ?int
    {
        return static::firstPositiveIntKey(static::pickupPointOptionsForCurrentUserLocation());
    }

    /**
     * @return array<int, string>
     */
    public static function contactPersonOptionsForLocation(int $locationId, ?int $pickupPointId = null): array
    {
        $connection = static::resolveSamedayConnectionForLocation($locationId);
        if (! $connection) {
            return [];
        }

        try {
            return app(SamedayAwbService::class)->getContactPersonOptions($connection, $pickupPointId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    public static function contactPersonOptionsForCurrentUserLocation(?int $pickupPointId = null): array
    {
        return static::contactPersonOptionsForLocation(static::currentUserLocationId(), $pickupPointId);
    }

    public static function defaultContactPersonForCurrentUserLocation(?int $pickupPointId = null): ?int
    {
        return static::firstPositiveIntKey(static::contactPersonOptionsForCurrentUserLocation($pickupPointId));
    }

    /**
     * @return array<int, string>
     */
    public static function serviceTaxOptionsForLocation(int $locationId, ?int $serviceId = null): array
    {
        $connection = static::resolveSamedayConnectionForLocation($locationId);
        if (! $connection) {
            return [];
        }

        try {
            return app(SamedayAwbService::class)->getServiceTaxOptions($connection, $serviceId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    public static function serviceTaxOptionsForCurrentUserLocation(?int $serviceId = null): array
    {
        return static::serviceTaxOptionsForLocation(static::currentUserLocationId(), $serviceId);
    }

    /**
     * @return array<int, string>
     */
    public static function deliveryIntervalOptionsForLocation(int $locationId, ?int $serviceId = null): array
    {
        $connection = static::resolveSamedayConnectionForLocation($locationId);
        if (! $connection) {
            return [];
        }

        try {
            return app(SamedayAwbService::class)->getDeliveryIntervalOptions($connection, $serviceId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    public static function deliveryIntervalOptionsForCurrentUserLocation(?int $serviceId = null): array
    {
        return static::deliveryIntervalOptionsForLocation(static::currentUserLocationId(), $serviceId);
    }

    /**
     * @return array<int, string>
     */
    public static function serviceOptionsForLocation(int $locationId): array
    {
        $connection = static::resolveSamedayConnectionForLocation($locationId);
        if (! $connection) {
            return [];
        }

        try {
            return app(SamedayAwbService::class)->getServiceOptions($connection);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    public static function serviceOptionsForCurrentUserLocation(): array
    {
        return static::serviceOptionsForLocation(static::currentUserLocationId());
    }

    /**
     * @return array<int, string>
     */
    public static function countyOptionsForLocation(int $locationId): array
    {
        $connection = static::resolveSamedayConnectionForLocation($locationId);
        if (! $connection) {
            return [];
        }

        try {
            return app(SamedayAwbService::class)->getCountyOptions($connection);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    public static function countyOptionsForCurrentUserLocation(): array
    {
        return static::countyOptionsForLocation(static::currentUserLocationId());
    }

    public static function countyNameForCurrentUserLocation(int $countyId): ?string
    {
        if ($countyId <= 0) {
            return null;
        }

        return static::countyOptionsForCurrentUserLocation()[$countyId] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public static function cityOptionsForLocation(int $locationId, int $countyId): array
    {
        $connection = static::resolveSamedayConnectionForLocation($locationId);
        if (! $connection || $countyId <= 0) {
            return [];
        }

        try {
            return app(SamedayAwbService::class)->getCityOptions($connection, $countyId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    public static function cityOptionsForCurrentUserLocation(int $countyId): array
    {
        return static::cityOptionsForLocation(static::currentUserLocationId(), $countyId);
    }

    public static function cityNameForCurrentUserLocation(int $countyId, int $cityId): ?string
    {
        if ($countyId <= 0 || $cityId <= 0) {
            return null;
        }

        return static::cityOptionsForCurrentUserLocation($countyId)[$cityId] ?? null;
    }

    /**
     * @param  array<int|string, string>  $options
     */
    private static function firstPositiveIntKey(array $options): ?int
    {
        if ($options === []) {
            return null;
        }

        $firstKey = array_key_first($options);
        if (! is_int($firstKey) && ! is_string($firstKey)) {
            return null;
        }

        $value = (int) $firstKey;

        return $value > 0 ? $value : null;
    }

    private static function hasAnyAvailableSamedayConnection(): bool
    {
        $user = static::currentUser();
        if (! $user) {
            return false;
        }

        $locationId = (int) ($user->location_id ?? 0);
        if ($locationId <= 0) {
            return false;
        }

        return IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_SAMEDAY)
            ->where('is_active', true)
            ->where('location_id', $locationId)
            ->exists();
    }
}
