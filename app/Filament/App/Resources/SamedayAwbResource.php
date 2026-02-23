<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\SamedayAwbResource\Pages;
use App\Models\IntegrationConnection;
use App\Models\SamedayAwb;
use App\Models\User;
use App\Services\Courier\SamedayAwbService;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
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
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                        Select::make('service_id')
                            ->label('Serviciu Sameday')
                            ->helperText('Dacă lași gol, se folosește serviciul default din cont.')
                            ->options(fn (): array => static::serviceOptionsForCurrentUserLocation())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                    ]),
                Section::make('Destinatar')
                    ->columns(2)
                    ->schema([
                        TextInput::make('recipient_name')
                            ->label('Nume destinatar')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('recipient_phone')
                            ->label('Telefon destinatar')
                            ->required()
                            ->maxLength(64),
                        TextInput::make('recipient_email')
                            ->label('Email destinatar')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('recipient_postal_code')
                            ->label('Cod poștal')
                            ->maxLength(32),
                        TextInput::make('recipient_county')
                            ->label('Județ')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('recipient_city')
                            ->label('Oraș')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('recipient_address')
                            ->label('Adresă')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Colet și opțiuni')
                    ->columns(3)
                    ->schema([
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
                        Textarea::make('observation')
                            ->label('Observații livrare')
                            ->rows(3)
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
        $options = static::pickupPointOptionsForCurrentUserLocation();
        if ($options === []) {
            return null;
        }

        $firstKey = array_key_first($options);
        if (! is_int($firstKey) && ! is_string($firstKey)) {
            return null;
        }

        $pickupPointId = (int) $firstKey;

        return $pickupPointId > 0 ? $pickupPointId : null;
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
