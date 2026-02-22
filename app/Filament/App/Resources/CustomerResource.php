<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CustomerResource\Pages;
use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use App\Services\CompanyData\OpenApiCompanyLookupService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Vânzări';

    protected static ?string $navigationLabel = 'Clienți';

    protected static ?string $modelLabel = 'Client';

    protected static ?string $pluralModelLabel = 'Clienți';

    protected static ?int $navigationSort = 5;

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function isSuperAdmin(): bool
    {
        return static::currentUser()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return auth()->check();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccessRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccessRecord($record);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detalii client')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('location_id')
                            ->label('Magazin')
                            ->relationship(
                                name: 'location',
                                titleAttribute: 'name',
                                modifyQueryUsing: function (Builder $query): void {
                                    $query
                                        ->where('type', Location::TYPE_STORE)
                                        ->where('is_active', true);

                                    $user = static::currentUser();

                                    if ($user && ! $user->isSuperAdmin()) {
                                        $query->whereIn('id', $user->operationalLocationIds());
                                    }
                                }
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->default(fn (): ?int => static::currentUser()?->location_id)
                            ->disabled(fn (): bool => ! static::isSuperAdmin())
                            ->dehydrated(),
                        Forms\Components\Select::make('type')
                            ->label('Tip client')
                            ->required()
                            ->options(Customer::typeOptions())
                            ->default(Customer::TYPE_INDIVIDUAL)
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state === Customer::TYPE_COMPANY) {
                                    return;
                                }

                                $set('cui', null);
                                $set('is_vat_payer', null);
                                $set('registration_number', null);
                                $set('representative_name', null);
                            }),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Activ')
                            ->default(true),
                        Forms\Components\TextInput::make('name')
                            ->label('Nume client')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('representative_name')
                            ->label('Nume reprezentant')
                            ->visible(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->required(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label('Telefon')
                            ->tel()
                            ->required(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->maxLength(64),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('cui')
                            ->label('CUI')
                            ->visible(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->helperText('La ieșirea din câmp, datele firmei se precompletează automat din OpenAPI.')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if ($get('type') !== Customer::TYPE_COMPANY) {
                                    return;
                                }

                                $normalizedCui = OpenApiCompanyLookupService::normalizeCui($state);

                                if ($normalizedCui === '') {
                                    return;
                                }

                                try {
                                    $companyData = app(OpenApiCompanyLookupService::class)->lookupByCui($normalizedCui);

                                    $fieldMap = [
                                        'name' => 'company_name',
                                        'registration_number' => 'company_registration_number',
                                        'postal_code' => 'company_postal_code',
                                        'phone' => 'company_phone',
                                        'address' => 'address',
                                        'city' => 'city',
                                        'county' => 'county',
                                    ];

                                    foreach ($fieldMap as $target => $source) {
                                        $value = trim((string) ($companyData[$source] ?? ''));

                                        if ($value !== '') {
                                            $set($target, $value);
                                        }
                                    }

                                    if (array_key_exists('company_is_vat_payer', $companyData)) {
                                        $set('is_vat_payer', (bool) $companyData['company_is_vat_payer']);
                                    }

                                    Notification::make()
                                        ->success()
                                        ->title('Date firmă actualizate')
                                        ->body('Datele clientului au fost preluate automat din OpenAPI.')
                                        ->send();
                                } catch (Throwable $exception) {
                                    Notification::make()
                                        ->warning()
                                        ->title('Nu am putut prelua datele firmei')
                                        ->body($exception->getMessage())
                                        ->send();
                                }
                            })
                            ->maxLength(64),
                        Forms\Components\Toggle::make('is_vat_payer')
                            ->label('Plătitor TVA')
                            ->visible(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->inline(false),
                        Forms\Components\TextInput::make('registration_number')
                            ->label('Nr. Reg. Com.')
                            ->visible(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->maxLength(255),
                    ]),
                Forms\Components\Section::make('Adresă implicită')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('address')
                            ->label('Adresă')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('postal_code')
                            ->label('Cod poștal')
                            ->maxLength(32),
                        Forms\Components\TextInput::make('city')
                            ->label('Oraș')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('county')
                            ->label('Județ')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('notes')
                            ->label('Observații')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Adrese de livrare alternative')
                    ->description('Adrese suplimentare cu contact dedicat pentru livrare.')
                    ->schema([
                        Forms\Components\Repeater::make('deliveryAddresses')
                            ->relationship()
                            ->orderColumn('position')
                            ->reorderableWithButtons()
                            ->defaultItems(0)
                            ->addActionLabel('Adaugă adresă alternativă')
                            ->itemLabel(function (array $state): ?string {
                                $label = trim((string) ($state['label'] ?? ''));
                                $address = trim((string) ($state['address'] ?? ''));

                                return $label !== '' ? $label : ($address !== '' ? $address : 'Adresă nouă');
                            })
                            ->collapsed()
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label('Etichetă')
                                    ->maxLength(255),
                                Forms\Components\Toggle::make('is_active')
                                    ->label('Activă')
                                    ->default(true),
                                Forms\Components\TextInput::make('contact_name')
                                    ->label('Nume contact')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('contact_phone')
                                    ->label('Telefon contact')
                                    ->tel()
                                    ->maxLength(64),
                                Forms\Components\TextInput::make('address')
                                    ->label('Adresă')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('city')
                                    ->label('Oraș')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('county')
                                    ->label('Județ')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('postal_code')
                                    ->label('Cod poștal')
                                    ->maxLength(32),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Customer::typeOptions()[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('representative_name')
                    ->label('Reprezentant')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('cui')
                    ->label('CUI')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activ')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizat')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tip client')
                    ->options(Customer::typeOptions()),
                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Magazin')
                    ->options(function (): array {
                        $query = Location::query()
                            ->where('type', Location::TYPE_STORE)
                            ->orderBy('name');

                        $user = static::currentUser();

                        if ($user && ! $user->isSuperAdmin()) {
                            $query->whereIn('id', $user->operationalLocationIds());
                        }

                        return $query->pluck('name', 'id')->all();
                    }),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activ'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('location'))
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('location');
        $user = static::currentUser();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('location_id', $user->operationalLocationIds());
    }

    private static function canAccessRecord(Model $record): bool
    {
        $user = static::currentUser();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $record instanceof Customer && in_array((int) $record->location_id, $user->operationalLocationIds(), true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
