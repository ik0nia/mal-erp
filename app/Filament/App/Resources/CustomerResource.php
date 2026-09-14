<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\CustomerResource\Pages;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\CompanyData\OpenApiCompanyLookupService;
use App\Services\Winmentor\WinmentorBridgeClient;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CustomerResource extends Resource
{
    use HasDynamicNavSort;

    use EnforcesLocationScope, ChecksRolePermissions;

    protected static ?string $model = Customer::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Vânzări';

    protected static ?string $navigationLabel = 'Clienți';

    protected static ?string $modelLabel = 'Client';

    protected static ?string $pluralModelLabel = 'Clienți';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return \App\Models\RolePermission::check(static::permissionKey(), 'can_create');
    }

    public static function canEdit(Model $record): bool
    {
        return \App\Models\RolePermission::check(static::permissionKey(), 'can_edit')
            && static::canAccessRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return \App\Models\RolePermission::check(static::permissionKey(), 'can_delete')
            && static::canAccessRecord($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Detalii client')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Forms\Components\Hidden::make('location_id')
                            ->default(fn (): ?int => static::currentUser()?->location_id)
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
                            ->visible(fn (string $operation): bool => $operation !== 'create')
                            ->default(true),
                        Forms\Components\TextInput::make('cui')
                            ->label('CUI')
                            ->visible(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->required(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->helperText('Introdu doar cifre. La ieșirea din câmp, datele firmei se precompletează automat din OpenAPI.')
                            ->inputMode('numeric')
                            ->rule('regex:/^[0-9]+$/')
                            ->extraInputAttributes(['pattern' => '[0-9]*'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if ($get('type') !== Customer::TYPE_COMPANY) {
                                    return;
                                }

                                $normalizedCui = OpenApiCompanyLookupService::normalizeCui($state);

                                if ($normalizedCui === '') {
                                    return;
                                }

                                if ($normalizedCui !== (string) $state) {
                                    $set('cui', $normalizedCui);
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
                        Forms\Components\Toggle::make('is_vat_payer')
                            ->label('Plătitor TVA')
                            ->visible(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->inline(false),
                        Forms\Components\TextInput::make('registration_number')
                            ->label('Nr. Reg. Com.')
                            ->visible(fn (Get $get): bool => $get('type') === Customer::TYPE_COMPANY)
                            ->maxLength(255),
                    ]),
                Section::make('Adresă implicită')
                    ->columnSpanFull()
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
                Section::make('Adrese de livrare alternative')
                    ->columnSpanFull()
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
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activ'),
            ])
            ->deferFilters(false)
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return static::applyLocationFilter(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    /**
     * Fișa client — infolist read-only cu date locale + date live WinMentor (cache 5 min).
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Date client')
                ->columns(3)
                ->schema([
                    TextEntry::make('name')->label('Denumire'),
                    TextEntry::make('type')->label('Tip')
                        ->formatStateUsing(fn ($state): string => Customer::typeOptions()[$state] ?? (string) $state)
                        ->badge()
                        ->color(fn ($state): string => $state === Customer::TYPE_COMPANY ? 'info' : 'gray'),
                    TextEntry::make('cui')->label('CUI / CNP')->placeholder('—'),
                    TextEntry::make('phone')->label('Telefon')->placeholder('—'),
                    TextEntry::make('email')->label('Email')->placeholder('—'),
                    TextEntry::make('city')->label('Localitate')
                        ->formatStateUsing(fn ($state, Customer $record): string => trim(($record->city ?? '') . ' ' . ($record->county ? '(' . $record->county . ')' : '')) ?: '—'),
                    TextEntry::make('address')->label('Adresă')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('WinMentor')
                ->columns(3)
                ->schema([
                    TextEntry::make('wm_asociat')->label('Asociere')
                        ->getStateUsing(fn (Customer $record): string => self::wmLink($record)['asociat'] ? 'Asociat' : 'Neasociat')
                        ->badge()
                        ->color(fn (Customer $record): string => self::wmLink($record)['asociat'] ? 'success' : 'danger'),
                    TextEntry::make('wm_furnizor')->label('E și furnizor?')
                        ->getStateUsing(fn (Customer $record): string => (self::wmLink($record)['este_furnizor'] ?? false) ? 'Da' : 'Nu')
                        ->badge()
                        ->color(fn (Customer $record): string => (self::wmLink($record)['este_furnizor'] ?? false) ? 'warning' : 'gray'),
                    TextEntry::make('wm_id')->label('ID intern WinMentor')
                        ->getStateUsing(fn (Customer $record): string => self::wmLink($record)['wm_id'] ?? '—'),
                    TextEntry::make('wm_sold')->label('Sold curent')
                        ->getStateUsing(function (Customer $record): string {
                            $fin = self::wmFinanceCached($record);
                            if ($fin === null) {
                                return 'neîncărcat';
                            }
                            return $fin['sold'] ?? '—';
                        })
                        ->badge()
                        ->color(function (Customer $record): string {
                            $fin = self::wmFinanceCached($record);
                            if ($fin === null) {
                                return 'gray';
                            }
                            return empty($fin['sold']) ? 'gray' : 'warning';
                        }),
                    TextEntry::make('wm_hint')->hiddenLabel()
                        ->getStateUsing(fn (): string => 'Apasă „Încarcă date WinMentor" (sus) pentru sold și facturi.')
                        ->visible(fn (Customer $record): bool => self::wmLink($record)['asociat'] && self::wmFinanceCached($record) === null)
                        ->color('gray')->columnSpanFull(),
                    TextEntry::make('wm_eroare')->hiddenLabel()
                        ->getStateUsing(fn (Customer $record): ?string => self::wmFinanceCached($record)['eroare'] ?? null)
                        ->visible(fn (Customer $record): bool => ! empty(self::wmFinanceCached($record)['eroare'] ?? null))
                        ->color('danger')->columnSpanFull(),
                ]),

            Section::make('Facturi de încasat')
                ->visible(fn (Customer $record): bool => ! empty(self::wmFinanceCached($record)['facturi'] ?? []))
                ->schema([
                    TextEntry::make('wm_facturi')->hiddenLabel()->html()->columnSpanFull()
                        ->getStateUsing(fn (Customer $record): string => self::facturiHtml(self::wmFinanceCached($record)['facturi'] ?? [])),
                ]),

            Section::make('Încasări prin bancă')
                ->description(fn (Customer $record): ?string => trim(
                    (self::wmFinanceCached($record)['interval'] ?? '') .
                    ' · doar încasările din jurnalul de bancă/trezorerie — plățile la casă (numerar/card) nu sunt expuse de WinMentor; reperul plății la zi e Soldul curent'
                , ' ·'))
                ->visible(fn (Customer $record): bool => self::wmFinanceCached($record) !== null && empty(self::wmFinanceCached($record)['eroare']))
                ->schema([
                    TextEntry::make('wm_incasari')->hiddenLabel()->html()->columnSpanFull()
                        ->getStateUsing(fn (Customer $record): string => self::incasariHtml(self::wmFinanceCached($record)['incasari'] ?? [])),
                ]),

            Section::make('Sedii de livrare alternative')
                ->visible(fn (Customer $record): bool => ! empty(self::wmFinanceCached($record)['sedii'] ?? []))
                ->schema([
                    TextEntry::make('wm_sedii')->hiddenLabel()->html()->columnSpanFull()
                        ->getStateUsing(fn (Customer $record): string => self::sediiHtml(self::wmFinanceCached($record)['sedii'] ?? [])),
                ]),

            Section::make('Comenzi online (site)')
                ->description(fn (Customer $record): string => 'Comenzi WooCommerce asociate acestui client (' . count(self::onlineOrders($record)) . ')')
                ->visible(fn (Customer $record): bool => ! empty(self::onlineOrders($record)))
                ->schema([
                    TextEntry::make('comenzi_online')->hiddenLabel()->html()->columnSpanFull()
                        ->getStateUsing(fn (Customer $record): string => self::comenziOnlineHtml(self::onlineOrders($record))),
                ]),

            Section::make('Evoluție activitate')
                ->description('Vânzări lunare + semnal de trend (relație în creștere/scădere)')
                ->visible(fn (Customer $record): bool => ! empty(self::monthlySales($record)))
                ->schema([
                    TextEntry::make('activitate')->hiddenLabel()->html()->columnSpanFull()
                        ->getStateUsing(fn (Customer $record): string => self::monthlySalesHtml(self::monthlySales($record))),
                ]),

            Section::make('Top produse cumpărate')
                ->description('Din tot istoricul de vânzări local (după valoare)')
                ->collapsible()
                ->collapsed()
                ->visible(fn (Customer $record): bool => ! empty(self::topProducts($record)))
                ->schema([
                    TextEntry::make('top_produse')->hiddenLabel()->html()->columnSpanFull()
                        ->getStateUsing(fn (Customer $record): string => self::topProductsHtml(self::topProducts($record))),
                ]),

            Section::make('Istoric facturi / vânzări')
                ->description('Din baza locală (ultimele 60 facturi)')
                ->collapsible()
                ->collapsed()
                ->visible(fn (Customer $record): bool => ! empty(self::salesHistory($record)))
                ->schema([
                    TextEntry::make('wm_istoric')->hiddenLabel()->html()->columnSpanFull()
                        ->getStateUsing(fn (Customer $record): string => self::salesHtml(self::salesHistory($record))),
                ]),
        ]);
    }

    /**
     * Comenzile online (WooCommerce) ale clientului — potrivite pe mai multe chei
     * (partenerul WinMentor sincronizat + telefon + email), fiindcă un cumpărător
     * online poate avea mai multe ID-uri de partener WinMentor.
     */
    public static function onlineOrders(Customer $record): array
    {
        return Cache::remember("cust_online_orders_{$record->id}", 600, function () use ($record) {
            $wm     = trim((string) ($record->winmentor_partner_id ?? ''));
            $phone9 = $record->phone ? substr(preg_replace('/\D/', '', $record->phone), -9) : '';
            $email  = $record->email ? mb_strtolower(trim($record->email)) : '';

            if ($wm === '' && $phone9 === '' && $email === '') {
                return [];
            }

            $orders = \App\Models\WooOrder::query()
                ->where(function ($w) use ($wm, $phone9, $email) {
                    if ($wm !== '') {
                        $w->orWhere('winmentor_client_id', $wm);
                    }
                    if (strlen($phone9) === 9) {
                        $w->orWhereRaw("RIGHT(REGEXP_REPLACE(JSON_UNQUOTE(JSON_EXTRACT(billing, '$.phone')), '[^0-9]', ''), 9) = ?", [$phone9]);
                    }
                    if ($email !== '') {
                        $w->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(billing, '$.email'))) = ?", [$email]);
                    }
                })
                ->orderByDesc('order_date')
                ->limit(50)
                ->get(['number', 'status', 'total', 'order_date', 'winmentor_invoice_nr', 'winmentor_sync_status']);

            return $orders->map(fn ($o) => [
                'number'    => $o->number,
                'status'    => $o->status,
                'total'     => number_format((float) $o->total, 2, ',', '.'),
                'data'      => $o->order_date ? \Carbon\Carbon::parse($o->order_date)->format('d.m.Y') : '',
                'factura'   => $o->winmentor_invoice_nr,
                'sincron'   => $o->winmentor_sync_status,
            ])->all();
        });
    }

    /**
     * Top produse cumpărate de client (agregat din TOT istoricul de vânzări local,
     * pe toate identitățile lui: CUI + part_id intern + cod extern).
     */
    public static function topProducts(Customer $record, int $limit = 15): array
    {
        return Cache::remember("cust_topprod_{$record->id}", 600, function () use ($record, $limit) {
            $cui   = trim((string) ($record->winmentor_id ?: $record->cui ?: ''));
            $wmId  = self::wmLink($record)['wm_id'] ?? null;
            $codEx = $wmId ? (string) (DB::table('winmentor_parteneri')->where('wm_id', $wmId)->value('cod_extern') ?? '') : '';

            if ($cui === '' && ! $wmId) {
                return [];
            }

            $rows = DB::table('winmentor_vanzari_raw')
                ->where(function ($q) use ($cui, $wmId, $codEx) {
                    if ($cui !== '') $q->orWhere('cod_fiscal_client', $cui);
                    if ($wmId) $q->orWhere('part_id', $wmId);
                    if ($codEx !== '') $q->orWhere('part_id', $codEx);
                })
                ->whereNotNull('sku')->where('sku', '!=', '')
                ->selectRaw('sku, MAX(uom) uom, SUM(cantitate) cant, SUM(lei_cu_tva) valoare, COUNT(DISTINCT CONCAT(serie_document,nr_factura)) nr_facturi, MAX(CONCAT(an,"-",LPAD(luna,2,"0"),"-",LPAD(zi,2,"0"))) ultima')
                ->groupBy('sku')
                ->orderByDesc('valoare')
                ->limit($limit)
                ->get();

            $names = DB::table('woo_products')->whereIn('sku', $rows->pluck('sku'))->pluck('name', 'sku');

            return $rows->map(fn ($r) => [
                'sku'       => $r->sku,
                'nume'      => $names[$r->sku] ?? $r->sku,
                'cant'      => (float) $r->cant,
                'uom'       => $r->uom,
                'valoare'   => (float) $r->valoare,
                'nr_facturi'=> (int) $r->nr_facturi,
                'ultima'    => $r->ultima,
            ])->all();
        });
    }

    protected static function topProductsHtml(array $prods): string
    {
        if (empty($prods)) {
            return '<p class="text-sm text-gray-500">Fără produse în istoricul de vânzări.</p>';
        }
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        $maxVal = max(array_map(fn ($p) => $p['valoare'], $prods)) ?: 1;
        $rows = '';
        foreach ($prods as $p) {
            $pct = round($p['valoare'] / $maxVal * 100);
            $rows .= '<tr>'
                . '<td style="padding:5px 8px">' . e(\Illuminate\Support\Str::limit($p['nume'], 48))
                    . '<div style="height:4px;background:#e5e7eb;border-radius:2px;margin-top:3px"><div style="height:4px;width:' . $pct . '%;background:#7c3aed;border-radius:2px"></div></div></td>'
                . '<td style="padding:5px 8px;text-align:right;white-space:nowrap;color:#6b7280">' . e($fmt($p['cant'])) . ' ' . e($p['uom']) . '</td>'
                . '<td style="padding:5px 8px;text-align:right;white-space:nowrap;font-weight:600">' . e($fmt($p['valoare'])) . ' lei</td>'
                . '<td style="padding:5px 8px;text-align:right;color:#9ca3af">' . $p['nr_facturi'] . '×</td>'
                . '</tr>';
        }
        return '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="text-align:left;border-bottom:1px solid #ddd;color:#6b7280">'
            . '<th style="padding:4px 8px">Produs</th><th style="padding:4px 8px;text-align:right">Cantitate</th>'
            . '<th style="padding:4px 8px;text-align:right">Valoare</th><th style="padding:4px 8px;text-align:right">Facturi</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * Vânzări lunare (ultimele 18 luni) pe toate identitățile clientului — pentru
     * graficul de activitate + semnal de „relație în scădere".
     */
    public static function monthlySales(Customer $record, int $months = 18): array
    {
        return Cache::remember("cust_monthly_{$record->id}", 600, function () use ($record, $months) {
            $cui   = trim((string) ($record->winmentor_id ?: $record->cui ?: ''));
            $wmId  = self::wmLink($record)['wm_id'] ?? null;
            $codEx = $wmId ? (string) (DB::table('winmentor_parteneri')->where('wm_id', $wmId)->value('cod_extern') ?? '') : '';

            if ($cui === '' && ! $wmId) {
                return [];
            }

            $raw = DB::table('winmentor_vanzari_raw')
                ->where(function ($q) use ($cui, $wmId, $codEx) {
                    if ($cui !== '') $q->orWhere('cod_fiscal_client', $cui);
                    if ($wmId) $q->orWhere('part_id', $wmId);
                    if ($codEx !== '') $q->orWhere('part_id', $codEx);
                })
                ->selectRaw('an, luna, SUM(lei_cu_tva) val')
                ->groupBy('an', 'luna')
                ->get()
                ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->an, $r->luna));

            $out = [];
            $cursor = now()->startOfMonth()->subMonths($months - 1);
            for ($i = 0; $i < $months; $i++) {
                $ym = $cursor->format('Y-m');
                $out[] = [
                    'ym'    => $ym,
                    'label' => $cursor->locale('ro')->isoFormat('MMM'),
                    'an'    => $cursor->format('y'),
                    'val'   => round((float) ($raw[$ym]->val ?? 0), 2),
                ];
                $cursor->addMonth();
            }
            return $out;
        });
    }

    protected static function monthlySalesHtml(array $luni): string
    {
        if (empty($luni)) {
            return '<p class="text-sm text-gray-500">Fără date de vânzări.</p>';
        }
        $vals   = array_column($luni, 'val');
        $max    = max($vals) ?: 1;
        $fmt    = fn ($v) => $v >= 1000 ? number_format($v / 1000, 1, ',', '.') . 'k' : number_format($v, 0, ',', '.');

        // Semnal trend: media ultimelor 3 luni vs 3 anterioare
        $n = count($vals);
        $last3  = $n >= 3 ? array_sum(array_slice($vals, -3)) / 3 : end($vals);
        $prev3  = $n >= 6 ? array_sum(array_slice($vals, -6, 3)) / 3 : $last3;
        $trend  = $prev3 > 0 ? round(($last3 - $prev3) / $prev3 * 100) : 0;
        if ($last3 < 1 && $prev3 > 1) {
            $badge = '<span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:600">⚠ Fără activitate recentă</span>';
        } elseif ($trend <= -30) {
            $badge = '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:600">↓ În scădere ' . abs($trend) . '%</span>';
        } elseif ($trend >= 30) {
            $badge = '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:600">↑ În creștere ' . $trend . '%</span>';
        } else {
            $badge = '<span style="background:#eef2f7;color:#3e4c59;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:600">→ Stabil</span>';
        }

        $bars = '';
        foreach ($luni as $m) {
            $h = (int) round($m['val'] / $max * 90);
            $color = $m['val'] > 0 ? '#7c3aed' : '#e5e7eb';
            $bars .= '<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:3px;min-width:0">'
                . '<div style="font-size:9px;color:#9ca3af;white-space:nowrap">' . ($m['val'] > 0 ? $fmt($m['val']) : '') . '</div>'
                . '<div title="' . e($m['ym']) . ': ' . number_format($m['val'], 2, ',', '.') . ' lei" style="width:70%;height:' . max($h, 2) . 'px;background:' . $color . ';border-radius:3px 3px 0 0"></div>'
                . '<div style="font-size:9px;color:#6b7280;white-space:nowrap">' . e($m['label']) . '</div>'
                . '</div>';
        }

        return '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'
            . '<span style="font-size:12px;color:#6b7280">Vânzări lunare (cu TVA) — ultimele ' . count($luni) . ' luni</span>' . $badge . '</div>'
            . '<div style="display:flex;align-items:flex-end;gap:2px;height:130px;padding-top:10px">' . $bars . '</div>';
    }

    protected static function comenziOnlineHtml(array $orders): string
    {
        if (empty($orders)) {
            return '<p class="text-sm text-gray-500">Fără comenzi online asociate.</p>';
        }

        $statusColor = fn ($s) => match ($s) {
            'completed' => '#059669', 'processing' => '#2563eb', 'cancelled', 'refunded', 'failed' => '#dc2626',
            'on-hold', 'pending' => '#b45309', default => '#6b7280',
        };

        $rows = '';
        $total = 0.0;
        foreach ($orders as $o) {
            $total += (float) str_replace(['.', ','], ['', '.'], $o['total']);
            $fact = $o['factura']
                ? '<span style="color:#059669">✓ ' . e($o['factura']) . '</span>'
                : '<span style="color:#9ca3af">—</span>';
            $rows .= '<tr>'
                . '<td style="padding:4px 8px">#' . e($o['number']) . '</td>'
                . '<td style="padding:4px 8px">' . e($o['data']) . '</td>'
                . '<td style="padding:4px 8px"><span style="color:' . $statusColor($o['status']) . ';font-weight:600">' . e($o['status']) . '</span></td>'
                . '<td style="padding:4px 8px;text-align:right">' . e($o['total']) . ' lei</td>'
                . '<td style="padding:4px 8px">' . $fact . '</td>'
                . '</tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="text-align:left;border-bottom:1px solid #ddd">'
            . '<th style="padding:4px 8px">Comandă</th><th style="padding:4px 8px">Dată</th>'
            . '<th style="padding:4px 8px">Status</th><th style="padding:4px 8px;text-align:right">Total</th>'
            . '<th style="padding:4px 8px">Factură WM</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr style="border-top:1px solid #ddd;font-weight:600">'
            . '<td style="padding:4px 8px" colspan="3">Total (' . count($orders) . ' comenzi)</td>'
            . '<td style="padding:4px 8px;text-align:right">' . number_format($total, 2, ',', '.') . ' lei</td><td></td>'
            . '</tr></tfoot></table>';
    }

    /**
     * Legătura client → partener WinMentor (RAPID, doar DB locală, fără COM).
     * Rezolvă ID-ul intern din oglinda winmentor_parteneri după CUI. Safe la randare.
     */
    public static function wmLink(Customer $record): array
    {
        $cui = trim((string) ($record->winmentor_id ?: $record->cui ?: ''));

        // Legătura STABILĂ: winmentor_partner_id (wm_id intern) — acoperă și PF fără CUI
        $row = null;
        if (filled($record->winmentor_partner_id)) {
            $row = DB::table('winmentor_parteneri')->where('wm_id', $record->winmentor_partner_id)->first();
        }

        // Fallback: convenția veche pe CUI
        if (! $row && $cui !== '') {
            $digits = preg_replace('/\D/', '', $cui);
            $row = DB::table('winmentor_parteneri')
                ->where('cod_fiscal', $cui)
                ->when($digits !== '', fn ($q) => $q->orWhereRaw("REPLACE(REPLACE(UPPER(cod_fiscal), ' ', ''), 'RO', '') = ?", [$digits]))
                ->first();
        }

        if (! $row) {
            return ['asociat' => false, 'cui' => $cui];
        }

        return [
            'asociat'       => true,
            'cui'           => $cui,
            'wm_id'         => $row->wm_id,
            'denumire_wm'   => $row->denumire,
            'este_furnizor' => Supplier::where('winmentor_id', $row->wm_id)->orWhere('winmentor_id', $cui)->exists(),
        ];
    }

    /** Memo per-request pentru istoricul de vânzări (evită query repetat în infolist). */
    protected static array $salesMemo = [];

    /**
     * Istoric facturi/vânzări pe client din tabela LOCALĂ winmentor_vanzari_raw
     * (rapid, fără COM). Grupat pe factură (serie + nr), cele mai recente 60.
     */
    public static function salesHistory(Customer $record): array
    {
        if (array_key_exists($record->id, self::$salesMemo)) {
            return self::$salesMemo[$record->id];
        }

        $cui = trim((string) ($record->winmentor_id ?: $record->cui ?: ''));
        $wmId = self::wmLink($record)['wm_id'] ?? null; // ID intern (necesar pt PF fără CUI)

        if ($cui === '' && ! $wmId) {
            return self::$salesMemo[$record->id] = [];
        }

        // ID-ul legacy (cod_extern) — documentele 2025+ îl folosesc adesea ca part_id
        $codExtern = $wmId
            ? (string) (DB::table('winmentor_parteneri')->where('wm_id', $wmId)->value('cod_extern') ?? '')
            : '';

        // Match după CUI (PJ) SAU part_id (ID intern SAU legacy — prinde și PF fără cod fiscal).
        $rows = DB::table('winmentor_vanzari_raw')
            ->where(function ($q) use ($cui, $wmId, $codExtern) {
                if ($cui !== '') {
                    $q->orWhere('cod_fiscal_client', $cui);
                }
                if ($wmId) {
                    $q->orWhere('part_id', $wmId);
                }
                if ($codExtern !== '') {
                    $q->orWhere('part_id', $codExtern);
                }
            })
            ->select('serie_document', 'nr_factura', 'data_emitere', 'data_scadenta', 'tip_document', 'valoare_factura', 'an', 'luna', 'zi', 'sku', 'cantitate', 'uom', 'pret', 'lei_cu_tva')
            ->orderByDesc('an')->orderByDesc('luna')->orderByDesc('zi')
            ->limit(3000)->get();

        $byFact = [];
        foreach ($rows as $r) {
            $key = $r->serie_document . '-' . $r->nr_factura;
            if (! isset($byFact[$key])) {
                $byFact[$key] = [
                    'serie'    => $r->serie_document,
                    'nr'       => $r->nr_factura,
                    'data'     => $r->data_emitere,
                    'scadenta' => $r->data_scadenta,
                    'tip'      => $r->tip_document,
                    'valoare'  => $r->valoare_factura,
                    'lines'    => [],
                ];
            }
            if (filled($r->sku)) {
                $byFact[$key]['lines'][] = [
                    'sku'  => $r->sku,
                    'cant' => $r->cantitate,
                    'uom'  => $r->uom,
                    'pret' => $r->pret,
                    'val'  => $r->lei_cu_tva,
                ];
            }
        }

        $facturi = array_slice(array_values($byFact), 0, 60);

        // Nume produs în bloc, după SKU (o singură interogare)
        $skus = collect($facturi)->flatMap(fn ($f) => array_column($f['lines'], 'sku'))->filter()->unique()->values();
        $names = $skus->isNotEmpty()
            ? DB::table('woo_products')->whereIn('sku', $skus)->pluck('name', 'sku')
            : collect();
        foreach ($facturi as &$f) {
            foreach ($f['lines'] as &$ln) {
                $ln['nume'] = $names[$ln['sku']] ?? $ln['sku'];
            }
        }
        unset($f, $ln);

        // Marcaj comenzi online: leagă factura de comanda de pe site
        // (după nr. factură dacă e deja legată, altfel euristic după dată + sumă).
        $online = self::onlineOrders($record);
        // Parser robust: „1.557,00" (RO) și „73.20" (zecimal punct) → float corect
        $parse = function ($s): float {
            $s = (string) $s;
            return str_contains($s, ',')
                ? (float) str_replace(',', '.', str_replace('.', '', $s)) // format RO
                : (float) $s;                                             // zecimal simplu
        };
        $byNr = [];
        $byDateAmt = [];
        foreach ($online as $o) {
            if (filled($o['factura'])) {
                $byNr[(string) $o['factura']] = $o['number'];
            }
            $byDateAmt[$o['data']] = ['nr' => $o['number'], 'amt' => $parse($o['total'])];
        }
        foreach ($facturi as &$f) {
            $f['online'] = null;
            $nr = (string) $f['nr'];
            if (isset($byNr[$nr])) {
                $f['online'] = $byNr[$nr];
                continue;
            }
            $dataFmt = $f['data'] ? \Carbon\Carbon::parse($f['data'])->format('d.m.Y') : '';
            if ($dataFmt && isset($byDateAmt[$dataFmt]) && abs($byDateAmt[$dataFmt]['amt'] - $parse($f['valoare'])) < 0.5) {
                $f['online'] = $byDateAmt[$dataFmt]['nr'];
            }
        }
        unset($f);

        return self::$salesMemo[$record->id] = $facturi;
    }

    /**
     * Date financiare WinMentor din cache (fără apel COM). null = neîncărcate încă.
     */
    public static function wmFinanceCached(Customer $record): ?array
    {
        // Calcul LOCAL instant din tabelele sincronizate (solduri/încasări/sedii) —
        // fără COM la randare; butonul din fișă doar golește cache-ul (10 min).
        return Cache::remember("cust_wm_v2_{$record->id}", 600, function () use ($record) {
            $link = self::wmLink($record);
            if (! ($link['asociat'] ?? false)) {
                return null;
            }

            $wmId      = (string) $link['wm_id'];
            $codExtern = (string) (DB::table('winmentor_parteneri')->where('wm_id', $wmId)->value('cod_extern') ?? '');
            $partIds   = array_values(array_filter([$wmId, $codExtern]));

            $fmtDate = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y') : '';
            $fmtNum  = fn ($v) => number_format((float) $v, 2, ',', '.');

            // Facturi de încasat + sold: din scadențarul oficial. Se includ și
            // stornourile (rest negativ) — altfel lista nu bate cu soldul, care
            // le însumează (caz real Ikonia: F 70610 anulată de 2 stornouri
            // necompensate; lista arăta 10.923 iar soldul 8.650)
            $solduri = DB::table('winmentor_solduri_raw')
                ->where('directie', 'client')
                ->whereIn('part_id', $partIds)
                ->whereRaw('ABS(rest_de_plata) >= 0.01')
                ->orderByDesc('data_factura')
                ->get();

            $facturi = $solduri->map(fn ($f) => [
                'tip'          => $f->tip_document,
                'nrDocument'   => $f->nr_factura,
                'dataDocument' => $fmtDate($f->data_factura),
                'rest'         => $fmtNum($f->rest_de_plata),
                'dataScadenta' => $fmtDate($f->termen_plata),
            ])->values()->all();

            // Încasări: preferăm istoricul complet per client (GetIncasariClienti,
            // sincronizat nocturn); fallback pe exportul lunar (incomplet) dacă
            // partenerul n-a fost încă acoperit de sync-ul per client
            $incasari = DB::table('winmentor_incasari_clienti')
                ->whereIn('part_id', $partIds)
                ->orderByDesc('data')
                ->limit(60)
                ->get()
                ->map(fn ($i) => [
                    'data'           => $fmtDate($i->data),
                    'documentRef'    => $i->document_ref,
                    'suma'           => $fmtNum($i->suma),
                    'detaliiFacturi' => $i->detalii_facturi ?? '',
                ])->all();

            if (empty($incasari)) {
                $incasari = DB::table('winmentor_incasari_raw')
                    ->whereIn('part_id', $partIds)
                    ->orderByDesc('data')
                    ->limit(60)
                    ->get()
                    ->map(fn ($i) => [
                        'data'           => $fmtDate($i->data),
                        'documentRef'    => $i->document_ref,
                        'suma'           => $fmtNum($i->suma),
                        'detaliiFacturi' => '',
                    ])->all();
            }

            // Sedii / puncte de livrare
            $sedii = DB::table('winmentor_sedii')
                ->where('partener_wm_id', $wmId)
                ->orderBy('pozitie')
                ->get()
                ->map(fn ($sd) => [
                    'denumire'   => $sd->denumire,
                    'localitate' => $sd->localitate,
                    'cod_postal' => $sd->cod_postal,
                ])->all();

            return [
                'sold'     => $fmtNum($solduri->sum('rest_de_plata')) . ' lei',
                'facturi'  => $facturi,
                'incasari' => $incasari,
                'sedii'    => $sedii,
                'interval' => ($ts = DB::table('winmentor_incasari_clienti')->whereIn('part_id', $partIds)->max('fetched_at'))
                    ? 'istoric complet · actualizat ' . \Carbon\Carbon::parse($ts)->format('d.m H:i')
                    : 'sincronizat local — istoric parțial (clientul intră în sync-ul nocturn)',
                'eroare'   => null,
            ];
        });
    }

    /**
     * Încarcă LENT (apeluri COM) soldul + facturile clientului și le pune în cache 5 min.
     * Se apelează DOAR la buton (cu spinner), niciodată la randarea paginii.
     */
    public static function loadWmFinance(Customer $record): array
    {
        $link = self::wmLink($record);
        if (! ($link['asociat'] ?? false)) {
            $data = ['sold' => null, 'facturi' => [], 'eroare' => 'Client neasociat în WinMentor.'];
            Cache::put("cust_wm_{$record->id}", $data, now()->addMinutes(5));
            return $data;
        }

        // Interval pentru încasări: tot istoricul (2019 → luna curentă).
        $an1 = 2019;
        $an2 = (int) now()->year;
        $luna2 = (int) now()->month;

        return Cache::remember("cust_wm_{$record->id}", now()->addMinutes(5), function () use ($link, $an1, $an2, $luna2) {
            $out = [
                'sold'     => null,
                'facturi'  => [],
                'incasari' => [],
                'sedii'    => [],
                'interval' => "01.{$an1} – " . str_pad((string) $luna2, 2, '0', STR_PAD_LEFT) . ".{$an2}",
                'eroare'   => null,
            ];
            try {
                $c = app(WinmentorBridgeClient::class);
                $out['sold'] = $c->getSoldPartener($link['wm_id'])['sold'] ?? null;
                $out['facturi'] = $c->getSoldDetaliat($link['wm_id']);
                $out['incasari'] = $c->getIncasariClient($link['wm_id'], $an1, 1, $an2, $luna2);
                $out['sedii'] = $c->getSediiLivrare($link['cui'], $link['wm_id']);
            } catch (Throwable $e) {
                $out['eroare'] = 'Date WinMentor indisponibile: ' . $e->getMessage();
            }
            return $out;
        });
    }

    protected static function facturiHtml(array $facturi): string
    {
        if (empty($facturi)) {
            return '<p class="text-sm text-gray-500">Nu sunt facturi de încasat.</p>';
        }
        // Sortare cronologică; stornourile (rest negativ) evidențiate — ele anulează
        // facturi din listă dar rămân „deschise" până la compensare în WinMentor
        usort($facturi, fn ($a, $b) => strcmp(
            preg_replace('/(\d{2})\.(\d{2})\.(\d{4})/', '$3$2$1', $a['dataDocument'] ?? ''),
            preg_replace('/(\d{2})\.(\d{2})\.(\d{4})/', '$3$2$1', $b['dataDocument'] ?? '')
        ));

        $rows = '';
        $total = 0.0;
        $areStorno = false;
        foreach ($facturi as $f) {
            $rest = (float) str_replace(['.', ','], ['', '.'], (string) ($f['rest'] ?? '0'));
            $total += $rest;
            $negativ = $rest < 0;
            $areStorno = $areStorno || $negativ;
            $style = $negativ ? ';color:#dc2626' : '';
            $rows .= '<tr>'
                . '<td style="padding:4px 8px">' . e($f['tip'] ?? '') . ($negativ ? ' <span style="color:#dc2626;font-size:11px">(storno)</span>' : '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['nrDocument'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['dataDocument'] ?? '') . '</td>'
                . '<td style="padding:4px 8px;text-align:right' . $style . '">' . e($f['rest'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['dataScadenta'] ?? '') . '</td>'
                . '</tr>';
        }

        $footer = '<tr style="border-top:1px solid #ddd;font-weight:600">'
            . '<td style="padding:4px 8px" colspan="3">Total rest de plată</td>'
            . '<td style="padding:4px 8px;text-align:right">' . number_format($total, 2, ',', '.') . '</td><td></td></tr>';

        $hint = $areStorno
            ? '<p style="font-size:12px;color:#92400e;margin-top:6px">⚠ Stornouri necompensate în WinMentor: documentele cu rest negativ anulează (parțial sau total) facturi din listă — după compensare în Mentor dispar amândouă din sold.</p>'
            : '';

        return '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="text-align:left;border-bottom:1px solid #ddd">'
            . '<th style="padding:4px 8px">Tip</th><th style="padding:4px 8px">Nr. doc</th>'
            . '<th style="padding:4px 8px">Dată</th><th style="padding:4px 8px;text-align:right">Rest de plată</th>'
            . '<th style="padding:4px 8px">Scadență</th>'
            . '</tr></thead><tbody>' . $rows . $footer . '</tbody></table>' . $hint;
    }

    protected static function sediiHtml(array $sedii): string
    {
        if (empty($sedii)) {
            return '<p class="text-sm text-gray-500">Fără sedii de livrare alternative.</p>';
        }

        // Grupăm sediile identice (nume+localitate) — la constructori aceleași
        // șantiere apar de mai multe ori în nomenclator
        $grouped = [];
        foreach ($sedii as $sd) {
            $key = mb_strtoupper(trim(($sd['denumire'] ?? '').'|'.($sd['localitate'] ?? '')));
            if (! isset($grouped[$key])) {
                $grouped[$key] = $sd + ['nr' => 0];
            }
            $grouped[$key]['nr']++;
            if (! empty($sd['email']) && empty($grouped[$key]['email'])) {
                $grouped[$key]['email'] = $sd['email'];
            }
        }

        $cards = '';
        foreach ($grouped as $sd) {
            $den = e($sd['denumire'] ?? '');
            $loc = e($sd['localitate'] ?? '');
            $extra = array_filter([
                ($sd['cod_postal'] ?? '') !== '' ? 'CP '.e($sd['cod_postal']) : null,
                ($sd['email'] ?? '') !== '' ? '✉ '.e($sd['email']) : null,
                ($sd['nr'] ?? 1) > 1 ? '×'.$sd['nr'].' în nomenclator' : null,
            ]);

            $cards .= '<div style="border:1px solid #e5e7eb;border-radius:.5rem;padding:.5rem .75rem;background:#fafafa;">'
                . '<div style="font-weight:600;color:#111827;font-size:.85rem;">📍 '.($den !== '' && $den !== $loc ? $den : $loc).'</div>'
                . ($den !== '' && $den !== $loc && $loc !== '' ? '<div style="font-size:.78rem;color:#6b7280;">'.$loc.'</div>' : '')
                . ($extra ? '<div style="font-size:.72rem;color:#9ca3af;margin-top:.15rem;">'.implode(' · ', $extra).'</div>' : '')
                . '</div>';
        }

        return '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.5rem;">'.$cards.'</div>';
    }

    protected static function salesHtml(array $facturi): string
    {
        if (empty($facturi)) {
            return '<p class="text-sm text-gray-500">Nu există facturi pentru acest client.</p>';
        }
        $bodies = '';
        $total = 0.0;
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        foreach ($facturi as $f) {
            $val = (string) ($f['valoare'] ?? '');
            $total += (float) str_replace([' ', ','], ['', '.'], $val);
            $lines = $f['lines'] ?? [];

            // Rândurile produselor (afișate la expandare)
            $lineRows = '';
            foreach ($lines as $ln) {
                $lineRows .= '<tr style="background:#fafbfc">'
                    . '<td style="padding:3px 8px 3px 24px;color:#374151" colspan="2">' . e(\Illuminate\Support\Str::limit($ln['nume'] ?? $ln['sku'], 55)) . '</td>'
                    . '<td style="padding:3px 8px;text-align:right;color:#6b7280">' . e($fmt($ln['cant'])) . ' ' . e($ln['uom'] ?? '') . '</td>'
                    . '<td style="padding:3px 8px;text-align:right;color:#6b7280">' . e($fmt($ln['pret'])) . '</td>'
                    . '<td style="padding:3px 8px;text-align:right;color:#111827">' . e($fmt($ln['val'])) . '</td>'
                    . '</tr>';
            }
            $hasLines = $lines !== [];
            $arrow = $hasLines ? '<span x-show="!open">▸</span><span x-show="open" x-cloak>▾</span> ' : '';

            $onlineBadge = ! empty($f['online'])
                ? ' <span style="background:#ede9fe;color:#6b21a8;border-radius:4px;padding:1px 6px;font-size:10px;font-weight:600;white-space:nowrap">🛒 online #' . e($f['online']) . '</span>'
                : '';

            $bodies .= '<tbody x-data="{open:false}">'
                . '<tr ' . ($hasLines ? '@click="open=!open" style="cursor:pointer"' : '') . '>'
                . '<td style="padding:4px 8px">' . $arrow . e(trim(($f['serie'] ?? '') . ' ' . ($f['nr'] ?? ''))) . $onlineBadge . '</td>'
                . '<td style="padding:4px 8px">' . e($f['data'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['tip'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['scadenta'] ?? '') . '</td>'
                . '<td style="padding:4px 8px;text-align:right">' . e($val) . '</td>'
                . '</tr>'
                . ($hasLines
                    ? '<tr x-show="open" x-cloak><td colspan="5" style="padding:0 8px 8px"><table style="width:100%;border-collapse:collapse;font-size:12px;background:#fafbfc;border-radius:6px">'
                        . '<tr style="color:#9ca3af;text-align:left"><td style="padding:3px 8px 3px 24px" colspan="2">Produs</td><td style="padding:3px 8px;text-align:right">Cant.</td><td style="padding:3px 8px;text-align:right">Preț</td><td style="padding:3px 8px;text-align:right">Valoare</td></tr>'
                        . $lineRows . '</table></td></tr>'
                    : '')
                . '</tbody>';
        }
        $totalFmt = number_format($total, 2, ',', '.');
        return '<p style="font-size:11px;color:#9ca3af;margin:0 0 6px">Click pe o factură pentru a vedea produsele.</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="text-align:left;border-bottom:1px solid #ddd">'
            . '<th style="padding:4px 8px">Factură</th><th style="padding:4px 8px">Dată</th>'
            . '<th style="padding:4px 8px">Tip</th><th style="padding:4px 8px">Scadență</th>'
            . '<th style="padding:4px 8px;text-align:right">Valoare</th>'
            . '</tr></thead>' . $bodies
            . '<tfoot><tr style="border-top:1px solid #ddd;font-weight:600">'
            . '<td style="padding:4px 8px" colspan="4">Total (' . count($facturi) . ' facturi)</td>'
            . '<td style="padding:4px 8px;text-align:right">' . e($totalFmt) . '</td>'
            . '</tr></tfoot></table>';
    }

    protected static function incasariHtml(array $incasari): string
    {
        if (empty($incasari)) {
            return '<p class="text-sm text-gray-500">Nu sunt încasări prin bancă în intervalul selectat (plățile la casă nu apar aici).</p>';
        }
        $rows = '';
        $total = 0.0;
        foreach ($incasari as $i) {
            $suma = (string) ($i['suma'] ?? '');
            $total += (float) str_replace([' ', '.', ','], ['', '', '.'], $suma);
            $detalii = array_values(array_filter(explode('~', (string) ($i['detaliiFacturi'] ?? ''))));
            // Curăță artefactele float din sumele legate de factură: „=94,2400000000016” → „=94,24”
            $detalii = array_map(
                fn ($d) => preg_replace_callback('/=(-?\d+(?:[.,]\d+)?)/', fn ($m) => '=' . number_format((float) str_replace(',', '.', $m[1]), 2, ',', '.'), $d),
                $detalii
            );
            $detaliiHtml = $detalii ? implode('<br>', array_map(fn ($d) => e($d), $detalii)) : '—';
            $rows .= '<tr>'
                . '<td style="padding:4px 8px;vertical-align:top">' . e($i['data'] ?? '') . '</td>'
                . '<td style="padding:4px 8px;vertical-align:top">' . e($i['documentRef'] ?? '') . '</td>'
                . '<td style="padding:4px 8px;text-align:right;vertical-align:top">' . e($suma) . '</td>'
                . '<td style="padding:4px 8px;font-size:12px;color:#555">' . $detaliiHtml . '</td>'
                . '</tr>';
        }
        $totalFmt = number_format($total, 2, ',', '.');
        return '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="text-align:left;border-bottom:1px solid #ddd">'
            . '<th style="padding:4px 8px">Dată</th><th style="padding:4px 8px">Document</th>'
            . '<th style="padding:4px 8px;text-align:right">Sumă</th><th style="padding:4px 8px">Facturi</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr style="border-top:1px solid #ddd;font-weight:600">'
            . '<td style="padding:4px 8px" colspan="2">Total încasări (' . count($incasari) . ')</td>'
            . '<td style="padding:4px 8px;text-align:right">' . e($totalFmt) . '</td><td></td>'
            . '</tr></tfoot></table>';
    }
}
