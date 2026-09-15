<?php

namespace App\Filament\App\Resources;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;

use App\Filament\App\Resources\SupplierResource\Pages;
use App\Filament\App\Resources\SupplierResource\RelationManagers\ContactsRelationManager;
use App\Filament\App\Resources\SupplierResource\RelationManagers\EmailsRelationManager;
use App\Filament\App\Resources\SupplierResource\RelationManagers\ProductsRelationManager;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WooProduct;
use Filament\Forms;
use Filament\Infolists;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class SupplierResource extends Resource
{
    use ChecksRolePermissions, HasDynamicNavSort;
    protected static ?string $model = Supplier::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Achiziții';

    protected static ?string $navigationLabel = 'Furnizori';

    protected static ?string $modelLabel = 'Furnizor';

    protected static ?string $pluralModelLabel = 'Furnizori';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make('Informații generale')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nume furnizor')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('website_url')
                        ->label('Website')
                        ->url()
                        ->maxLength(255)
                        ->prefix('https://')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('address')
                        ->label('Adresă')
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Activ')
                        ->default(true)
                        ->columnSpanFull(),
                ]),

            \Filament\Schemas\Components\Section::make('Date fiscale și bancare')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('vat_number')
                        ->label('CUI / CIF')
                        ->maxLength(50),
                    Forms\Components\TextInput::make('reg_number')
                        ->label('Nr. Reg. Com.')
                        ->maxLength(50),
                    Forms\Components\TextInput::make('bank_account')
                        ->label('IBAN')
                        ->maxLength(50),
                    Forms\Components\TextInput::make('bank_name')
                        ->label('Bancă')
                        ->maxLength(255),
                ]),

            \Filament\Schemas\Components\Section::make('Configurare achiziții')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('buyers')
                        ->label('Responsabili achiziții (Buyers)')
                        ->relationship('buyers', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->placeholder('Neasignat'),

                    Forms\Components\TextInput::make('po_approval_threshold')
                        ->label('Plafon maxim PO fără aprobare (RON)')
                        ->numeric()
                        ->minValue(0)
                        ->nullable()
                        ->placeholder('Fără plafon (aprobat automat)'),
                ]),

            \Filament\Schemas\Components\Section::make('Notițe')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->label('')
                        ->rows(3),
                ]),

            \Filament\Schemas\Components\Section::make('Condiții comerciale')
                ->columnSpanFull()
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->collapsed()
                ->schema([
                    \Filament\Schemas\Components\Tabs::make()
                        ->tabs([
                            // ── Tab 1: Livrare ──────────────────────────────
                            \Filament\Schemas\Components\Tabs\Tab::make('Livrare')
                                ->icon('heroicon-o-truck')
                                ->schema([
                                    \Filament\Schemas\Components\Grid::make(3)->schema([
                                        Forms\Components\TextInput::make('conditions.delivery.lead_days_standard')
                                            ->label('Lead time standard (zile)')
                                            ->helperText('Câte zile lucrătoare trec de la plasarea comenzii până la livrare în condiții normale.')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\TextInput::make('conditions.delivery.lead_days_urgent')
                                            ->label('Lead time urgent (zile)')
                                            ->helperText('Termenul de livrare accelerată (dacă furnizorul oferă această opțiune).')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\TextInput::make('conditions.delivery.order_cutoff_time')
                                            ->label('Oră limită comandă')
                                            ->helperText('Ora până la care o comandă plasată azi este procesată tot azi.')
                                            ->placeholder('ex: 14:00'),
                                    ]),
                                    Forms\Components\CheckboxList::make('conditions.delivery.order_days')
                                        ->label('Zile preluare comenzi')
                                        ->helperText('Zilele în care furnizorul acceptă și procesează comenzi noi.')
                                        ->options(['mon'=>'Luni','tue'=>'Marți','wed'=>'Miercuri','thu'=>'Joi','fri'=>'Vineri','sat'=>'Sâmbătă','sun'=>'Duminică'])
                                        ->columns(7)
                                        ->columnSpanFull(),
                                    Forms\Components\CheckboxList::make('conditions.delivery.delivery_days')
                                        ->label('Zile livrare')
                                        ->helperText('Zilele în care furnizorul efectuează livrări fizice.')
                                        ->options(['mon'=>'Luni','tue'=>'Marți','wed'=>'Miercuri','thu'=>'Joi','fri'=>'Vineri','sat'=>'Sâmbătă','sun'=>'Duminică'])
                                        ->columns(7)
                                        ->columnSpanFull(),
                                    Forms\Components\Toggle::make('conditions.delivery.pickup_available')
                                        ->label('Ridicare din depozit propriu')
                                        ->helperText('Poți ridica marfa direct de la depozitul furnizorului, fără a aștepta livrarea.')
                                        ->live(),
                                    Forms\Components\TextInput::make('conditions.delivery.pickup_address')
                                        ->label('Adresă depozit ridicare')
                                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => (bool) $get('conditions.delivery.pickup_available'))
                                        ->columnSpanFull(),
                                    Forms\Components\Textarea::make('conditions.delivery.note')
                                        ->label('Note livrare')
                                        ->rows(2)->columnSpanFull(),
                                ]),

                            // ── Tab 2: Comandă ───────────────────────────────
                            \Filament\Schemas\Components\Tabs\Tab::make('Comandă')
                                ->icon('heroicon-o-shopping-bag')
                                ->schema([
                                    Forms\Components\CheckboxList::make('conditions.ordering.methods')
                                        ->label('Metode de plasare comenzi')
                                        ->helperText('EDI = schimb automat de date între sistemele informatice (pentru furnizori mari cu integrare software).')
                                        ->options([
                                            'email'       => 'Email',
                                            'phone'       => 'Telefon',
                                            'portal'      => 'Portal web',
                                            'whatsapp'    => 'WhatsApp',
                                            'in_person'   => 'Față în față',
                                            'edi'         => 'EDI',
                                        ])
                                        ->columns(3)
                                        ->columnSpanFull(),
                                    \Filament\Schemas\Components\Grid::make(2)->schema([
                                        Forms\Components\Select::make('conditions.ordering.preferred_method')
                                            ->label('Metodă preferată')
                                            ->helperText('Metoda pe care furnizorul o recomandă pentru a primi comenzile cel mai rapid.')
                                            ->options([
                                                'email'     => 'Email',
                                                'phone'     => 'Telefon',
                                                'portal'    => 'Portal web',
                                                'whatsapp'  => 'WhatsApp',
                                                'in_person' => 'Față în față',
                                                'edi'       => 'EDI',
                                            ])
                                            ->native(false)->nullable(),
                                        Forms\Components\TextInput::make('conditions.ordering.portal_url')
                                            ->label('URL portal comenzi')
                                            ->helperText('Adresa web a platformei online unde se plasează comenzile.')
                                            ->url()->nullable(),
                                        Forms\Components\TextInput::make('conditions.ordering.order_email')
                                            ->label('Email comenzi')
                                            ->helperText('Adresa de email dedicată exclusiv pentru trimiterea comenzilor (poate fi diferită de emailul general al furnizorului).')
                                            ->email()->nullable(),
                                        Forms\Components\TextInput::make('conditions.ordering.order_phone')
                                            ->label('Telefon comenzi')
                                            ->tel()->nullable(),
                                        Forms\Components\TextInput::make('conditions.ordering.order_whatsapp')
                                            ->label('WhatsApp comenzi')
                                            ->tel()->nullable(),
                                        Forms\Components\TextInput::make('conditions.ordering.portal_username')
                                            ->label('User portal')
                                            ->helperText('Contul/utilizatorul cu care vă autentificați pe portalul furnizorului.')
                                            ->nullable(),
                                    ]),
                                    \Filament\Schemas\Components\Grid::make(3)->schema([
                                        Forms\Components\TextInput::make('conditions.ordering.min_order_value')
                                            ->label('Valoare minimă comandă (RON)')
                                            ->helperText('Sub această valoare furnizorul poate refuza comanda sau aplica o suprataxă.')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\TextInput::make('conditions.ordering.min_order_qty_lines')
                                            ->label('Nr. minim linii')
                                            ->helperText('Numărul minim de produse distincte per comandă.')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\TextInput::make('conditions.ordering.below_minimum_surcharge')
                                            ->label('Suprataxă sub minim (RON)')
                                            ->helperText('Sumă fixă adăugată automat pe factură dacă comanda nu atinge minimul.')
                                            ->numeric()->minValue(0)->nullable(),
                                    ]),
                                    Forms\Components\Textarea::make('conditions.ordering.note')
                                        ->label('Note comenzi')
                                        ->rows(2)->columnSpanFull(),
                                ]),

                            // ── Tab 3: Transport ─────────────────────────────
                            \Filament\Schemas\Components\Tabs\Tab::make('Transport')
                                ->icon('heroicon-o-map-pin')
                                ->schema([
                                    \Filament\Schemas\Components\Grid::make(2)->schema([
                                        Forms\Components\Select::make('conditions.transport.incoterm')
                                            ->label('Incoterm')
                                            ->helperText('Regula care stabilește până unde livrează furnizorul și cine suportă costul/riscul transportului.')
                                            ->options([
                                                'EXW' => 'EXW — Tu ridici marfa de la depozitul furnizorului',
                                                'FCA' => 'FCA — Furnizorul predă la curier; tu plătești transportul',
                                                'CPT' => 'CPT — Furnizorul plătește transportul până la destinație; riscul trece la curier',
                                                'CIP' => 'CIP — Ca CPT, dar cu asigurare de transport inclusă',
                                                'DAP' => 'DAP — Furnizorul livrează la adresa ta; tu plătești vămuirea (import)',
                                                'DDP' => 'DDP — Furnizorul livrează complet: transport + taxe vamale incluse',
                                                'FOB' => 'FOB — Furnizorul pune marfa pe vapor (specific transport maritim)',
                                                'CFR' => 'CFR — Ca FOB, dar furnizorul plătește și nava (fără asigurare)',
                                                'CIF' => 'CIF — Ca CFR, dar cu asigurare maritimă inclusă',
                                            ])
                                            ->native(false)->nullable(),
                                        Forms\Components\Select::make('conditions.transport.transport_paid_by')
                                            ->label('Transport plătit de')
                                            ->helperText('Cine suportă costul efectiv al transportului, indiferent de Incoterm.')
                                            ->options(['supplier'=>'Furnizor','buyer'=>'Cumpărător (noi)','split'=>'Împărțit'])
                                            ->native(false)->nullable(),
                                        Forms\Components\TextInput::make('conditions.transport.free_shipping_threshold')
                                            ->label('Prag transport gratuit (RON)')
                                            ->helperText('Valoarea minimă a comenzii de la care furnizorul oferă transport fără cost suplimentar.')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\TextInput::make('conditions.transport.shipping_cost_fixed')
                                            ->label('Cost fix transport (RON)')
                                            ->helperText('Suma fixă de transport aplicată când comanda este sub pragul de transport gratuit.')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\TextInput::make('conditions.transport.carrier')
                                            ->label('Curier / Transportator')
                                            ->helperText('Firma de curierat sau transport folosită de furnizor (ex: Fan Courier, DHL, transport propriu).'),
                                        Forms\Components\Toggle::make('conditions.transport.insurance_included')
                                            ->label('Asigurare marfă inclusă')
                                            ->helperText('Marfa este asigurată pe durata transportului pe cheltuiala furnizorului.'),
                                    ]),
                                    Forms\Components\Textarea::make('conditions.transport.note')
                                        ->label('Note transport')
                                        ->rows(2)->columnSpanFull(),
                                ]),

                            // ── Tab 4: Plată ─────────────────────────────────
                            \Filament\Schemas\Components\Tabs\Tab::make('Plată')
                                ->icon('heroicon-o-banknotes')
                                ->schema([
                                    Forms\Components\CheckboxList::make('conditions.payment.methods')
                                        ->label('Metode plată acceptate')
                                        ->helperText('Filă CEC = instrument de plată bancar; Bilet la ordin = angajament de plată la o dată viitoare; Compensare = stingerea datoriilor reciproce fără transfer de bani; Factoring = furnizorul cesionează factura unei instituții financiare care îi plătește imediat — tu plătești instituției.')
                                        ->options([
                                            'bank_transfer'  => 'Transfer bancar (OP)',
                                            'cash'           => 'Numerar',
                                            'card'           => 'Card',
                                            'check'          => 'Filă CEC',
                                            'bill_of_ex'     => 'Bilet la ordin',
                                            'direct_debit'   => 'Debitare directă',
                                            'compensare'     => 'Compensare',
                                            'factoring'      => 'Factoring',
                                        ])
                                        ->columns(4)
                                        ->columnSpanFull(),
                                    \Filament\Schemas\Components\Grid::make(3)->schema([
                                        Forms\Components\Select::make('conditions.payment.default_term')
                                            ->label('Termen de plată')
                                            ->helperText('"Net X zile" = plătești factura în maximum X zile de la data emiterii.')
                                            ->options([
                                                'avans_total'   => 'Avans 100% — plătești înainte de livrare',
                                                'avans_partial' => 'Avans parțial — parte înainte, parte după livrare',
                                                'la_livrare'    => 'Plată la livrare — plătești când primești marfa',
                                                'net_7'         => 'Net 7 zile — plătești în max. 7 zile de la factură',
                                                'net_14'        => 'Net 14 zile',
                                                'net_30'        => 'Net 30 zile',
                                                'net_45'        => 'Net 45 zile',
                                                'net_60'        => 'Net 60 zile',
                                                'net_90'        => 'Net 90 zile',
                                                'custom'        => 'Custom — introduci manual numărul de zile',
                                            ])
                                            ->native(false)->nullable()->live(),
                                        Forms\Components\TextInput::make('conditions.payment.net_days')
                                            ->label('Zile termen custom')
                                            ->numeric()->minValue(0)->nullable()
                                            ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('conditions.payment.default_term') === 'custom'),
                                        Forms\Components\TextInput::make('conditions.payment.advance_percent')
                                            ->label('Procent avans (%)')
                                            ->helperText('Procentul din valoarea comenzii care trebuie plătit în avans.')
                                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')->nullable()
                                            ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('conditions.payment.default_term') === 'avans_partial'),
                                        Forms\Components\Select::make('conditions.payment.currency')
                                            ->label('Monedă facturare')
                                            ->helperText('Moneda în care furnizorul emite facturile.')
                                            ->options(['RON'=>'RON','EUR'=>'EUR','USD'=>'USD'])
                                            ->default('RON')->native(false),
                                        Forms\Components\TextInput::make('conditions.payment.early_payment_discount')
                                            ->label('Discount plată anticipată (%)')
                                            ->helperText('Reducere acordată dacă plătești factura mai devreme decât termenul agreat.')
                                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')->nullable(),
                                        Forms\Components\TextInput::make('conditions.payment.early_payment_days')
                                            ->label('Termen discount anticipat (zile)')
                                            ->helperText('Plata trebuie efectuată în acest număr de zile pentru a beneficia de reducere.')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\TextInput::make('conditions.payment.late_penalty_percent')
                                            ->label('Penalitate întârziere (%/zi)')
                                            ->helperText('Procent aplicat zilnic asupra sumei restante după depășirea scadenței.')
                                            ->numeric()->minValue(0)->suffix('%')->nullable(),
                                    ]),
                                    Forms\Components\Textarea::make('conditions.payment.fx_clause')
                                        ->label('Clauză valutară')
                                        ->helperText('Regula de conversie valutară aplicată (ex: factura în EUR, plata la cursul BNR din ziua plății).')
                                        ->rows(2)->nullable()->columnSpanFull(),
                                    Forms\Components\Textarea::make('conditions.payment.note')
                                        ->label('Note plată')
                                        ->rows(2)->columnSpanFull(),
                                ]),

                            // ── Tab 5: Discount comercial ─────────────────────
                            \Filament\Schemas\Components\Tabs\Tab::make('Discount')
                                ->icon('heroicon-o-tag')
                                ->schema([
                                    \Filament\Schemas\Components\Grid::make(3)->schema([
                                        Forms\Components\TextInput::make('conditions.commercial.discount_percent')
                                            ->label('Discount comercial general (%)')
                                            ->helperText('Reducerea fixă aplicată la toate comenzile, negociată cu furnizorul.')
                                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')->nullable(),
                                        Forms\Components\Select::make('conditions.commercial.discount_type')
                                            ->label('Aplicare discount')
                                            ->helperText('Rabat anual = reducere retroactivă acordată la sfârșitul anului dacă ai depășit un prag de achiziții.')
                                            ->options([
                                                'line_item'     => 'Per linie factură — aplicat pe fiecare produs',
                                                'invoice_total' => 'Total factură — aplicat pe suma totală',
                                                'rebate_annual' => 'Rabat anual — reducere retroactivă la final de an',
                                            ])
                                            ->native(false)->nullable(),
                                        Forms\Components\TextInput::make('conditions.commercial.rebate_threshold_value')
                                            ->label('Prag anual rabat (RON)')
                                            ->helperText('Valoarea totală de achiziții pe an de la care se activează rabatul.')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\TextInput::make('conditions.commercial.rebate_percent')
                                            ->label('% rabat la prag')
                                            ->helperText('Procentul din valoarea anuală totală care se returnează sau se deduce.')
                                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')->nullable(),
                                        Forms\Components\TextInput::make('conditions.commercial.quarterly_discount_percent')
                                            ->label('Discount trimestrial (%)')
                                            ->helperText('Reducere acordată trimestrial, de obicei în funcție de volumul de achiziții din trimestrul anterior.')
                                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')->nullable(),
                                        Forms\Components\TextInput::make('conditions.commercial.promotional_discount')
                                            ->label('Discount promoțional (%)')
                                            ->helperText('Reducere temporară, valabilă până la data de mai jos.')
                                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')->nullable(),
                                        Forms\Components\DatePicker::make('conditions.commercial.promotional_until')
                                            ->label('Valabil până la')
                                            ->nullable()->displayFormat('d.m.Y'),
                                    ]),
                                    Forms\Components\Repeater::make('conditions.commercial.volume_discounts')
                                        ->label('Discounturi pe tranșe de volum')
                                        ->helperText('Reduceri progresive în funcție de valoarea comenzii (ex: peste 5.000 RON → 3%, peste 10.000 RON → 5%).')
                                        ->schema([
                                            Forms\Components\TextInput::make('from_value')
                                                ->label('De la valoare (RON)')
                                                ->numeric()->minValue(0)->required(),
                                            Forms\Components\TextInput::make('discount_percent')
                                                ->label('Discount (%)')
                                                ->numeric()->minValue(0)->maxValue(100)->suffix('%')->required(),
                                        ])
                                        ->columns(2)
                                        ->addActionLabel('Adaugă tranșă')
                                        ->defaultItems(0)
                                        ->columnSpanFull(),
                                    Forms\Components\Textarea::make('conditions.commercial.discount_note')
                                        ->label('Note discount')
                                        ->rows(2)->columnSpanFull(),
                                ]),

                            // ── Tab 6: Retur & Garanție ───────────────────────
                            \Filament\Schemas\Components\Tabs\Tab::make('Retur & Garanție')
                                ->icon('heroicon-o-arrow-uturn-left')
                                ->schema([
                                    \Filament\Schemas\Components\Grid::make(2)->schema([
                                        Forms\Components\Toggle::make('conditions.returns.return_allowed')
                                            ->label('Retururi acceptate')
                                            ->helperText('Furnizorul acceptă în principiu returnarea mărfii.')
                                            ->live(),
                                        Forms\Components\Toggle::make('conditions.returns.return_authorization_req')
                                            ->label('Necesită autorizație (RMA)')
                                            ->helperText('RMA = Return Merchandise Authorization. Trebuie să ceri aprobarea furnizorului înainte de a trimite marfa înapoi.'),
                                        Forms\Components\TextInput::make('conditions.returns.return_window_days')
                                            ->label('Ferestră retur (zile)')
                                            ->helperText('Numărul maxim de zile de la recepție în care poți iniția un retur.')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\Select::make('conditions.returns.return_condition')
                                            ->label('Condiție marfă returnată')
                                            ->helperText('Starea în care trebuie să fie marfa pentru a fi acceptată la retur.')
                                            ->options([
                                                'unopened'   => 'Nedeschis / sigilat — ambalajul original intact',
                                                'resalable'  => 'Vandabil — poate fi revândut',
                                                'any'        => 'Orice stare — inclusiv defect',
                                            ])
                                            ->native(false)->nullable(),
                                        Forms\Components\TextInput::make('conditions.returns.return_restocking_fee')
                                            ->label('Comision restocking (%)')
                                            ->helperText('Procent reținut de furnizor din valoarea mărfii returnate, pentru costurile de reprocesare.')
                                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')->nullable(),
                                        Forms\Components\TextInput::make('conditions.returns.return_email')
                                            ->label('Email retururi')
                                            ->helperText('Adresa dedicată pentru solicitări de retur (poate fi diferită de emailul general).')
                                            ->email()->nullable(),
                                    ]),
                                    Forms\Components\Textarea::make('conditions.returns.return_note')
                                        ->label('Procedura de retur')
                                        ->rows(3)->columnSpanFull(),
                                    \Filament\Schemas\Components\Grid::make(2)->schema([
                                        Forms\Components\TextInput::make('conditions.returns.warranty_months')
                                            ->label('Garanție produse (luni)')
                                            ->numeric()->minValue(0)->nullable(),
                                        Forms\Components\Select::make('conditions.returns.warranty_type')
                                            ->label('Tip garanție')
                                            ->options([
                                                'supplier'     => 'Furnizor',
                                                'manufacturer' => 'Producător',
                                                'both'         => 'Ambele',
                                            ])
                                            ->native(false)->nullable(),
                                    ]),
                                    Forms\Components\Textarea::make('conditions.returns.warranty_note')
                                        ->label('Note garanție')
                                        ->rows(2)->columnSpanFull(),
                                ]),
                        ])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Scorecard furnizor: metrici de performanță achiziții (cache 10 min). */
    public static function scorecard(\App\Models\Supplier $record): array
    {
        return \Illuminate\Support\Facades\Cache::remember("sup_scorecard_{$record->id}", 600, function () use ($record) {
            $sid = $record->id;

            // Fill-rate (primit / comandat)
            $items = \Illuminate\Support\Facades\DB::table('purchase_order_items as i')
                ->join('purchase_orders as p', 'p.id', '=', 'i.purchase_order_id')
                ->where('p.supplier_id', $sid)
                ->where('p.status', 'received');
            $ordered  = (float) (clone $items)->sum('quantity');
            $received = (float) (clone $items)->sum('received_quantity');
            $fillRate = $ordered > 0 ? round($received / $ordered * 100, 1) : null;

            // Lead time
            $lead = \App\Models\PurchaseOrder::where('supplier_id', $sid)->whereNotNull('lead_time_days');
            $avgLead = $lead->exists() ? round((float) $lead->avg('lead_time_days'), 1) : null;

            // Volum & fiabilitate
            $pos        = \App\Models\PurchaseOrder::where('supplier_id', $sid);
            $poCount    = (clone $pos)->count();
            $totalValue = (float) (clone $pos)->sum('total_value');
            $rejected   = (clone $pos)->where('status', 'rejected')->count();
            $rejectRate = $poCount > 0 ? round($rejected / $poCount * 100, 1) : 0.0;

            // Stabilitate preț: % înregistrări cu anomalie
            $priceLogs = \Illuminate\Support\Facades\DB::table('product_purchase_price_logs')->where('supplier_id', $sid);
            $priceN    = (clone $priceLogs)->count();
            $anomalies = (clone $priceLogs)->where('has_anomaly', true)->count();
            $anomalyRate = $priceN > 0 ? round($anomalies / $priceN * 100, 1) : null;

            // Notă generală (0-100): fill-rate 40% + lead-time 25% + fiabilitate 20% + preț 15%
            $score = null;
            if ($fillRate !== null) {
                $sLead  = $avgLead !== null ? max(0, 100 - $avgLead * 5) : 70; // 0 zile=100, 20 zile=0
                $sRely  = 100 - $rejectRate;
                $sPrice = $anomalyRate !== null ? max(0, 100 - $anomalyRate * 3) : 80;
                $score  = (int) round($fillRate * 0.40 + $sLead * 0.25 + $sRely * 0.20 + $sPrice * 0.15);
            }
            $grade = $score === null ? '—' : ($score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 45 ? 'D' : 'E'))));

            return compact('fillRate', 'avgLead', 'poCount', 'totalValue', 'rejectRate', 'anomalyRate', 'priceN', 'score', 'grade');
        });
    }

    public static function infolist(Schema $schema): Schema
    {
        $deptLabel = fn ($state) => match ($state) {
            'comercial'     => 'Comercial',
            'comenzi'       => 'Comenzi',
            'director'      => 'Director',
            'contabilitate' => 'Contabilitate / Financiar',
            'logistica'     => 'Logistică',
            'tehnic'        => 'Tehnic / Service',
            'marketing'     => 'Marketing',
            'altul'         => 'Altul',
            default         => null,
        };

        return $schema->schema([
            \Filament\Schemas\Components\Section::make('Scorecard furnizor')
                ->description('Performanță achiziții — fill-rate, livrare, fiabilitate, preț')
                ->columns(6)
                ->columnSpanFull()
                ->visible(fn (\App\Models\Supplier $record): bool => (self::scorecard($record)['poCount'] ?? 0) > 0)
                ->schema([
                    Infolists\Components\TextEntry::make('sc_grade')
                        ->label('Notă generală')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => self::scorecard($record)['grade'] . (self::scorecard($record)['score'] !== null ? ' · ' . self::scorecard($record)['score'] . '/100' : ''))
                        ->badge()->size(TextSize::Large)
                        ->color(fn (\App\Models\Supplier $record): string => match (self::scorecard($record)['grade']) {
                            'A' => 'success', 'B' => 'info', 'C' => 'warning', 'D', 'E' => 'danger', default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('sc_fill')
                        ->label('Fill-rate')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => ($f = self::scorecard($record)['fillRate']) !== null ? $f . '%' : '—')
                        ->badge()
                        ->color(fn (\App\Models\Supplier $record): string => ($f = self::scorecard($record)['fillRate']) === null ? 'gray' : ($f >= 95 ? 'success' : ($f >= 85 ? 'warning' : 'danger'))),
                    Infolists\Components\TextEntry::make('sc_lead')
                        ->label('Lead time mediu')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => ($l = self::scorecard($record)['avgLead']) !== null ? $l . ' zile' : '—'),
                    Infolists\Components\TextEntry::make('sc_po')
                        ->label('Comenzi')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => self::scorecard($record)['poCount'] . ' PO-uri'),
                    Infolists\Components\TextEntry::make('sc_val')
                        ->label('Volum total')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => number_format(self::scorecard($record)['totalValue'], 0, ',', '.') . ' lei'),
                    Infolists\Components\TextEntry::make('sc_reject')
                        ->label('Respinse / anomalii preț')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => self::scorecard($record)['rejectRate'] . '% · ' . (($a = self::scorecard($record)['anomalyRate']) !== null ? $a . '%' : '—'))
                        ->color(fn (\App\Models\Supplier $record): string => self::scorecard($record)['rejectRate'] > 10 ? 'danger' : 'gray'),
                ]),

            \Filament\Schemas\Components\Section::make('Informații generale')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->label('Nume furnizor')
                        ->weight(\Filament\Support\Enums\FontWeight::Bold)
                        ->size(TextSize::Large),

                    Infolists\Components\IconEntry::make('is_active')
                        ->label('Status')
                        ->boolean()
                        ->trueColor('success')
                        ->falseColor('danger'),

                    Infolists\Components\TextEntry::make('website_url')
                        ->label('Website')
                        ->url(fn ($state) => $state)
                        ->openUrlInNewTab()
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('buyers.name')
                        ->label('Responsabili achiziții')
                        ->listWithLineBreaks()
                        ->placeholder('Neasignat'),

                    Infolists\Components\TextEntry::make('address')
                        ->label('Adresă')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            \Filament\Schemas\Components\Section::make('Date fiscale și bancare')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('vat_number')
                        ->label('CUI / CIF')
                        ->placeholder('—')
                        ->copyable()->copyMessage('Copiat!'),

                    Infolists\Components\TextEntry::make('reg_number')
                        ->label('Nr. Reg. Com.')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('bank_account')
                        ->label('IBAN')
                        ->placeholder('—')
                        ->copyable()->copyMessage('Copiat!'),

                    Infolists\Components\TextEntry::make('bank_name')
                        ->label('Bancă')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('po_approval_threshold')
                        ->label('Plafon PO fără aprobare')
                        ->money('RON')
                        ->placeholder('Fără plafon (aprobat automat)'),
                ]),

            \Filament\Schemas\Components\Section::make('Notițe')
                ->collapsible()
                ->schema([
                    Infolists\Components\TextEntry::make('notes')
                        ->label('')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            \Filament\Schemas\Components\Section::make('Condiții comerciale')
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->collapsed()
                ->schema([
                    \Filament\Schemas\Components\Tabs::make()->tabs([

                        \Filament\Schemas\Components\Tabs\Tab::make('Livrare')->icon('heroicon-o-truck')->schema([
                            \Filament\Schemas\Components\Grid::make(3)->schema([
                                Infolists\Components\TextEntry::make('conditions.delivery.lead_days_standard')->label('Lead time standard')->suffix(' zile')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.delivery.lead_days_urgent')->label('Lead time urgent')->suffix(' zile')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.delivery.order_cutoff_time')->label('Oră limită comandă')->placeholder('—'),
                            ]),
                            Infolists\Components\TextEntry::make('conditions.delivery.order_days')
                                ->label('Zile preluare comenzi')
                                ->formatStateUsing(fn ($state) => collect((array)$state)->map(fn($d) => match($d){
                                    'mon'=>'Luni','tue'=>'Marți','wed'=>'Miercuri','thu'=>'Joi',
                                    'fri'=>'Vineri','sat'=>'Sâmbătă','sun'=>'Duminică',default=>$d
                                })->join(', '))
                                ->placeholder('—'),
                            Infolists\Components\TextEntry::make('conditions.delivery.delivery_days')
                                ->label('Zile livrare')
                                ->formatStateUsing(fn ($state) => collect((array)$state)->map(fn($d) => match($d){
                                    'mon'=>'Luni','tue'=>'Marți','wed'=>'Miercuri','thu'=>'Joi',
                                    'fri'=>'Vineri','sat'=>'Sâmbătă','sun'=>'Duminică',default=>$d
                                })->join(', '))
                                ->placeholder('—'),
                            Infolists\Components\IconEntry::make('conditions.delivery.pickup_available')->label('Ridicare depozit propriu')->boolean(),
                            Infolists\Components\TextEntry::make('conditions.delivery.pickup_address')->label('Adresă depozit')->placeholder('—'),
                            Infolists\Components\TextEntry::make('conditions.delivery.note')->label('Note livrare')->placeholder('—')->columnSpanFull(),
                        ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Comandă')->icon('heroicon-o-shopping-bag')->schema([
                            Infolists\Components\TextEntry::make('conditions.ordering.methods')
                                ->label('Metode comandă')
                                ->formatStateUsing(fn ($state) => collect((array)$state)->map(fn($m) => match($m){
                                    'email'=>'Email','phone'=>'Telefon','portal'=>'Portal web',
                                    'whatsapp'=>'WhatsApp','in_person'=>'Față în față','edi'=>'EDI',default=>$m
                                })->join(', '))
                                ->badge()->placeholder('—'),
                            \Filament\Schemas\Components\Grid::make(3)->schema([
                                Infolists\Components\TextEntry::make('conditions.ordering.preferred_method')
                                    ->label('Metodă preferată')
                                    ->formatStateUsing(fn($state)=>match($state){'email'=>'Email','phone'=>'Telefon','portal'=>'Portal','whatsapp'=>'WhatsApp','in_person'=>'Față în față','edi'=>'EDI',default=>$state??'—'})
                                    ->badge()->color('success')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.ordering.order_email')->label('Email comenzi')->icon('heroicon-o-envelope')->url(fn($state)=>$state?"mailto:$state":null)->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.ordering.order_phone')->label('Telefon comenzi')->icon('heroicon-o-phone')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.ordering.order_whatsapp')->label('WhatsApp')->icon('heroicon-o-chat-bubble-left')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.ordering.portal_url')->label('Portal comenzi')->url(fn($state)=>$state)->openUrlInNewTab()->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.ordering.portal_username')->label('User portal')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.ordering.min_order_value')->label('Valoare minimă comandă')->money('RON')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.ordering.min_order_qty_lines')->label('Nr. minim linii')->suffix(' linii')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.ordering.below_minimum_surcharge')->label('Suprataxă sub minim')->money('RON')->placeholder('—'),
                            ]),
                            Infolists\Components\TextEntry::make('conditions.ordering.note')->label('Note comenzi')->placeholder('—')->columnSpanFull(),
                        ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Transport')->icon('heroicon-o-map-pin')->schema([
                            \Filament\Schemas\Components\Grid::make(3)->schema([
                                Infolists\Components\TextEntry::make('conditions.transport.incoterm')->label('Incoterm')->badge()->color('primary')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.transport.transport_paid_by')
                                    ->label('Transport plătit de')
                                    ->formatStateUsing(fn($state)=>match($state){'supplier'=>'Furnizor','buyer'=>'Cumpărător','split'=>'Împărțit',default=>$state??'—'})
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.transport.carrier')->label('Curier / Transportator')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.transport.free_shipping_threshold')->label('Prag transport gratuit')->money('RON')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.transport.shipping_cost_fixed')->label('Cost fix transport')->money('RON')->placeholder('—'),
                                Infolists\Components\IconEntry::make('conditions.transport.insurance_included')->label('Asigurare inclusă')->boolean(),
                            ]),
                            Infolists\Components\TextEntry::make('conditions.transport.note')->label('Note transport')->placeholder('—')->columnSpanFull(),
                        ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Plată')->icon('heroicon-o-banknotes')->schema([
                            Infolists\Components\TextEntry::make('conditions.payment.methods')
                                ->label('Metode plată acceptate')
                                ->formatStateUsing(fn ($state) => collect((array)$state)->map(fn($m) => match($m){
                                    'bank_transfer'=>'Transfer bancar','cash'=>'Numerar','card'=>'Card',
                                    'check'=>'CEC','bill_of_ex'=>'Bilet la ordin','direct_debit'=>'Debitare directă',
                                    'compensare'=>'Compensare','factoring'=>'Factoring',default=>$m
                                })->join(', '))
                                ->placeholder('—'),
                            \Filament\Schemas\Components\Grid::make(3)->schema([
                                Infolists\Components\TextEntry::make('conditions.payment.default_term')
                                    ->label('Termen de plată')
                                    ->formatStateUsing(fn($state)=>match($state){
                                        'avans_total'=>'Avans 100%','avans_partial'=>'Avans parțial',
                                        'la_livrare'=>'La livrare','net_7'=>'Net 7 zile','net_14'=>'Net 14 zile',
                                        'net_30'=>'Net 30 zile','net_45'=>'Net 45 zile','net_60'=>'Net 60 zile',
                                        'net_90'=>'Net 90 zile','custom'=>'Custom',default=>$state??'—'
                                    })
                                    ->badge()->color('info')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.payment.net_days')->label('Zile termen custom')->suffix(' zile')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.payment.advance_percent')->label('Procent avans')->suffix('%')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.payment.currency')->label('Monedă facturare')->badge()->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.payment.early_payment_discount')->label('Discount plată anticipată')->suffix('%')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.payment.early_payment_days')->label('Termen discount anticipat')->suffix(' zile')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.payment.late_penalty_percent')->label('Penalitate întârziere')->suffix('%/zi')->placeholder('—'),
                            ]),
                            Infolists\Components\TextEntry::make('conditions.payment.fx_clause')->label('Clauză valutară')->placeholder('—'),
                            Infolists\Components\TextEntry::make('conditions.payment.note')->label('Note plată')->placeholder('—')->columnSpanFull(),
                        ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Discount')->icon('heroicon-o-tag')->schema([
                            \Filament\Schemas\Components\Grid::make(3)->schema([
                                Infolists\Components\TextEntry::make('conditions.commercial.discount_percent')->label('Discount comercial general')->suffix('%')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.commercial.discount_type')
                                    ->label('Aplicare discount')
                                    ->formatStateUsing(fn($state)=>match($state){'line_item'=>'Per linie','invoice_total'=>'Total factură','rebate_annual'=>'Rabat anual',default=>$state??'—'})
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.commercial.rebate_threshold_value')->label('Prag rabat anual')->money('RON')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.commercial.rebate_percent')->label('% rabat la prag')->suffix('%')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.commercial.quarterly_discount_percent')->label('Discount trimestrial')->suffix('%')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.commercial.promotional_discount')->label('Discount promoțional')->suffix('%')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.commercial.promotional_until')->label('Valabil până la')->date('d.m.Y')->placeholder('—'),
                            ]),
                            Infolists\Components\RepeatableEntry::make('conditions.commercial.volume_discounts')
                                ->label('Tranșe volum')
                                ->schema([
                                    Infolists\Components\TextEntry::make('from_value')->label('De la (RON)')->money('RON'),
                                    Infolists\Components\TextEntry::make('discount_percent')->label('Discount')->suffix('%'),
                                ])
                                ->columns(2)
                                ->placeholder('—'),
                            Infolists\Components\TextEntry::make('conditions.commercial.discount_note')->label('Note discount')->placeholder('—')->columnSpanFull(),
                        ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Retur & Garanție')->icon('heroicon-o-arrow-uturn-left')->schema([
                            \Filament\Schemas\Components\Grid::make(3)->schema([
                                Infolists\Components\IconEntry::make('conditions.returns.return_allowed')->label('Retururi acceptate')->boolean(),
                                Infolists\Components\IconEntry::make('conditions.returns.return_authorization_req')->label('Necesită RMA')->boolean(),
                                Infolists\Components\TextEntry::make('conditions.returns.return_window_days')->label('Ferestră retur')->suffix(' zile')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.returns.return_condition')
                                    ->label('Condiție marfă')
                                    ->formatStateUsing(fn($state)=>match($state){'unopened'=>'Nedeschis/sigilat','resalable'=>'Vandabil','any'=>'Orice stare',default=>$state??'—'})
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.returns.return_restocking_fee')->label('Comision restocking')->suffix('%')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.returns.return_email')->label('Email retururi')->icon('heroicon-o-envelope')->placeholder('—'),
                            ]),
                            Infolists\Components\TextEntry::make('conditions.returns.return_note')->label('Procedura de retur')->placeholder('—')->columnSpanFull(),
                            \Filament\Schemas\Components\Grid::make(3)->schema([
                                Infolists\Components\TextEntry::make('conditions.returns.warranty_months')->label('Garanție produse')->suffix(' luni')->placeholder('—'),
                                Infolists\Components\TextEntry::make('conditions.returns.warranty_type')
                                    ->label('Tip garanție')
                                    ->formatStateUsing(fn($state)=>match($state){'supplier'=>'Furnizor','manufacturer'=>'Producător','both'=>'Ambele',default=>$state??'—'})
                                    ->badge()->placeholder('—'),
                            ]),
                            Infolists\Components\TextEntry::make('conditions.returns.warranty_note')->label('Note garanție')->placeholder('—')->columnSpanFull(),
                        ]),

                    ])->columnSpanFull(),
                ]),

            \Filament\Schemas\Components\Section::make('Situație financiară (WinMentor)')
                ->description(fn (\App\Models\Supplier $record): ?string => ($f = self::wmFinance($record)) ? (($f['sold_source'] ?? '') === 'live' ? 'Sold live din WinMentor' : 'Sold din date locale') . (($f['reconciled'] ?? true) ? ' · scadențar reconciliat ✓' : ' · ⚠ scadențar nereconciliat') . ($f['interval'] ? ' · ' . $f['interval'] : '') : null)
                ->icon('heroicon-o-banknotes')
                ->collapsible()
                ->columns(4)
                ->columnSpanFull()
                ->visible(fn (\App\Models\Supplier $record): bool => self::wmFinance($record) !== null && auth()->user()?->email === 'codrut@ikonia.ro')
                ->schema([
                    Infolists\Components\TextEntry::make('wm_sold_furnizor')->label('Sold de plată')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => self::wmFinance($record)['sold'] ?? '—')
                        ->badge()->color('warning')->columnSpan(1),
                    Infolists\Components\TextEntry::make('wm_restante')->label('Restanțe (peste scadență)')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => ($f = self::wmFinance($record)) ? $f['restante_fmt'] . ' lei · ' . $f['nr_restante'] . ' fact.' : '—')
                        ->badge()->color('danger')->icon('heroicon-o-exclamation-triangle')
                        ->visible(fn (\App\Models\Supplier $record): bool => (self::wmFinance($record)['restante'] ?? 0) > 0.01)
                        ->columnSpan(1),
                    Infolists\Components\TextEntry::make('wm_id_furnizor')->label('ID WinMentor')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => (string) ($record->winmentor_id ?: '—'))->columnSpan(1),
                    Infolists\Components\TextEntry::make('wm_nr_facturi_plata')->label('Facturi de plată')
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => (string) count(self::wmFinance($record)['facturi'] ?? []))->columnSpan(1),
                    Infolists\Components\TextEntry::make('wm_warn')->hiddenLabel()->columnSpanFull()
                        ->getStateUsing(fn (\App\Models\Supplier $record): ?string => self::wmFinance($record)['warn'] ?? null)
                        ->visible(fn (\App\Models\Supplier $record): bool => ! empty(self::wmFinance($record)['warn'] ?? null))
                        ->badge()->color('danger')->icon('heroicon-o-exclamation-triangle'),
                    Infolists\Components\TextEntry::make('wm_facturi_plata')->label('Facturi de plată (scadențar)')->html()->columnSpanFull()
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => self::facturiPlataHtml(self::wmFinance($record)['facturi'] ?? [])),
                    Infolists\Components\TextEntry::make('wm_plati')->label('Plăți efectuate')->html()->columnSpanFull()
                        ->getStateUsing(fn (\App\Models\Supplier $record): string => self::platiHtml(self::wmFinance($record)['plati'] ?? [])),
                ]),

            \Filament\Schemas\Components\Section::make('Persoane de contact')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('contacts')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('name')
                                ->label('Nume')
                                ->weight(\Filament\Support\Enums\FontWeight::Bold),

                            Infolists\Components\TextEntry::make('department')
                                ->label('Departament')
                                ->badge()
                                ->color(fn ($state) => match ($state) {
                                    'comercial'     => 'primary',
                                    'comenzi'       => 'success',
                                    'director'      => 'danger',
                                    'contabilitate' => 'warning',
                                    default         => 'gray',
                                })
                                ->formatStateUsing($deptLabel)
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('role')
                                ->label('Funcție')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('email')
                                ->label('Email')
                                ->icon('heroicon-o-envelope')
                                ->url(fn ($state) => $state ? "mailto:{$state}" : null)
                                ->placeholder('—')
                                ->copyable()->copyMessage('Copiat!'),

                            Infolists\Components\TextEntry::make('phone')
                                ->label('Telefon')
                                ->icon('heroicon-o-phone')
                                ->placeholder('—')
                                ->copyable()->copyMessage('Copiat!'),

                            Infolists\Components\IconEntry::make('is_primary')
                                ->label('Principal')
                                ->boolean(),
                        ])
                        ->columns(6),
                ]),

        ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();
        if ($user && in_array($user->role, [User::ROLE_CONSULTANT_VANZARI, User::ROLE_SUPORT_FINANCIAR])) {
            $query->whereHas('buyers', fn ($q) => $q->where('users.id', $user->id));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Furnizor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\ImageColumn::make('brands.logo_url')
                    ->label('Branduri')
                    ->disk('public')
                    ->stacked()
                    ->limit(6)
                    ->size(36)
                    ->extraImgAttributes(['style' => 'object-fit: contain; background: white; border-radius: 4px;'])
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('contacts.name')
                    ->label('Contact principal')
                    ->listWithLineBreaks()
                    ->limitList(1)
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('contacts.phone')
                    ->label('Telefon')
                    ->listWithLineBreaks()
                    ->limitList(1)
                    ->placeholder('-')
                    ->copyable()->copyMessage('Copiat!')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('vat_number')
                    ->label('CUI')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('buyers.name')
                    ->label('Buyers')
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activ')
                    ->boolean(),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('Produse')
                    ->counts('products')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modificat')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activ'),
            ])
            ->deferFilters(false)
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make()
                    ->visible(fn () => ! auth()->user()?->isConsultantVanzari()),

                Actions\Action::make('create_po')
                    ->label('Creează PO')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('success')
                    ->url(fn (Supplier $record) => \App\Filament\App\Resources\PurchaseOrderResource::getUrl('create', [
                        'supplier_id' => $record->id,
                    ])),

                Actions\Action::make('move_products')
                    ->label('Mută produse')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->visible(fn (Supplier $record): bool => ! auth()->user()?->isConsultantVanzari() && $record->products()->exists())
                    ->form(function (Supplier $record): array {
                        $productOptions = $record->products()
                            ->orderBy('woo_products.name')
                            ->get(['woo_products.id', 'woo_products.name', 'woo_products.sku'])
                            ->mapWithKeys(fn ($p) => [
                                $p->id => '[' . ($p->sku ?: '—') . '] ' . $p->name,
                            ])
                            ->all();

                        return [
                            Forms\Components\Select::make('target_supplier_id')
                                ->label('Furnizor destinație')
                                ->options(
                                    Supplier::query()
                                        ->where('id', '!=', $record->id)
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                )
                                ->searchable()
                                ->required()
                                ->native(false),

                            Forms\Components\Radio::make('mode')
                                ->label('Ce produse muți?')
                                ->options([
                                    'all'      => 'Toate produsele (' . count($productOptions) . ')',
                                    'selected' => 'Selectează manual',
                                ])
                                ->default('all')
                                ->live()
                                ->required(),

                            Forms\Components\CheckboxList::make('product_ids')
                                ->label('Produse de mutat')
                                ->options($productOptions)
                                ->searchable()
                                ->bulkToggleable()
                                ->columns(1)
                                ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get): bool => $get('mode') === 'selected')
                                ->required(fn (\Filament\Schemas\Components\Utilities\Get $get): bool => $get('mode') === 'selected'),
                        ];
                    })
                    ->modalHeading(fn (Supplier $record): string => 'Mută produse de la: ' . $record->name)
                    ->modalSubmitActionLabel('Mută produsele')
                    ->modalWidth('lg')
                    ->action(function (Supplier $record, array $data): void {
                        $targetId = (int) $data['target_supplier_id'];

                        if ($data['mode'] === 'all') {
                            $productIds = $record->products()->pluck('woo_products.id')->all();
                        } else {
                            $productIds = array_map('intval', $data['product_ids'] ?? []);
                        }

                        if (empty($productIds)) {
                            Notification::make()
                                ->title('Niciun produs selectat')
                                ->warning()
                                ->send();
                            return;
                        }

                        $moved   = 0;
                        $skipped = 0; // produs deja asociat la furnizorul destinație

                        DB::transaction(function () use ($record, $targetId, $productIds, &$moved, &$skipped) {
                            foreach ($productIds as $productId) {
                                // Verifică dacă există deja la furnizorul destinație
                                $existsAtTarget = DB::table('product_suppliers')
                                    ->where('woo_product_id', $productId)
                                    ->where('supplier_id', $targetId)
                                    ->exists();

                                if ($existsAtTarget) {
                                    // Șterge doar asocierea de la sursa
                                    DB::table('product_suppliers')
                                        ->where('woo_product_id', $productId)
                                        ->where('supplier_id', $record->id)
                                        ->delete();
                                    $skipped++;
                                } else {
                                    // Mută: update supplier_id
                                    DB::table('product_suppliers')
                                        ->where('woo_product_id', $productId)
                                        ->where('supplier_id', $record->id)
                                        ->update([
                                            'supplier_id' => $targetId,
                                            'updated_at'  => now(),
                                        ]);
                                    $moved++;
                                }
                            }
                        });

                        $targetName = Supplier::find($targetId)?->name ?? 'furnizorul destinație';
                        $msg        = "Mutat: {$moved} produse → {$targetName}";
                        if ($skipped > 0) {
                            $msg .= " ({$skipped} existau deja acolo și au fost dezasociate de la sursă)";
                        }

                        Notification::make()
                            ->title($msg)
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->before(function (Actions\DeleteBulkAction $action, \Illuminate\Database\Eloquent\Collection $records): void {
                            $withProducts = $records->filter(fn (Supplier $s) => $s->products()->exists());
                            if ($withProducts->isNotEmpty()) {
                                $names = $withProducts->pluck('name')->join(', ');
                                Notification::make()
                                    ->title('Ștergere blocată')
                                    ->body("Furnizorii următori au produse asociate și nu pot fi șterși: {$names}")
                                    ->danger()
                                    ->persistent()
                                    ->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return ! $record->products()->exists();
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            ProductsRelationManager::class,
        ];
    }

    /**
     * Situație financiară furnizor din tabelele WinMentor sincronizate LOCAL (read-only,
     * fără COM la randare) — sold de plată, scadențar și plăți. Oglindă a fișei client.
     */
    public static function wmFinance(\App\Models\Supplier $record): ?array
    {
        return \Illuminate\Support\Facades\Cache::remember("supp_wm_fin_{$record->id}", 600, function () use ($record) {
            $wm = trim((string) ($record->winmentor_id ?? ''));
            if ($wm === '' || $wm === '0') {
                return null;
            }
            $codEx = (string) (DB::table('winmentor_parteneri')->where('wm_id', $wm)->value('cod_extern') ?? '');
            $pids  = array_values(array_filter(array_unique([$wm, $codEx])));
            if (empty($pids)) {
                return null;
            }

            $fmtDate = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y') : '';
            $fmtNum  = fn ($v) => number_format((float) $v, 2, ',', '.');

            $solduri = DB::table('winmentor_solduri_raw')
                ->where('directie', 'furnizor')
                ->whereIn('part_id', $pids)
                ->whereRaw('ABS(rest_de_plata) >= 0.01')
                ->orderByDesc('data_factura')
                ->get();

            // Nimic sincronizat pentru acest furnizor → nu afișăm secțiunea
            if ($solduri->isEmpty() && DB::table('winmentor_plati_raw')->whereIn('part_id', $pids)->doesntExist()) {
                return null;
            }

            // Marcaje de reconciliere locală: facturi WinMentor stinse/fantomă (nu le putem șterge din Mentor)
            $overrides = DB::table('winmentor_factura_overrides')
                ->whereIn('part_id', $pids)->where('directie', 'furnizor')
                ->pluck('action', 'nr_factura')->all();

            // Facturile DESCHISE = scadențarul minus cele marcate stinse/fantomă
            $openRows = $solduri->reject(fn ($f) => isset($overrides[$f->nr_factura]))->values();

            $facturi = $openRows->map(fn ($f) => [
                'tip'          => $f->tip_document,
                'nrDocument'   => $f->nr_factura,
                'dataDocument' => $fmtDate($f->data_factura),
                'rest'         => $fmtNum($f->rest_de_plata),
                'dataScadenta' => $fmtDate($f->termen_plata),
            ])->values()->all();

            $plati = DB::table('winmentor_plati_raw')
                ->whereIn('part_id', $pids)
                ->orderByDesc('data')
                ->limit(60)
                ->get()
                ->map(fn ($p) => [
                    'data'        => $fmtDate($p->data),
                    'documentRef' => $p->document_ref,
                    'suma'        => $fmtNum($p->suma),
                ])->all();

            $ts = DB::table('winmentor_solduri_raw')->where('directie', 'furnizor')->whereIn('part_id', $pids)->max('fetched_at');

            // Restanțe din facturile DESCHISE (după reconciliere): sold − facturile neajunse la scadență.
            $today   = \Carbon\Carbon::today();
            $openSum = (float) $openRows->sum('rest_de_plata');
            $neajunse = 0.0; $nrRestante = 0;
            foreach ($openRows as $f) {
                $rest = (float) $f->rest_de_plata;
                if ($rest <= 0.01) {
                    continue; // avansuri/stornouri (negative) — se reflectă deja în sold
                }
                $due = null;
                if ($f->termen_plata) {
                    try { $due = \Carbon\Carbon::parse($f->termen_plata)->startOfDay(); } catch (\Throwable $e) {
                    }
                }
                if ($due !== null && $due->lt($today)) {
                    $nrRestante++;          // factură cu scadență depășită
                } else {
                    $neajunse += $rest;     // neajunsă la scadență (sau fără scadență)
                }
            }
            $restante = max(0.0, $openSum - $neajunse);

            // Sold AUTORITAR din getSoldPartener (per-partener, live) — ancora de adevăr.
            // Scadențarul global /api/solduri/furnizori e nesigur (facturi stinse afișate deschise);
            // reconcilierea = marchezi facturile stinse până când scadențarul deschis bate cu soldul.
            $authSold = null;
            try {
                $bridge = app(\App\Services\Winmentor\WinmentorBridgeClient::class);
                if ($bridge->isReachable()) {
                    $r = $bridge->getSoldPartener($wm);
                    $authSold = self::parseWmSold($r['sold'] ?? null);
                }
            } catch (\Throwable $e) {
            }

            // De plată = magnitudinea soldului autoritar (furnizor: negativ = datorăm)
            $soldPlata = $authSold !== null ? abs($authSold) : abs($openSum);

            // Reconciliat = scadențarul DESCHIS bate cu soldul autoritar (±10% sau ±50 lei)
            $reconciled = true; $gap = 0.0;
            if ($authSold !== null) {
                $gap = abs($authSold) - abs($openSum);
                $reconciled = abs($gap) <= max(50.0, abs($authSold) * 0.10);
            }
            $nrOverrides = count($overrides);

            $warn = null;
            if (! $reconciled) {
                $warn = 'Scadențarul deschis (' . $fmtNum(abs($openSum)) . ' lei, ' . $openRows->count() . ' facturi'
                    . ($nrOverrides ? ", {$nrOverrides} marcate stinse" : '')
                    . ') NU bate cu soldul real (' . $fmtNum(abs($authSold ?? 0)) . ' lei). De reconciliat: '
                    . $fmtNum(abs($gap)) . ' lei — marchează facturile deja stinse (butonul „Reconciliere scadențar").';
            }

            return [
                'sold'         => $fmtNum($soldPlata) . ' lei',
                'sold_source'  => $authSold !== null ? 'live' : 'local',
                'facturi'      => $facturi,        // facturile DESCHISE (mereu afișate, ca să poată fi marcate)
                'plati'        => $plati,
                'restante'     => $restante,
                'nr_restante'  => $nrRestante,
                'restante_fmt' => $fmtNum($restante),
                'reconciled'   => $reconciled,
                'gap'          => $gap,
                'gap_fmt'      => $fmtNum(abs($gap)),
                'nr_overrides' => $nrOverrides,
                'warn'         => $warn,
                'interval'     => $ts ? \Carbon\Carbon::parse($ts)->format('d.m.Y H:i') : '',
            ];
        });
    }

    /** part_ids folosite pentru scadențar/plăți: wm_id + cod_extern din winmentor_parteneri. */
    public static function wmPartIds(\App\Models\Supplier $record): array
    {
        $wm = trim((string) ($record->winmentor_id ?? ''));
        if ($wm === '' || $wm === '0') {
            return [];
        }
        $codEx = (string) (DB::table('winmentor_parteneri')->where('wm_id', $wm)->value('cod_extern') ?? '');
        return array_values(array_filter(array_unique([$wm, $codEx])));
    }

    /** Parsează soldul din getSoldPartener („-35512,468" / „-8395" / „636.744,36") în float. */
    protected static function parseWmSold(mixed $s): ?float
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s); // separator de mii
        }
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : null;
    }

    protected static function facturiPlataHtml(array $facturi): string
    {
        if (empty($facturi)) {
            return '<p class="text-sm text-gray-500">Nu sunt facturi de plată.</p>';
        }
        $today = \Carbon\Carbon::today();
        $rows = '';
        $totalDepasit = 0.0; $nrDepasit = 0;
        foreach ($facturi as $f) {
            $rest = (float) str_replace(['.', ','], ['', '.'], (string) ($f['rest'] ?? '0'));
            $neg  = $rest < 0;

            // Scadență depășită? (doar pentru sume încă de plată, nu stornouri)
            $scad = (string) ($f['dataScadenta'] ?? '');
            $depasit = false; $zile = 0;
            if ($scad !== '' && $rest > 0.01) {
                try {
                    $d = \Carbon\Carbon::createFromFormat('d.m.Y', $scad)->startOfDay();
                    if ($d->lt($today)) { $depasit = true; $zile = (int) $d->diffInDays($today); }
                } catch (\Throwable $e) {
                }
            }
            if ($depasit) { $totalDepasit += $rest; $nrDepasit++; }

            $scadCell = e($scad);
            if ($depasit) {
                $scadCell = '<span style="color:#b91c1c;font-weight:700">' . e($scad) . '</span>'
                    . ' <span style="display:inline-block;background:#fee2e2;color:#b91c1c;border-radius:6px;padding:1px 6px;font-size:10px;font-weight:700;white-space:nowrap">⚠ ' . $zile . ' z întârziere</span>';
            }
            $restColor = ($neg || $depasit) ? '#b91c1c' : '#111';
            $rows .= '<tr style="' . ($depasit ? 'background:#fff5f5' : '') . '">'
                . '<td style="padding:4px 8px;vertical-align:top">' . e(trim(($f['tip'] ?? '') . ' ' . ($f['nrDocument'] ?? ''))) . '</td>'
                . '<td style="padding:4px 8px;vertical-align:top">' . e($f['dataDocument'] ?? '') . '</td>'
                . '<td style="padding:4px 8px;vertical-align:top">' . $scadCell . '</td>'
                . '<td style="padding:4px 8px;text-align:right;vertical-align:top;font-weight:' . ($depasit ? '700' : '400') . ';color:' . $restColor . '">' . e($f['rest'] ?? '') . ($neg ? ' ↩' : '') . '</td>'
                . '</tr>';
        }
        $foot = $nrDepasit > 0
            ? '<tfoot><tr style="position:sticky;bottom:0;background:#fff5f5;border-top:1px solid #fecaca;font-weight:700;color:#b91c1c">'
                . '<td style="padding:5px 8px" colspan="3">⚠ ' . $nrDepasit . ' facturi cu termen depășit</td>'
                . '<td style="padding:5px 8px;text-align:right">' . e(number_format($totalDepasit, 2, ',', '.')) . '</td>'
                . '</tr></tfoot>'
            : '';
        return '<div style="max-height:320px;overflow-y:auto;border:1px solid #eceff3;border-radius:8px">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead style="position:sticky;top:0;background:#fff;box-shadow:0 1px 0 #e5e7eb"><tr style="text-align:left">'
            . '<th style="padding:4px 8px">Document</th><th style="padding:4px 8px">Dată</th>'
            . '<th style="padding:4px 8px">Scadență</th><th style="padding:4px 8px;text-align:right">Rest de plată</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>' . $foot . '</table></div>';
    }

    protected static function platiHtml(array $plati): string
    {
        if (empty($plati)) {
            return '<p class="text-sm text-gray-500">Nu sunt plăți înregistrate.</p>';
        }
        $rows = '';
        $total = 0.0;
        foreach ($plati as $p) {
            $total += (float) str_replace([' ', '.', ','], ['', '', '.'], (string) ($p['suma'] ?? ''));
            $rows .= '<tr>'
                . '<td style="padding:4px 8px">' . e($p['data'] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . e($p['documentRef'] ?? '') . '</td>'
                . '<td style="padding:4px 8px;text-align:right">' . e($p['suma'] ?? '') . '</td>'
                . '</tr>';
        }
        $totalFmt = number_format($total, 2, ',', '.');
        return '<div style="max-height:320px;overflow-y:auto;border:1px solid #eceff3;border-radius:8px">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead style="position:sticky;top:0;background:#fff;box-shadow:0 1px 0 #e5e7eb"><tr style="text-align:left">'
            . '<th style="padding:4px 8px">Dată</th><th style="padding:4px 8px">Document</th>'
            . '<th style="padding:4px 8px;text-align:right">Sumă</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr style="position:sticky;bottom:0;background:#fff;border-top:1px solid #ddd;font-weight:600">'
            . '<td style="padding:4px 8px" colspan="2">Total plăți (' . count($plati) . ')</td>'
            . '<td style="padding:4px 8px;text-align:right">' . e($totalFmt) . '</td>'
            . '</tr></tfoot></table></div>';
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'view'   => Pages\ViewSupplier::route('/{record}'),
            'edit'   => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
