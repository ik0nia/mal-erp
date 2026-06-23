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

            Section::make('Încasări')
                ->description(fn (Customer $record): ?string => self::wmFinanceCached($record)['interval'] ?? null)
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
     * Legătura client → partener WinMentor (RAPID, doar DB locală, fără COM).
     * Rezolvă ID-ul intern din oglinda winmentor_parteneri după CUI. Safe la randare.
     */
    public static function wmLink(Customer $record): array
    {
        $cui = trim((string) ($record->winmentor_id ?: $record->cui ?: ''));
        if ($cui === '') {
            return ['asociat' => false, 'cui' => ''];
        }

        $digits = preg_replace('/\D/', '', $cui);
        $row = DB::table('winmentor_parteneri')
            ->where('cod_fiscal', $cui)
            ->when($digits !== '', fn ($q) => $q->orWhereRaw("REPLACE(REPLACE(UPPER(cod_fiscal), ' ', ''), 'RO', '') = ?", [$digits]))
            ->first();

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

        // Match după CUI (PJ) SAU part_id (ID intern, prinde și PF fără cod fiscal).
        $rows = DB::table('winmentor_vanzari_raw')
            ->where(function ($q) use ($cui, $wmId) {
                if ($cui !== '') {
                    $q->orWhere('cod_fiscal_client', $cui);
                }
                if ($wmId) {
                    $q->orWhere('part_id', $wmId);
                }
            })
            ->select('serie_document', 'nr_factura', 'data_emitere', 'data_scadenta', 'tip_document', 'valoare_factura', 'an', 'luna', 'zi')
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
                ];
            }
        }

        return self::$salesMemo[$record->id] = array_slice(array_values($byFact), 0, 60);
    }

    /**
     * Date financiare WinMentor din cache (fără apel COM). null = neîncărcate încă.
     */
    public static function wmFinanceCached(Customer $record): ?array
    {
        return Cache::get("cust_wm_{$record->id}");
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

        // Interval pentru încasări: 1 ian. anul trecut → luna curentă (acoperă restanțe).
        $an1 = (int) now()->subYear()->year;
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
        $rows = '';
        foreach ($facturi as $f) {
            $rows .= '<tr>'
                . '<td style="padding:4px 8px">' . e($f['tip'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['nrDocument'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['dataDocument'] ?? '') . '</td>'
                . '<td style="padding:4px 8px;text-align:right">' . e($f['rest'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['dataScadenta'] ?? '') . '</td>'
                . '</tr>';
        }
        return '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="text-align:left;border-bottom:1px solid #ddd">'
            . '<th style="padding:4px 8px">Tip</th><th style="padding:4px 8px">Nr. doc</th>'
            . '<th style="padding:4px 8px">Dată</th><th style="padding:4px 8px;text-align:right">Rest de plată</th>'
            . '<th style="padding:4px 8px">Scadență</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    protected static function sediiHtml(array $sedii): string
    {
        if (empty($sedii)) {
            return '<p class="text-sm text-gray-500">Fără sedii de livrare alternative.</p>';
        }
        $rows = '';
        foreach ($sedii as $s) {
            $rows .= '<tr>'
                . '<td style="padding:4px 8px">' . e($s['denumire'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($s['localitate'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($s['cod_postal'] ?? '') . '</td>'
                . '</tr>';
        }
        return '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="text-align:left;border-bottom:1px solid #ddd">'
            . '<th style="padding:4px 8px">Denumire sediu</th><th style="padding:4px 8px">Localitate</th>'
            . '<th style="padding:4px 8px">Cod poștal</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    protected static function salesHtml(array $facturi): string
    {
        if (empty($facturi)) {
            return '<p class="text-sm text-gray-500">Nu există facturi pentru acest client.</p>';
        }
        $rows = '';
        $total = 0.0;
        foreach ($facturi as $f) {
            $val = (string) ($f['valoare'] ?? '');
            $total += (float) str_replace([' ', ','], ['', '.'], $val);
            $rows .= '<tr>'
                . '<td style="padding:4px 8px">' . e(trim(($f['serie'] ?? '') . ' ' . ($f['nr'] ?? ''))) . '</td>'
                . '<td style="padding:4px 8px">' . e($f['data'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['tip'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($f['scadenta'] ?? '') . '</td>'
                . '<td style="padding:4px 8px;text-align:right">' . e($val) . '</td>'
                . '</tr>';
        }
        $totalFmt = number_format($total, 2, ',', '.');
        return '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="text-align:left;border-bottom:1px solid #ddd">'
            . '<th style="padding:4px 8px">Factură</th><th style="padding:4px 8px">Dată</th>'
            . '<th style="padding:4px 8px">Tip</th><th style="padding:4px 8px">Scadență</th>'
            . '<th style="padding:4px 8px;text-align:right">Valoare</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr style="border-top:1px solid #ddd;font-weight:600">'
            . '<td style="padding:4px 8px" colspan="4">Total (' . count($facturi) . ' facturi)</td>'
            . '<td style="padding:4px 8px;text-align:right">' . e($totalFmt) . '</td>'
            . '</tr></tfoot></table>';
    }

    protected static function incasariHtml(array $incasari): string
    {
        if (empty($incasari)) {
            return '<p class="text-sm text-gray-500">Nu sunt încasări în intervalul selectat.</p>';
        }
        $rows = '';
        $total = 0.0;
        foreach ($incasari as $i) {
            $suma = (string) ($i['suma'] ?? '');
            $total += (float) str_replace([' ', '.', ','], ['', '', '.'], $suma);
            $detalii = array_values(array_filter(explode('~', (string) ($i['detaliiFacturi'] ?? ''))));
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
