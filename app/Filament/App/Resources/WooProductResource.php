<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Resources\WooProductResource\Pages;
use App\Models\DailyStockMetric;
use App\Models\ProductReviewRequest;
use App\Models\Supplier;
use App\Models\WooCategory;
use App\Models\WooProduct;
use Filament\Forms;
use Filament\Forms\Form;
use App\Models\Brand;
use Filament\Infolists\Components\Actions;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
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
    use EnforcesLocationScope;

    protected static ?string $model = WooProduct::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Administrare magazin';

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

        return $user !== null && ($user->isSuperAdmin() || $user->role === \App\Models\User::ROLE_MANAGER);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informații produs')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Denumire')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('brand')
                        ->label('Brand / Producător')
                        ->maxLength(255),
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
                    Forms\Components\Textarea::make('erp_notes')
                        ->label('Notițe interne ERP')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Categorii')
                ->schema([
                    Forms\Components\Select::make('categories')
                        ->label('Categorii')
                        ->relationship('categories', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->native(false),
                ]),

            Forms\Components\Section::make('Atribute tehnice')
                ->description('Atribute vizibile pe fișa produsului în magazinul online.')
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

            Forms\Components\Section::make('Furnizori')
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
                            Forms\Components\TextInput::make('purchase_price')
                                ->label('Preț achiziție')
                                ->numeric()
                                ->step(0.0001),
                            Forms\Components\Select::make('currency')
                                ->label('Monedă')
                                ->options(['RON' => 'RON', 'EUR' => 'EUR', 'USD' => 'USD'])
                                ->default('RON')
                                ->native(false),
                            Forms\Components\TextInput::make('lead_days')
                                ->label('Zile livrare')
                                ->numeric()
                                ->minValue(0),
                            Forms\Components\TextInput::make('min_order_qty')
                                ->label('Cant. minimă')
                                ->numeric()
                                ->minValue(0),
                            Forms\Components\Toggle::make('is_preferred')
                                ->label('Furnizor preferat')
                                ->default(false),
                            Forms\Components\Textarea::make('notes')
                                ->label('Notițe')
                                ->rows(2)
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('main_image_url')
                    ->label('Imagine')
                    ->size(96)
                    ->square()
                    ->defaultImageUrl('https://placehold.co/96x96?text=No+Img'),
                TextColumn::make('name')
                    ->label('Produs')
                    ->formatStateUsing(fn (WooProduct $record): string => $record->decoded_name)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return static::applyOptimizedSearch($query, $search);
                    })
                    ->sortable()
                    ->wrap(),
                TextColumn::make('source')
                    ->label('Sursă')
                    ->badge()
                    ->formatStateUsing(fn (WooProduct $record): string => $record->is_placeholder ? 'ERP (contabilitate)' : 'WooCommerce')
                    ->color(fn (WooProduct $record): string => $record->is_placeholder ? 'warning' : 'success')
                    ->toggleable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Preț')
                    ->placeholder('-'),
                TextColumn::make('stock_status')
                    ->label('Stoc')
                    ->badge(),
                TextColumn::make('categories_list')
                    ->label('Categorii')
                    ->state(fn (WooProduct $record): string => $record->categories->pluck('name')->implode(', '))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Actualizat')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->label('Sursă')
                    ->options([
                        WooProduct::SOURCE_WOOCOMMERCE => 'WooCommerce',
                        WooProduct::SOURCE_WINMENTOR_CSV => 'ERP (contabilitate)',
                    ]),
                Tables\Filters\SelectFilter::make('stock_status')
                    ->label('Stoc')
                    ->options([
                        'instock' => 'În stoc',
                        'outofstock' => 'Fără stoc',
                        'onbackorder' => 'Precomandă',
                    ]),
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
            ->filtersFormColumns(5)
            ->persistFiltersInSession()
            ->recordUrl(fn (WooProduct $record): string => static::getUrl('view', ['record' => $record]))
            ->searchPlaceholder('Caută după nume, SKU, slug sau categorie...')
            ->searchDebounce('800ms')
            ->defaultSort('name');
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
            'suppliers.contacts',
        ]);

        $user = static::currentUser();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereHas('connection', function (Builder $connectionQuery) use ($user): void {
            $connectionQuery->where(function (Builder $inner) use ($user): void {
                $inner->whereIn('location_id', $user->operationalLocationIds())
                    ->orWhereNull('location_id');
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
                        ->orWhere('woo_products.sku', 'like', $like)
                        ->orWhere('woo_products.slug', 'like', $like)
                        ->orWhereHas('categories', function (Builder $categoryQuery) use ($like): void {
                            $categoryQuery->where('woo_categories.name', 'like', $like);
                        });
                });
            }
        });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // ── Primul card: poza mare + info compactă ──────────────────
                Section::make()
                    ->schema([
                        ViewEntry::make('product_header')
                            ->label('')
                            ->view('filament.infolist.product-header')
                            ->columnSpanFull(),
                    ]),

                // ── Acțiuni (edit, reverificare, stoc, contact) ─────────────
                Actions::make([
                    InfolistAction::make('edit')
                        ->label('Editează')
                        ->icon('heroicon-o-pencil')
                        ->color('primary')
                        ->url(fn (WooProduct $record) => WooProductResource::getUrl('edit', ['record' => $record->id], panel: 'app')),
                    InfolistAction::make('review_request')
                        ->label('Reverificare produs')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('warning')
                        ->modalHeading('Solicitare reverificare produs')
                        ->modalDescription('Descrie ce anume trebuie verificat sau modificat la acest produs.')
                        ->modalWidth('lg')
                        ->form([
                            \Filament\Forms\Components\Textarea::make('message')
                                ->label('Mesaj')
                                ->placeholder('Ex: Imaginea principală e greșită, descrierea lipsește, prețul pare incorect...')
                                ->required()
                                ->rows(4)
                                ->maxLength(2000),
                        ])
                        ->action(function (WooProduct $record, array $data): void {
                            ProductReviewRequest::create([
                                'woo_product_id' => $record->id,
                                'user_id'        => auth()->id(),
                                'message'        => $data['message'],
                                'status'         => ProductReviewRequest::STATUS_PENDING,
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->title('Solicitare trimisă')
                                ->body('Echipa de produs a fost notificată.')
                                ->success()
                                ->send();
                        })
                        ->modalSubmitActionLabel('Trimite solicitarea')
                        ->modalCancelActionLabel('Anulează'),
                    InfolistAction::make('refresh_stock')
                        ->label('Verifică stoc')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->action(function (WooProduct $record): void {
                            $record->loadMissing('stocks');
                            $qty      = number_format((float) $record->stocks->sum('quantity'), 0, ',', '.');
                            $syncedAt = $record->stocks->max('synced_at');
                            $date     = $syncedAt
                                ? \Illuminate\Support\Carbon::parse($syncedAt)->format('d.m.Y H:i')
                                : 'necunoscut';

                            \Filament\Notifications\Notification::make()
                                ->title('Stoc WinMentor: ' . $qty . ' buc')
                                ->body('Ultima actualizare: ' . $date)
                                ->icon('heroicon-o-cube')
                                ->iconColor('info')
                                ->send();
                        }),
                    InfolistAction::make('contact_supplier')
                        ->label('Contact furnizor')
                        ->icon('heroicon-o-phone')
                        ->color('gray')
                        ->visible(function (WooProduct $record): bool {
                            $record->loadMissing('suppliers');
                            return $record->suppliers->isNotEmpty();
                        })
                        ->modalHeading('Date contact furnizori')
                        ->modalContent(fn (WooProduct $record): HtmlString => static::renderSupplierContactModal($record))
                        ->modalSubmitAction(false)
                        ->modalCancelAction(fn (\Filament\Actions\StaticAction $action) => $action->label('Închide')),
                ]),

                // ── Descriere ────────────────────────────────────────────────
                Section::make('Descriere')
                    ->schema([
                        TextEntry::make('description')
                            ->label('')
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->hidden(fn (WooProduct $record): bool => blank($record->description)),

                // ── Atribute tehnice ─────────────────────────────────────────
                Section::make('Atribute tehnice')
                    ->description('Atribute generate automat din denumirea produsului.')
                    ->schema([
                        TextEntry::make('attributes_table')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => static::renderAttributesTable($record))
                            ->html(),
                    ]),
                Section::make('Istoric variație stoc')
                    ->description('Afișează 10 poziții vizibile și până la 30 poziții cu scroll.')
                    ->schema([
                        TextEntry::make('daily_variation_history')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => static::renderDailyVariationHistory($record))
                            ->html(),
                    ]),
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
                    ]),
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
                    ]),
            ]);
    }

    /**
     * @return Collection<int, DailyStockMetric>
     */
    public static function renderProductHeader(WooProduct $record): HtmlString
    {
        $imageUrl   = filled($record->main_image_url) ? e($record->main_image_url) : 'https://placehold.co/320x320?text=No+Image';
        $name       = e($record->decoded_name);
        $sku        = e($record->sku ?? '-');
        $price      = $record->price ? number_format((float) $record->price, 2, ',', '.') . ' lei' : '-';
        $regPrice   = $record->regular_price ? number_format((float) $record->regular_price, 2, ',', '.') . ' lei' : null;
        $salePrice  = $record->sale_price ? number_format((float) $record->sale_price, 2, ',', '.') . ' lei' : null;
        $source     = $record->is_placeholder ? 'ERP (contabilitate)' : 'WooCommerce';
        $sourceClr  = $record->is_placeholder ? '#d97706' : '#16a34a';
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

        $priceBlockHtml = '<div style="text-align:right;flex-shrink:0;min-width:130px;">';
        if ($isOnSale) {
            $priceBlockHtml .= '<div style="font-size:1.1rem;color:#dc2626;text-decoration:line-through;line-height:1.2;opacity:0.6;">'
                . $regPrice . '</div>';
        }
        $priceBlockHtml .= '<div style="font-size:2rem;font-weight:800;color:#dc2626;line-height:1.1;white-space:nowrap;">'
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

        // Descriere scurtă
        $shortDesc = filled($record->short_description)
            ? '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #f3f4f6;font-size:0.875rem;color:#374151;line-height:1.6;">'
              . $record->short_description
              . '</div>'
            : '';

        $html = '<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:start;width:100%;">'

            // Poza produs — responsivă
            . '<div style="flex:0 0 auto;width:min(340px,100%);">'
            . '<img src="' . $imageUrl . '" alt="' . $name . '" '
            . 'style="width:100%;aspect-ratio:1/1;object-fit:contain;border-radius:12px;border:1px solid #e5e7eb;background:#fafafa;" />'
            . '</div>'

            // Coloana dreapta: brand + nume + preț + tags + grid + descriere
            . '<div style="display:flex;flex-direction:column;gap:10px;min-width:0;flex:1 1 280px;">'

            // Brand
            . ($brandHtml ? '<div>' . $brandHtml . '</div>' : '')

            // Denumire produs + preț pe același rând
            . '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;">'
            . '<div style="font-size:1.4rem;font-weight:700;color:#111827;line-height:1.35;">' . $name . '</div>'
            . $priceBlockHtml
            . '</div>'

            // Etichete inline
            . '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">'
            . '<span style="background:#f3f4f6;color:#374151;border-radius:6px;padding:2px 8px;font-size:0.78rem;">SKU: ' . $sku . '</span>'
            . '<span style="background:#f3f4f6;color:' . $sourceClr . ';border-radius:6px;padding:2px 8px;font-size:0.78rem;">' . $source . '</span>'
            . '<span style="background:#f3f4f6;color:' . $stockClr . ';border-radius:6px;padding:2px 8px;font-size:0.78rem;">' . $stockLbl . '</span>'
            . ($location !== '-' ? '<span style="background:#f3f4f6;color:#374151;border-radius:6px;padding:2px 8px;font-size:0.78rem;">' . $location . '</span>' : '')
            . ($cats ? '<span style="background:#f3f4f6;color:#374151;border-radius:6px;padding:2px 8px;font-size:0.78rem;">' . e($cats) . '</span>' : '')
            . '</div>'

            // Stoc WinMentor
            . $stockHtml

            // Cod de bare
            . $barcodeHtml

            // Descriere scurtă
            . $shortDesc

            . '</div>'  // end coloana dreapta
            . '</div>'; // end wrapper

        return new HtmlString($html);
    }

    private static function renderSupplierContactModal(WooProduct $record): HtmlString
    {
        $rows = '';
        foreach ($record->suppliers as $supplier) {
            $logoHtml = filled($supplier->logo_url)
                ? '<img src="' . e($supplier->logo_url) . '" alt="' . e($supplier->name) . '" style="height:40px;max-width:140px;object-fit:contain;margin-bottom:8px;" />'
                : '';

            $field = fn (string $icon, string $label, ?string $val): string => filled($val)
                ? '<div style="display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f3f4f6;">'
                  . '<span style="color:#6b7280;font-size:0.85rem;min-width:130px;">' . $label . '</span>'
                  . '<span style="font-size:0.85rem;font-weight:500;color:#111827;">' . e($val) . '</span>'
                  . '</div>'
                : '';

            $skuVal   = $supplier->pivot->supplier_sku ?? null;
            $priceVal = $supplier->pivot->purchase_price
                ? number_format((float) $supplier->pivot->purchase_price, 2, ',', '.') . ' ' . ($supplier->pivot->currency ?? 'RON')
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
                . $logoHtml
                . '<div style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:8px;">' . e($supplier->name) . '</div>'
                . $field('phone', 'Telefon general', $supplier->phone)
                . $field('envelope', 'Email general', $supplier->email)
                . $field('globe', 'Website', $supplier->website_url)
                . $field('tag', 'SKU furnizor', $skuVal)
                . $field('currency', 'Preț achiziție', $priceVal)
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
            $closingQty = number_format((float) $metric->closing_total_qty, 3, '.', ',').' buc';
            $qtyText = static::formatSignedMetric($qtyVariation, 3, ' buc');
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
        $closingQty = number_format((float) $latest->closing_total_qty, 3, '.', ',').' buc';
        $color = static::variationInlineColor($latestVariation);

        return new HtmlString(
            '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.9rem;background:#fff;">'
            .'<div style="font-size:0.8rem;color:#6b7280;">Variație cantitate</div>'
            .'<div style="margin-top:0.35rem;font-size:1.35rem;font-weight:700;color:'.$color.';">'
            .static::formatSignedMetric($latestVariation, 3, ' buc')
            .'</div>'
            .'<div style="margin-top:0.2rem;font-size:0.8rem;color:#6b7280;">Ultima zi: '.$latestDay.'</div>'
            .'<div style="margin-top:0.55rem;display:flex;gap:0.7rem;flex-wrap:wrap;font-size:0.8rem;color:#374151;">'
            .'<span>7 poziții: <strong>'.static::formatSignedMetric($sum7, 3, ' buc').'</strong></span>'
            .'<span>30 poziții: <strong>'.static::formatSignedMetric($sum30, 3, ' buc').'</strong></span>'
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
