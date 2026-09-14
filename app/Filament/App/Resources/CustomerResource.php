<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\CustomerResource\Pages;
use App\Filament\App\Resources\WooOrderResource;
use App\Filament\App\Resources\WooProductResource;
use Filament\Schemas\Components\Tabs;
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
        return $schema->columns(1)->schema([
            // Hero KPI — sinteza relației, sus de tot
            Section::make()
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('hero')->hiddenLabel()->html()->columnSpanFull()
                        ->getStateUsing(fn (Customer $record): string => self::heroHtml($record)),
                ]),

            Section::make('Clienți legați (același client real)')
                ->description('Fișe identificate ca aceeași entitate — rapoarte separate per fișă')
                ->columnSpanFull()
                ->visible(fn (Customer $record): bool => self::groupMembers($record)->count() > 1)
                ->schema([
                    TextEntry::make('clienti_legati')->hiddenLabel()->html()->columnSpanFull()
                        ->getStateUsing(fn (Customer $record): string => self::linkedMembersHtml($record)),
                ]),

            Tabs::make()->columnSpanFull()->tabs([

                Tabs\Tab::make('Prezentare generală')
                    ->icon('heroicon-o-chart-bar')
                    ->schema([
                        Section::make('Evoluție activitate')
                            ->description('Vânzări lunare (cu TVA) + semnal de trend')
                            ->columnSpanFull()
                            ->visible(fn (Customer $record): bool => ! empty(self::monthlySales($record)))
                            ->schema([
                                TextEntry::make('activitate')->hiddenLabel()->html()->columnSpanFull()
                                    ->getStateUsing(fn (Customer $record): string => self::monthlySalesHtml(self::monthlySales($record))),
                            ]),

                        Section::make('Top produse cumpărate')
                            ->description('Ce cumpără cel mai mult · click pe produs pentru fișă')
                            ->columnSpanFull()
                            ->visible(fn (Customer $record): bool => ! empty(self::topProducts($record, 'all')))
                            ->schema([
                                // Filtru de perioadă = Tabs NATIV Filament (funcționează prin Livewire)
                                Tabs::make()->tabs([
                                    Tabs\Tab::make('Toată perioada')->schema([
                                        TextEntry::make('tp_all')->hiddenLabel()->html()->columnSpanFull()
                                            ->getStateUsing(fn (Customer $record): string => self::renderTopTable(self::topProducts($record, 'all'))),
                                    ]),
                                    Tabs\Tab::make('Ultimul an')->schema([
                                        TextEntry::make('tp_1y')->hiddenLabel()->html()->columnSpanFull()
                                            ->getStateUsing(fn (Customer $record): string => self::renderTopTable(self::topProducts($record, '1y'))),
                                    ]),
                                    Tabs\Tab::make('Ultimele 6 luni')->schema([
                                        TextEntry::make('tp_6m')->hiddenLabel()->html()->columnSpanFull()
                                            ->getStateUsing(fn (Customer $record): string => self::renderTopTable(self::topProducts($record, '6m'))),
                                    ]),
                                ]),
                            ]),
                    ]),

                Tabs\Tab::make('Financiar')
                    ->icon('heroicon-o-banknotes')
                    ->columns(2)
                    ->schema([
                        Section::make('Facturi de încasat')
                            ->columnSpan(1)
                            ->visible(fn (Customer $record): bool => ! empty(self::wmFinanceCached($record)['facturi'] ?? []))
                            ->schema([
                                TextEntry::make('wm_facturi')->hiddenLabel()->html()->columnSpanFull()
                                    ->getStateUsing(fn (Customer $record): string => self::facturiHtml(self::wmFinanceCached($record)['facturi'] ?? [], $record)),
                            ]),

                        Section::make('Încasări prin bancă')
                            ->columnSpan(1)
                            ->description(fn (Customer $record): ?string => trim((self::wmFinanceCached($record)['interval'] ?? '') . ' · doar din jurnalul de bancă/trezorerie (nu numerar/card)', ' ·'))
                            ->visible(fn (Customer $record): bool => self::wmFinanceCached($record) !== null && empty(self::wmFinanceCached($record)['eroare']))
                            ->schema([
                                TextEntry::make('wm_incasari')->hiddenLabel()->html()->columnSpanFull()
                                    ->getStateUsing(fn (Customer $record): string => self::incasariHtml(self::wmFinanceCached($record)['incasari'] ?? [])),
                            ]),
                    ]),

                Tabs\Tab::make('Comenzi & documente')
                    ->icon('heroicon-o-shopping-bag')
                    ->schema([
                        Section::make('Comenzi online (site)')
                            ->columnSpanFull()
                            ->description(fn (Customer $record): string => count(self::onlineOrders($record)) . ' comenzi WooCommerce · click pe comandă pentru detalii')
                            ->visible(fn (Customer $record): bool => ! empty(self::onlineOrders($record)))
                            ->schema([
                                TextEntry::make('comenzi_online')->hiddenLabel()->html()->columnSpanFull()
                                    ->getStateUsing(fn (Customer $record): string => self::comenziOnlineHtml(self::onlineOrders($record))),
                            ]),

                        Section::make('Istoric facturi / vânzări')
                            ->columnSpanFull()
                            ->description('Click pe o factură pentru produse · 🛒 = comandă online')
                            ->visible(fn (Customer $record): bool => ! empty(self::salesHistory($record)))
                            ->schema([
                                TextEntry::make('wm_istoric')->hiddenLabel()->html()->columnSpanFull()
                                    ->getStateUsing(fn (Customer $record): string => self::salesHtml(self::salesHistory($record))),
                            ]),
                    ]),

                Tabs\Tab::make('Date & contact')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->schema([
            Section::make('Date client')
                ->columns(2)
                ->columnSpan(1)
                ->schema([
                    TextEntry::make('name')->label('Denumire'),
                    TextEntry::make('kind')->label('Tip')
                        ->getStateUsing(fn (Customer $record): string => self::customerKind($record)['label'])
                        ->badge()
                        ->color(fn (Customer $record): string => self::customerKind($record)['key'] === 'pj' ? 'info' : 'warning'),
                    TextEntry::make('cui')->label('CUI / CNP')->placeholder('—'),
                    TextEntry::make('phone')->label('Telefon')->placeholder('—'),
                    TextEntry::make('email')->label('Email')->placeholder('—'),
                    TextEntry::make('city')->label('Localitate')
                        ->formatStateUsing(fn ($state, Customer $record): string => trim(($record->city ?? '') . ' ' . ($record->county ? '(' . $record->county . ')' : '')) ?: '—'),
                    TextEntry::make('address')->label('Adresă')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('WinMentor')
                ->columns(2)
                ->columnSpan(1)
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

                        Section::make('Sedii de livrare alternative')
                            ->columnSpanFull()
                            ->visible(fn (Customer $record): bool => ! empty(self::wmFinanceCached($record)['sedii'] ?? []))
                            ->schema([
                                TextEntry::make('wm_sedii')->hiddenLabel()->html()->columnSpanFull()
                                    ->getStateUsing(fn (Customer $record): string => self::sediiHtml(self::wmFinanceCached($record)['sedii'] ?? [])),
                            ]),
                    ]), // end Tab „Date & contact"

            ]), // end Tabs
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
            $ids     = self::identities($record);
            $partIds = $ids['part_ids'];
            $phones  = $ids['phones'];
            $emails  = $ids['emails'];

            if (empty($partIds) && empty($phones) && empty($emails)) {
                return [];
            }

            $orders = \App\Models\WooOrder::query()
                ->where(function ($w) use ($partIds, $phones, $emails) {
                    if ($partIds) {
                        $w->orWhereIn('winmentor_client_id', $partIds);
                    }
                    if ($phones) {
                        $w->orWhereRaw("RIGHT(REGEXP_REPLACE(JSON_UNQUOTE(JSON_EXTRACT(billing, '$.phone')), '[^0-9]', ''), 9) IN (" . implode(',', array_fill(0, count($phones), '?')) . ')', $phones);
                    }
                    if ($emails) {
                        $w->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(billing, '$.email'))) IN (" . implode(',', array_fill(0, count($emails), '?')) . ')', $emails);
                    }
                })
                ->orderByDesc('order_date')
                ->limit(50)
                ->get(['id', 'number', 'status', 'total', 'order_date', 'winmentor_invoice_nr', 'winmentor_sync_status']);

            // Rezolvă factura lipsă (scoped pe client): index F-facturi după dată+sumă.
            $invIndex = [];
            if ($orders->filter(fn ($o) => blank($o->winmentor_invoice_nr))->isNotEmpty()) {
                if ($ids['cuis'] || $ids['part_ids']) {
                    $invs = DB::table('winmentor_vanzari_raw')
                        ->where(function ($q) use ($ids) {
                            if ($ids['cuis']) $q->orWhereIn('cod_fiscal_client', $ids['cuis']);
                            if ($ids['part_ids']) $q->orWhereIn('part_id', $ids['part_ids']);
                        })
                        ->where('serie_document', 'like', 'F%')
                        ->selectRaw('nr_factura, an, luna, zi, MAX(valoare_factura) val')
                        ->groupBy('nr_factura', 'an', 'luna', 'zi')
                        ->get();
                    foreach ($invs as $iv) {
                        $key = sprintf('%02d.%02d.%04d', $iv->zi, $iv->luna, $iv->an);
                        $invIndex[$key][] = ['nr' => $iv->nr_factura, 'val' => (float) $iv->val];
                    }
                }
            }

            return $orders->map(function ($o) use ($invIndex) {
                $data    = $o->order_date ? \Carbon\Carbon::parse($o->order_date)->format('d.m.Y') : '';
                $factura = $o->winmentor_invoice_nr;
                if (! $factura && $data && isset($invIndex[$data])) {
                    foreach ($invIndex[$data] as $cand) {
                        if (abs($cand['val'] - (float) $o->total) < 0.5) {
                            $factura = $cand['nr'];
                            break;
                        }
                    }
                }
                return [
                    'id'      => $o->id,
                    'number'  => $o->number,
                    'status'  => $o->status,
                    'total'   => number_format((float) $o->total, 2, ',', '.'),
                    'data'    => $data,
                    'factura' => $factura,
                    'sincron' => $o->winmentor_sync_status,
                ];
            })->all();
        });
    }

    /**
     * Top produse cumpărate de client (agregat din TOT istoricul de vânzări local,
     * pe toate identitățile lui: CUI + part_id intern + cod extern).
     */
    public static function topProducts(Customer $record, string $period = 'all', int $limit = 30): array
    {
        return Cache::remember("cust_topprod_{$record->id}_{$period}", 600, function () use ($record, $period, $limit) {
            $ids = self::identities($record);
            if (empty($ids['cuis']) && empty($ids['part_ids'])) {
                return [];
            }

            $cutoff = match ($period) {
                '6m' => now()->subMonths(6),
                '1y' => now()->subYear(),
                default => null,
            };

            $rows = DB::table('winmentor_vanzari_raw')
                ->where(function ($q) use ($ids) {
                    if ($ids['cuis']) $q->orWhereIn('cod_fiscal_client', $ids['cuis']);
                    if ($ids['part_ids']) $q->orWhereIn('part_id', $ids['part_ids']);
                })
                ->when($cutoff, fn ($q) => $q->whereRaw('CONCAT(an, LPAD(luna,2,"0"), LPAD(zi,2,"0")) >= ?', [$cutoff->format('Ymd')]))
                ->whereNotNull('sku')->where('sku', '!=', '')
                ->selectRaw('sku, MAX(uom) uom, SUM(cantitate) cant, SUM(lei_cu_tva) valoare, COUNT(DISTINCT CONCAT(serie_document,nr_factura)) nr_facturi, MAX(CONCAT(an,"-",LPAD(luna,2,"0"),"-",LPAD(zi,2,"0"))) ultima')
                ->groupBy('sku')
                ->orderByDesc('valoare')
                ->limit($limit)
                ->get();

            $prods = DB::table('woo_products')->whereIn('sku', $rows->pluck('sku'))->get(['id', 'sku', 'name'])->keyBy('sku');
            $names = $prods->map(fn ($p) => $p->name);

            return $rows->map(fn ($r) => [
                'sku'       => $r->sku,
                'prod_id'   => $prods[$r->sku]->id ?? null,
                'nume'      => $names[$r->sku] ?? $r->sku,
                'cant'      => (float) $r->cant,
                'uom'       => $r->uom,
                'valoare'   => (float) $r->valoare,
                'nr_facturi'=> (int) $r->nr_facturi,
                'ultima'    => $r->ultima,
            ])->all();
        });
    }

    protected static function topProductsHtml(array $byPeriod): string
    {
        $periods = ['all' => 'Toată perioada', '1y' => 'Ultimul an', '6m' => 'Ultimele 6 luni'];

        if (empty(array_filter($byPeriod))) {
            return '<p class="text-sm text-gray-500">Fără produse în istoricul de vânzări.</p>';
        }

        // Filtrare pe CSS pur (radio + sibling selectors) — fără JS, nu depinde de Alpine
        // (Filament strip-uiește atributele x-*/@ din HTML-ul din TextEntry).
        $uid = 'tp' . substr(md5(uniqid('', true)), 0, 7);
        $radios = $tabs = $panels = $css = '';
        foreach ($periods as $key => $lbl) {
            $radios .= '<input type="radio" name="' . $uid . '" id="' . $uid . '-' . $key . '"' . ($key === 'all' ? ' checked' : '') . '>';
            $tabs   .= '<label for="' . $uid . '-' . $key . '" class="tpl">' . e($lbl) . '</label>';
            $panels .= '<div class="tpp tp-' . $key . '">' . self::renderTopTable($byPeriod[$key] ?? []) . '</div>';
            $css    .= '#' . $uid . '-' . $key . ':checked~.tpt label[for="' . $uid . '-' . $key . '"]{background:#7c3aed;color:#fff}'
                     . '#' . $uid . '-' . $key . ':checked~.tp-' . $key . '{display:block}';
        }

        return '<div class="' . $uid . '">'
            . '<style>'
            . '.' . $uid . '>input{position:absolute;opacity:0;width:0;height:0}'
            . '.' . $uid . ' .tpp{display:none}'
            . '.' . $uid . ' .tpl{display:inline-block;border-radius:9999px;padding:4px 12px;font-size:12px;font-weight:600;cursor:pointer;margin-right:6px;background:#f3f4f6;color:#374151;user-select:none}'
            . $css
            . '</style>'
            . $radios
            . '<div class="tpt" style="margin-bottom:10px">' . $tabs . '</div>'
            . $panels
            . '</div>';
    }

    protected static function renderTopTable(array $prods): string
    {
        if (empty($prods)) {
            return '<p style="font-size:13px;color:#9ca3af;padding:8px">Fără produse în această perioadă.</p>';
        }
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        $maxVal = max(array_map(fn ($p) => $p['valoare'], $prods)) ?: 1;
        $rows = '';
        foreach ($prods as $p) {
            $pct = round($p['valoare'] / $maxVal * 100);
            $url = ! empty($p['prod_id']) ? WooProductResource::getUrl('view', ['record' => $p['prod_id']]) : null;
            $nume = e(\Illuminate\Support\Str::limit($p['nume'], 50));
            $numeCell = $url
                ? '<a href="' . $url . '" style="color:#6b21a8;text-decoration:none;font-weight:500">' . $nume . ' ↗</a>'
                : $nume;
            $rows .= '<tr style="border-bottom:1px solid #f3f4f6">'
                . '<td style="padding:6px 8px">' . $numeCell
                    . '<div style="height:4px;background:#eef2f7;border-radius:2px;margin-top:4px"><div style="height:4px;width:' . $pct . '%;background:#7c3aed;border-radius:2px"></div></div></td>'
                . '<td style="padding:6px 8px;text-align:right;white-space:nowrap;color:#6b7280">' . e($fmt($p['cant'])) . ' ' . e($p['uom']) . '</td>'
                . '<td style="padding:6px 8px;text-align:right;white-space:nowrap;font-weight:700">' . e($fmt($p['valoare'])) . ' lei</td>'
                . '<td style="padding:6px 8px;text-align:right;color:#9ca3af">' . $p['nr_facturi'] . '×</td>'
                . '</tr>';
        }
        return '<div style="max-height:340px;overflow-y:auto;border:1px solid #eceff3;border-radius:8px">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead style="position:sticky;top:0;background:#fff;box-shadow:0 1px 0 #e5e7eb"><tr style="text-align:left;color:#6b7280">'
            . '<th style="padding:6px 8px">Produs</th><th style="padding:6px 8px;text-align:right">Cantitate</th>'
            . '<th style="padding:6px 8px;text-align:right">Valoare</th><th style="padding:6px 8px;text-align:right">Facturi</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * Vânzări lunare (ultimele 18 luni) pe toate identitățile clientului — pentru
     * graficul de activitate + semnal de „relație în scădere".
     */
    public static function monthlySales(Customer $record, int $months = 18): array
    {
        return Cache::remember("cust_monthly_{$record->id}", 600, function () use ($record, $months) {
            $ids = self::identities($record);
            if (empty($ids['cuis']) && empty($ids['part_ids'])) {
                return [];
            }

            $raw = DB::table('winmentor_vanzari_raw')
                ->where(function ($q) use ($ids) {
                    if ($ids['cuis']) $q->orWhereIn('cod_fiscal_client', $ids['cuis']);
                    if ($ids['part_ids']) $q->orWhereIn('part_id', $ids['part_ids']);
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

        $lastN = count($luni);
        $bars = '';
        foreach ($luni as $i => $m) {
            $h = (int) round($m['val'] / $max * 120);
            $isRecent = $i >= $lastN - 3; // ultimele 3 luni evidențiate
            if ($m['val'] <= 0) {
                $barStyle = 'background:#eef2f7';
            } elseif ($isRecent) {
                $barStyle = 'background:linear-gradient(180deg,#8b5cf6,#6d28d9)';
            } else {
                $barStyle = 'background:linear-gradient(180deg,#c4b5fd,#a78bfa)';
            }
            $showLabel = $m['val'] > $max * 0.12;
            $bars .= '<div class="ac-col" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;min-width:0">'
                . '<div style="font-size:9px;font-weight:600;color:#7c3aed;white-space:nowrap;height:12px">' . ($showLabel ? $fmt($m['val']) : '') . '</div>'
                . '<div title="' . e($m['ym']) . ': ' . number_format($m['val'], 2, ',', '.') . ' lei" style="width:66%;max-width:22px;height:' . max($h, 3) . 'px;' . $barStyle . ';border-radius:5px 5px 0 0;transition:opacity .15s"></div>'
                . '<div style="font-size:9px;color:' . ($isRecent ? '#4c1d95' : '#9aa5b1') . ';font-weight:' . ($isRecent ? '700' : '400') . ';white-space:nowrap">' . e($m['label']) . '</div>'
                . '</div>';
        }

        return '<style>.ac-col:hover > div:nth-child(2){opacity:.75}</style>'
            . '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">'
            . '<span style="font-size:12px;color:#9aa5b1;font-weight:500">Vânzări lunare (cu TVA) · ultimele ' . count($luni) . ' luni</span>' . $badge . '</div>'
            . '<div style="display:flex;align-items:flex-end;gap:3px;height:160px;border-bottom:1px solid #eef2f7">' . $bars . '</div>';
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
            $nrCell = ! empty($o['id'])
                ? '<a href="' . WooOrderResource::getUrl('view', ['record' => $o['id']]) . '" style="color:#6b21a8;text-decoration:none;font-weight:600">#' . e($o['number']) . ' ↗</a>'
                : '#' . e($o['number']);
            $rows .= '<tr>'
                . '<td style="padding:4px 8px">' . $nrCell . '</td>'
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

    /** Membrii grupului (fișele legate ale aceluiași client real), inclusiv fișa curentă. */
    public static function groupMembers(Customer $record): \Illuminate\Support\Collection
    {
        if (blank($record->customer_group_id)) {
            return collect([$record]);
        }
        return Customer::where('customer_group_id', $record->customer_group_id)->get();
    }

    /**
     * Toate identitățile clientului (agregat pe grup): CUI-uri, part_id-uri WinMentor
     * (id intern + cod extern), telefoane (ultimele 9 cifre), emailuri.
     * @return array{cuis:array, part_ids:array, phones:array, emails:array}
     */
    public static function identities(Customer $record): array
    {
        return Cache::remember("cust_ids_{$record->id}", 600, function () use ($record) {
            $cuis = $partIds = $phones = $emails = [];
            // DOAR fișa curentă — rapoartele sunt PER identitate (firmă vs PF vs altă firmă),
            // nu agregate pe grup. Grupul e doar pentru identificare/navigare.
            foreach ([$record] as $m) {
                $cui = trim((string) ($m->winmentor_id ?: $m->cui ?: ''));
                if ($cui !== '') {
                    $cuis[] = $cui;
                }
                $wmId = self::wmLink($m)['wm_id'] ?? null;
                if ($wmId) {
                    $partIds[] = (string) $wmId;
                    $codEx = (string) (DB::table('winmentor_parteneri')->where('wm_id', $wmId)->value('cod_extern') ?? '');
                    if ($codEx !== '') {
                        $partIds[] = $codEx;
                    }
                }
                if ($m->phone) {
                    $p9 = substr(preg_replace('/\D/', '', $m->phone), -9);
                    if (strlen($p9) === 9) {
                        $phones[] = $p9;
                    }
                }
                if ($m->email) {
                    $emails[] = mb_strtolower(trim($m->email));
                }
            }
            return [
                'cuis'     => array_values(array_unique($cuis)),
                'part_ids' => array_values(array_unique($partIds)),
                'phones'   => array_values(array_unique($phones)),
                'emails'   => array_values(array_unique($emails)),
            ];
        });
    }

    /** Leagă clientul curent cu altul (unește grupurile lor). */
    public static function linkCustomers(Customer $a, int $bId): void
    {
        $b = Customer::find($bId);
        if (! $b || $b->id === $a->id) {
            return;
        }
        $groupId = $a->customer_group_id ?? $b->customer_group_id ?? min($a->id, $b->id);

        $ids = collect([$a->id, $b->id]);
        foreach ([$a->customer_group_id, $b->customer_group_id] as $g) {
            if ($g) {
                $ids = $ids->merge(Customer::where('customer_group_id', $g)->pluck('id'));
            }
        }
        $allIds = $ids->unique()->values();
        Customer::whereIn('id', $allIds)->update(['customer_group_id' => $groupId]);
        self::clearGroupCaches($allIds);
    }

    /** Scoate clientul din grup. */
    public static function unlinkCustomer(Customer $record): void
    {
        $groupId = $record->customer_group_id;
        $record->update(['customer_group_id' => null]);
        if ($groupId) {
            // dacă rămâne un singur membru, îl scoatem și pe el din grup
            $rest = Customer::where('customer_group_id', $groupId)->get();
            if ($rest->count() <= 1) {
                Customer::where('customer_group_id', $groupId)->update(['customer_group_id' => null]);
            }
            self::clearGroupCaches($rest->pluck('id')->push($record->id));
        }
    }

    protected static function clearGroupCaches(\Illuminate\Support\Collection $ids): void
    {
        foreach ($ids as $id) {
            foreach (['cust_ids_', 'cust_wm_v2_', 'cust_online_orders_', 'cust_topprod_', 'cust_monthly_'] as $p) {
                Cache::forget($p . $id);
            }
        }
    }

    /**
     * Tip client FIABIL: după prezența codului fiscal (CUI/CIF), nu după câmpul `type`
     * care e adesea greșit (PF marcate ca „company"). Firmă = are cod fiscal; PF = nu are.
     */
    public static function customerKind(Customer $record): array
    {
        $fiscal = trim((string) ($record->cui ?: ''));
        // winmentor_id ține adesea CUI-ul (nu id intern)
        if ($fiscal === '' && preg_match('/^\s*(RO)?\d{4,}\s*$/i', (string) $record->winmentor_id)) {
            $fiscal = trim((string) $record->winmentor_id);
        }
        $esteFirma = $fiscal !== '';

        return $esteFirma
            ? ['key' => 'pj', 'label' => 'Firmă', 'icon' => '🏢', 'bg' => '#dbeafe', 'fg' => '#1e40af', 'cui' => $fiscal]
            : ['key' => 'pf', 'label' => 'Persoană fizică', 'icon' => '👤', 'bg' => '#f3e8ff', 'fg' => '#6b21a8', 'cui' => null];
    }

    /** KPI-uri sintetice pentru antetul fișei. */
    public static function kpis(Customer $record): array
    {
        return Cache::remember("cust_kpis_{$record->id}", 600, function () use ($record) {
            $ids = self::identities($record);
            $fin = self::wmFinanceCached($record);
            $luni = self::monthlySales($record);

            $soldRaw = $fin ? (float) str_replace(['.', ' ', 'lei', ','], ['', '', '', '.'], (string) ($fin['sold'] ?? '0')) : 0.0;
            $vanzari18 = array_sum(array_column($luni, 'val'));

            $nrFacturi = 0;
            $ultima = null;
            if (! empty($ids['cuis']) || ! empty($ids['part_ids'])) {
                $agg = DB::table('winmentor_vanzari_raw')
                    ->where(function ($q) use ($ids) {
                        if ($ids['cuis']) $q->orWhereIn('cod_fiscal_client', $ids['cuis']);
                        if ($ids['part_ids']) $q->orWhereIn('part_id', $ids['part_ids']);
                    })
                    ->selectRaw('COUNT(DISTINCT CONCAT(serie_document,nr_factura)) n, MAX(CONCAT(an,"-",LPAD(luna,2,"0"),"-",LPAD(zi,2,"0"))) ult')
                    ->first();
                $nrFacturi = (int) ($agg->n ?? 0);
                $ultima = $agg->ult ?? null;
            }

            // Trend (media ult. 3 luni vs 3 anterioare)
            $vals = array_column($luni, 'val');
            $n = count($vals);
            $last3 = $n >= 3 ? array_sum(array_slice($vals, -3)) / 3 : 0;
            $prev3 = $n >= 6 ? array_sum(array_slice($vals, -6, 3)) / 3 : 0;
            $trend = $prev3 > 0 ? round(($last3 - $prev3) / $prev3 * 100) : 0;

            return [
                'sold'      => $fin['sold'] ?? '—',
                'sold_raw'  => $soldRaw,
                'vanzari18' => $vanzari18,
                'nr_facturi'=> $nrFacturi,
                'ultima'    => $ultima,
                'zile_ultima' => $ultima ? (int) \Carbon\Carbon::parse($ultima)->diffInDays(now()) : null,
                'trend'     => $trend,
                'last3'     => $last3,
                'prev3'     => $prev3,
                'online'    => count(self::onlineOrders($record)),
            ];
        });
    }

    protected static function heroHtml(Customer $record): string
    {
        $k = self::kpis($record);
        $fmt = fn ($v) => $v >= 1000 ? number_format($v / 1000, 1, ',', '.') . 'k' : number_format($v, 0, ',', '.');

        // Card sold — colorat după stare
        $soldColor = $k['sold_raw'] > 0.5 ? '#b45309' : ($k['sold_raw'] < -0.5 ? '#2563eb' : '#059669');
        $soldSub   = $k['sold_raw'] > 0.5 ? 'de încasat' : ($k['sold_raw'] < -0.5 ? 'avans client' : 'achitat');

        // Trend / risc
        if ($k['zile_ultima'] !== null && $k['zile_ultima'] > 120 && $k['vanzari18'] > 0) {
            $trendBadge = ['#b91c1c', '#fee2e2', '⚠ Inactiv ' . $k['zile_ultima'] . ' zile'];
        } elseif ($k['last3'] < 1 && $k['prev3'] > 1) {
            $trendBadge = ['#b91c1c', '#fee2e2', '⚠ Fără activitate recentă'];
        } elseif ($k['trend'] <= -30) {
            $trendBadge = ['#92400e', '#fef3c7', '↓ În scădere ' . abs($k['trend']) . '%'];
        } elseif ($k['trend'] >= 30) {
            $trendBadge = ['#166534', '#dcfce7', '↑ În creștere ' . $k['trend'] . '%'];
        } else {
            $trendBadge = ['#3e4c59', '#eef2f7', '→ Stabil'];
        }

        $card = fn ($label, $value, $sub, $color, $icon, $iconBg) =>
            '<div style="flex:1;min-width:150px;background:#fff;border:1px solid #eef2f7;border-radius:16px;padding:14px 16px;box-shadow:0 1px 2px rgba(16,24,40,.04)">'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">'
                . '<span style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600">' . $label . '</span>'
                . '<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:9px;background:' . $iconBg . ';font-size:14px">' . $icon . '</span>'
            . '</div>'
            . '<div style="font-size:22px;font-weight:800;color:' . $color . ';line-height:1.05;letter-spacing:-.01em">' . $value . '</div>'
            . '<div style="font-size:11px;color:#9aa5b1;margin-top:3px">' . $sub . '</div></div>';

        $ultimaTxt = $k['ultima'] ? \Carbon\Carbon::parse($k['ultima'])->format('d.m.Y') : '—';
        $ultimaSub = $k['zile_ultima'] !== null ? 'acum ' . $k['zile_ultima'] . ' zile' : '';

        // Badge tip client (PF / Firmă) — fiabil, după cod fiscal
        $kind = self::customerKind($record);
        $kindBadge = '<div style="margin-bottom:12px">'
            . '<span style="display:inline-flex;align-items:center;gap:5px;background:' . $kind['bg'] . ';color:' . $kind['fg'] . ';border-radius:9999px;padding:5px 14px;font-size:12px;font-weight:700">'
            . $kind['icon'] . ' ' . $kind['label']
            . ($kind['cui'] ? '<span style="opacity:.7;font-weight:500"> · CUI ' . e($kind['cui']) . '</span>' : '')
            . '</span></div>';

        return $kindBadge
            . '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:stretch">'
            . $card('Sold curent', number_format($k['sold_raw'], 2, ',', '.') . ' lei', $soldSub . ' (cu TVA)', $soldColor, '💰', '#fef3c7')
            . $card('Vânzări 18 luni', $fmt($k['vanzari18']) . ' lei', 'cu TVA', '#111827', '📈', '#dcfce7')
            . $card('Facturi', number_format($k['nr_facturi'], 0, ',', '.'), $k['online'] > 0 ? $k['online'] . ' comenzi online' : 'în istoric', '#111827', '🧾', '#e0e7ff')
            . $card('Ultima activitate', $ultimaTxt, $ultimaSub, '#111827', '🕐', '#f3e8ff')
            . '<div style="flex:1;min-width:150px;background:linear-gradient(135deg,' . $trendBadge[1] . ',#fff);border:1px solid ' . $trendBadge[1] . ';border-radius:16px;padding:14px 16px;display:flex;flex-direction:column;justify-content:center;box-shadow:0 1px 2px rgba(16,24,40,.04)">'
                . '<div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:' . $trendBadge[0] . ';opacity:.75;margin-bottom:6px;font-weight:600">Starea relației</div>'
                . '<div style="font-size:15px;font-weight:800;color:' . $trendBadge[0] . '">' . $trendBadge[2] . '</div></div>'
            . '</div>';
    }

    /** Tip document din seria WinMentor: AE = aviz, F* = factură. */
    protected static function docType(?string $serie, ?string $tip = null): array
    {
        $s = strtoupper(trim((string) $serie));
        if (str_starts_with($s, 'AE') || stripos((string) $tip, 'aviz') !== false) {
            return ['label' => 'Aviz', 'icon' => '🚚', 'bg' => '#fef3c7', 'fg' => '#92400e'];
        }
        return ['label' => 'Factură', 'icon' => '📄', 'bg' => '#dbeafe', 'fg' => '#1e40af'];
    }

    protected static function linkedMembersHtml(Customer $record): string
    {
        $fmt = fn ($v) => $v >= 1000 ? number_format($v / 1000, 1, ',', '.') . 'k' : number_format($v, 0, ',', '.');
        $cards = '';
        foreach (self::groupMembers($record) as $m) {
            $isSelf = $m->id === $record->id;
            $url = self::getUrl('view', ['record' => $m->id]);
            $kind = self::customerKind($m);
            $k = self::kpis($m); // sold + vânzări PROPRII acestei fișe
            $cards .= '<a href="' . $url . '" style="display:block;border:1px solid ' . ($isSelf ? '#7c3aed' : '#e5e7eb') . ';border-radius:10px;padding:10px 14px;margin:0 8px 8px 0;text-decoration:none;background:' . ($isSelf ? '#f5f3ff' : '#fff') . ';flex:1;min-width:200px">'
                . '<div style="font-weight:700;color:#111827;font-size:13px">' . e($m->name) . ($isSelf ? ' <span style="color:#7c3aed;font-size:10px">● aici</span>' : ' <span style="color:#9ca3af;font-size:11px">↗</span>') . '</div>'
                . '<div style="margin:3px 0 6px"><span style="background:' . $kind['bg'] . ';color:' . $kind['fg'] . ';border-radius:9999px;padding:1px 8px;font-size:10px;font-weight:600">' . $kind['icon'] . ' ' . $kind['label'] . '</span></div>'
                . '<div style="display:flex;gap:14px">'
                    . '<div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase">Sold</span><br><span style="font-weight:700;color:' . ($k['sold_raw'] > 0.5 ? '#b45309' : '#059669') . '">' . number_format($k['sold_raw'], 0, ',', '.') . ' lei</span></div>'
                    . '<div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase">Vânzări 18l</span><br><span style="font-weight:700;color:#111827">' . $fmt($k['vanzari18']) . ' lei</span></div>'
                . '</div></a>';
        }
        return '<p style="font-size:11px;color:#9ca3af;margin:0 0 8px">Aceeași entitate reală, fișe separate. Rapoartele de mai jos sunt DOAR pentru fișa curentă — click pe alta pentru raportul ei.</p>'
            . '<div style="display:flex;flex-wrap:wrap">' . $cards . '</div>';
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

        $ids = self::identities($record);
        if (empty($ids['cuis']) && empty($ids['part_ids'])) {
            return self::$salesMemo[$record->id] = [];
        }

        // Match după CUI (PJ) SAU part_id (ID intern SAU legacy) — pe toate identitățile grupului.
        $rows = DB::table('winmentor_vanzari_raw')
            ->where(function ($q) use ($ids) {
                if ($ids['cuis']) {
                    $q->orWhereIn('cod_fiscal_client', $ids['cuis']);
                }
                if ($ids['part_ids']) {
                    $q->orWhereIn('part_id', $ids['part_ids']);
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

        $facturi = array_slice(array_values($byFact), 0, 150);

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
            $ids     = self::identities($record); // agregat pe grup
            $partIds = $ids['part_ids'];
            if (empty($partIds)) {
                return null;
            }
            $wmId = self::wmLink($record)['wm_id'] ?? ($partIds[0] ?? '');

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
                ->whereIn('partener_wm_id', $partIds)
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

    protected static function facturiHtml(array $facturi, ?Customer $record = null): string
    {
        if (empty($facturi)) {
            return '<p class="text-sm text-gray-500">Nu sunt facturi de încasat.</p>';
        }
        usort($facturi, fn ($a, $b) => strcmp(
            preg_replace('/(\d{2})\.(\d{2})\.(\d{4})/', '$3$2$1', $a['dataDocument'] ?? ''),
            preg_replace('/(\d{2})\.(\d{2})\.(\d{4})/', '$3$2$1', $b['dataDocument'] ?? '')
        ));

        // Liniile (produsele) fiecărei facturi deschise — o singură interogare
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        $linesByNr = [];
        if ($record) {
            $ids = self::identities($record);
            $nrs = array_values(array_filter(array_map(fn ($f) => $f['nrDocument'] ?? null, $facturi)));
            if ($nrs && ($ids['cuis'] || $ids['part_ids'])) {
                $raw = DB::table('winmentor_vanzari_raw')
                    ->where(function ($q) use ($ids) {
                        if ($ids['cuis']) $q->orWhereIn('cod_fiscal_client', $ids['cuis']);
                        if ($ids['part_ids']) $q->orWhereIn('part_id', $ids['part_ids']);
                    })
                    ->whereIn('nr_factura', $nrs)->whereNotNull('sku')
                    ->get(['nr_factura', 'sku', 'cantitate', 'uom', 'pret', 'lei_cu_tva']);
                $names = DB::table('woo_products')->whereIn('sku', $raw->pluck('sku'))->get(['id', 'sku', 'name'])->keyBy('sku');
                foreach ($raw as $r) {
                    $linesByNr[$r->nr_factura][] = [
                        'nume' => $names[$r->sku]->name ?? $r->sku, 'prod_id' => $names[$r->sku]->id ?? null,
                        'cant' => $r->cantitate, 'uom' => $r->uom, 'pret' => $r->pret, 'val' => $r->lei_cu_tva,
                    ];
                }
            }
        }

        $uid = 'fi' . substr(md5(uniqid('', true)), 0, 7);
        $grid = 'display:grid;grid-template-columns:110px 1fr 92px 110px 92px;align-items:center;gap:8px';
        $total = 0.0; $areStorno = false; $rows = '';
        foreach ($facturi as $f) {
            $rest = (float) str_replace(['.', ','], ['', '.'], (string) ($f['rest'] ?? '0'));
            $total += $rest;
            $negativ = $rest < 0;
            $areStorno = $areStorno || $negativ;
            $dt = self::docType('', $f['tip'] ?? '');
            $tipBadge = '<span style="display:inline-flex;gap:3px;background:' . ($negativ ? '#fee2e2' : $dt['bg']) . ';color:' . ($negativ ? '#b91c1c' : $dt['fg']) . ';border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap">' . ($negativ ? '↩ Storno' : $dt['icon'] . ' ' . $dt['label']) . '</span>';
            $lines = $linesByNr[$f['nrDocument'] ?? ''] ?? [];
            $hasLines = $lines !== [];

            $lineTable = '';
            foreach ($lines as $ln) {
                $purl = $ln['prod_id'] ? WooProductResource::getUrl('view', ['record' => $ln['prod_id']]) : null;
                $nume = e(\Illuminate\Support\Str::limit($ln['nume'], 55));
                $lineTable .= '<div style="display:grid;grid-template-columns:1fr 92px 100px;gap:8px;padding:4px 10px 4px 24px;font-size:12px;border-top:1px solid #eef1f4">'
                    . '<span style="color:#374151">' . ($purl ? '<a href="' . $purl . '" style="color:#6b21a8;text-decoration:none">' . $nume . ' ↗</a>' : $nume) . '</span>'
                    . '<span style="text-align:right;color:#6b7280">' . e($fmt($ln['cant'])) . ' ' . e($ln['uom']) . '</span>'
                    . '<span style="text-align:right;font-weight:600">' . e($fmt($ln['val'])) . '</span></div>';
            }

            $summary = '<summary style="' . $grid . ';padding:8px 10px">'
                . '<span>' . ($hasLines ? '<span class="arw"></span> ' : '') . $tipBadge . '</span>'
                . '<span style="font-family:DejaVu Sans Mono,monospace;font-weight:600;color:#111827">' . e($f['nrDocument'] ?? '') . '</span>'
                . '<span style="color:#52606d;font-size:12px">' . e($f['dataDocument'] ?? '') . '</span>'
                . '<span style="text-align:right;font-weight:700;color:' . ($negativ ? '#dc2626' : '#111827') . '">' . e($f['rest'] ?? '') . '</span>'
                . '<span style="color:#52606d;font-size:12px;text-align:right">' . e($f['dataScadenta'] ?? '—') . '</span>'
                . '</summary>';

            $rows .= $hasLines
                ? '<details class="fhrow">' . $summary . '<div style="background:#fafbfc">' . $lineTable . '</div></details>'
                : '<div class="fhrow">' . $summary . '</div>';
        }

        $hint = $areStorno
            ? '<p style="font-size:11px;color:#92400e;margin-top:6px">↩ Stornourile (rest negativ) anulează facturi din listă — după compensare în Mentor dispar amândouă din sold.</p>'
            : '';

        return '<style>'
            . '.' . $uid . ' .fhrow{border-top:1px solid #f1f3f5}'
            . '.' . $uid . ' summary{list-style:none;cursor:pointer}.' . $uid . ' summary::-webkit-details-marker{display:none}'
            . '.' . $uid . ' summary:hover{background:#faf9ff}.' . $uid . ' details[open]>summary{background:#f5f3ff}'
            . '.' . $uid . ' .arw::before{content:"▸";color:#9ca3af}.' . $uid . ' details[open] .arw::before{content:"▾";color:#7c3aed}'
            . '</style>'
            . '<div class="' . $uid . '">'
            . '<div style="' . $grid . ';padding:6px 10px;font-size:10px;text-transform:uppercase;color:#9aa5b1;font-weight:600;border-bottom:1px solid #eceff3">'
            . '<span>Tip</span><span>Nr. doc</span><span>Dată</span><span style="text-align:right">Rest</span><span style="text-align:right">Scadență</span></div>'
            . '<div style="max-height:360px;overflow-y:auto">' . $rows . '</div>'
            . '<div style="' . $grid . ';padding:8px 10px;font-weight:700;border-top:2px solid #eceff3">'
            . '<span></span><span>Total rest</span><span></span><span style="text-align:right">' . number_format($total, 2, ',', '.') . '</span><span></span></div>'
            . '</div>' . $hint;
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
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        $uid = 'fh' . substr(md5(uniqid('', true)), 0, 7);
        $grid = 'display:grid;grid-template-columns:1fr 92px 108px 92px 108px;align-items:center;gap:8px';
        $nrFacturi = 0; $nrAvize = 0; $total = 0.0; $rows = '';

        foreach ($facturi as $f) {
            $total += (float) str_replace([' ', ','], ['', '.'], (string) ($f['valoare'] ?? ''));
            $negativ = str_starts_with(trim((string) ($f['valoare'] ?? '')), '-');
            $lines = $f['lines'] ?? [];
            $dt = self::docType($f['serie'] ?? '', $f['tip'] ?? '');
            $dt['label'] === 'Aviz' ? $nrAvize++ : $nrFacturi++;

            $lineTable = '';
            foreach ($lines as $ln) {
                $purl = ! empty($ln['prod_id'] ?? null) ? WooProductResource::getUrl('view', ['record' => $ln['prod_id']]) : null;
                $nume = e(\Illuminate\Support\Str::limit($ln['nume'] ?? $ln['sku'], 60));
                $numeCell = $purl ? '<a href="' . $purl . '" style="color:#6b21a8;text-decoration:none">' . $nume . ' ↗</a>' : $nume;
                $lineTable .= '<div style="display:grid;grid-template-columns:1fr 92px 80px 100px;gap:8px;padding:4px 10px 4px 30px;font-size:12px;border-top:1px solid #eef1f4">'
                    . '<span style="color:#374151">' . $numeCell . '</span>'
                    . '<span style="text-align:right;color:#6b7280">' . e($fmt($ln['cant'])) . ' ' . e($ln['uom'] ?? '') . '</span>'
                    . '<span style="text-align:right;color:#6b7280">' . e($fmt($ln['pret'])) . '</span>'
                    . '<span style="text-align:right;color:#111827;font-weight:600">' . e($fmt($ln['val'])) . '</span></div>';
            }

            $onlineBadge = ! empty($f['online'])
                ? ' <span style="background:#ede9fe;color:#6b21a8;border-radius:5px;padding:1px 7px;font-size:10px;font-weight:700;white-space:nowrap">🛒 #' . e($f['online']) . '</span>'
                : '';
            $tipBadge = '<span style="display:inline-flex;align-items:center;gap:3px;background:' . $dt['bg'] . ';color:' . $dt['fg'] . ';border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap">' . $dt['icon'] . ' ' . $dt['label'] . '</span>';
            $hasLines = $lines !== [];

            $summary = '<summary style="' . $grid . ';padding:9px 10px">'
                . '<span style="min-width:0;overflow:hidden">' . ($hasLines ? '<span class="arw"></span> ' : '<span style="display:inline-block;width:14px"></span>') . '<span style="font-weight:600;color:#111827;font-family:DejaVu Sans Mono,monospace">' . e($f['nr'] ?? '') . '</span>' . $onlineBadge . '</span>'
                . '<span style="color:#52606d;font-size:12px">' . e($f['data'] ?? '') . '</span>'
                . '<span>' . $tipBadge . '</span>'
                . '<span style="color:#52606d;font-size:12px">' . e($f['scadenta'] ?? '—') . '</span>'
                . '<span style="text-align:right;font-weight:700;color:' . ($negativ ? '#dc2626' : '#111827') . '">' . e($f['valoare'] ?? '') . '</span>'
                . '</summary>';

            $rows .= $hasLines
                ? '<details class="fhrow">' . $summary
                    . '<div style="background:#fafbfc"><div style="display:grid;grid-template-columns:1fr 92px 80px 100px;gap:8px;padding:5px 10px 5px 30px;font-size:10px;text-transform:uppercase;color:#9aa5b1;font-weight:600"><span>Produs</span><span style="text-align:right">Cant.</span><span style="text-align:right">Preț</span><span style="text-align:right">Valoare</span></div>' . $lineTable . '</div></details>'
                : '<div class="fhrow">' . $summary . '</div>';
        }

        $totalFmt = number_format($total, 2, ',', '.');
        return '<style>'
            . '.' . $uid . ' .fhrow{border-top:1px solid #f1f3f5}'
            . '.' . $uid . ' summary{list-style:none;cursor:pointer;transition:background .1s}'
            . '.' . $uid . ' summary::-webkit-details-marker{display:none}'
            . '.' . $uid . ' summary:hover{background:#faf9ff}'
            . '.' . $uid . ' details[open]>summary{background:#f5f3ff}'
            . '.' . $uid . ' .arw::before{content:"▸";color:#9ca3af}'
            . '.' . $uid . ' details[open] .arw::before{content:"▾";color:#7c3aed}'
            . '</style>'
            . '<div class="' . $uid . '">'
            . '<div style="' . $grid . ';padding:6px 10px;font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#9aa5b1;font-weight:600;border-bottom:1px solid #eceff3">'
            . '<span>Nr. doc · click = produse</span><span>Dată</span><span>Tip</span><span>Scadență</span><span style="text-align:right">Valoare</span></div>'
            . '<div style="max-height:480px;overflow-y:auto;border:1px solid #eceff3;border-top:none;border-radius:0 0 10px 10px">' . $rows . '</div>'
            . '<div style="' . $grid . ';padding:9px 10px;font-weight:700;border-top:2px solid #eceff3;font-size:13px">'
            . '<span>📄 ' . $nrFacturi . ' facturi · 🚚 ' . $nrAvize . ' avize</span><span></span><span></span><span></span><span style="text-align:right">' . e($totalFmt) . ' lei</span></div>'
            . '</div>';
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
        return '<div style="max-height:360px;overflow-y:auto;border:1px solid #eceff3;border-radius:8px">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead style="position:sticky;top:0;background:#fff;box-shadow:0 1px 0 #e5e7eb"><tr style="text-align:left">'
            . '<th style="padding:4px 8px">Dată</th><th style="padding:4px 8px">Document</th>'
            . '<th style="padding:4px 8px;text-align:right">Sumă</th><th style="padding:4px 8px">Facturi</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr style="position:sticky;bottom:0;background:#fff;border-top:1px solid #ddd;font-weight:600">'
            . '<td style="padding:4px 8px" colspan="2">Total încasări (' . count($incasari) . ')</td>'
            . '<td style="padding:4px 8px;text-align:right">' . e($totalFmt) . '</td><td></td>'
            . '</tr></tfoot></table></div>';
    }
}
