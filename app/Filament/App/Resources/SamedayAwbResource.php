<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\SamedayAwbResource\Pages;
use App\Models\IntegrationConnection;
use App\Models\SamedayAwb;
use App\Models\User;
use App\Services\Courier\SamedayAwbService;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Throwable;

class SamedayAwbResource extends Resource
{
    use HasDynamicNavSort;

    use EnforcesLocationScope, ChecksRolePermissions;

    protected static ?string $model = SamedayAwb::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Livrare';

    protected static ?string $navigationLabel = 'AWB Sameday';

    protected static ?string $modelLabel = 'AWB Sameday';

    protected static ?string $pluralModelLabel = 'AWB-uri Sameday';

    public static function shouldRegisterNavigation(): bool
    {
        return static::hasAnyAvailableSamedayConnection();
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

    public static function form(Schema $schema): Schema
    {
        return $schema->schema(static::formComponents());
    }

    /**
     * Componentele formularului de AWB — refolosite și de popup-ul „Creare AWB"
     * de pe comanda WooCommerce (aceeași sursă unică de adevăr).
     */
    public static function formComponents(): array
    {
        return [
                Section::make('Expeditor')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        Hidden::make('location_id')
                            ->default(fn (): ?int => static::currentUser()?->location_id)
                            ->dehydrated(),
                        Select::make('pickup_point_id')
                            ->label('Pickup point Sameday')
                            ->options(fn (): array => static::pickupPointOptionsForCurrentUserLocation())
                            ->default(fn (): ?int => static::defaultPickupPointForCurrentUserLocation())
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $set(
                                    'contact_person_id',
                                    static::defaultContactPersonForCurrentUserLocation((int) ($state ?? 0))
                                );
                            })
                            ->columnSpan(2)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                        Select::make('contact_person_id')
                            ->label('Persoană contact')
                            ->options(fn (Get $get): array => static::contactPersonOptionsForCurrentUserLocation((int) ($get('pickup_point_id') ?? 0)))
                            ->default(fn (Get $get): ?int => static::defaultContactPersonForCurrentUserLocation((int) ($get('pickup_point_id') ?? 0)))
                            ->columnSpan(2)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                        Select::make('service_id')
                            ->label('Serviciu Sameday')
                            ->default(fn (): ?int => static::defaultServiceForCurrentUserLocation())
                            ->options(fn (): array => static::serviceOptionsForCurrentUserLocation())
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('service_tax_ids', []);
                                $set('delivery_interval_id', null);
                            })
                            ->columnSpan(2)
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
                            ->options(fn (Get $get): array => static::deliveryIntervalOptionsForCurrentUserLocation((int) ($get('service_id') ?? 0)))
                            ->columnSpan(2)
                            ->searchable()
                            ->native(false)
                            ->nullable(),
                        Toggle::make('third_party_pickup')
                            ->label('Ridicare de la terț')
                            ->default(false)
                            ->columnSpan(2)
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
                            ->columnSpan(4),
                    ]),
                Section::make('Destinatar')
                    ->columnSpanFull()
                    ->columns(6)
                    ->schema([
                        Select::make('locker_last_mile')
                            ->label('Căsuță Easybox (opțional — livrare în locker)')
                            ->searchable()
                            ->columnSpan(6)
                            ->getSearchResultsUsing(fn (string $search): array => \Illuminate\Support\Facades\DB::table('sameday_lockers')
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                    ->orWhere('city', 'like', "%{$search}%")
                                    ->orWhere('address', 'like', "%{$search}%"))
                                ->limit(30)->get()
                                ->mapWithKeys(fn ($l) => [$l->locker_id => $l->name.' — '.$l->address.', '.$l->city.' ('.$l->county.')'])
                                ->all())
                            ->getOptionLabelUsing(function ($value): string {
                                $l = \Illuminate\Support\Facades\DB::table('sameday_lockers')->where('locker_id', $value)->first();
                                return $l
                                    ? $l->name.' — '.$l->address.', '.$l->city
                                    : 'Easybox #'.$value.' (ales de client — valid, dar lipsește din nomenclatorul local)';
                            })
                            ->helperText('Pentru livrare Easybox alege căsuța de pe hartă sau caut-o în listă (serviciul trece automat pe Locker NextDay). Se precompletează automat din comandă când aceasta e Easybox.'),
                        \Filament\Forms\Components\ViewField::make('locker_map')
                            ->label('')
                            ->view('filament.app.sameday-locker-map', [
                                'lockerField'  => 'locker_last_mile',
                                'serviceField' => 'service_id',
                            ])
                            ->dehydrated(false)
                            ->columnSpan(6),
                        Select::make('recipient_type')
                            ->label('Tip destinatar')
                            ->options([
                                'individual' => 'Persoană fizică',
                                'company' => 'Persoană juridică',
                            ])
                            ->default('individual')
                            ->live()
                            ->columnSpan(2)
                            ->native(false),
                        TextInput::make('recipient_name')
                            ->label('Nume destinatar')
                            ->required()
                            ->columnSpan(2)
                            ->maxLength(255),
                        TextInput::make('recipient_phone')
                            ->label('Telefon destinatar')
                            ->required()
                            ->columnSpan(2)
                            ->maxLength(64),
                        TextInput::make('recipient_company_name')
                            ->label('Companie destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->required(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->columnSpan(3)
                            ->maxLength(255),
                        TextInput::make('recipient_company_cui')
                            ->label('CUI destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->columnSpan(1)
                            ->maxLength(64),
                        TextInput::make('recipient_company_onrc')
                            ->label('Nr. ONRC destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->columnSpan(2)
                            ->maxLength(128),
                        TextInput::make('recipient_company_bank')
                            ->label('Bancă destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->columnSpan(2)
                            ->maxLength(128),
                        TextInput::make('recipient_company_iban')
                            ->label('IBAN destinatar')
                            ->visible(fn (Get $get): bool => $get('recipient_type') === 'company')
                            ->columnSpan(2)
                            ->maxLength(64),
                        TextInput::make('recipient_email')
                            ->label('Email destinatar')
                            ->email()
                            ->columnSpan(2)
                            ->maxLength(255),
                        TextInput::make('recipient_postal_code')
                            ->label('Cod poștal')
                            ->columnSpan(1)
                            ->maxLength(32),
                        Select::make('recipient_county_id')
                            ->label('Județ (Sameday)')
                            ->options(fn (): array => static::countyOptionsForCurrentUserLocation())
                            ->required()
                            ->columnSpan(1)
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $set('recipient_city_id', null);
                            }),
                        Select::make('recipient_city_id')
                            ->label('Oraș (Sameday)')
                            ->options(fn (Get $get): array => static::cityOptionsForCurrentUserLocation((int) ($get('recipient_county_id') ?? 0)))
                            ->required()
                            ->columnSpan(2)
                            ->disabled(fn (Get $get): bool => (int) ($get('recipient_county_id') ?? 0) <= 0)
                            ->searchable()
                            ->native(false)
                            ->live(),
                        TextInput::make('recipient_street')
                            ->label('Stradă')
                            ->required()
                            ->columnSpan(2)
                            ->maxLength(255),
                        TextInput::make('recipient_street_no')
                            ->label('Număr')
                            ->columnSpan(1)
                            ->maxLength(50),
                        TextInput::make('recipient_block')
                            ->label('Bloc')
                            ->columnSpan(1)
                            ->maxLength(50),
                        TextInput::make('recipient_staircase')
                            ->label('Scară')
                            ->columnSpan(1)
                            ->maxLength(50),
                        TextInput::make('recipient_floor')
                            ->label('Etaj')
                            ->columnSpan(1)
                            ->maxLength(50),
                        TextInput::make('recipient_apartment')
                            ->label('Apartament')
                            ->columnSpan(1)
                            ->maxLength(50),
                        Textarea::make('recipient_address')
                            ->label('Adresă completă (opțional, override)')
                            ->helperText('Dacă lași gol, adresa se compune automat din stradă + număr + bloc + scară + etaj + apartament.')
                            ->rows(2)
                            ->columnSpan(6),
                    ]),
                Section::make('Colet și opțiuni')
                    ->columnSpanFull()
                    ->columns(6)
                    ->schema([
                        Select::make('package_type')
                            ->label('Tip trimitere')
                            ->options([
                                0 => 'Colet',
                                1 => 'Plic',
                                2 => 'Pachet mare',
                            ])
                            ->default(0)
                            ->columnSpan(2)
                            ->native(false)
                            ->required(),
                        Select::make('awb_payment_type')
                            ->label('Plata expedierii la')
                            ->options([
                                1 => 'Expeditor',
                            ])
                            ->default(1)
                            ->columnSpan(2)
                            ->native(false)
                            ->disabled()
                            ->dehydrated(),
                        TextInput::make('package_count')
                            ->label('Număr colete')
                            ->numeric()
                            ->required()
                            ->default(1)
                            ->columnSpan(1)
                            ->minValue(1),
                        TextInput::make('package_weight_kg')
                            ->label('Greutate / colet (kg)')
                            ->numeric()
                            ->required()
                            ->default(fn (): float => static::defaultPackageWeightForCurrentUserLocation())
                            ->columnSpan(1)
                            ->minValue(0.01),
                        TextInput::make('cod_amount')
                            ->label('Ramburs (RON)')
                            ->numeric()
                            ->default(0)
                            ->columnSpan(2)
                            ->minValue(0),
                        TextInput::make('insured_value')
                            ->label('Valoare asigurată (RON)')
                            ->numeric()
                            ->default(0)
                            ->columnSpan(2)
                            ->minValue(0),
                        TextInput::make('reference')
                            ->label('Referință internă')
                            ->columnSpan(2)
                            ->maxLength(255),
                        Textarea::make('price_observation')
                            ->label('Observații cost')
                            ->columnSpan(3)
                            ->rows(2),
                        Textarea::make('client_observation')
                            ->label('Observații client')
                            ->columnSpan(3)
                            ->rows(2),
                        Textarea::make('observation')
                            ->label('Observații livrare')
                            ->rows(2)
                            ->columnSpan(6),
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
                            ->defaultItems(0)
                            ->addActionLabel('Adaugă colet')
                            ->columnSpan(6),
                    ]),
            ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('awb_number')
                    ->label('AWB')
                    ->searchable()
                    ->placeholder('-')
                    ->copyable()->copyMessage('Copiat!'),
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
            ->deferFilters(false)
            ->recordActions([
                Actions\Action::make('cancel_awb')
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
                Actions\Action::make('details')
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
        return static::applyLocationFilter(
            parent::getEloquentQuery()->with(['location', 'connection'])
        );
    }

    /**
     * Starea completă de default a formularului — necesară în modalele Action,
     * unde fillForm() ÎNLOCUIEȘTE starea și NU aplică default-urile componentelor.
     *
     * @return array<string, mixed>
     */
    public static function defaultFormState(): array
    {
        $pickup = static::defaultPickupPointForCurrentUserLocation();

        return [
            'location_id'          => static::currentUser()?->location_id,
            'pickup_point_id'      => $pickup,
            'contact_person_id'    => static::defaultContactPersonForCurrentUserLocation($pickup),
            'service_id'           => static::defaultServiceForCurrentUserLocation(),
            'service_tax_ids'      => [],
            'delivery_interval_id' => null,
            'third_party_pickup'   => false,
            'recipient_type'       => 'individual',
            'package_type'         => 0,
            'awb_payment_type'     => 1,
            'package_count'        => 1,
            'package_weight_kg'    => static::defaultPackageWeightForCurrentUserLocation(),
            'cod_amount'           => 0,
            'insured_value'        => 0,
            'parcels'              => [],
        ];
    }

    /**
     * Prefill complet din comanda Woo: destinatar (cu mapare județ/oraș pe nomenclatorul
     * Sameday), firmă (CUI/ONRC din meta av_facturare), colet (greutate totală din produse
     * + dimensiunile produsului cel mai voluminos), Easybox → serviciul de locker.
     *
     * @return array{prefill: array<string, mixed>, summary: string, missing_weight: array<int, string>}
     */
    public static function orderPrefillData(\App\Models\WooOrder $order): array
    {
        $locationId = (int) ($order->location_id ?: static::currentUserLocationId());

        $prefill = array_filter([
            'recipient_name'        => (string) $order->customer_name,
            'recipient_phone'       => (string) $order->customer_phone,
            'recipient_email'       => (string) $order->customer_email,
            'recipient_street'      => (string) data_get($order->shipping, 'address_1', data_get($order->billing, 'address_1', '')),
            'recipient_postal_code' => (string) data_get($order->shipping, 'postcode', data_get($order->billing, 'postcode', '')),
            'reference'             => (string) $order->number,
        ]);

        if ($order->payment_method === 'cod') {
            $prefill['cod_amount'] = (float) $order->total;
        }

        // Județ (cod ISO Woo, ex. "BH") + oraș → nomenclatorul Sameday
        $countyId = static::resolveCountyIdFromText($locationId, (string) data_get($order->shipping, 'state', data_get($order->billing, 'state', '')));
        if ($countyId) {
            $prefill['recipient_county_id'] = $countyId;
            $cityId = static::resolveCityIdFromText($locationId, $countyId, (string) data_get($order->shipping, 'city', data_get($order->billing, 'city', '')));
            if ($cityId) {
                $prefill['recipient_city_id'] = $cityId;
            }
        }

        // Easybox → căsuța + serviciul de locker (meta value = JSON string sau array)
        $lockerMeta = collect(data_get($order->data, 'meta_data', []))
            ->firstWhere('key', '_sameday_shipping_locker_id');
        $lockerValue = data_get($lockerMeta, 'value');
        if (is_string($lockerValue) && $lockerValue !== '') {
            $lockerValue = json_decode($lockerValue, true);
        }
        $lockerId = (int) data_get($lockerValue, 'lockerId', 0);
        if ($lockerId > 0) {
            $prefill['locker_last_mile'] = $lockerId;
            $prefill['service_id'] = 15; // Locker NextDay
        }

        // Greutate totală + dimensiunile produsului dominant
        $totalWeight = 0.0;
        $missingWeight = [];
        $itemCount = 0;
        $bestDims = null;
        $bestVolume = 0.0;

        foreach ($order->items as $item) {
            $itemCount += (int) $item->quantity;

            $product = \App\Models\WooProduct::where('woo_id', $item->woo_product_id)->first(['data']);
            $weight = (float) data_get($product?->data, 'weight', 0);
            if ($weight > 0) {
                $totalWeight += $weight * (float) $item->quantity;
            } else {
                $missingWeight[] = (string) $item->name;
            }

            $length = (float) data_get($product?->data, 'dimensions.length', 0);
            $width  = (float) data_get($product?->data, 'dimensions.width', 0);
            $height = (float) data_get($product?->data, 'dimensions.height', 0);
            $volume = $length * $width * $height;
            if ($volume > $bestVolume) {
                $bestVolume = $volume;
                $bestDims = ['length' => $length, 'width' => $width, 'height' => $height];
            }
        }

        if ($totalWeight > 0) {
            $roundedWeight = round(max(0.1, $totalWeight), 2);
            $prefill['package_weight_kg'] = $roundedWeight;
            $prefill['parcels'] = [array_filter([
                'weight_kg' => $roundedWeight,
                'length_cm' => $bestDims ? (int) ceil($bestDims['length']) : null,
                'width_cm'  => $bestDims ? (int) ceil($bestDims['width']) : null,
                'height_cm' => $bestDims ? (int) ceil($bestDims['height']) : null,
            ])];
        }

        // Persoană juridică: companie din billing + CUI/ONRC din meta av_facturare
        $company = trim((string) data_get($order->billing, 'company', ''));
        if ($company !== '') {
            $prefill['recipient_type'] = 'company';
            $prefill['recipient_company_name'] = $company;

            $facturare = collect(data_get($order->data, 'meta_data', []))->firstWhere('key', 'av_facturare');
            $prefill['recipient_company_cui'] = (string) data_get($facturare, 'value.cui', '');
            $prefill['recipient_company_onrc'] = (string) data_get($facturare, 'value.nr_reg_com', '');
        }

        $parts = [
            'Comanda #'.$order->number,
            $itemCount.' '.($itemCount === 1 ? 'produs' : 'produse'),
        ];
        $parts[] = $totalWeight > 0
            ? number_format($totalWeight, 2, ',', '.').' kg din greutățile produselor'
            : 'fără greutăți pe produse — verifică greutatea!';
        if ($order->payment_method === 'cod') {
            $parts[] = 'ramburs '.number_format((float) $order->total, 2, ',', '.').' RON';
        }

        return [
            'prefill'        => $prefill,
            'summary'        => implode(' · ', $parts),
            'missing_weight' => $missingWeight,
        ];
    }

    /** Notificare standard pentru produsele fără greutate din prefill. */
    public static function notifyMissingWeights(array $missingWeight): void
    {
        if (empty($missingWeight)) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Produse fără greutate setată')
            ->body(
                'Greutatea precompletată NU include: '
                .e(implode(', ', array_slice($missingWeight, 0, 5)))
                .(count($missingWeight) > 5 ? ' +'.(count($missingWeight) - 5).' altele' : '')
                .'. Ajustează greutatea coletului dacă e cazul.'
            )
            ->persistent()
            ->send();
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
        $options = static::serviceOptionsForCurrentUserLocation();

        // Serviciul configurat pe conexiune (ex. 7 = 24H) are prioritate
        $connection = static::resolveSamedayConnectionForLocation(static::currentUserLocationId());
        $configured = (int) data_get($connection?->settings, 'default_service_id', 0);
        if ($configured > 0 && array_key_exists($configured, $options)) {
            return $configured;
        }

        return static::firstPositiveIntKey($options);
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
        $connection = static::resolveSamedayConnectionForLocation(static::currentUserLocationId());
        if ($connection) {
            try {
                $default = app(SamedayAwbService::class)->getDefaultPickupPointId($connection);
                if ($default) {
                    return $default;
                }
            } catch (Throwable) {
                // fallback mai jos
            }
        }

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
        $connection = static::resolveSamedayConnectionForLocation(static::currentUserLocationId());
        if ($connection) {
            try {
                $default = app(SamedayAwbService::class)->getDefaultContactPersonId($connection, $pickupPointId);
                if ($default) {
                    return $default;
                }
            } catch (Throwable) {
                // fallback mai jos
            }
        }

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

    /** Cod ISO Woo (ex. "CJ") sau nume de județ → ID județ din nomenclatorul Sameday. */
    public static function resolveCountyIdFromText(int $locationId, string $county): ?int
    {
        $county = trim($county);
        if ($county === '') {
            return null;
        }

        $isoToName = [
            'AB' => 'Alba', 'AR' => 'Arad', 'AG' => 'Arges', 'BC' => 'Bacau', 'BH' => 'Bihor',
            'BN' => 'Bistrita-Nasaud', 'BT' => 'Botosani', 'BV' => 'Brasov', 'BR' => 'Braila',
            'B' => 'Bucuresti', 'BZ' => 'Buzau', 'CS' => 'Caras-Severin', 'CL' => 'Calarasi',
            'CJ' => 'Cluj', 'CT' => 'Constanta', 'CV' => 'Covasna', 'DB' => 'Dambovita',
            'DJ' => 'Dolj', 'GL' => 'Galati', 'GR' => 'Giurgiu', 'GJ' => 'Gorj', 'HR' => 'Harghita',
            'HD' => 'Hunedoara', 'IL' => 'Ialomita', 'IS' => 'Iasi', 'IF' => 'Ilfov',
            'MM' => 'Maramures', 'MH' => 'Mehedinti', 'MS' => 'Mures', 'NT' => 'Neamt',
            'OT' => 'Olt', 'PH' => 'Prahova', 'SM' => 'Satu Mare', 'SJ' => 'Salaj', 'SB' => 'Sibiu',
            'SV' => 'Suceava', 'TR' => 'Teleorman', 'TM' => 'Timis', 'TL' => 'Tulcea',
            'VS' => 'Vaslui', 'VL' => 'Valcea', 'VN' => 'Vrancea',
        ];

        $name = $isoToName[strtoupper($county)] ?? $county;
        $needle = static::normalizeGeoName($name);

        foreach (static::countyOptionsForLocation($locationId) as $id => $label) {
            if (static::normalizeGeoName($label) === $needle) {
                return (int) $id;
            }
        }

        return null;
    }

    /** Nume oraș din comandă → ID oraș Sameday (match exact normalizat, sectoare București, apoi prefix). */
    public static function resolveCityIdFromText(int $locationId, int $countyId, string $city): ?int
    {
        $needle = static::normalizeGeoName($city);
        if ($needle === '' || $countyId <= 0) {
            return null;
        }

        $options = static::cityOptionsForLocation($locationId, $countyId);

        // București: "Sector 3" / "Bucuresti Sectorul 3" → "Sectorul 3"
        if (preg_match('/sector(?:ul)?\s*(\d)/', $needle, $m)) {
            foreach ($options as $id => $label) {
                if (str_contains(static::normalizeGeoName($label), 'sectorul '.$m[1])) {
                    return (int) $id;
                }
            }
        }

        foreach ($options as $id => $label) {
            if (static::normalizeGeoName($label) === $needle) {
                return (int) $id;
            }
        }

        foreach ($options as $id => $label) {
            $norm = static::normalizeGeoName($label);
            if (str_starts_with($norm, $needle) || str_starts_with($needle, $norm)) {
                return (int) $id;
            }
        }

        return null;
    }

    /** Lowercase + fără diacritice, pentru comparat nume geografice. */
    private static function normalizeGeoName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return preg_replace('/[^a-z0-9 -]/', '', $value) ?? '';
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
