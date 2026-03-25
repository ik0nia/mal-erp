<?php

namespace App\Filament\App\Resources;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;

use App\Filament\App\Resources\PurchaseOrderResource\Pages;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WooProduct;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Schemas\Schema;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class PurchaseOrderResource extends Resource
{
    use ChecksRolePermissions, HasDynamicNavSort;
    protected static ?string $model = PurchaseOrder::class;

    protected static string|\BackedEnum|null $navigationIcon   = 'heroicon-o-shopping-bag';
    protected static string|\UnitEnum|null $navigationGroup  = 'Achiziții';
    protected static ?string $navigationLabel  = 'Comenzi furnizori';
    protected static ?string $modelLabel       = 'Comandă furnizor';
    protected static ?string $pluralModelLabel = 'Comenzi furnizori';
    protected static ?int    $navigationSort   = 3;

    public static function getNavigationBadge(): ?string
    {
        if (! static::canCreate()) return null;

        $query = \App\Models\PurchaseRequestItem::query()
            ->where('status', \App\Models\PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                \App\Models\PurchaseRequest::STATUS_SUBMITTED,
                \App\Models\PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->where(fn ($q) => $q
                ->whereDoesntHave('product')
                ->orWhereHas('product', fn ($p) => $p->where('is_discontinued', false))
            );

        $user = auth()->user();
        if ($user instanceof User && $user->role === User::ROLE_CONSULTANT_VANZARI) {
            $supplierIds = Supplier::where('buyer_id', $user->id)->pluck('id');
            $query->whereIn('supplier_id', $supplierIds);
        }

        $count = $query->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Informații comandă')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('number')
                        ->label('Număr PO')
                        ->disabled()
                        ->placeholder('Se generează automat'),

                    \Filament\Schemas\Components\Group::make([
                        Select::make('supplier_id')
                            ->label('Furnizor')
                            ->options(function (): array {
                                $query = Supplier::query()->where('is_active', true);

                                $user = auth()->user();
                                if ($user && ! $user->isAdmin()) {
                                    $query->whereHas('buyers', fn ($q) => $q->where('users.id', $user->id));
                                }

                                return $query->orderBy('name')->pluck('name', 'id')->all();
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->hidden(fn () => filled(request()->query('supplier_id'))),

                        \Filament\Forms\Components\Placeholder::make('supplier_name')
                            ->label('Furnizor')
                            ->content(fn () => new \Illuminate\Support\HtmlString(
                                '<span style="font-size:1.25rem;font-weight:700;color:var(--gray-900)">'
                                . e(Supplier::find((int) request()->query('supplier_id'))?->name ?? '—')
                                . '</span>'
                            ))
                            ->visible(fn () => filled(request()->query('supplier_id'))),

                        \Filament\Forms\Components\Hidden::make('supplier_id')
                            ->visible(fn () => filled(request()->query('supplier_id'))),
                    ]),

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
                ->columnSpanFull()
                ->schema(fn (string $operation): array => [
                    static::buildItemsRepeater($operation),

                    Placeholder::make('total_value')
                        ->label('Total comandă')
                        ->hidden($operation === 'create')
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

                Tables\Columns\TextColumn::make('reception_status')
                    ->label('Recepție')
                    ->badge()
                    ->getStateUsing(function (PurchaseOrder $record): ?string {
                        if (! in_array($record->status, [
                            PurchaseOrder::STATUS_RECEIVED,
                            PurchaseOrder::STATUS_SENT,
                        ], true)) {
                            return null;
                        }

                        if ($record->status === PurchaseOrder::STATUS_SENT) {
                            return 'așteptare';
                        }

                        // RECEIVED — verificăm dacă are lipsuri
                        $hasShortfall = $record->items->contains(
                            fn ($item): bool => $item->received_quantity !== null
                                && (float) $item->received_quantity < (float) $item->quantity
                        );

                        return $hasShortfall ? 'partiala' : 'completa';
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'completa'  => 'Completă',
                        'partiala'  => 'Parțială ⚠',
                        'așteptare' => 'Așteptare',
                        default     => '',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'completa'  => 'success',
                        'partiala'  => 'warning',
                        'așteptare' => 'gray',
                        default     => 'gray',
                    }),

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

                Tables\Filters\TernaryFilter::make('partial_reception')
                    ->label('Recepție parțială')
                    ->queries(
                        true: fn (Builder $q) => $q
                            ->where('status', PurchaseOrder::STATUS_RECEIVED)
                            ->whereHas('items', fn ($i) => $i->whereColumn('received_quantity', '<', 'quantity')
                                ->whereNotNull('received_quantity')),
                        false: fn (Builder $q) => $q
                            ->where('status', PurchaseOrder::STATUS_RECEIVED)
                            ->whereDoesntHave('items', fn ($i) => $i->whereColumn('received_quantity', '<', 'quantity')
                                ->whereNotNull('received_quantity')),
                    ),
            ])
            ->deferFilters(false)
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrder::STATUS_DRAFT),
                Actions\DeleteAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => static::canDelete($record))
                    ->requiresConfirmation(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
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
                    TextEntry::make('received_at')->label('Recepționat la')->dateTime('d.m.Y H:i')->placeholder('—'),
                    TextEntry::make('receivedBy.name')->label('Recepționat de')->placeholder('—'),
                    TextEntry::make('received_notes')->label('Observații recepție')->placeholder('—')->columnSpanFull(),
                ]),

            InfolistSection::make('Comunicare furnizor')
                ->collapsed()
                ->schema([
                    ViewEntry::make('supplier_emails_context')
                        ->label('')
                        ->view('filament.app.infolist.purchase-order-emails'),
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
                            TextEntry::make('quantity')->label('Cant. comandată'),
                            TextEntry::make('received_quantity')
                                ->label('Cant. recepționată')
                                ->placeholder('—')
                                ->formatStateUsing(function ($state, \App\Models\PurchaseOrderItem $record): string {
                                    if ($state === null) return '—';
                                    $qty = (float) $state;
                                    $ordered = (float) $record->quantity;
                                    if ($qty < $ordered) {
                                        return number_format($qty, 0, ',', '.') . ' / ' . number_format($ordered, 0, ',', '.') . ' ⚠';
                                    }
                                    return number_format($qty, 0, ',', '.');
                                })
                                ->color(fn ($state, \App\Models\PurchaseOrderItem $record): string =>
                                    $state === null ? 'gray' :
                                    ((float) $state < (float) $record->quantity ? 'warning' : 'success')
                                )
                                ->badge(),
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
        $query = parent::getEloquentQuery()->with(['supplier', 'buyer', 'items']);

        $user = auth()->user();
        if ($user && $user->role === User::ROLE_CONSULTANT_VANZARI) {
            $query->whereHas('supplier', fn ($q) => $q->where('buyer_id', $user->id));
        }

        return $query;
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
                User::ROLE_CONSULTANT_VANZARI,
            ], true);
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) return false;

        // Nimeni nu poate șterge comenzi în statusuri active
        if (! in_array($record->status, [
            PurchaseOrder::STATUS_DRAFT,
            PurchaseOrder::STATUS_CANCELLED,
            PurchaseOrder::STATUS_REJECTED,
        ], true)) {
            return false;
        }

        return $user->isSuperAdmin() || $user->isAdmin()
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

    private static function buildItemsRepeater(string $operation): Repeater
    {
        $isCreate = $operation === 'create';

        $productSelectField = Select::make('woo_product_id')
            ->label('Caută produs')
            ->searchable()
            ->searchPrompt('Caută după nume sau SKU...')
            ->noSearchResultsMessage('Niciun produs găsit la acest furnizor.')
            ->getSearchResultsUsing(function (string $search, Get $get): array {
                $supplierId = (int) ($get('../../supplier_id') ?? 0);

                if (! $supplierId) {
                    return [];
                }

                return WooProduct::query()
                    ->whereHas('suppliers', fn ($q) => $q->where('suppliers.id', $supplierId))
                    ->where(fn (Builder $q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                    )
                    ->limit(30)->get()
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
            ->afterStateUpdated(function ($state, Set $set, Get $get) use ($isCreate): void {
                if (! $state) return;

                $supplierId = (int) ($get('../../supplier_id') ?? 0);

                if ($supplierId) {
                    $ps = ProductSupplier::where('woo_product_id', $state)
                        ->where('supplier_id', $supplierId)
                        ->first();

                    if ($ps) {
                        $set('supplier_sku', $ps->supplier_sku);
                        if (! $isCreate && $ps->purchase_price) {
                            $set('unit_price', (float) $ps->purchase_price);
                        }
                    }
                }

                $product = WooProduct::query()->find($state, ['id', 'name', 'sku', 'min_stock_qty', 'max_stock_qty']);
                if ($product) {
                    $set('product_name', $product->decoded_name ?? $product->name);
                    $set('sku', $product->sku);
                }

                if ($isCreate) {
                    // Fetch stock + velocity data pentru câmpurile info_*
                    $row = \Illuminate\Support\Facades\DB::table('woo_products as wp')
                        ->leftJoin('bi_product_velocity_current as bpv', 'bpv.reference_product_id', '=', 'wp.sku')
                        ->leftJoin(
                            \Illuminate\Support\Facades\DB::raw('(SELECT woo_product_id, COALESCE(SUM(quantity),0) as total_qty FROM product_stocks GROUP BY woo_product_id) stk'),
                            'stk.woo_product_id', '=', 'wp.id'
                        )
                        ->where('wp.id', $state)
                        ->select([
                            'wp.min_stock_qty',
                            'wp.max_stock_qty',
                            \Illuminate\Support\Facades\DB::raw('COALESCE(stk.total_qty, 0) as stock'),
                            \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_7d, 0)  as avg7'),
                            \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_30d, 0) as avg30'),
                            \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_90d, 0) as avg90'),
                        ])
                        ->first();

                    if ($row) {
                        $avg7  = (float) $row->avg7;
                        $avg30 = (float) $row->avg30;
                        $avg90 = (float) $row->avg90;
                        $stock = (float) $row->stock;
                        $minStk = $row->min_stock_qty !== null ? (float) $row->min_stock_qty : null;
                        $maxStk = $row->max_stock_qty !== null ? (float) $row->max_stock_qty : null;

                        $base  = max($avg7, $avg30, $avg90);
                        $trend = ($avg30 > 0 && $avg7 > 0 && $avg7 < $avg30 * 0.85)
                            ? max(0.5, $avg7 / $avg30)
                            : 1.0;
                        $velDay      = $base * $trend;
                        $sales7d     = round($avg7 * 7, 1);
                        $sales30d    = round($avg30 * 30, 1);
                        $safetyStock = $velDay * 3;
                        $hint        = max(0, (int) ceil($velDay * 7 + $safetyStock - $stock));

                        // additional_store
                        if ($maxStk !== null && $maxStk > 0) {
                            $addStore   = max(0, (int) ceil($maxStk - $stock));
                            $calcMethod = 'max_stock';
                        } elseif ($hint > 0) {
                            $addStore   = $hint;
                            $calcMethod = 'velocity';
                        } else {
                            $addStore   = 0;
                            $calcMethod = null;
                        }

                        $daysToStockout = $avg7 > 0 ? round($stock / $avg7, 1) : null;

                        $set('info_stock',          $stock);
                        $set('info_sales_7d',        $sales7d);
                        $set('info_days_stockout',   $daysToStockout);
                        $set('quantity_hint',        $addStore > 0 ? $addStore : null);
                        $set('recommendation_json', json_encode([
                            'from_requests'     => 0,
                            'reserved_qty'      => 0,
                            'general_qty'       => 0,
                            'current_stock'     => $stock,
                            'velocity_day'      => $velDay,
                            'sales_7d'          => $sales7d,
                            'sales_30d'         => $sales30d,
                            'min_stock_qty'     => $minStk,
                            'max_stock_qty'     => $maxStk,
                            'additional_store'  => $addStore,
                            'total_recommended' => $addStore,
                            'calc_method'       => $calcMethod,
                        ], JSON_UNESCAPED_UNICODE));
                    } else {
                        $set('info_stock',          null);
                        $set('info_sales_7d',        null);
                        $set('info_days_stockout',   null);
                        $set('quantity_hint',        null);
                        $set('recommendation_json',  null);
                    }
                }
            })
            ->nullable();

        if ($isCreate) {
            // CREATE: 10 câmpuri vizibile + 7 Hidden la sfârșit
            $schema = [
                // 1. thumbnail
                Placeholder::make('thumbnail')
                    ->label('')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => static::productThumbnail($get('woo_product_id')))
                    ->extraAttributes(['class' => 'w-12']),
                // 2. woo_product_id
                $productSelectField,
                // 3. sku — vizibil, readonly, auto-fill
                TextInput::make('sku')->label('SKU')->readOnly()->nullable(),
                // 4. supplier_sku
                TextInput::make('supplier_sku')->label('SKU furnizor')->nullable(),
                // 5. quantity
                TextInput::make('quantity')
                    ->label('Cantitate')->numeric()->minValue(0.001)
                    ->formatStateUsing(fn ($state) => $state !== null ? (float) $state : null)
                    ->placeholder(fn (Get $get): ?string => $get('quantity_hint') ? (string)(int)$get('quantity_hint') : null)
                    ->live(onBlur: true),
                // 6. col_stock — citește din info_stock Hidden
                Placeholder::make('col_stock')
                    ->label('Stoc actual')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        $get('info_stock') !== null && $get('info_stock') !== ''
                            ? '<span class="text-sm font-mono">'.number_format((float)$get('info_stock'), 0, ',', '.').'</span>'
                            : '<span class="text-gray-300 text-sm">—</span>'
                    )),
                // 7. col_sales_7d — citește din info_sales_7d Hidden
                Placeholder::make('col_sales_7d')
                    ->label('Vânz. 7z')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        $get('info_sales_7d') !== null && $get('info_sales_7d') !== ''
                            ? '<span class="text-sm font-mono text-blue-600">'.number_format((float)$get('info_sales_7d'), 1, ',', '.').'</span>'
                            : '<span class="text-gray-300 text-sm">—</span>'
                    )),
                // 8. col_days_stockout — citește din info_days_stockout Hidden
                Placeholder::make('col_days_stockout')
                    ->label('Epuizare')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        static::renderDaysToStockout($get('info_days_stockout'))
                    )),
                // 9. sources_info
                Placeholder::make('sources_info')
                    ->label('Surse')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => static::renderSourcesInfo($get('sources_json'), $get('recommendation_json'), $get('product_name'), (int) $get('woo_product_id'))),
                // 10. notes
                TextInput::make('notes')->label('Notițe')->nullable(),
                // Hidden — stocaj date
                Hidden::make('product_name'),
                Hidden::make('sources_json'),
                Hidden::make('recommendation_json'),
                Hidden::make('quantity_hint'),
                Hidden::make('info_stock'),
                Hidden::make('info_sales_7d'),
                Hidden::make('info_days_stockout'),
            ];
        } else {
            // EDIT: toate câmpurile vizibile, cu prețuri
            $schema = [
                Placeholder::make('thumbnail')
                    ->label('')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => static::productThumbnail($get('woo_product_id')))
                    ->extraAttributes(['class' => 'w-12']),
                $productSelectField,
                TextInput::make('product_name')->label('Denumire produs')->required(),
                TextInput::make('sku')->label('SKU intern')->nullable(),
                TextInput::make('supplier_sku')->label('SKU furnizor')->nullable(),
                TextInput::make('quantity')
                    ->label('Cantitate')->numeric()->minValue(0.001)->required()
                    ->default(1)
                    ->formatStateUsing(fn ($state) => $state !== null ? (float) $state : null)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Set $set, Get $get) =>
                        $set('line_total_preview', static::computeLineTotal($get('unit_price'), $state))
                    ),
                TextInput::make('unit_price')
                    ->label('Preț unitar')->numeric()->minValue(0)->nullable()->suffix('RON')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Set $set, Get $get) =>
                        $set('line_total_preview', static::computeLineTotal($state, $get('quantity')))
                    ),
                Placeholder::make('line_total_preview')
                    ->label('Total linie')
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<span class="font-semibold text-sm">'.
                        number_format((float)($get('quantity') ?? 0) * (float)($get('unit_price') ?? 0), 2, ',', '.').
                        ' RON</span>'
                    )),
                Placeholder::make('sources_info')
                    ->label('Surse')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => static::renderSourcesInfo($get('sources_json'), $get('recommendation_json'), $get('product_name'), (int) $get('woo_product_id'))),
                TextInput::make('notes')->label('Notițe')->nullable(),
                Hidden::make('sources_json'),
                Hidden::make('recommendation_json'),
                Hidden::make('quantity_hint'),
            ];
        }

        return Repeater::make('items')
            ->label('')
            ->relationship('items')
            ->schema($schema)
            ->columns($isCreate ? 10 : 10)
            ->defaultItems($isCreate ? 0 : 1)
            ->addActionLabel('Adaugă produs');
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

    private static function renderDaysToStockout(mixed $days): string
    {
        if ($days === null || $days === '') {
            return '<span class="text-gray-300 text-sm">—</span>';
        }

        $d = (float) $days;

        if ($d <= 0) {
            return '<span class="text-xs font-semibold text-red-600 bg-red-50 px-1.5 py-0.5 rounded">Epuizat</span>';
        }

        if ($d < 7) {
            return '<span class="text-xs font-semibold text-red-600">'.round($d, 1).' zile</span>';
        }

        if ($d < 14) {
            return '<span class="text-xs font-semibold text-orange-500">'.round($d, 1).' zile</span>';
        }

        return '<span class="text-xs text-gray-500">'.round($d, 1).' zile</span>';
    }

    private static function computeLineTotal($price, $qty): string
    {
        return number_format((float) ($price ?? 0) * (float) ($qty ?? 0), 2, ',', '.');
    }

    public static function renderSourcesInfo(?string $json, ?string $recJson = null, ?string $productName = null, int $productId = 0): HtmlString
    {
        $sources        = $json ? json_decode($json, true) : null;
        $requestSources = is_array($sources) ? array_filter($sources, fn ($s) => ! empty($s['request_item_id'])) : [];
        $count          = count($requestSources);
        $rec            = ($recJson && $recJson !== 'null') ? json_decode($recJson, true) : null;

        // If neither requests nor recommendation data, show placeholder
        if ($count === 0 && (! is_array($rec))) {
            if (blank($json)) {
                return new HtmlString('<span class="text-xs text-gray-400">—</span>');
            }
            return new HtmlString('<span class="text-xs text-gray-400 italic">velocitate</span>');
        }

        // Button label
        if ($count > 0) {
            $label = $count === 1 ? '1 necesar' : "{$count} necesare";
        } else {
            $label = 'velocitate';
        }

        // ---- helper: row cu label / valoare ----
        $row = fn (string $label, string $value, string $valueClass = 'text-gray-900 font-medium'): string =>
            '<div class="flex items-center justify-between gap-4 px-3 py-2">'
            .'<span class="text-sm text-gray-500">'.$label.'</span>'
            .'<span class="text-sm '.$valueClass.'">'.$value.'</span>'
            .'</div>';

        // ---- Section 1: request sources ----
        $rows = '';
        foreach ($requestSources as $src) {
            $qty         = number_format((float) ($src['quantity'] ?? 0), 0, ',', '.');
            $number      = e($src['request_number'] ?? '—');
            $consultant  = e($src['consultant'] ?? '—');
            $location    = e($src['location'] ?? '');
            $requestedAt = e($src['requested_at'] ?? '');
            $neededBy    = isset($src['needed_by']) && $src['needed_by']
                ? date('d.m.Y', strtotime((string) $src['needed_by']))
                : null;
            $clientRef   = e($src['client_reference'] ?? '');
            $requestId   = (int) ($src['request_id'] ?? 0);
            $url         = $requestId ? '/purchase-requests/'.$requestId : null;
            $urgentBadge = ! empty($src['is_urgent'])
                ? '<span class="ml-2 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-bold bg-red-100 text-red-700 ring-1 ring-red-200">URGENT</span>'
                : '';

            $link = $url
                ? '<a href="'.e($url).'" target="_blank" rel="noopener"
                        class="text-base font-bold text-primary-600 hover:text-primary-700 hover:underline inline-flex items-center gap-1.5">
                        '.$number.'
                        <svg class="h-3.5 w-3.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                      </a>'
                : '<span class="text-base font-bold text-gray-800">'.$number.'</span>';

            $rows .= '<div class="rounded-lg border border-gray-200 overflow-hidden">';
            // header card
            $rows .= '<div class="flex items-center gap-2 bg-gray-50 px-3 py-2.5 border-b border-gray-200">'.$link.$urgentBadge.'</div>';
            // rows alternante
            $rows .= '<div class="divide-y divide-gray-100">';
            $rows .= $row('Creat de', $consultant);
            if ($location)    $rows .= $row('Locație', $location);
            if ($requestedAt) $rows .= $row('Data cererii', $requestedAt);
            if ($neededBy)    $rows .= $row('Necesar până la', '<span class="font-semibold text-amber-700">'.$neededBy.'</span>', '');
            if ($clientRef)   $rows .= $row('Ref. client', '<span class="font-semibold text-orange-700">'.$clientRef.'</span> <span class="ml-1 text-xs bg-orange-100 text-orange-600 rounded px-1.5 py-0.5 font-medium">rezervat</span>', '');
            $rows .= $row('Cantitate solicitată', '<span class="text-base font-bold text-gray-900">'.$qty.' buc.</span>', '');
            $rows .= '</div>';
            $rows .= '</div>';
        }

        // ---- Section 2: recommendation breakdown ----
        $recSection = '';
        if (is_array($rec)) {
            $fromReq    = (float) ($rec['from_requests'] ?? 0);
            $reserved   = (float) ($rec['reserved_qty'] ?? 0);
            $general    = (float) ($rec['general_qty'] ?? 0);
            $stock      = (float) ($rec['current_stock'] ?? 0);
            $velDay     = (float) ($rec['velocity_day'] ?? 0);
            $sales7d    = (float) ($rec['sales_7d'] ?? 0);
            $sales30d   = (float) ($rec['sales_30d'] ?? 0);
            $minStk     = isset($rec['min_stock_qty']) && $rec['min_stock_qty'] !== null ? (float) $rec['min_stock_qty'] : null;
            $maxStk     = isset($rec['max_stock_qty']) && $rec['max_stock_qty'] !== null ? (float) $rec['max_stock_qty'] : null;
            $addStore   = (float) ($rec['additional_store'] ?? 0);
            $totalRec   = (float) ($rec['total_recommended'] ?? 0);
            $method     = $rec['calc_method'] ?? null;

            $recSection .= '<div class="rounded-lg border border-blue-200 overflow-hidden">';
            $recSection .= '<div class="bg-blue-50 px-3 py-2.5 border-b border-blue-200">';
            $recSection .= '<span class="text-sm font-bold text-blue-800">Recomandare cantitate</span>';
            $recSection .= '</div>';
            $recSection .= '<div class="divide-y divide-gray-100">';

            if ($fromReq > 0) {
                $recSection .= $row('Din necesare', number_format($fromReq, 0, ',', '.').' buc.');
                if ($reserved > 0) {
                    $recSection .= $row('— din care rezervate client', '<span class="text-orange-700 font-semibold">'.number_format($reserved, 0, ',', '.').' buc.</span>', '');
                }
                if ($general > 0) {
                    $recSection .= $row('— din care pt. stoc general', number_format($general, 0, ',', '.').' buc.');
                }
            }

            $recSection .= $row('Stoc curent', number_format($stock, 0, ',', '.').' buc.');

            if ($sales7d > 0 || $sales30d > 0) {
                $recSection .= $row('Mișcări ultimele 7 zile', number_format($sales7d, 1, ',', '.').' buc.');
                $recSection .= $row('Mișcări ultimele 30 zile', number_format($sales30d, 1, ',', '.').' buc.');
                $recSection .= $row('Velocitate ajustată', number_format($velDay, 2, ',', '.').' buc./zi');
            }

            if ($minStk !== null) {
                $recSection .= $row('Stoc minim (reorder point)', number_format($minStk, 0, ',', '.').' buc.');
            }
            if ($maxStk !== null) {
                $recSection .= $row('Stoc maxim (target)', number_format($maxStk, 0, ',', '.').' buc.');
            }

            if ($addStore > 0) {
                $methodLabel = match ($method) {
                    'max_stock' => 'Suplimentar pt. stoc maxim',
                    'velocity'  => 'Suplimentar pt. stoc magazin',
                    default     => 'Suplimentar magazin',
                };
                $recSection .= $row($methodLabel, '<span class="text-blue-700 font-bold">+'.number_format($addStore, 0, ',', '.').' buc.</span>', '');
            }

            $recSection .= '</div>';
            // Total footer
            $recSection .= '<div class="flex items-center justify-between bg-blue-600 px-3 py-3">';
            $recSection .= '<span class="text-sm font-bold text-white">Total recomandat</span>';
            $recSection .= '<span class="text-lg font-black text-white">'.number_format($totalRec, 0, ',', '.').' buc.</span>';
            $recSection .= '</div>';
            $recSection .= '</div>';
        }

        $uid = 'src_'.substr(md5(($json ?? '').'|'.($recJson ?? '')), 0, 8);

        // Product info for modal header
        $productHeaderHtml = '';
        if ($productName || $productId) {
            $displayName = $productName ?: '—';
            $priceHtml   = '';
            if ($productId) {
                $product = WooProduct::query()->find($productId, ['id', 'name', 'regular_price', 'sale_price', 'price']);
                if ($product) {
                    $displayName = $product->decoded_name ?: $displayName;
                    $priceVal    = filled($product->sale_price) ? $product->sale_price : ($product->regular_price ?: $product->price);
                    if ($priceVal) {
                        $formatted = number_format((float) $priceVal, 2, ',', '.').' RON';
                        $isSale    = filled($product->sale_price) && (float) $product->sale_price < (float) $product->regular_price;
                        if ($isSale) {
                            $regular   = number_format((float) $product->regular_price, 2, ',', '.').' RON';
                            $priceHtml = '<span class="text-sm font-bold text-green-700">'.$formatted.'</span>'
                                        .' <span class="text-xs text-gray-400 line-through">'.$regular.'</span>';
                        } else {
                            $priceHtml = '<span class="text-sm font-bold text-gray-700">'.$formatted.'</span>';
                        }
                    }
                }
            }
            $productUrl  = $productId ? '/produse/'.$productId : null;
            $nameHtml    = $productUrl
                ? '<a href="'.e($productUrl).'" target="_blank" rel="noopener"
                       class="text-sm font-semibold text-primary-600 hover:text-primary-700 hover:underline inline-flex items-center gap-1.5 leading-snug">
                       '.e($displayName).'
                       <svg class="h-3.5 w-3.5 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                         <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                       </svg>
                     </a>'
                : '<span class="text-sm font-semibold text-gray-900">'.e($displayName).'</span>';

            $productHeaderHtml = '<div class="px-6 py-3 bg-gray-50 border-b border-gray-200 space-y-1">'
                .'<div>'.$nameHtml.'</div>'
                .($priceHtml ? '<p class="flex items-center gap-2">'.
                    '<span class="text-xs text-gray-400">Preț vânzare:</span> '.$priceHtml.'</p>' : '')
                .'</div>';
        }

        $sectionTitle = $count > 0
            ? '<p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Necesare</p>'
            : '';

        $html = <<<HTML
<div x-data="{ open: false }" class="inline-block" id="{$uid}">
    <button type="button" @click.stop="open = true"
            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium
                   bg-primary-50 text-primary-700 hover:bg-primary-100 border border-primary-200
                   transition-colors cursor-pointer">
        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {$label}
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak @keydown.escape.window="open = false"
             class="fixed inset-0 z-[500] flex items-center justify-center p-4">

            <div @click="open = false"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm"></div>

            <div @click.stop
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="relative w-full max-w-lg bg-white rounded-xl shadow-xl ring-1 ring-gray-950/5 overflow-hidden dark:bg-gray-900 dark:ring-white/10">

                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Detalii cantitate</h3>
                    <button type="button" @click="open = false"
                            class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 h-8 w-8 text-gray-400 hover:text-gray-500 focus-visible:ring-primary-600 dark:text-gray-500 dark:hover:text-gray-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {$productHeaderHtml}

                <div class="px-6 py-5 space-y-3 max-h-[75vh] overflow-y-auto">
                    {$sectionTitle}
                    {$rows}
                    {$recSection}
                </div>
            </div>
        </div>
    </template>
</div>
HTML;

        return new HtmlString($html);
    }
}
