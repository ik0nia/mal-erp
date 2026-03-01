<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PurchaseOrderResource\Pages;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WooProduct;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationIcon   = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup  = 'Achiziții';
    protected static ?string $navigationLabel  = 'Comenzi furnizori';
    protected static ?string $modelLabel       = 'Comandă furnizor';
    protected static ?string $pluralModelLabel = 'Comenzi furnizori';
    protected static ?int    $navigationSort   = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Informații comandă')
                ->columns(3)
                ->schema([
                    TextInput::make('number')
                        ->label('Număr PO')
                        ->disabled()
                        ->placeholder('Se generează automat'),

                    Select::make('supplier_id')
                        ->label('Furnizor')
                        ->options(fn (): array => Supplier::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                        )
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabledOn('edit'),

                    Select::make('status')
                        ->label('Status')
                        ->options(PurchaseOrder::statusOptions())
                        ->disabled()
                        ->default(PurchaseOrder::STATUS_DRAFT),

                    Textarea::make('notes_internal')
                        ->label('Notițe interne')
                        ->rows(2)
                        ->columnSpanFull(),

                    Textarea::make('notes_supplier')
                        ->label('Notițe pentru furnizor')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Produse comandate')
                ->schema([
                    TableRepeater::make('items')
                        ->label('')
                        ->relationship('items')
                        ->headers([
                            Header::make('thumbnail')->label('')->width('48px'),
                            Header::make('woo_product_id')->label('Caută produs')->width('200px'),
                            Header::make('product_name')->label('Denumire produs')->width('180px'),
                            Header::make('sku')->label('SKU intern')->width('110px'),
                            Header::make('supplier_sku')->label('SKU furnizor')->width('110px'),
                            Header::make('quantity')->label('Cant.')->width('90px'),
                            Header::make('unit_price')->label('Preț unitar')->width('110px'),
                            Header::make('line_total_preview')->label('Total linie')->width('110px'),
                            Header::make('sources_info')->label('Surse')->width('140px'),
                            Header::make('notes')->label('Notițe')->width('140px'),
                            Header::make('sources_json')->label('')->width('1px'),
                        ])
                        ->schema([
                            Placeholder::make('thumbnail')
                                ->label('Imagine')
                                ->hiddenLabel()
                                ->content(fn (Get $get): HtmlString => static::productThumbnail($get('woo_product_id')))
                                ->extraAttributes(['class' => 'w-12']),

                            // Căutare produs filtrat după furnizorul selectat
                            Select::make('woo_product_id')
                                ->label('Caută produs')
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search, Get $get): array {
                                    $supplierId = (int) ($get('../../supplier_id') ?? 0);

                                    $query = WooProduct::query()
                                        ->where(fn (Builder $q) => $q
                                            ->where('name', 'like', "%{$search}%")
                                            ->orWhere('sku', 'like', "%{$search}%")
                                        );

                                    // Filtrare după furnizor dacă e selectat
                                    if ($supplierId) {
                                        $query->whereHas('suppliers', fn ($q) => $q->where('suppliers.id', $supplierId));
                                    }

                                    return $query->limit(30)->get()
                                        ->mapWithKeys(fn (WooProduct $p): array => [
                                            $p->id => "[{$p->sku}] ".($p->decoded_name ?? $p->name),
                                        ])
                                        ->all();
                                })
                                ->getOptionLabelUsing(function ($value): ?string {
                                    $p = WooProduct::query()->find($value, ['id', 'name', 'sku']);
                                    return $p ? "[{$p->sku}] ".($p->decoded_name ?? $p->name) : null;
                                })
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                    if (! $state) return;

                                    $supplierId = (int) ($get('../../supplier_id') ?? 0);

                                    // Auto-fill SKU furnizor + preț de achiziție
                                    if ($supplierId) {
                                        $ps = ProductSupplier::where('woo_product_id', $state)
                                            ->where('supplier_id', $supplierId)
                                            ->first();

                                        if ($ps) {
                                            $set('supplier_sku', $ps->supplier_sku);
                                            if ($ps->purchase_price) {
                                                $set('unit_price', (float) $ps->purchase_price);
                                            }
                                        }
                                    }

                                    // Auto-fill denumire + SKU intern din WooProduct
                                    $product = WooProduct::query()->find($state, ['id', 'name', 'sku']);
                                    if ($product) {
                                        $set('product_name', $product->decoded_name ?? $product->name);
                                        $set('sku', $product->sku);
                                    }
                                })
                                ->nullable(),

                            TextInput::make('product_name')
                                ->label('Denumire produs')
                                ->required(),

                            TextInput::make('sku')
                                ->label('SKU intern')
                                ->nullable(),

                            TextInput::make('supplier_sku')
                                ->label('SKU furnizor')
                                ->nullable(),

                            TextInput::make('quantity')
                                ->label('Cantitate')
                                ->numeric()
                                ->minValue(0.001)
                                ->required()
                                ->default(1)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn ($state, Set $set, Get $get) =>
                                    $set('line_total_preview', static::computeLineTotal($get('unit_price'), $state))
                                ),

                            TextInput::make('unit_price')
                                ->label('Preț unitar')
                                ->numeric()
                                ->minValue(0)
                                ->nullable()
                                ->suffix('RON')
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn ($state, Set $set, Get $get) =>
                                    $set('line_total_preview', static::computeLineTotal($state, $get('quantity')))
                                ),

                            Placeholder::make('line_total_preview')
                                ->label('Total linie')
                                ->content(fn (Get $get): HtmlString => new HtmlString(
                                    '<span class="font-semibold text-sm">'.
                                    number_format(
                                        (float) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0),
                                        2, ',', '.'
                                    ).' RON</span>'
                                )),

                            Placeholder::make('sources_info')
                                ->label('Surse')
                                ->hiddenLabel()
                                ->content(fn (Get $get): HtmlString => static::renderSourcesInfo($get('sources_json'))),

                            TextInput::make('notes')
                                ->label('Notițe')
                                ->nullable(),

                            Hidden::make('sources_json'),
                        ])
                        ->defaultItems(1)
                        ->addActionLabel('Adaugă produs'),

                    Placeholder::make('total_value')
                        ->label('Total comandă')
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            '<span class="text-xl font-bold">'.
                            number_format(
                                collect($get('items') ?? [])
                                    ->sum(fn ($item): float =>
                                        (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0)
                                    ),
                                2, ',', '.'
                            ).' RON</span>'
                        )),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Număr PO')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Furnizor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string => PurchaseOrder::statusColors()[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state): string => PurchaseOrder::statusOptions()[$state] ?? $state),

                Tables\Columns\TextColumn::make('total_value')
                    ->label('Valoare')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '.').' RON')
                    ->sortable(),

                Tables\Columns\TextColumn::make('buyer.name')
                    ->label('Resp. achiziții')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Trimis la')
                    ->dateTime('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(PurchaseOrder::statusOptions()),

                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Furnizor')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrder::STATUS_DRAFT),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Informații comandă')
                ->columns(3)
                ->schema([
                    TextEntry::make('number')->label('Număr PO'),
                    TextEntry::make('supplier.name')->label('Furnizor'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn ($state): string => PurchaseOrder::statusColors()[$state] ?? 'gray')
                        ->formatStateUsing(fn ($state): string => PurchaseOrder::statusOptions()[$state] ?? $state),
                    TextEntry::make('buyer.name')->label('Responsabil achiziții'),
                    TextEntry::make('total_value')
                        ->label('Valoare totală')
                        ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '.').' RON'),
                    TextEntry::make('currency')->label('Monedă'),
                    TextEntry::make('notes_internal')->label('Notițe interne')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('notes_supplier')->label('Notițe pentru furnizor')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('approved_at')->label('Aprobat la')->dateTime('d.m.Y H:i')->placeholder('—'),
                    TextEntry::make('approvedBy.name')->label('Aprobat de')->placeholder('—'),
                    TextEntry::make('rejection_reason')->label('Motiv respingere')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('sent_at')->label('Trimis la')->dateTime('d.m.Y H:i')->placeholder('—'),
                ]),

            InfolistSection::make('Produse comandate')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('product_name')->label('Produs'),
                            TextEntry::make('sku')->label('SKU intern')->placeholder('—'),
                            TextEntry::make('supplier_sku')->label('SKU furnizor')->placeholder('—'),
                            TextEntry::make('quantity')->label('Cantitate'),
                            TextEntry::make('unit_price')
                                ->label('Preț unitar')
                                ->formatStateUsing(fn ($state): string => $state
                                    ? number_format((float) $state, 4, ',', '.').' RON'
                                    : '—'),
                            TextEntry::make('line_total')
                                ->label('Total linie')
                                ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '.').' RON'),
                            TextEntry::make('notes')->label('Notițe')->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'buyer', 'items']);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) return false;

        return $user->isSuperAdmin()
            || $user->isAdmin()
            || in_array($user->role, [
                User::ROLE_MANAGER_ACHIZITII,
                User::ROLE_MANAGER,
            ], true);
    }

    public static function canApprove(Model $record): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) return false;

        return $user->isSuperAdmin()
            || in_array($user->role, [
                User::ROLE_MANAGER,
                User::ROLE_DIRECTOR_FINANCIAR,
                User::ROLE_DIRECTOR_VANZARI,
            ], true);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'view'   => Pages\ViewPurchaseOrder::route('/{record}'),
            'edit'   => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    private static function productThumbnail(?int $productId): HtmlString
    {
        static $cache = [];

        if (! $productId) {
            return new HtmlString('<span class="inline-flex h-8 w-8 items-center justify-center rounded bg-gray-100 text-[9px] text-gray-400">—</span>');
        }

        if (! array_key_exists($productId, $cache)) {
            $cache[$productId] = WooProduct::query()->whereKey($productId)->value('main_image_url');
        }

        $url = filled($cache[$productId]) ? e((string) $cache[$productId]) : null;

        if (! $url) {
            return new HtmlString('<span class="inline-flex h-8 w-8 items-center justify-center rounded bg-gray-100 text-[9px] text-gray-400">—</span>');
        }

        return new HtmlString(
            '<img src="'.$url.'" alt="" class="h-8 w-8 rounded object-cover ring-1 ring-gray-200/70" loading="lazy" />'
        );
    }

    private static function computeLineTotal($price, $qty): string
    {
        return number_format((float) ($price ?? 0) * (float) ($qty ?? 0), 2, ',', '.');
    }

    public static function renderSourcesInfo(?string $json): HtmlString
    {
        if (blank($json)) {
            return new HtmlString('<span class="text-xs text-gray-400">—</span>');
        }

        $sources = json_decode($json, true);
        if (! is_array($sources) || empty($sources)) {
            return new HtmlString('<span class="text-xs text-gray-400">—</span>');
        }

        $lines = [];
        foreach ($sources as $src) {
            $qty     = number_format((float) ($src['quantity'] ?? 0), 0, ',', '.');
            $number  = e((string) ($src['request_number'] ?? '—'));
            $urgentBadge = ! empty($src['is_urgent'])
                ? ' <span class="inline-flex items-center rounded px-1 text-[9px] font-bold bg-red-100 text-red-700">URG</span>'
                : '';

            $neededBy = isset($src['needed_by']) && $src['needed_by']
                ? 'Necesar: '.date('d.m.Y', strtotime((string) $src['needed_by']))
                : null;

            $tooltip = implode(' · ', array_filter([
                $src['consultant'] ?? null,
                $src['location']   ?? null,
                $neededBy,
                $src['client_reference'] ?? null,
            ]));

            $lines[] = '<div title="'.e($tooltip).'" class="flex items-center gap-1 leading-tight">'
                .'<span class="font-mono text-[11px] tabular-nums">'.$qty.'×</span>'
                .' <span class="text-[11px] text-gray-600">'.$number.'</span>'
                .$urgentBadge
                .'</div>';
        }

        return new HtmlString('<div class="space-y-0.5">'.implode('', $lines).'</div>');
    }
}
