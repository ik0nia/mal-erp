<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\PurchaseRequestResource;
use App\Filament\App\Resources\WooProductResource\Pages;
use App\Models\DailyStockMetric;
use App\Models\ProductReviewRequest;
use App\Models\ProductSupplierLog;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WooCategory;
use App\Models\WooProduct;
use Filament\Notifications\Notification;
use Filament\Forms;
use App\Models\Brand;
use Filament\Schemas\Schema;

use Filament\Actions\Action as InfolistAction;
use Filament\Schemas\Components\Actions;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class WooProductResource extends Resource
{
    use HasDynamicNavSort;

    use EnforcesLocationScope, ChecksRolePermissions;

    protected static ?string $model = WooProduct::class;

    protected static ?string $slug = 'produse';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Administrare magazin';

    protected static ?string $navigationLabel = 'Produse';

    protected static ?string $modelLabel = 'Produs';

    protected static ?string $pluralModelLabel = 'Produse';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->check();
    }

    public static function canEdit(Model $record): bool
    {
        $user = static::currentUser();

        if ($user === null) {
            return false;
        }

        if ($user->role === \App\Models\User::ROLE_MANAGER) {
            return true;
        }

        return \App\Models\RolePermission::check(static::permissionKey(), 'can_edit');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make('Informații produs')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Denumire')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('winmentor_name')
                        ->label('Denumire WinMentor')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('(completat automat din import)')
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('short_description')
                        ->label('Descriere scurtă')
                        ->rows(2)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('description')
                        ->label('Descriere completă')
                        ->rows(4)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('sku')
                        ->label('SKU / EAN')
                        ->maxLength(50)
                        ->helperText('Codul de bare (EAN) al produsului individual'),
                    Forms\Components\TextInput::make('brand')
                        ->label('Brand / Producător')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('main_image_url')
                        ->label('URL imagine principală')
                        ->url()
                        ->maxLength(500)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('unit')
                        ->label('Unitate de măsură')
                        ->options([
                            'buc' => 'buc',
                            'kg'  => 'kg',
                            'g'   => 'g',
                            'm'   => 'm',
                            'm2'  => 'm²',
                            'm3'  => 'm³',
                            'l'   => 'l',
                            'ml'  => 'ml',
                            'set' => 'set',
                            'pac' => 'pac',
                            'role' => 'role',
                        ])
                        ->searchable()
                        ->native(false),
                    Forms\Components\TextInput::make('weight')
                        ->label('Greutate (kg)')
                        ->maxLength(20),
                    Forms\Components\TextInput::make('dim_length')
                        ->label('Lungime (cm)')
                        ->maxLength(20),
                    Forms\Components\TextInput::make('dim_width')
                        ->label('Lățime (cm)')
                        ->maxLength(20),
                    Forms\Components\TextInput::make('dim_height')
                        ->label('Înălțime (cm)')
                        ->maxLength(20),
                    Forms\Components\TextInput::make('volume_m3')
                        ->label('Volum (m³)')
                        ->numeric()
                        ->step(0.000001)
                        ->helperText('Volum unitar pentru calcule transport'),
                    Forms\Components\Select::make('product_type')
                        ->label('Tip produs (BI)')
                        ->options(WooProduct::productTypeOptions())
                        ->default(WooProduct::TYPE_SHOP)
                        ->native(false)
                        ->helperText('Clasificare folosită de modulul BI. Producție și Garanție palet sunt excluse din rapoarte.'),
                    Forms\Components\Textarea::make('erp_notes')
                        ->label('Notițe interne ERP')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            \Filament\Schemas\Components\Section::make('Categorii')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Select::make('categories')
                        ->label('Categorii')
                        ->relationship('categories', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->native(false),
                ]),

            \Filament\Schemas\Components\Section::make('Atribute tehnice')
                ->description('Atribute vizibile pe fișa produsului în magazinul online.')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Repeater::make('attributesRelation')
                        ->label('')
                        ->relationship('attributes')
                        ->addActionLabel('Adaugă atribut')
                        ->reorderable('position')
                        ->columns(2)
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('Atribut')
                                ->required()
                                ->placeholder('ex: Material, Putere (W), Dulie')
                                ->datalist(\App\Console\Commands\GenerateProductAttributesCommand::KNOWN_ATTRIBUTES)
                                ->maxLength(100),
                            Forms\Components\TextInput::make('value')
                                ->label('Valoare')
                                ->required()
                                ->placeholder('ex: Cupru, 4.5, E14')
                                ->maxLength(255),
                        ]),
                ]),

            \Filament\Schemas\Components\Section::make('Furnizori')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Repeater::make('suppliers_data')
                        ->label('')
                        ->addActionLabel('Adaugă furnizor')
                        ->columns(3)
                        ->default([])
                        ->schema([
                            Forms\Components\Select::make('supplier_id')
                                ->label('Furnizor')
                                ->options(Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                ->required()
                                ->searchable()
                                ->native(false)
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('supplier_sku')
                                ->label('SKU furnizor')
                                ->maxLength(100),
                            Forms\Components\TextInput::make('supplier_product_name')
                                ->label('Denumire la furnizor')
                                ->maxLength(255)
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('supplier_package_sku')
                                ->label('Cod ambalaj furnizor')
                                ->helperText('Codul cutiei/baxului la furnizor')
                                ->maxLength(100),
                            Forms\Components\TextInput::make('supplier_package_ean')
                                ->label('EAN ambalaj')
                                ->helperText('Codul de bare al cutiei/baxului')
                                ->maxLength(30),
                            Forms\Components\TextInput::make('purchase_price')
                                ->label('Preț achiziție (fără TVA)')
                                ->numeric()
                                ->step(0.0001),
                            Forms\Components\Placeholder::make('purchase_price_vat')
                                ->label('Preț achiziție (cu TVA)')
                                ->content(fn (\Filament\Schemas\Components\Utilities\Get $get): string => $get('purchase_price')
                                    ? number_format((float) $get('purchase_price') * 1.21, 2, ',', '.') . ' RON'
                                    : '—'),
                            Forms\Components\Select::make('currency')
                                ->label('Monedă')
                                ->options(['RON' => 'RON', 'EUR' => 'EUR', 'USD' => 'USD'])
                                ->default('RON')
                                ->native(false),
                            Forms\Components\TextInput::make('purchase_uom')
                                ->label('UM cumpărare')
                                ->placeholder('ex: palet, bax, cutie')
                                ->maxLength(50),
                            Forms\Components\TextInput::make('conversion_factor')
                                ->label('Factor conversie')
                                ->helperText('Câte buc = 1 UM cumpărare')
                                ->numeric()
                                ->minValue(0)
                                ->default(1),
                            Forms\Components\TextInput::make('lead_days')
                                ->label('Zile livrare')
                                ->numeric()
                                ->minValue(0),
                            Forms\Components\TextInput::make('incoterms')
                                ->label('Incoterms')
                                ->placeholder('EXW, DAP, DDP...')
                                ->maxLength(10),
                            Forms\Components\Toggle::make('price_includes_transport')
                                ->label('Preț include transport'),
                            Forms\Components\TextInput::make('min_order_qty')
                                ->label('Cant. minimă')
                                ->numeric()
                                ->minValue(0),
                            Forms\Components\TextInput::make('order_multiple')
                                ->label('Multiplu comandă')
                                ->helperText('Se comandă doar multipli de această cantitate (ex: 48 = bax)')
                                ->numeric()
                                ->minValue(0),
                            Forms\Components\TextInput::make('po_max_qty')
                                ->label('Cant. max PO (fără aprobare)')
                                ->numeric()
                                ->minValue(0),
                            Forms\Components\DatePicker::make('date_start')
                                ->label('Preț valid de la'),
                            Forms\Components\DatePicker::make('date_end')
                                ->label('Preț valid până la'),
                            Forms\Components\TextInput::make('over_delivery_tolerance')
                                ->label('Toleranță supra-livrare (%)')
                                ->numeric()
                                ->suffix('%'),
                            Forms\Components\TextInput::make('under_delivery_tolerance')
                                ->label('Toleranță sub-livrare (%)')
                                ->numeric()
                                ->suffix('%'),
                            Forms\Components\Toggle::make('is_preferred')
                                ->label('Furnizor preferat')
                                ->default(false),
                            Forms\Components\Textarea::make('notes')
                                ->label('Notițe')
                                ->rows(2)
                                ->columnSpanFull(),
                        ]),
                ]),
            \Filament\Schemas\Components\Section::make('Ambalare')
                ->columnSpanFull()
                ->collapsible()
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('qty_per_inner_box')
                        ->label('Buc/cutie interioară')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('qty_per_carton')
                        ->label('Buc/carton master')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('cartons_per_pallet')
                        ->label('Cartoane/palet')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('ean_carton')
                        ->label('EAN carton master')
                        ->maxLength(30),
                ]),
            \Filament\Schemas\Components\Section::make('Conformitate și logistică')
                ->columns(3)
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    Forms\Components\TextInput::make('country_of_origin')
                        ->label('Țara de origine')
                        ->maxLength(2)
                        ->placeholder('RO, CN, DE...')
                        ->helperText('Cod ISO (2 litere)'),
                    Forms\Components\TextInput::make('customs_tariff_code')
                        ->label('Cod HS/NC')
                        ->maxLength(20)
                        ->placeholder('ex: 3214.10.10')
                        ->helperText('Cod vamal'),
                    Forms\Components\TextInput::make('vat_rate')
                        ->label('Cotă TVA (%)')
                        ->numeric()
                        ->default(19)
                        ->suffix('%'),
                    Forms\Components\TextInput::make('warranty_months')
                        ->label('Garanție (luni)')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('luni'),
                    Forms\Components\TextInput::make('certification_codes')
                        ->label('Certificări')
                        ->maxLength(255)
                        ->placeholder('CE, ROHS, REACH...')
                        ->helperText('Separate prin virgulă'),
                    Forms\Components\TextInput::make('msds_link')
                        ->label('Fișă securitate (MSDS)')
                        ->url()
                        ->maxLength(500)
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('storage_conditions')
                        ->label('Condiții depozitare')
                        ->maxLength(255)
                        ->placeholder('ex: Loc uscat, 5-30°C')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('shelf_life_days')
                        ->label('Termen valabilitate')
                        ->numeric()
                        ->suffix('zile'),
                    Forms\Components\Toggle::make('is_fragile')
                        ->label('Fragil')
                        ->inline(false),
                    Forms\Components\Toggle::make('is_stackable')
                        ->label('Stivuibil')
                        ->default(true)
                        ->inline(false),
                    Forms\Components\Toggle::make('is_temperature_sensitive')
                        ->label('Sensibil la temperatură')
                        ->inline(false),
                ]),
            \Filament\Schemas\Components\Section::make('Mod aprovizionare')
                ->description('Controlează cum se aprovizionează produsul și dacă mai face parte din portofoliu.')
                ->collapsible()
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Toggle::make('is_on_demand')
                        ->label('La comandă (fără stoc fizic)')
                        ->helperText('Backorders permise în WooCommerce, stoc 0, PNR auto-creat la comenzi.')
                        ->default(false)
                        ->live()
                        ->afterStateHydrated(function (Forms\Components\Toggle $component, $record): void {
                            if ($record) {
                                $component->state($record->procurement_type === WooProduct::PROCUREMENT_ON_DEMAND);
                            }
                        }),
                    Forms\Components\TextInput::make('on_demand_label')
                        ->label('Mesaj disponibilitate (afișat pe site)')
                        ->placeholder('ex: Disponibil în 3-5 zile lucrătoare')
                        ->maxLength(100)
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get): bool => (bool) $get('is_on_demand')),
                    \Filament\Schemas\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('min_stock_qty')
                            ->label('Stoc minim (reorder point)')
                            ->helperText('Când stocul scade sub această valoare, produsul apare în alertele de reaprovizionare.')
                            ->numeric()->minValue(0)->nullable(),
                        Forms\Components\TextInput::make('max_stock_qty')
                            ->label('Stoc maxim (target)')
                            ->helperText('Cât vrem să avem în stoc după aprovizionare. Dacă e gol, se calculează din velocitate × 14 zile.')
                            ->numeric()->minValue(0)->nullable(),
                    ]),
                    \Filament\Schemas\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('safety_stock')
                            ->label('Stoc de siguranță')
                            ->numeric()
                            ->suffix('buc'),
                        Forms\Components\TextInput::make('reorder_qty')
                            ->label('Cantitate reorder')
                            ->helperText('Cantitate recomandată de comandat la atingerea stocului minim')
                            ->numeric()
                            ->suffix('buc'),
                    ]),
                    Forms\Components\Select::make('replenishment_method')
                        ->label('Metodă reaprovizionare')
                        ->options([
                            'manual' => 'Manual',
                            'reorder_point' => 'Punct de reorder',
                            'min_max' => 'Min/Max',
                        ])
                        ->native(false),
                    \Filament\Schemas\Components\Grid::make(3)->schema([
                        Forms\Components\TextInput::make('avg_daily_consumption')
                            ->label('Consum mediu zilnic')
                            ->numeric()
                            ->disabled()
                            ->suffix('buc/zi'),
                        Forms\Components\Select::make('abc_classification')
                            ->label('Clasificare ABC')
                            ->options([
                                'A' => 'A - Valoare mare',
                                'B' => 'B - Valoare medie',
                                'C' => 'C - Valoare mică',
                            ])
                            ->disabled()
                            ->native(false),
                        Forms\Components\Select::make('xyz_classification')
                            ->label('Clasificare XYZ')
                            ->options([
                                'X' => 'X - Cerere constantă',
                                'Y' => 'Y - Cerere variabilă',
                                'Z' => 'Z - Cerere imprevizibilă',
                            ])
                            ->disabled()
                            ->native(false),
                    ]),
                    Forms\Components\Toggle::make('is_discontinued')
                        ->label('Fără reaprovizionare')
                        ->helperText('Vindem stocul existent, dar nu mai achiziționăm. Exclus din sugestii de reaprovizionare.')
                        ->live(),
                    Forms\Components\Textarea::make('discontinued_reason')
                        ->label('Motiv')
                        ->placeholder('ex: Înlocuit de modelul X, furnizor oprit livrările...')
                        ->rows(2)
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get): bool => (bool) $get('is_discontinued')),
                    Forms\Components\Select::make('substituted_by_id')
                        ->label('Înlocuit de produsul')
                        ->helperText('La achiziții viitoare se va comanda produsul selectat în loc de acesta.')
                        ->relationship('substitutedBy', 'name')
                        ->searchable()
                        ->preload(false)
                        ->getSearchResultsUsing(fn (string $search) => WooProduct::query()
                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn ($p) => [$p->id => "[{$p->sku}] {$p->name}"]))
                        ->getOptionLabelUsing(fn ($value) => WooProduct::find($value)?->let(fn ($p) => "[{$p->sku}] {$p->name}"))
                        ->placeholder('Niciun înlocuitor setat')
                        ->native(false)
                        ->nullable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginationPageOptions([25, 50, 100, 250, 500])
            ->defaultPaginationPageOption(50)
            ->columns([
                ImageColumn::make('main_image_url')
                    ->label('Imagine')
                    ->size(56)
                    ->square()
                    ->defaultImageUrl('https://placehold.co/96x96?text=No+Img')
                    ->extraHeaderAttributes(['class' => 'hidden sm:table-cell'])
                    ->extraAttributes(['class' => 'hidden sm:table-cell']),
                TextColumn::make('name')
                    ->label('Produs')
                    ->formatStateUsing(fn (WooProduct $record): string => $record->decoded_name)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return static::applyOptimizedSearch($query, $search);
                    })
                    ->sortable()
                    ->wrap(),
                TextColumn::make('winmentor_name')
                    ->label('Denumire WinMentor')
                    ->placeholder('-')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return static::applyOptimizedSearch($query, $search);
                    })
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')
                    ->label('Sursă')
                    ->badge()
                    ->formatStateUsing(fn (WooProduct $record): string => match ($record->source) {
                        WooProduct::SOURCE_TOYA_API     => 'Feed furnizor Toya',
                        WooProduct::SOURCE_WINMENTOR_CSV => 'ERP (contabilitate)',
                        default                          => 'WooCommerce',
                    })
                    ->color(fn (WooProduct $record): string => match ($record->source) {
                        WooProduct::SOURCE_TOYA_API     => 'info',
                        WooProduct::SOURCE_WINMENTOR_CSV => 'warning',
                        default                          => 'success',
                    })
                    ->extraHeaderAttributes(['class' => 'hidden md:table-cell'])
                    ->extraAttributes(['class' => 'hidden md:table-cell'])
                    ->toggleable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->placeholder('-')
                    ->copyable()->copyMessage('Copiat!')
                    ->toggleable(),
                TextColumn::make('preferred_supplier')
                    ->label('Furnizor')
                    ->getStateUsing(fn (WooProduct $record): ?string =>
                        $record->suppliers->firstWhere('pivot.is_preferred', true)?->name
                        ?? $record->suppliers->first()?->name
                    )
                    ->placeholder('-')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Preț')
                    ->placeholder('-'),
                TextColumn::make('stock_status')
                    ->label('Stoc')
                    ->badge(),
                TextColumn::make('procurement_type')
                    ->label('Aprovizionare')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'on_demand' ? 'warning' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === 'on_demand' ? 'La comandă' : 'Stoc')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_discontinued')
                    ->label('Fără reaprov.')
                    ->boolean()
                    ->trueIcon('heroicon-o-archive-box-x-mark')
                    ->trueColor('danger')
                    ->falseIcon('')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('categories_list')
                    ->label('Categorii')
                    ->state(fn (WooProduct $record): string => $record->categories->pluck('name')->implode(', '))
                    ->placeholder('-')
                    ->extraHeaderAttributes(['class' => 'hidden lg:table-cell'])
                    ->extraAttributes(['class' => 'hidden lg:table-cell'])
                    ->toggleable(),
                Tables\Columns\SelectColumn::make('product_type')
                    ->label('Tip produs')
                    ->options(WooProduct::productTypeOptions())
                    ->extraHeaderAttributes(['class' => 'hidden lg:table-cell'])
                    ->extraAttributes(['class' => 'hidden lg:table-cell'])
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Actualizat')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable()
                    ->extraHeaderAttributes(['class' => 'hidden md:table-cell'])
                    ->extraAttributes(['class' => 'hidden md:table-cell']),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_type')
                    ->label('Tip produs')
                    ->options(WooProduct::productTypeOptions()),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Sursă')
                    ->options([
                        WooProduct::SOURCE_WOOCOMMERCE    => 'WooCommerce',
                        WooProduct::SOURCE_WINMENTOR_CSV  => 'ERP (contabilitate)',
                        WooProduct::SOURCE_TOYA_API       => 'Feed furnizor Toya',
                    ]),
                Tables\Filters\SelectFilter::make('stock_status')
                    ->label('Stoc')
                    ->options([
                        'instock' => 'În stoc',
                        'outofstock' => 'Fără stoc',
                        'onbackorder' => 'Precomandă',
                    ]),
                Tables\Filters\TernaryFilter::make('is_discontinued')
                    ->label('Reaprovizionare')
                    ->placeholder('Toate')
                    ->trueLabel('Fără reaprovizionare')
                    ->falseLabel('Cu reaprovizionare')
                    ->queries(
                        true:  fn (Builder $query) => $query->where('is_discontinued', true),
                        false: fn (Builder $query) => $query->where('is_discontinued', false),
                    ),
                Tables\Filters\TernaryFilter::make('procurement_type_filter')
                    ->label('La comandă')
                    ->placeholder('Toate')
                    ->trueLabel('Doar la comandă')
                    ->falseLabel('Fără "la comandă"')
                    ->queries(
                        true:  fn (Builder $query) => $query->where('procurement_type', 'on_demand'),
                        false: fn (Builder $query) => $query->where('procurement_type', 'stock'),
                    ),
                Tables\Filters\TernaryFilter::make('web_enriched')
                    ->label('Îmbogățit web')
                    ->placeholder('Toate')
                    ->trueLabel('Da (EAN lookup)')
                    ->falseLabel('Nu')
                    ->queries(
                        true:  fn (Builder $query) => $query->where('erp_notes', 'like', '%[web-enriched]%'),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('erp_notes')->orWhere('erp_notes', 'not like', '%[web-enriched]%')),
                    ),
                Tables\Filters\TernaryFilter::make('has_image')
                    ->label('Imagine')
                    ->placeholder('Toate produsele')
                    ->trueLabel('Cu imagine')
                    ->falseLabel('Fără imagine')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('main_image_url')->where('main_image_url', '!=', ''),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('main_image_url')->orWhere('main_image_url', '')),
                    ),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Categorie')
                    ->options(function (): array {
                        $user = static::currentUser();

                        if (! $user) {
                            return [];
                        }

                        $query = WooCategory::query()->orderBy('name');

                        if (! $user->isSuperAdmin()) {
                            $query->whereHas('connection', function (Builder $connectionQuery) use ($user): void {
                                $connectionQuery->where(function (Builder $inner) use ($user): void {
                                    $inner->whereIn('location_id', $user->operationalLocationIds())
                                        ->orWhereNull('location_id');
                                });
                            });
                        }

                        return $query->pluck('name', 'id')->all();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $categoryId = (int) ($data['value'] ?? 0);

                        if ($categoryId <= 0) {
                            return $query;
                        }

                        return $query->whereHas('categories', function (Builder $categoryQuery) use ($categoryId): void {
                            $categoryQuery->where('woo_categories.id', $categoryId);
                        });
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(['default' => 2, 'sm' => 3, 'lg' => 5])
            ->persistFiltersInSession()
            ->recordUrl(fn (WooProduct $record): string => static::getUrl('view', ['record' => $record]))
            ->searchPlaceholder('Caută după nume, SKU, slug sau categorie...')
            ->searchDebounce('800ms')
            ->defaultSort('name')
            ->actionsPosition(\Filament\Tables\Enums\RecordActionsPosition::BeforeColumns)
            ->recordActions([
                InfolistAction::make('add_to_necesar')
                    ->label('Necesar')
                    ->icon('heroicon-o-shopping-cart')
                    ->button()
                    ->size(\Filament\Support\Enums\Size::Medium)
                    ->color('warning')
                    ->extraAttributes(['style' => 'background-color:#f97316;color:white;border-color:#ea6c00;font-weight:600;'])
                    ->modalHeading(fn (WooProduct $record) => 'Adaugă la necesar: '.($record->decoded_name ?? $record->name))
                    ->modalSubmitActionLabel('Adaugă')
                    ->form(static::necesarModalForm())
                    ->action(function (WooProduct $record, array $data): void {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        $draft    = PurchaseRequest::getOrCreateDraft($user);
                        $existing = $draft->items()->where('woo_product_id', $record->id)->first();

                        if ($existing) {
                            $existing->update(['quantity' => (float) $existing->quantity + (float) $data['quantity']]);
                        } else {
                            $draft->items()->create([
                                'woo_product_id' => $record->id,
                                'quantity'       => $data['quantity'],
                                'is_urgent'      => $data['is_urgent'] ?? false,
                                'notes'          => $data['notes'] ?? null,
                            ]);
                        }

                        Notification::make()
                            ->success()
                            ->title('Produs adăugat la necesar')
                            ->actions([
                                \Filament\Actions\Action::make('open_cart')
                                    ->label('Deschide coșul →')
                                    ->url(PurchaseRequestResource::getUrl('edit', ['record' => $draft->id])),
                            ])
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWooProducts::route('/'),
            'view'  => Pages\ViewWooProduct::route('/{record}'),
            'edit'  => Pages\EditWooProduct::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'connection.location',
            'categories',
            'suppliers',
        ]);

        $user = static::currentUser();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($user): void {
            $outer->whereNull('connection_id')
                ->orWhereHas('connection', function (Builder $connectionQuery) use ($user): void {
                    $connectionQuery->where(function (Builder $inner) use ($user): void {
                        $inner->whereIn('location_id', $user->operationalLocationIds())
                            ->orWhereNull('location_id');
                    });
                });
        });
    }

    private static function applyOptimizedSearch(Builder $query, string $search): Builder
    {
        $terms = collect(preg_split('/\s+/', trim($search)))
            ->filter(fn ($term): bool => is_string($term) && $term !== '')
            ->map(fn (string $term): string => str_replace(['%', '_'], ['\\%', '\\_'], $term))
            ->values();

        if ($terms->isEmpty()) {
            return $query;
        }

        return $query->where(function (Builder $searchQuery) use ($terms): void {
            foreach ($terms as $term) {
                $like = "%{$term}%";

                $searchQuery->where(function (Builder $termQuery) use ($like): void {
                    $termQuery
                        ->where('woo_products.name', 'like', $like)
                        ->orWhere('woo_products.winmentor_name', 'like', $like)
                        ->orWhere('woo_products.sku', 'like', $like)
                        ->orWhere('woo_products.slug', 'like', $like)
                        ->orWhereHas('categories', function (Builder $categoryQuery) use ($like): void {
                            $categoryQuery->where('woo_categories.name', 'like', $like);
                        });
                });
            }
        });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // ── Primul card: poza mare + info compactă ──────────────────
                Section::make()
                    ->schema([
                        ViewEntry::make('product_header')
                            ->label('')
                            ->view('filament.infolist.product-header')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                // ── Acțiuni utilitare stânga (edit, contact) ────────────────
                Actions::make([
                    InfolistAction::make('edit')
                        ->label('Editează')
                        ->icon('heroicon-o-pencil')
                        ->color('gray')
                        ->size(\Filament\Support\Enums\Size::Small)
                        ->visible(fn (WooProduct $record): bool => WooProductResource::canEdit($record))
                        ->url(fn (WooProduct $record) => WooProductResource::getUrl('edit', ['record' => $record->id], panel: 'app')),
                    InfolistAction::make('contact_supplier')
                        ->label('Contact furnizor')
                        ->icon('heroicon-o-phone')
                        ->color('gray')
                        ->size(\Filament\Support\Enums\Size::Small)
                        ->visible(function (WooProduct $record): bool {
                            $record->loadMissing('suppliers');
                            return $record->suppliers->isNotEmpty();
                        })
                        ->modalHeading('Date contact furnizori')
                        ->modalContent(fn (WooProduct $record): HtmlString => static::renderSupplierContactModal($record))
                        ->modalSubmitAction(false)
                        ->modalCancelAction(fn (\Filament\Actions\StaticAction $action) => $action->label('Închide')),

                    InfolistAction::make('toggle_site_status')
                        ->label(fn (WooProduct $record): string => $record->status === 'publish' ? 'Trece în draft' : 'Publică pe site')
                        ->icon(fn (WooProduct $record): string => $record->status === 'publish' ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                        ->color(fn (WooProduct $record): string => $record->status === 'publish' ? 'gray' : 'success')
                        ->size(\Filament\Support\Enums\Size::Small)
                        ->visible(function (WooProduct $record): bool {
                            if ($record->is_placeholder || ! $record->woo_id || ! $record->connection_id) {
                                return false;
                            }
                            $user = auth()->user();
                            return $user instanceof User && ($user->isAdmin() || $user->role === User::ROLE_MANAGER);
                        })
                        ->requiresConfirmation()
                        ->modalHeading(fn (WooProduct $record): string => $record->status === 'publish'
                            ? 'Treci produsul în draft?'
                            : 'Publică produsul pe site?')
                        ->modalDescription(fn (WooProduct $record): string => $record->status === 'publish'
                            ? 'Produsul va dispărea de pe site imediat.'
                            : 'Produsul va deveni vizibil pe site imediat.')
                        ->modalSubmitActionLabel(fn (WooProduct $record): string => $record->status === 'publish' ? 'Da, trece în draft' : 'Da, publică')
                        ->action(function (WooProduct $record): void {
                            $newStatus = $record->status === 'publish' ? 'draft' : 'publish';
                            try {
                                $client = new \App\Services\WooCommerce\WooClient($record->connection);
                                $result = $client->updateProductStatus((int) $record->woo_id, $newStatus);
                            } catch (\Throwable $e) {
                                Notification::make()->danger()->title('Eroare WooCommerce')->body($e->getMessage())->send();
                                return;
                            }
                            $confirmedStatus = $result['status'] ?? null;
                            if ($confirmedStatus !== $newStatus) {
                                Notification::make()->warning()->title('WooCommerce nu a confirmat schimbarea')
                                    ->body('Status returnat: ' . ($confirmedStatus ?? 'necunoscut'))->send();
                                return;
                            }
                            $record->update(['status' => $newStatus]);
                            Notification::make()->success()
                                ->title($newStatus === 'publish' ? 'Produs publicat pe site' : 'Produs trecut în draft')
                                ->send();
                        }),
                ]),

                // ── Acțiuni principale (Reverificare + Asociează furnizor) ───
                Actions::make([
                    InfolistAction::make('review_request')
                        ->label('Reverificare')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('warning')
                        ->size(\Filament\Support\Enums\Size::Small)
                        ->modalHeading(fn (WooProduct $record) => 'Reverificare: '.($record->decoded_name ?? $record->name))
                        ->modalDescription('Descrie ce anume trebuie verificat sau modificat la acest produs.')
                        ->modalWidth('lg')
                        ->modalSubmitActionLabel('Trimite solicitarea')
                        ->modalCancelActionLabel('Anulează')
                        ->form([
                            \Filament\Forms\Components\Textarea::make('message')
                                ->label('Mesaj')
                                ->placeholder('Ex: Imaginea principală e greșită, descrierea lipsește, prețul pare incorect...')
                                ->required()
                                ->rows(4)
                                ->maxLength(2000),
                            \Filament\Forms\Components\FileUpload::make('photo_path')
                                ->label('Poză (opțional)')
                                ->helperText('Pe telefon poți face o poză direct sau alege din galerie.')
                                ->image()
                                ->disk('public')
                                ->directory('review-photos')
                                ->maxSize(20480)
                                ->extraInputAttributes(['accept' => 'image/*']),
                        ])
                        ->action(function (WooProduct $record, array $data): void {
                            \App\Models\ProductReviewRequest::create([
                                'woo_product_id' => $record->id,
                                'user_id'        => auth()->id(),
                                'message'        => $data['message'],
                                'photo_path'     => $data['photo_path'] ?? null,
                                'status'         => \App\Models\ProductReviewRequest::STATUS_PENDING,
                            ]);

                            Notification::make()
                                ->success()
                                ->title('Solicitare trimisă')
                                ->body('Echipa de produs a fost notificată.')
                                ->send();
                        }),

                    InfolistAction::make('associate_supplier')
                        ->label('Asociază furnizor')
                        ->icon('heroicon-o-link')
                        ->color('success')
                        ->size(\Filament\Support\Enums\Size::Small)
                        ->visible(function (WooProduct $record): bool {
                            $record->loadMissing('suppliers');
                            return $record->suppliers->isEmpty();
                        })
                        ->modalHeading('Asociează furnizor')
                        ->modalWidth('lg')
                        ->modalSubmitActionLabel('Asociează')
                        ->modalCancelActionLabel('Anulează')
                        ->form([
                            \Filament\Forms\Components\Select::make('supplier_id')
                                ->label('Caută furnizor')
                                ->placeholder('Scrie cel puțin 2 caractere...')
                                ->searchable()
                                ->native(false)
                                ->required()
                                ->noSearchResultsMessage('Niciun furnizor găsit. Folosește butonul + pentru a crea unul nou.')
                                ->searchPrompt('Caută după nume...')
                                ->getSearchResultsUsing(fn (string $search): array =>
                                    Supplier::where('is_active', true)
                                        ->where('name', 'like', '%' . $search . '%')
                                        ->orderBy('name')
                                        ->limit(20)
                                        ->pluck('name', 'id')
                                        ->toArray()
                                )
                                ->getOptionLabelUsing(fn (mixed $value): ?string =>
                                    Supplier::find($value)?->name
                                )
                                ->createOptionForm([
                                    \Filament\Forms\Components\TextInput::make('name')
                                        ->label('Nume furnizor')
                                        ->required()
                                        ->maxLength(255),
                                ])
                                ->createOptionAction(fn (\Filament\Actions\Action $action) =>
                                    $action
                                        ->modalHeading('Furnizor nou')
                                        ->modalSubmitActionLabel('Creează')
                                        ->modalCancelActionLabel('Anulează')
                                )
                                ->createOptionUsing(function (array $data): int {
                                    return Supplier::create([
                                        'name'      => trim($data['name']),
                                        'is_active' => true,
                                    ])->id;
                                }),
                        ])
                        ->action(function (WooProduct $record, array $data): void {
                            $supplier  = Supplier::findOrFail((int) $data['supplier_id']);
                            $logAction = $supplier->created_at->gt(now()->subSeconds(30))
                                ? 'created_and_associated'
                                : 'associated';

                            if (! $record->suppliers()->where('supplier_id', $supplier->id)->exists()) {
                                $record->suppliers()->attach($supplier->id);
                            }

                            \App\Models\ProductSupplierLog::create([
                                'woo_product_id' => $record->id,
                                'supplier_id'    => $supplier->id,
                                'user_id'        => auth()->id(),
                                'action'         => $logAction,
                            ]);

                            Notification::make()
                                ->success()
                                ->title('Furnizor asociat')
                                ->body('Produsul a fost asociat furnizorului „' . $supplier->name . '".')
                                ->send();
                        }),

                    InfolistAction::make('toggle_no_reorder')
                        ->label(fn (WooProduct $record): string => $record->is_discontinued
                            ? 'Reactivează reaprovizionarea'
                            : 'Fără reaprovizionare')
                        ->icon(fn (WooProduct $record): string => $record->is_discontinued
                            ? 'heroicon-o-arrow-uturn-left'
                            : 'heroicon-o-archive-box-x-mark')
                        ->color(fn (WooProduct $record): string => $record->is_discontinued ? 'success' : 'danger')
                        ->size(\Filament\Support\Enums\Size::Small)
                        ->requiresConfirmation()
                        ->modalHeading(fn (WooProduct $record): string => $record->is_discontinued
                            ? 'Reactivezi reaprovizionarea?'
                            : 'Marchezi ca "Fără reaprovizionare"?')
                        ->modalDescription(fn (WooProduct $record): string => $record->is_discontinued
                            ? 'Produsul va apărea din nou în sugestiile de reaprovizionare.'
                            : 'Produsul va fi exclus din Necesar Marfă. Stocul existent se vinde în continuare.')
                        ->form(fn (WooProduct $record): array => $record->is_discontinued ? [] : [
                            \Filament\Forms\Components\Textarea::make('discontinued_reason')
                                ->label('Motiv (opțional)')
                                ->placeholder('ex: Înlocuit de modelul X, furnizor oprit livrările...')
                                ->rows(2),
                        ])
                        ->action(function (WooProduct $record, array $data): void {
                            $markingDiscontinued = ! $record->is_discontinued;

                            $record->update([
                                'is_discontinued'     => $markingDiscontinued,
                                'discontinued_reason' => $markingDiscontinued ? ($data['discontinued_reason'] ?? null) : null,
                            ]);

                            // Sincronizăm backorders în WooCommerce
                            if ($record->woo_id && $record->connection) {
                                try {
                                    $client = new \App\Services\WooCommerce\WooClient($record->connection);
                                    // Discontinued → backorders dezactivate; reactivat → backorders permise (notify)
                                    $client->updateProduct((int) $record->woo_id, [
                                        'backorders' => $markingDiscontinued ? 'no' : 'notify',
                                    ]);
                                } catch (\Throwable) {
                                    // Sync WooCommerce eșuat — nu blocăm operația locală
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title($markingDiscontinued
                                    ? 'Marcat ca fără reaprovizionare (backorders dezactivate)'
                                    : 'Reaprovizionare reactivată (backorders permise)')
                                ->send();
                        }),

                    InfolistAction::make('toggle_on_demand')
                        ->label(fn (WooProduct $record): string => $record->procurement_type === WooProduct::PROCUREMENT_ON_DEMAND
                            ? 'Scoate din "La comandă"'
                            : 'Marchează "La comandă"')
                        ->icon(fn (WooProduct $record): string => $record->procurement_type === WooProduct::PROCUREMENT_ON_DEMAND
                            ? 'heroicon-o-archive-box-arrow-down'
                            : 'heroicon-o-clock')
                        ->color(fn (WooProduct $record): string => $record->procurement_type === WooProduct::PROCUREMENT_ON_DEMAND
                            ? 'gray'
                            : 'warning')
                        ->size(\Filament\Support\Enums\Size::Small)
                        ->requiresConfirmation()
                        ->modalHeading(fn (WooProduct $record): string => $record->procurement_type === WooProduct::PROCUREMENT_ON_DEMAND
                            ? 'Scoți din modul "La comandă"?'
                            : 'Marchezi ca "La comandă"?')
                        ->modalDescription(fn (WooProduct $record): string => $record->procurement_type === WooProduct::PROCUREMENT_ON_DEMAND
                            ? 'Revine la comportament normal (stoc fizic). WooCommerce: backorders dezactivate.'
                            : 'Stocul din WooCommerce va fi setat la 0, backorders activate. PNR auto-creat la comenzi.')
                        ->form(fn (WooProduct $record): array => $record->procurement_type !== WooProduct::PROCUREMENT_ON_DEMAND ? [
                            \Filament\Forms\Components\TextInput::make('on_demand_label')
                                ->label('Mesaj disponibilitate pe site (opțional)')
                                ->placeholder('ex: Disponibil în 3-5 zile lucrătoare')
                                ->maxLength(100),
                        ] : [])
                        ->action(function (WooProduct $record, array $data): void {
                            $isOnDemand = $record->procurement_type === WooProduct::PROCUREMENT_ON_DEMAND;
                            $newType    = $isOnDemand ? WooProduct::PROCUREMENT_STOCK : WooProduct::PROCUREMENT_ON_DEMAND;
                            $record->update([
                                'procurement_type' => $newType,
                                'on_demand_label'  => $isOnDemand ? null : ($data['on_demand_label'] ?? null),
                            ]);
                            if ($record->woo_id) {
                                try {
                                    $client = new \App\Services\WooCommerce\WooClient($record->connection);
                                    $client->updateProduct($record->woo_id, $isOnDemand
                                        ? ['backorders' => 'no']
                                        : ['manage_stock' => true, 'stock_quantity' => 0, 'backorders' => 'yes']
                                    );
                                } catch (\Throwable) {}
                            }
                            Notification::make()
                                ->success()
                                ->title($isOnDemand ? 'Produs revenit la stoc normal' : 'Produs marcat ca "La comandă"')
                                ->send();
                        }),
                ])->extraAttributes(['class' => 'erp-main-actions'])
                  ->alignment(\Filament\Support\Enums\Alignment::Right),

                // ── Denumire WinMentor ───────────────────────────────────────
                Section::make('Denumire WinMentor')
                    ->schema([
                        TextEntry::make('winmentor_name')
                            ->label('')
                            ->columnSpanFull(),
                    ])
                    ->hidden(fn (WooProduct $record): bool => blank($record->winmentor_name)),

                // ── Descriere ────────────────────────────────────────────────
                Section::make('Descriere')
                    ->schema([
                        TextEntry::make('description')
                            ->label('')
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->hidden(fn (WooProduct $record): bool =>
                        blank($record->description)
                        || ! \App\Models\RolePermission::check('woo_product_section_descriere')
                    ),

                // ── Atribute tehnice ─────────────────────────────────────────
                Section::make('Atribute tehnice')
                    ->description('Atribute generate automat din denumirea produsului.')
                    ->schema([
                        TextEntry::make('attributes_table')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => static::renderAttributesTable($record))
                            ->html(),
                    ])
                    ->visible(fn (): bool => \App\Models\RolePermission::check('woo_product_section_atribute_tehnice')),

                Section::make('Istoric variație stoc')
                    ->description('Afișează 10 poziții vizibile și până la 30 poziții cu scroll.')
                    ->schema([
                        TextEntry::make('daily_variation_history')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => static::renderDailyVariationHistory($record))
                            ->html(),
                    ])
                    ->visible(fn (): bool => \App\Models\RolePermission::check('woo_product_section_istoric_stoc')),
                Section::make('Rezumat variații')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('daily_quantity_variation_card')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => static::renderQuantityVariationCard($record))
                            ->html(),
                        TextEntry::make('daily_value_variation_card')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => static::renderValueVariationCard($record))
                            ->html(),
                    ])
                    ->visible(fn (): bool => \App\Models\RolePermission::check('woo_product_section_rezumat_variatii')),

                // ── Istoric prețuri achiziție ─────────────────────────────────
                Section::make('Istoric prețuri achiziție')
                    ->collapsible()
                    ->collapsed(false)
                    ->schema([
                        TextEntry::make('purchase_price_history')
                            ->label('')
                            ->hiddenLabel()
                            ->state(fn (WooProduct $record): HtmlString => static::renderPurchasePriceHistory($record))
                            ->html(),
                    ])
                    ->hidden(fn (WooProduct $record): bool => $record->purchasePriceLogs()->doesntExist())
                    ->visible(fn (): bool => auth()->user()?->email === 'codrut@ikonia.ro'),

                Section::make('Payload brut (Woo)')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('data_pretty')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => new HtmlString(
                                '<pre style="white-space: pre-wrap;">'.e(json_encode($record->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>'
                            ))
                            ->html(),
                    ])
                    ->visible(fn (): bool => \App\Models\RolePermission::check('woo_product_section_payload_brut')),
            ]);
    }

    public static function renderPurchasePriceHistory(WooProduct $record): HtmlString
    {
        $logs = $record->purchasePriceLogs()->with('supplier')->get();

        if ($logs->isEmpty()) {
            return new HtmlString('<p style="font-size:0.875rem;color:#9ca3af;">Nu există istoric.</p>');
        }

        $rows = '';
        foreach ($logs as $log) {
            $date = $log->acquired_at ? $log->acquired_at->format('d.m.Y') : '—';
            $price = number_format((float) $log->unit_price, 2, '.', ' ') . ' ' . $log->currency;
            $priceWithVat = number_format((float) $log->unit_price * 1.21, 2, '.', ' ') . ' ' . $log->currency;
            $uom = e($log->uom ?? '—');
            $source = match ($log->source) {
                'winmentor_import' => '<span style="font-size:0.75rem;color:#9ca3af;">WinMentor</span>',
                'crm'              => '<span style="font-size:0.75rem;color:#3b82f6;">CRM</span>',
                default            => '<span style="font-size:0.75rem;color:#9ca3af;">Manual</span>',
            };

            if ($log->supplier) {
                $supplierUrl = \App\Filament\App\Resources\SupplierResource::getUrl('view', ['record' => $log->supplier_id]);
                $supplierHtml = '<a href="' . e($supplierUrl) . '" style="color:#8B1A1A;text-decoration:none;font-size:0.875rem;" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">' . e($log->supplier->name) . '</a>';
            } elseif ($log->supplier_name_raw) {
                $supplierHtml = '<span style="font-size:0.875rem;color:#6b7280;font-style:italic;">' . e($log->supplier_name_raw) . '</span>';
            } else {
                $supplierHtml = '<span style="font-size:0.875rem;color:#9ca3af;">—</span>';
            }

            $rows .= "<tr style=\"border-bottom:1px solid #e5e7eb;\">
                <td style=\"padding:6px 12px;font-size:0.875rem;\">{$date}</td>
                <td style=\"padding:6px 12px;font-size:0.875rem;font-family:monospace;font-weight:600;\">{$price}</td>
                <td style=\"padding:6px 12px;font-size:0.75rem;color:#6b7280;\">{$priceWithVat} <span style=\"color:#9ca3af;\">(+TVA)</span></td>
                <td style=\"padding:6px 12px;font-size:0.875rem;\">{$uom}</td>
                <td style=\"padding:6px 12px;\">{$supplierHtml}</td>
                <td style=\"padding:6px 12px;\">{$source}</td>
            </tr>";
        }

        return new HtmlString("
            <div style=\"overflow-x:auto;\">
                <table style=\"width:100%;text-align:left;border-collapse:collapse;\">
                    <thead>
                        <tr style=\"border-bottom:2px solid #d1d5db;\">
                            <th style=\"padding:8px 12px;font-size:0.75rem;color:#6b7280;text-transform:uppercase;font-weight:600;\">Data</th>
                            <th style=\"padding:8px 12px;font-size:0.75rem;color:#6b7280;text-transform:uppercase;font-weight:600;\">Preț achiziție</th>
                            <th style=\"padding:8px 12px;font-size:0.75rem;color:#6b7280;text-transform:uppercase;font-weight:600;\">cu TVA 21%</th>
                            <th style=\"padding:8px 12px;font-size:0.75rem;color:#6b7280;text-transform:uppercase;font-weight:600;\">U.M.</th>
                            <th style=\"padding:8px 12px;font-size:0.75rem;color:#6b7280;text-transform:uppercase;font-weight:600;\">Furnizor</th>
                            <th style=\"padding:8px 12px;font-size:0.75rem;color:#6b7280;text-transform:uppercase;font-weight:600;\">Sursă</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        ");
    }

    /**
     * @return Collection<int, DailyStockMetric>
     */
    public static function renderProductHeader(WooProduct $record): HtmlString
    {
        $imageUrl   = filled($record->main_image_url) ? e($record->main_image_url) : 'https://placehold.co/320x320?text=No+Image';
        $name       = e($record->decoded_name);

        // Buton Resync WooCommerce (icon-only, logo Woo)
        $canResync = false;
        if ($record->woo_id && $record->connection_id) {
            $authUser = auth()->user();
            if ($authUser instanceof User) {
                $canResync = $authUser->isAdmin() || in_array($authUser->role, [
                    User::ROLE_MANAGER,
                    User::ROLE_DIRECTOR_VANZARI,
                ], true);
            }
        }
        $resyncBtn = $canResync
            ? '<button x-on:click="$wire.mountAction(\'resync_from_woo\')" type="button" title="Resync WooCommerce"'
              . ' style="display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;'
              . 'background:#7F54B3;border:none;cursor:pointer;border-radius:7px;padding:5px 7px;'
              . 'opacity:0.8;transition:opacity .15s;" '
              . 'x-on:mouseenter="$el.style.opacity=\'1\'" x-on:mouseleave="$el.style.opacity=\'0.8\'">'
              . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;">'
              . '<path d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>'
              . '</svg></button>'
            : '';
        $sku        = e($record->sku ?? '-');
        $price      = $record->price ? number_format((float) $record->price, 2, ',', '.') . ' lei' : '-';
        $regPrice   = $record->regular_price ? number_format((float) $record->regular_price, 2, ',', '.') . ' lei' : null;
        $salePrice  = $record->sale_price ? number_format((float) $record->sale_price, 2, ',', '.') . ' lei' : null;
        $source    = match ($record->source) {
            WooProduct::SOURCE_TOYA_API      => 'Feed produse Toya',
            WooProduct::SOURCE_WINMENTOR_CSV => 'ERP (contabilitate)',
            default                          => $record->is_placeholder ? 'ERP (contabilitate)' : 'WooCommerce',
        };
        $sourceClr = match ($record->source) {
            WooProduct::SOURCE_TOYA_API      => '#2563eb',
            WooProduct::SOURCE_WINMENTOR_CSV => '#d97706',
            default                          => $record->is_placeholder ? '#d97706' : '#16a34a',
        };
        $stock      = $record->stock_status ?? '-';
        $stockClr   = $stock === 'instock' ? '#16a34a' : '#dc2626';
        $stockLbl   = match ($stock) { 'instock' => 'În stoc', 'outofstock' => 'Fără stoc', 'onbackorder' => 'Precomandă', default => $stock };
        $cats       = $record->categories->pluck('name')->implode(', ');
        $location   = e($record->connection?->location?->name ?? '-');

        // Brand logo sau badge text
        $brandHtml = '';
        if (filled($record->brand)) {
            $brandModel = Brand::whereRaw('LOWER(name) = LOWER(?)', [trim((string) $record->brand)])->first();
            if ($brandModel && filled($brandModel->logo_url)) {
                $logoUrl   = \Illuminate\Support\Facades\Storage::url((string) $brandModel->logo_url);
                $brandHtml = '<img src="' . e($logoUrl) . '" alt="' . e($record->brand) . '" '
                    . 'style="height:44px;max-width:140px;object-fit:contain;" />';
            } else {
                $brandHtml = '<span style="display:inline-flex;align-items:center;background:#eff6ff;color:#1d4ed8;'
                    . 'border:1px solid #bfdbfe;border-radius:6px;padding:2px 10px;font-size:0.78rem;font-weight:600;">'
                    . e($record->brand) . '</span>';
            }
        }

        // Bloc preț
        $priceFloat    = (float) ($record->price ?? 0);
        $regPriceFloat = (float) ($record->regular_price ?? 0);
        $isOnSale      = $regPriceFloat > 0 && $priceFloat > 0 && $priceFloat < $regPriceFloat;

        $priceBlockHtml = '<div class="ph-price-block">';
        if ($isOnSale) {
            $priceBlockHtml .= '<div style="font-size:1.1rem;color:#dc2626;text-decoration:line-through;line-height:1.2;opacity:0.6;">'
                . $regPrice . '</div>';
        }
        $priceBlockHtml .= '<div class="ph-price-main">'
            . $price . '</div>';
        $priceBlockHtml .= '</div>';

        // Stoc WinMentor
        $record->loadMissing('stocks');
        $stockQty   = number_format((float) $record->stocks->sum('quantity'), 0, ',', '.');
        $syncedAt   = $record->stocks->max('synced_at');
        $syncedDate = $syncedAt ? \Illuminate\Support\Carbon::parse($syncedAt)->format('d.m.Y H:i') : null;
        $stockHtml  = '<div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;">'
            . '<span style="font-size:1.15rem;font-weight:700;color:#111827;">Stoc WinMentor: '
            . '<span style="color:#2563eb;">' . $stockQty . ' buc</span></span>'
            . ($syncedDate
                ? '<span style="font-size:0.78rem;color:#9ca3af;">(' . $syncedDate . ')</span>'
                : '')
            . '</div>';

        // Cod de bare
        $barcodeHtml = static::renderBarcodeHtml($record->sku ?? '');

        // Buton Adaugă la necesar
        $addToNecesarBtn = '<button'
            . ' x-on:click="$wire.mountAction(\'add_to_necesar\')"'
            . ' type="button"'
            . ' title="Adaugă la necesar"'
            . ' style="margin-top:10px;background:#dc2626;color:#fff;border:none;cursor:pointer;'
            . 'border-radius:8px;padding:11px 18px;font-size:0.875rem;font-weight:600;'
            . 'display:flex;align-items:center;justify-content:center;gap:8px;width:100%;">'
            . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;">'
            . '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />'
            . '</svg>'
            . 'Adaugă la necesar'
            . '</button>';

        // Descriere scurtă
        $shortDesc = filled($record->short_description)
            ? '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #f3f4f6;font-size:0.875rem;color:#374151;line-height:1.6;">'
              . $record->short_description
              . '</div>'
            : '';

        $style = '<style>'
            . '.ph-name-price-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;}'
            . '.ph-price-block{text-align:right;flex-shrink:0;min-width:130px;}'
            . '.ph-price-main{font-size:2rem;font-weight:800;color:#dc2626;line-height:1.1;white-space:nowrap;}'
            . '.ph-name{font-size:1.4rem;font-weight:700;color:#111827;line-height:1.35;}'
            . '@media(max-width:640px){'
            . '.ph-name-price-row{flex-direction:column !important;gap:4px;}'
            . '.ph-price-block{text-align:left !important;min-width:unset !important;}'
            . '.ph-price-main{font-size:1.5rem !important;white-space:normal;}'
            . '.ph-name{font-size:1.15rem !important;}'
            . '}'
            . '</style>';

        // Bulina status listare site
        $isListed  = ! $record->is_placeholder && $record->status === 'publish';
        $dotColor  = $isListed ? '#16a34a' : '#dc2626';
        $dotTitle  = $isListed ? 'Listat pe site' : 'Nelistat pe site';
        $dotHtml   = '<span title="' . $dotTitle . '" style="'
            . 'position:absolute;top:10px;left:10px;'
            . 'width:24px;height:24px;border-radius:50%;'
            . 'background:' . $dotColor . ';'
            . 'border:3px solid #fff;'
            . 'box-shadow:0 2px 6px rgba(0,0,0,0.35);'
            . 'flex-shrink:0;'
            . '"></span>';

        $html = $style . '<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:start;width:100%;">'

            // Poza produs — responsivă
            . '<div style="flex:0 0 auto;width:min(340px,100%);position:relative;">'
            . '<img src="' . $imageUrl . '" alt="' . $name . '" '
            . 'style="width:100%;aspect-ratio:1/1;object-fit:contain;border-radius:12px;border:1px solid #e5e7eb;background:#fafafa;" />'
            . $dotHtml
            . '</div>'

            // Coloana dreapta: brand + nume + preț + tags + grid + descriere
            . '<div style="display:flex;flex-direction:column;gap:10px;min-width:0;flex:1 1 280px;">'

            // Brand
            . ($brandHtml ? '<div>' . $brandHtml . '</div>' : '')

            // Denumire produs + preț pe același rând
            . '<div class="ph-name-price-row">'
            . '<div style="display:flex;align-items:flex-start;gap:8px;min-width:0;">'
            . '<div class="ph-name">' . $name . '</div>'
            . $resyncBtn
            . '</div>'
            . $priceBlockHtml
            . '</div>'

            // Etichete inline
            . '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">'
            . '<span style="background:#f3f4f6;color:#374151;border-radius:6px;padding:2px 8px;font-size:0.78rem;cursor:pointer;" onclick="navigator.clipboard.writeText(\'' . $sku . '\');new FilamentNotification().title(\'Copiat!\').success().duration(2000).send()">SKU: ' . $sku . '</span>'
            . '<span style="background:#f3f4f6;color:' . $sourceClr . ';border-radius:6px;padding:2px 8px;font-size:0.78rem;">' . $source . '</span>'
            . '<span style="background:#f3f4f6;color:' . $stockClr . ';border-radius:6px;padding:2px 8px;font-size:0.78rem;">' . $stockLbl . '</span>'
            . ($location !== '-' ? '<span style="background:#f3f4f6;color:#374151;border-radius:6px;padding:2px 8px;font-size:0.78rem;">' . $location . '</span>' : '')
            . ($cats ? '<span style="background:#f3f4f6;color:#374151;border-radius:6px;padding:2px 8px;font-size:0.78rem;">' . e($cats) . '</span>' : '')
            . '</div>'

            // Stoc WinMentor
            . $stockHtml

            // Cod de bare
            . $barcodeHtml

            // Buton Adaugă la necesar (după barcode pe desktop; pe mobil barcode e hidden → apare după stoc)
            . $addToNecesarBtn

            // Descriere scurtă
            . $shortDesc

            . '</div>'  // end coloana dreapta
            . '</div>'; // end wrapper

        return new HtmlString($html);
    }

    public static function necesarModalForm(): array
    {
        return [
            \Filament\Forms\Components\TextInput::make('quantity')
                ->label('Cantitate')
                ->numeric()
                ->minValue(0.001)
                ->default(1)
                ->required()
                ->live(onBlur: true)
                ->extraInputAttributes([
                    'style'     => 'text-align:center;font-size:1.5rem;font-weight:700;width:80px;',
                    'autofocus' => 'autofocus',
                ])
                ->prefixAction(
                    \Filament\Actions\Action::make('qty_decrease')
                        ->icon('heroicon-o-minus')
                        ->extraAttributes(['tabindex' => '-1'])
                        ->action(fn (\Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set) =>
                            $set('quantity', max(1, (int) $get('quantity') - 1))
                        )
                )
                ->suffixAction(
                    \Filament\Actions\Action::make('qty_increase')
                        ->icon('heroicon-o-plus')
                        ->extraAttributes(['tabindex' => '-1'])
                        ->action(fn (\Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set) =>
                            $set('quantity', (int) $get('quantity') + 1)
                        )
                ),
            \Filament\Forms\Components\Toggle::make('is_urgent')
                ->label('Urgent')
                ->default(false),
            \Filament\Forms\Components\TextInput::make('notes')
                ->label('Notițe')
                ->nullable(),
        ];
    }

    private static function renderSupplierContactModal(WooProduct $record): HtmlString
    {
        $rows = '';
        foreach ($record->suppliers as $supplier) {
            $field = fn (string $icon, string $label, ?string $val): string => filled($val)
                ? '<div style="display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f3f4f6;">'
                  . '<span style="color:#6b7280;font-size:0.85rem;min-width:130px;">' . $label . '</span>'
                  . '<span style="font-size:0.85rem;font-weight:500;color:#111827;">' . e($val) . '</span>'
                  . '</div>'
                : '';

            $skuVal        = $supplier->pivot->supplier_sku ?? null;
            $currency      = $supplier->pivot->currency ?? 'RON';
            $priceNet      = $supplier->pivot->purchase_price
                ? number_format((float) $supplier->pivot->purchase_price, 2, ',', '.') . ' ' . $currency
                : null;
            $priceVat      = $supplier->pivot->purchase_price
                ? number_format((float) $supplier->pivot->purchase_price * 1.21, 2, ',', '.') . ' RON'
                : null;

            // Persoane de contact
            $contactsHtml = '';
            foreach ($supplier->contacts as $contact) {
                $primaryBadge = $contact->is_primary
                    ? '<span style="background:#fef9c3;color:#854d0e;border-radius:4px;padding:1px 6px;font-size:0.7rem;font-weight:600;margin-left:6px;">Principal</span>'
                    : '';
                $contactsHtml .= '<div style="background:#f9fafb;border-radius:8px;padding:10px 12px;margin-top:8px;">'
                    . '<div style="font-weight:600;color:#111827;font-size:0.85rem;margin-bottom:4px;">'
                    . e($contact->name) . $primaryBadge
                    . ($contact->role ? ' <span style="color:#6b7280;font-weight:400;">· ' . e($contact->role) . '</span>' : '')
                    . '</div>'
                    . $field('phone', 'Telefon', $contact->phone)
                    . $field('envelope', 'Email', $contact->email)
                    . $field('note', 'Notițe', $contact->notes)
                    . '</div>';
            }

            $rows .= '<div style="margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #e5e7eb;">'
                . '<div style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:8px;">' . e($supplier->name) . '</div>'
                . $field('phone', 'Telefon general', $supplier->phone)
                . $field('envelope', 'Email general', $supplier->email)
                . $field('globe', 'Website', $supplier->website_url)
                . $field('tag', 'SKU furnizor', $skuVal)
                . $field('currency', 'Preț achiziție (fără TVA)', $priceNet)
                . $field('currency', 'Preț achiziție (cu TVA)', $priceVat)
                . $field('document', 'CUI', $supplier->vat_number)
                . $field('note', 'Notițe', $supplier->notes)
                . ($contactsHtml ? '<div style="margin-top:10px;font-size:0.78rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;">Persoane de contact</div>' . $contactsHtml : '')
                . '</div>';
        }

        return new HtmlString('<div style="font-size:0.9rem;">' . $rows . '</div>');
    }

    private static function recentDailyMetrics(WooProduct $record, int $limit = 30): Collection
    {
        static $cache = [];
        $cacheKey = $record->getKey().':'.(string) $record->sku.':'.$limit;

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $cache[$cacheKey] = $record->dailyStockMetrics()
            ->orderByDesc('day')
            ->limit(max(1, $limit))
            ->get([
                'day',
                'daily_total_variation',
                'daily_sales_value_variation',
                'closing_total_qty',
                'closing_sales_value',
                'snapshots_count',
            ]);

        return $cache[$cacheKey];
    }

    private static function renderDailyVariationHistory(WooProduct $record): HtmlString
    {
        $metrics = static::recentDailyMetrics($record, 30);

        if ($metrics->isEmpty()) {
            return new HtmlString(
                '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.85rem;color:#6b7280;font-size:0.875rem;">'
                .'Nu există încă date de variație pentru acest produs.'
                .'</div>'
            );
        }

        $rows = [];
        foreach ($metrics as $index => $metric) {
            $qtyVariation = (float) $metric->daily_total_variation;
            $valueVariation = (float) $metric->daily_sales_value_variation;
            $qtyColor = static::variationInlineColor($qtyVariation);
            $valueColor = static::variationInlineColor($valueVariation);
            $position = $index + 1;
            $dayLabel = static::formatMetricDay($metric->day);
            $closingQty = static::formatQty((float) $metric->closing_total_qty).' buc';
            $qtyText = static::formatSignedQty($qtyVariation, ' buc');
            $valueText = static::formatSignedCurrency($valueVariation);

            $rows[] = '<tr>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;">'.$position.'</td>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;">'.$dayLabel.'</td>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;color:'.$qtyColor.';font-weight:600;text-align:right;">'.$qtyText.'</td>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;color:'.$valueColor.';font-weight:600;text-align:right;">'.$valueText.'</td>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;text-align:right;">'.$closingQty.'</td>'
                .'</tr>';
        }

        $table = '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;overflow:hidden;background:#fff;">'
            .'<div style="max-height:26.5rem;overflow-y:auto;">'
            .'<table style="width:100%;border-collapse:collapse;font-size:0.82rem;">'
            .'<thead style="position:sticky;top:0;background:#f9fafb;z-index:1;">'
            .'<tr>'
            .'<th style="padding:0.55rem;text-align:left;border-bottom:1px solid #e5e7eb;width:64px;">#</th>'
            .'<th style="padding:0.55rem;text-align:left;border-bottom:1px solid #e5e7eb;">Zi</th>'
            .'<th style="padding:0.55rem;text-align:right;border-bottom:1px solid #e5e7eb;">Variație cant.</th>'
            .'<th style="padding:0.55rem;text-align:right;border-bottom:1px solid #e5e7eb;">Variație valoare</th>'
            .'<th style="padding:0.55rem;text-align:right;border-bottom:1px solid #e5e7eb;">Stoc final</th>'
            .'</tr>'
            .'</thead>'
            .'<tbody>'.implode('', $rows).'</tbody>'
            .'</table>'
            .'</div>'
            .'</div>';

        return new HtmlString($table);
    }

    private static function renderQuantityVariationCard(WooProduct $record): HtmlString
    {
        $metrics = static::recentDailyMetrics($record, 30);

        if ($metrics->isEmpty()) {
            return new HtmlString(
                '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.9rem;background:#fff;">'
                .'<div style="font-size:0.8rem;color:#6b7280;">Variație cantitate</div>'
                .'<div style="margin-top:0.45rem;font-size:1.2rem;font-weight:700;color:#6b7280;">-</div>'
                .'<div style="margin-top:0.25rem;font-size:0.8rem;color:#9ca3af;">Fără date disponibile.</div>'
                .'</div>'
            );
        }

        /** @var DailyStockMetric $latest */
        $latest = $metrics->first();
        $latestVariation = (float) $latest->daily_total_variation;
        $latestDay = static::formatMetricDay($latest->day);
        $sum7 = $metrics->take(7)->sum(fn (DailyStockMetric $metric): float => (float) $metric->daily_total_variation);
        $sum30 = $metrics->sum(fn (DailyStockMetric $metric): float => (float) $metric->daily_total_variation);
        $closingQty = static::formatQty((float) $latest->closing_total_qty).' buc';
        $color = static::variationInlineColor($latestVariation);

        return new HtmlString(
            '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.9rem;background:#fff;">'
            .'<div style="font-size:0.8rem;color:#6b7280;">Variație cantitate</div>'
            .'<div style="margin-top:0.35rem;font-size:1.35rem;font-weight:700;color:'.$color.';">'
            .static::formatSignedQty($latestVariation, ' buc')
            .'</div>'
            .'<div style="margin-top:0.2rem;font-size:0.8rem;color:#6b7280;">Ultima zi: '.$latestDay.'</div>'
            .'<div style="margin-top:0.55rem;display:flex;gap:0.7rem;flex-wrap:wrap;font-size:0.8rem;color:#374151;">'
            .'<span>7 poziții: <strong>'.static::formatSignedQty($sum7, ' buc').'</strong></span>'
            .'<span>30 poziții: <strong>'.static::formatSignedQty($sum30, ' buc').'</strong></span>'
            .'<span>Stoc final: <strong>'.$closingQty.'</strong></span>'
            .'</div>'
            .'</div>'
        );
    }

    private static function renderValueVariationCard(WooProduct $record): HtmlString
    {
        $metrics = static::recentDailyMetrics($record, 30);

        if ($metrics->isEmpty()) {
            return new HtmlString(
                '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.9rem;background:#fff;">'
                .'<div style="font-size:0.8rem;color:#6b7280;">Variație valoare</div>'
                .'<div style="margin-top:0.45rem;font-size:1.2rem;font-weight:700;color:#6b7280;">-</div>'
                .'<div style="margin-top:0.25rem;font-size:0.8rem;color:#9ca3af;">Fără date disponibile.</div>'
                .'</div>'
            );
        }

        /** @var DailyStockMetric $latest */
        $latest = $metrics->first();
        $latestVariation = (float) $latest->daily_sales_value_variation;
        $latestDay = static::formatMetricDay($latest->day);
        $sum7 = $metrics->take(7)->sum(fn (DailyStockMetric $metric): float => (float) $metric->daily_sales_value_variation);
        $sum30 = $metrics->sum(fn (DailyStockMetric $metric): float => (float) $metric->daily_sales_value_variation);
        $closingValue = number_format((float) $latest->closing_sales_value, 2, '.', ',').' RON';
        $color = static::variationInlineColor($latestVariation);

        return new HtmlString(
            '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.9rem;background:#fff;">'
            .'<div style="font-size:0.8rem;color:#6b7280;">Variație valoare</div>'
            .'<div style="margin-top:0.35rem;font-size:1.35rem;font-weight:700;color:'.$color.';">'
            .static::formatSignedCurrency($latestVariation)
            .'</div>'
            .'<div style="margin-top:0.2rem;font-size:0.8rem;color:#6b7280;">Ultima zi: '.$latestDay.'</div>'
            .'<div style="margin-top:0.55rem;display:flex;gap:0.7rem;flex-wrap:wrap;font-size:0.8rem;color:#374151;">'
            .'<span>7 poziții: <strong>'.static::formatSignedCurrency($sum7).'</strong></span>'
            .'<span>30 poziții: <strong>'.static::formatSignedCurrency($sum30).'</strong></span>'
            .'<span>Valoare stoc final: <strong>'.$closingValue.'</strong></span>'
            .'</div>'
            .'</div>'
        );
    }

    private static function formatSignedMetric(?float $value, int $decimals, string $suffix = ''): string
    {
        if ($value === null) {
            return '-';
        }

        $formatted = number_format(abs($value), $decimals, '.', ',');
        $sign = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return trim("{$sign}{$formatted}{$suffix}");
    }

    private static function formatQty(float $value): string
    {
        return floor($value) == $value
            ? number_format($value, 0, '.', '')
            : number_format($value, 2, '.', '');
    }

    private static function formatSignedQty(?float $value, string $suffix = ''): string
    {
        if ($value === null) {
            return '-';
        }

        $formatted = static::formatQty(abs($value));
        $sign = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return trim("{$sign}{$formatted}{$suffix}");
    }

    private static function formatSignedCurrency(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        $formatted = number_format(abs($value), 2, '.', ',');
        $sign = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return trim("{$sign}{$formatted} RON");
    }

    private static function variationInlineColor(?float $value): string
    {
        if ($value === null || abs($value) < 0.00001) {
            return '#6b7280';
        }

        return $value > 0 ? '#16a34a' : '#dc2626';
    }

    private static function renderAttributesTable(WooProduct $record): HtmlString
    {
        $attrs = $record->attributes;

        // Fallback: atribute din data JSON (pentru produsele WooCommerce reale)
        if ($attrs->isEmpty() && $record->data) {
            $data     = is_array($record->data) ? $record->data : json_decode((string) $record->data, true);
            $wooAttrs = $data['attributes'] ?? [];

            if (! empty($wooAttrs)) {
                $rows = '';
                foreach ($wooAttrs as $attr) {
                    $name  = e($attr['name'] ?? '');
                    $value = e(implode(', ', $attr['options'] ?? []));
                    $rows .= "<tr>
                        <td style='padding:0.45rem 0.75rem;border-bottom:1px solid #f3f4f6;font-weight:500;color:#374151;width:40%;'>{$name}</td>
                        <td style='padding:0.45rem 0.75rem;border-bottom:1px solid #f3f4f6;color:#6b7280;'>{$value}</td>
                    </tr>";
                }

                return new HtmlString(
                    '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;overflow:hidden;background:#fff;">'
                    .'<table style="width:100%;border-collapse:collapse;font-size:0.875rem;">'
                    .'<tbody>'.$rows.'</tbody></table></div>'
                    .'<p style="margin-top:0.5rem;font-size:0.75rem;color:#9ca3af;">Sursa: WooCommerce API</p>'
                );
            }
        }

        if ($attrs->isEmpty()) {
            return new HtmlString(
                '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.85rem;color:#6b7280;font-size:0.875rem;">'
                .'Niciun atribut generat încă pentru acest produs.'
                .'</div>'
            );
        }

        $rows = '';
        foreach ($attrs as $attr) {
            $name  = e($attr->name);
            $value = e($attr->value);
            $badge = $attr->source === 'generated'
                ? '<span style="font-size:0.7rem;background:#f0fdf4;color:#16a34a;padding:0.1rem 0.4rem;border-radius:9999px;margin-left:0.4rem;">auto</span>'
                : '';
            $rows .= "<tr>
                <td style='padding:0.45rem 0.75rem;border-bottom:1px solid #f3f4f6;font-weight:500;color:#374151;width:40%;'>{$name}{$badge}</td>
                <td style='padding:0.45rem 0.75rem;border-bottom:1px solid #f3f4f6;color:#6b7280;'>{$value}</td>
            </tr>";
        }

        return new HtmlString(
            '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;overflow:hidden;background:#fff;">'
            .'<table style="width:100%;border-collapse:collapse;font-size:0.875rem;">'
            .'<tbody>'.$rows.'</tbody></table></div>'
        );
    }

    private static function formatMetricDay(mixed $day): string
    {
        if ($day instanceof Carbon) {
            return $day->format('d.m.Y');
        }

        $timestamp = strtotime((string) $day);

        return $timestamp !== false ? date('d.m.Y', $timestamp) : '-';
    }

    private static function renderBarcodeHtml(string $sku): string
    {
        if ($sku === '' || $sku === '-') {
            return '';
        }

        $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $sku);
        $filename  = 'barcodes/' . $safeName . '.png';
        $disk      = \Illuminate\Support\Facades\Storage::disk('public');

        if (! $disk->exists($filename)) {
            try {
                $png = static::generateBarcodePng($sku);
                $disk->put($filename, $png);
            } catch (\Throwable) {
                return '';
            }
        }

        $url = $disk->url($filename);

        return '<div class="hidden md:block" style="margin-top:4px;">'
            . '<img src="' . e($url) . '" alt="Barcode ' . e($sku) . '" '
            . 'style="height:100px;max-width:100%;display:block;background:white;" />'
            . '<div style="font-size:0.75rem;color:#9ca3af;letter-spacing:0.12em;margin-top:4px;">' . e($sku) . '</div>'
            . '</div>';
    }

    private static function generateBarcodePng(string $sku, int $widthFactor = 2, int $height = 80): string
    {
        // Extrage datele barcode fără a depinde de un renderer specific
        $reader = new class extends \Picqer\Barcode\BarcodeGenerator {
            public function getData(string $code, string $type): \Picqer\Barcode\Barcode
            {
                return $this->getBarcodeData($code, $type);
            }
        };

        $barcode    = $reader->getData($sku, \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_128);
        $totalWidth = $barcode->getWidth() * $widthFactor;

        // Construiește un rând de pixeli (grayscale: 0=negru, 255=alb)
        $row = str_repeat("\xff", $totalWidth);
        $x   = 0;
        foreach ($barcode->getBars() as $bar) {
            $bw = $bar->getWidth() * $widthFactor;
            if ($bar->isBar()) {
                for ($i = $x; $i < $x + $bw; $i++) {
                    $row[$i] = "\x00";
                }
            }
            $x += $bw;
        }

        // Construiește datele brute PNG (filter byte 0 + rând)
        $rawData = '';
        for ($y = 0; $y < $height; $y++) {
            $rawData .= "\x00" . $row;
        }

        // IHDR: width(4) height(4) bitDepth(1)=8 colorType(1)=0(grayscale) compress(1) filter(1) interlace(1)
        $ihdr = pack('NN', $totalWidth, $height) . "\x08\x00\x00\x00\x00";

        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data) & 0xFFFFFFFF);
        };

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('IDAT', gzcompress($rawData, 6))
            . $chunk('IEND', '');
    }
}
