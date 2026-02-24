<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Resources\OfferResource\Pages;
use App\Models\Location;
use App\Models\Offer;
use App\Models\User;
use App\Models\WooProduct;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class OfferResource extends Resource
{
    use EnforcesLocationScope;

    protected static ?string $model = Offer::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Vânzări';

    protected static ?string $navigationLabel = 'Oferte';

    protected static ?string $modelLabel = 'Ofertă';

    protected static ?string $pluralModelLabel = 'Oferte';

    protected static ?int $navigationSort = 10;

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
                            ->disabled(fn (): bool => ! (static::currentUser()?->isSuperAdmin() ?? false))
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
                        TableRepeater::make('items')
                            ->relationship()
                            ->orderColumn('position')
                            ->live()
                            ->defaultItems(0)
                            ->minItems(1)
                            ->addActionLabel('Adaugă produs')
                            ->addActionAlignment('start')
                            ->streamlined()
                            ->stackAt(MaxWidth::Large)
                            ->emptyLabel('Nu ai produse în ofertă. Adaugă primul produs.')
                            ->reorderable(false)
                            ->reorderableWithButtons(false)
                            ->reorderableWithDragAndDrop(false)
                            ->extraAttributes([
                                'class' => 'offer-items-repeater',
                            ])
                            ->headers([
                                Header::make('thumbnail')
                                    ->label('Imagine')
                                    ->width('64px'),
                                Header::make('woo_product_id')
                                    ->label('Produs')
                                    ->markAsRequired(),
                                Header::make('quantity')
                                    ->label('Cant.')
                                    ->markAsRequired()
                                    ->align('right')
                                    ->width('100px'),
                                Header::make('unit_price')
                                    ->label('Preț unitar')
                                    ->markAsRequired()
                                    ->align('right')
                                    ->width('150px'),
                                Header::make('discount_percent')
                                    ->label('Discount %')
                                    ->align('right')
                                    ->width('110px'),
                                Header::make('line_total_preview')
                                    ->label('Total linie')
                                    ->align('right')
                                    ->width('140px'),
                            ])
                            ->addAction(fn (FormAction $action): FormAction => $action
                                ->extraAttributes([
                                    'data-offer-add-item' => '1',
                                ])
                            )
                            ->schema([
                                Placeholder::make('thumbnail')
                                    ->label('Imagine')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): HtmlString => static::productThumbnail($get))
                                    ->extraAttributes(['class' => 'w-16']),
                                Select::make('woo_product_id')
                                    ->label('Produs')
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->placeholder('Alege produs')
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

                                        $set('product_name', $product->decoded_name);
                                        $set('sku', $product->sku);

                                        if ($product->price !== null) {
                                            $set('unit_price', (float) $product->price);
                                        }
                                    })
                                    ->columnSpan(5),
                                TextInput::make('quantity')
                                    ->label('Cantitate')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->required()
                                    ->live()
                                    ->columnSpan(2)
                                    ->inputMode('decimal')
                                    ->extraInputAttributes(['class' => 'text-right']),
                                TextInput::make('unit_price')
                                    ->label('Preț unitar')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->step(0.0001)
                                    ->required()
                                    ->prefix('RON')
                                    ->live()
                                    ->columnSpan(2)
                                    ->inputMode('decimal')
                                    ->extraInputAttributes(['class' => 'text-right']),
                                TextInput::make('discount_percent')
                                    ->label('Discount %')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.01)
                                    ->suffix('%')
                                    ->live()
                                    ->columnSpan(1)
                                    ->inputMode('decimal')
                                    ->extraInputAttributes([
                                        'class' => 'text-right',
                                        'x-on:keydown.tab' => "if (! \$event.shiftKey) { const row = \$el.closest('tr.table-repeater-row'); if (! row || row.nextElementSibling) { return; } const wrapper = \$el.closest('.table-repeater-component'); const addButton = wrapper?.querySelector('[data-offer-add-item]'); if (! addButton) { return; } \$event.preventDefault(); addButton.click(); setTimeout(() => { const rows = wrapper.querySelectorAll('tr.table-repeater-row'); const lastRow = rows[rows.length - 1]; const productInput = lastRow?.querySelector('input[role=combobox], input[type=text], input[type=search]'); productInput?.focus(); }, 180); }",
                                    ]),
                                Placeholder::make('line_total_preview')
                                    ->label('Total linie')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): string => static::linePreview($get))
                                    ->columnSpan(3)
                                    ->extraAttributes(['class' => 'text-right font-semibold']),
                                Hidden::make('sku'),
                                Hidden::make('product_name'),
                                Hidden::make('position'),
                            ])
                            ->columnSpanFull(),
                    ]),
                Section::make('Totaluri')
                    ->columns(3)
                    ->schema([
                        Placeholder::make('subtotal_live')
                            ->label('Subtotal')
                            ->content(fn (Get $get): string => static::formatCurrency(static::offerTotals($get)['subtotal'])),
                        Placeholder::make('discount_total_live')
                            ->label('Discount total')
                            ->content(fn (Get $get): string => static::formatCurrency(static::offerTotals($get)['discount_total'])),
                        Placeholder::make('total_live')
                            ->label('Total')
                            ->content(fn (Get $get): string => static::formatCurrency(static::offerTotals($get)['total']))
                            ->extraAttributes(['class' => 'font-bold']),
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
                Tables\Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Offer $record): string => static::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('print')
                    ->label('Print')
                    ->icon('heroicon-o-printer')
                    ->url(fn (Offer $record): string => static::getUrl('print', [
                        'record' => $record,
                        'auto_print' => 1,
                    ]))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Detalii ofertă')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('number')->label('Număr'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Offer::statusOptions()[$state] ?? $state),
                        TextEntry::make('valid_until')
                            ->label('Valabilă până la')
                            ->date('d.m.Y'),
                        TextEntry::make('client_name')->label('Client'),
                        TextEntry::make('client_company')->label('Companie')->placeholder('-'),
                        TextEntry::make('client_phone')->label('Telefon')->placeholder('-'),
                        TextEntry::make('client_email')->label('Email')->placeholder('-'),
                        TextEntry::make('location.name')->label('Magazin')->placeholder('-'),
                        TextEntry::make('user.name')->label('Operator')->placeholder('-'),
                        TextEntry::make('notes')
                            ->label('Observații')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                InfolistSection::make('Produse ofertă')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                ImageEntry::make('product.main_image_url')
                                    ->label('Imagine')
                                    ->height(40)
                                    ->defaultImageUrl('https://placehold.co/40x40?text=No+Img'),
                                TextEntry::make('product_name')
                                    ->label('Produs')
                                    ->formatStateUsing(fn ($state): string => html_entity_decode((string) $state, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                                    ->weight('medium'),
                                TextEntry::make('quantity')
                                    ->label('Cant.')
                                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3, '.', '')),
                                TextEntry::make('unit_price')
                                    ->label('Preț unitar')
                                    ->formatStateUsing(fn ($state): string => static::formatCurrency((float) $state)),
                                TextEntry::make('discount_percent')
                                    ->label('Discount %')
                                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, '.', '').' %'),
                                TextEntry::make('line_total')
                                    ->label('Total linie')
                                    ->formatStateUsing(fn ($state): string => static::formatCurrency((float) $state))
                                    ->weight('bold'),
                            ])
                            ->columns(6),
                    ]),
                InfolistSection::make('Totaluri')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->formatStateUsing(fn ($state): string => static::formatCurrency((float) $state)),
                        TextEntry::make('discount_total')
                            ->label('Discount total')
                            ->formatStateUsing(fn ($state): string => static::formatCurrency((float) $state)),
                        TextEntry::make('total')
                            ->label('Total')
                            ->weight('bold')
                            ->formatStateUsing(fn ($state): string => static::formatCurrency((float) $state)),
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
        return static::applyLocationFilter(
            parent::getEloquentQuery()->with(['location', 'items'])
        );
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
        $name = $product->decoded_name;

        return "{$name} [{$sku}] - {$price} RON";
    }

    private static function applyProductScope(Builder $query, ?int $locationId): void
    {
        if ($locationId) {
            $query->whereHas('connection', function (Builder $connectionQuery) use ($locationId): void {
                $connectionQuery->where(function (Builder $inner) use ($locationId): void {
                    $inner->where('location_id', $locationId)
                        ->orWhereNull('location_id');
                });
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
                $connectionQuery->where(function (Builder $inner) use ($locationIds): void {
                    $inner->whereIn('location_id', $locationIds)
                        ->orWhereNull('location_id');
                });
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

    private static function productThumbnail(Get $get): HtmlString
    {
        $productId = (int) ($get('woo_product_id') ?? 0);

        if ($productId <= 0) {
            return new HtmlString('<span class="inline-flex h-8 w-8 items-center justify-center rounded bg-gray-100 text-[9px] text-gray-400">No Img</span>');
        }

        static $imageCache = [];

        if (! array_key_exists($productId, $imageCache)) {
            $imageCache[$productId] = WooProduct::query()
                ->whereKey($productId)
                ->value('main_image_url');
        }

        $imageUrl = $imageCache[$productId];

        $resolvedImage = filled($imageUrl)
            ? e((string) $imageUrl)
            : 'https://placehold.co/56x56?text=No+Img';

        return new HtmlString(
            '<img src="'.$resolvedImage.'" alt="Produs" class="h-8 w-8 rounded object-cover ring-1 ring-gray-200/70" />'
        );
    }

    /**
     * @return array{subtotal: float, discount_total: float, total: float}
     */
    private static function offerTotals(Get $get): array
    {
        $items = $get('items');

        if (! is_array($items)) {
            return [
                'subtotal' => 0.0,
                'discount_total' => 0.0,
                'total' => 0.0,
            ];
        }

        $subtotal = 0.0;
        $total = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = max(0, (float) ($item['quantity'] ?? 0));
            $unitPrice = max(0, (float) ($item['unit_price'] ?? 0));
            $discountPercent = min(100, max(0, (float) ($item['discount_percent'] ?? 0)));

            $lineSubtotal = $quantity * $unitPrice;
            $lineTotal = $lineSubtotal * (1 - ($discountPercent / 100));

            $subtotal += $lineSubtotal;
            $total += $lineTotal;
        }

        return [
            'subtotal' => $subtotal,
            'discount_total' => max(0, $subtotal - $total),
            'total' => $total,
        ];
    }

    private static function formatCurrency(float $value): string
    {
        return number_format($value, 2, '.', ',').' RON';
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
            'view' => Pages\ViewOffer::route('/{record}'),
            'print' => Pages\PrintOffer::route('/{record}/print'),
            'edit' => Pages\EditOffer::route('/{record}/edit'),
        ];
    }
}
