<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages;
use App\Models\Location;
use App\Models\User;
use App\Services\CompanyData\OpenApiCompanyLookupService;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Setări';

    protected static ?string $navigationLabel = 'Locații';

    protected static ?string $modelLabel = 'Locație';

    protected static ?string $pluralModelLabel = 'Locații';

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nume')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->label('Tip')
                    ->required()
                    ->options(Location::typeOptions())
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state !== Location::TYPE_WAREHOUSE) {
                            $set('store_id', null);
                        }

                        if ($state === Location::TYPE_WAREHOUSE) {
                            $set('company_name', null);
                            $set('company_vat_number', null);
                            $set('company_registration_number', null);
                            $set('company_postal_code', null);
                            $set('company_phone', null);
                            $set('company_bank', null);
                            $set('company_bank_account', null);
                        }
                    })
                    ->native(false),
                Forms\Components\Select::make('store_id')
                    ->label('Magazin părinte')
                    ->options(function (): array {
                        return Location::query()
                            ->where('type', Location::TYPE_STORE)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('type') === Location::TYPE_WAREHOUSE)
                    ->required(fn (Get $get): bool => $get('type') === Location::TYPE_WAREHOUSE)
                    ->helperText('Depozitul este întotdeauna sub un magazin.'),
                Forms\Components\TextInput::make('address')
                    ->label('Adresă')
                    ->maxLength(255),
                Forms\Components\TextInput::make('city')
                    ->label('Oraș')
                    ->maxLength(255),
                Forms\Components\TextInput::make('county')
                    ->label('Județ')
                    ->maxLength(255),
                Forms\Components\TextInput::make('company_name')
                    ->label('Denumire firmă')
                    ->visible(fn (Get $get): bool => $get('type') !== Location::TYPE_WAREHOUSE)
                    ->maxLength(255),
                Forms\Components\TextInput::make('company_vat_number')
                    ->label('CUI')
                    ->visible(fn (Get $get): bool => $get('type') !== Location::TYPE_WAREHOUSE)
                    ->helperText('La ieșirea din câmp, datele firmei se precompletează automat din OpenAPI.')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if ($get('type') === Location::TYPE_WAREHOUSE) {
                            return;
                        }

                        $normalizedCui = OpenApiCompanyLookupService::normalizeCui($state);

                        if ($normalizedCui === '') {
                            return;
                        }

                        try {
                            $companyData = app(OpenApiCompanyLookupService::class)->lookupByCui($normalizedCui);

                            foreach ([
                                'company_name',
                                'company_registration_number',
                                'company_postal_code',
                                'company_phone',
                                'address',
                                'city',
                                'county',
                            ] as $field) {
                                $value = trim((string) ($companyData[$field] ?? ''));

                                if ($value !== '') {
                                    $set($field, $value);
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Date firmă actualizate')
                                ->body('Datele firmei au fost preluate automat din OpenAPI.')
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->warning()
                                ->title('Nu am putut prelua datele firmei')
                                ->body($exception->getMessage())
                                ->send();
                        }
                    })
                    ->maxLength(255),
                Forms\Components\TextInput::make('company_registration_number')
                    ->label('Nr. Reg. Com.')
                    ->visible(fn (Get $get): bool => $get('type') !== Location::TYPE_WAREHOUSE)
                    ->maxLength(255),
                Forms\Components\TextInput::make('company_postal_code')
                    ->label('Cod poștal')
                    ->visible(fn (Get $get): bool => $get('type') !== Location::TYPE_WAREHOUSE)
                    ->maxLength(50),
                Forms\Components\TextInput::make('company_phone')
                    ->label('Număr de telefon')
                    ->tel()
                    ->visible(fn (Get $get): bool => $get('type') !== Location::TYPE_WAREHOUSE)
                    ->maxLength(255),
                Forms\Components\TextInput::make('company_bank')
                    ->label('Bancă')
                    ->visible(fn (Get $get): bool => $get('type') !== Location::TYPE_WAREHOUSE)
                    ->maxLength(255),
                Forms\Components\TextInput::make('company_bank_account')
                    ->label('Cont bancar')
                    ->visible(fn (Get $get): bool => $get('type') !== Location::TYPE_WAREHOUSE)
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_active')
                    ->label('Activă')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nume')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('city')
                    ->label('Oraș')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('company_phone')
                    ->label('Telefon')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('company_bank')
                    ->label('Bancă')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('store.name')
                    ->label('Magazin părinte')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activă')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creat la')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tip')
                    ->options(Location::typeOptions()),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activă')
                    ->trueLabel('Doar active')
                    ->falseLabel('Doar inactive')
                    ->native(false),
            ])
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
        return [
            // V1: fără relații suplimentare.
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}
