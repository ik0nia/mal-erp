<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\OfferResource\Pages;
use App\Models\Location;
use App\Models\Offer;
use App\Models\User;
use App\Models\WooProduct;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
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
use Filament\Forms\Get;
use Filament\Forms\Set;

class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Vânzări';

    protected static ?string $navigationLabel = 'Oferte';

    protected static ?string $modelLabel = 'Ofertă';

    protected static ?string $pluralModelLabel = 'Oferte';

    protected static ?int $navigationSort = 10;

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function isSuperAdmin(): bool
    {
        return static::currentUser()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Detalii ofertă')
                    ->columns(3)
                    ->schema([
                        TextInput::make('number')
                            ->label('Număr ofertă')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Se generează automat'),
                        Select::make('status')
                            ->label('Status')
                            ->options(Offer::statusOptions())
                            ->default(Offer::STATUS_DRAFT)
                            ->required()
                            ->native(false),
                        DatePicker::make('valid_until')
                            ->label('Valabilă până la')
                            ->native(false)
                            ->default(now()->addDays(14)),
                        Select::make('location_id')
                            ->label('Magazin')
                            ->relationship(
                                name: 'location',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('type', Location::TYPE_STORE)
                                    ->where('is_active', true)
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->default(fn (): ?int => static::currentUser()?->location_id)
                            ->disabled(fn (): bool => ! static::isSuperAdmin())
                            ->dehydrated()
                            ->live(),
                        Hidden::make('user_id')
                            ->default(fn (): ?int => auth()->id()),
                        TextInput::make('client_name')
                            ->label('Client')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        TextInput::make('client_company')
                            ->label('Companie')
                            ->maxLength(255),
                        TextInput::make('client_email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('client_phone')
                            ->label('Telefon')
                            ->maxLength(50),
                        Textarea::make('notes')
                            ->label('Observații')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Produse ofertă')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->orderColumn('position')
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Adaugă produs')
                            ->collapsed()
                            ->schema([
                                Select::make('woo_product_id')
                                    ->label('Produs')
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->helperText('Se afișează produse cu stoc disponibil pentru magazinul selectat.')
                                    ->getSearchResultsUsing(fn (string $search, Get $get): array => static::getProductSearchResults($search, $get))
                                    ->getOptionLabelUsing(fn ($value): ?string => static::getProductOptionLabel($value))
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        if (! $state) {
                                            return;
                                        }

                                        $product = WooProduct::query()
                                            ->select(['id', 'name', 'sku', 'price'])
                                            ->find((int) $state);

                                        if (! $product) {
                                            return;
                                        }

                                        $set('product_name', $product->name);
                                        $set('sku', $product->sku);

                                        if ($product->price !== null) {
                                            $set('unit_price', (float) $product->price);
                                        }
                                    })
                                    ->columnSpan(5),
                                TextInput::make('sku')
                                    ->label('SKU')
                                    ->disabled()
                                    ->dehydrated()
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                TextInput::make('quantity')
                                    ->label('Cantitate')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->required()
                                    ->live()
                                    ->columnSpan(2),
                                TextInput::make('unit_price')
                                    ->label('Preț unitar')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->step(0.0001)
                                    ->required()
                                    ->prefix('RON')
                                    ->live()
                                    ->columnSpan(2),
                                TextInput::make('discount_percent')
                                    ->label('Discount %')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.01)
                                    ->suffix('%')
                                    ->live()
                                    ->columnSpan(1),
                                Placeholder::make('line_total_preview')
                                    ->label('Total linie')
                                    ->content(fn (Get $get): string => static::linePreview($get))
                                    ->columnSpan(3),
                                Hidden::make('product_name'),
                                Hidden::make('position'),
                            ])
                            ->columns(12)
                            ->columnSpanFull(),
                    ]),
                Section::make('Totaluri')
                    ->columns(3)
                    ->schema([
                        TextInput::make('subtotal')
                            ->label('Subtotal')
                            ->disabled()
                            ->dehydrated(false)
                            ->prefix('RON'),
                        TextInput::make('discount_total')
                            ->label('Discount total')
                            ->disabled()
                            ->dehydrated(false)
                            ->prefix('RON'),
                        TextInput::make('total')
                            ->label('Total')
                            ->disabled()
                            ->dehydrated(false)
                            ->prefix('RON'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Număr')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('client_name')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Offer::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Offer::STATUS_SENT => 'info',
                        Offer::STATUS_ACCEPTED => 'success',
                        Offer::STATUS_REJECTED => 'danger',
                        Offer::STATUS_EXPIRED => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('RON')
                    ->sortable(),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Valabilă până la')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizat')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(Offer::statusOptions()),
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
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            //
        ];
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['location', 'items']);
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

        return $record instanceof Offer && in_array((int) $record->location_id, $user->operationalLocationIds(), true);
    }

    private static function getProductSearchResults(string $search, Get $get): array
    {
        $locationId = static::resolveSelectedLocationId($get);

        $query = WooProduct::query()
            ->select(['id', 'name', 'sku', 'price'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $query->where(function (Builder $searchQuery) use ($like): void {
                    $searchQuery
                        ->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like);
                });
            });

        static::applyProductScope($query, $locationId);

        return $query
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (WooProduct $product): array => [
                $product->id => static::formatProductOption($product),
            ])
            ->all();
    }

    private static function getProductOptionLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $product = WooProduct::query()
            ->select(['id', 'name', 'sku', 'price'])
            ->find((int) $value);

        return $product ? static::formatProductOption($product) : null;
    }

    private static function formatProductOption(WooProduct $product): string
    {
        $price = $product->price !== null ? number_format((float) $product->price, 2, '.', '') : '-';
        $sku = $product->sku ?: '-';

        return "{$product->name} [{$sku}] - {$price} RON";
    }

    private static function applyProductScope(Builder $query, ?int $locationId): void
    {
        if ($locationId) {
            $query->whereHas('connection', function (Builder $connectionQuery) use ($locationId): void {
                $connectionQuery->where('location_id', $locationId);
            })->whereHas('stocks', function (Builder $stockQuery) use ($locationId): void {
                $stockQuery
                    ->where('location_id', $locationId)
                    ->where('quantity', '>', 0);
            });

            return;
        }

        $user = static::currentUser();

        if ($user && ! $user->isSuperAdmin()) {
            $locationIds = $user->operationalLocationIds();

            $query->whereHas('connection', function (Builder $connectionQuery) use ($locationIds): void {
                $connectionQuery->whereIn('location_id', $locationIds);
            })->whereHas('stocks', function (Builder $stockQuery) use ($locationIds): void {
                $stockQuery
                    ->whereIn('location_id', $locationIds)
                    ->where('quantity', '>', 0);
            });

            return;
        }

        $query->whereHas('stocks', function (Builder $stockQuery): void {
            $stockQuery->where('quantity', '>', 0);
        });
    }

    private static function resolveSelectedLocationId(Get $get): ?int
    {
        $candidate = $get('../../location_id') ?? $get('location_id');

        if (! is_numeric($candidate)) {
            return null;
        }

        return (int) $candidate;
    }

    private static function linePreview(Get $get): string
    {
        $quantity = max(0, (float) ($get('quantity') ?? 0));
        $unitPrice = max(0, (float) ($get('unit_price') ?? 0));
        $discountPercent = min(100, max(0, (float) ($get('discount_percent') ?? 0)));

        $lineTotal = $quantity * $unitPrice * (1 - ($discountPercent / 100));

        return number_format($lineTotal, 2, '.', '').' RON';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOffers::route('/'),
            'create' => Pages\CreateOffer::route('/create'),
            'edit' => Pages\EditOffer::route('/{record}/edit'),
        ];
    }
}
