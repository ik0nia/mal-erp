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
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
            Forms\Components\Section::make('Informații generale')
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

            Forms\Components\Section::make('Date fiscale și bancare')
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

            Forms\Components\Section::make('Configurare achiziții')
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

            Forms\Components\Section::make('Notițe')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->label('')
                        ->rows(3),
                ]),

            Forms\Components\Section::make('Condiții comerciale')
                ->columnSpanFull()
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\Components\Tabs::make()
                        ->tabs([
                            // ── Tab 1: Livrare ──────────────────────────────
                            Forms\Components\Tabs\Tab::make('Livrare')
                                ->icon('heroicon-o-truck')
                                ->schema([
                                    Forms\Components\Grid::make(3)->schema([
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
                                        ->visible(fn (Forms\Get $get) => (bool) $get('conditions.delivery.pickup_available'))
                                        ->columnSpanFull(),
                                    Forms\Components\Textarea::make('conditions.delivery.note')
                                        ->label('Note livrare')
                                        ->rows(2)->columnSpanFull(),
                                ]),

                            // ── Tab 2: Comandă ───────────────────────────────
                            Forms\Components\Tabs\Tab::make('Comandă')
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
                                    Forms\Components\Grid::make(2)->schema([
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
                                    Forms\Components\Grid::make(3)->schema([
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
                            Forms\Components\Tabs\Tab::make('Transport')
                                ->icon('heroicon-o-map-pin')
                                ->schema([
                                    Forms\Components\Grid::make(2)->schema([
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
                            Forms\Components\Tabs\Tab::make('Plată')
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
                                    Forms\Components\Grid::make(3)->schema([
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
                                            ->visible(fn (Forms\Get $get) => $get('conditions.payment.default_term') === 'custom'),
                                        Forms\Components\TextInput::make('conditions.payment.advance_percent')
                                            ->label('Procent avans (%)')
                                            ->helperText('Procentul din valoarea comenzii care trebuie plătit în avans.')
                                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')->nullable()
                                            ->visible(fn (Forms\Get $get) => $get('conditions.payment.default_term') === 'avans_partial'),
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
                            Forms\Components\Tabs\Tab::make('Discount')
                                ->icon('heroicon-o-tag')
                                ->schema([
                                    Forms\Components\Grid::make(3)->schema([
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
                            Forms\Components\Tabs\Tab::make('Retur & Garanție')
                                ->icon('heroicon-o-arrow-uturn-left')
                                ->schema([
                                    Forms\Components\Grid::make(2)->schema([
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
                                    Forms\Components\Grid::make(2)->schema([
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
            Infolists\Components\Section::make('Informații generale')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->label('Nume furnizor')
                        ->weight(\Filament\Support\Enums\FontWeight::Bold)
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large),

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

            Infolists\Components\Section::make('Date fiscale și bancare')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('vat_number')
                        ->label('CUI / CIF')
                        ->placeholder('—')
                        ->copyable(),

                    Infolists\Components\TextEntry::make('reg_number')
                        ->label('Nr. Reg. Com.')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('bank_account')
                        ->label('IBAN')
                        ->placeholder('—')
                        ->copyable(),

                    Infolists\Components\TextEntry::make('bank_name')
                        ->label('Bancă')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('po_approval_threshold')
                        ->label('Plafon PO fără aprobare')
                        ->money('RON')
                        ->placeholder('Fără plafon (aprobat automat)'),
                ]),

            Infolists\Components\Section::make('Notițe')
                ->collapsible()
                ->schema([
                    Infolists\Components\TextEntry::make('notes')
                        ->label('')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Condiții comerciale')
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Infolists\Components\Tabs::make()->tabs([

                        Infolists\Components\Tabs\Tab::make('Livrare')->icon('heroicon-o-truck')->schema([
                            Infolists\Components\Grid::make(3)->schema([
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

                        Infolists\Components\Tabs\Tab::make('Comandă')->icon('heroicon-o-shopping-bag')->schema([
                            Infolists\Components\TextEntry::make('conditions.ordering.methods')
                                ->label('Metode comandă')
                                ->formatStateUsing(fn ($state) => collect((array)$state)->map(fn($m) => match($m){
                                    'email'=>'Email','phone'=>'Telefon','portal'=>'Portal web',
                                    'whatsapp'=>'WhatsApp','in_person'=>'Față în față','edi'=>'EDI',default=>$m
                                })->join(', '))
                                ->badge()->placeholder('—'),
                            Infolists\Components\Grid::make(3)->schema([
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

                        Infolists\Components\Tabs\Tab::make('Transport')->icon('heroicon-o-map-pin')->schema([
                            Infolists\Components\Grid::make(3)->schema([
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

                        Infolists\Components\Tabs\Tab::make('Plată')->icon('heroicon-o-banknotes')->schema([
                            Infolists\Components\TextEntry::make('conditions.payment.methods')
                                ->label('Metode plată acceptate')
                                ->formatStateUsing(fn ($state) => collect((array)$state)->map(fn($m) => match($m){
                                    'bank_transfer'=>'Transfer bancar','cash'=>'Numerar','card'=>'Card',
                                    'check'=>'CEC','bill_of_ex'=>'Bilet la ordin','direct_debit'=>'Debitare directă',
                                    'compensare'=>'Compensare','factoring'=>'Factoring',default=>$m
                                })->join(', '))
                                ->placeholder('—'),
                            Infolists\Components\Grid::make(3)->schema([
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

                        Infolists\Components\Tabs\Tab::make('Discount')->icon('heroicon-o-tag')->schema([
                            Infolists\Components\Grid::make(3)->schema([
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

                        Infolists\Components\Tabs\Tab::make('Retur & Garanție')->icon('heroicon-o-arrow-uturn-left')->schema([
                            Infolists\Components\Grid::make(3)->schema([
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
                            Infolists\Components\Grid::make(3)->schema([
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

            Infolists\Components\Section::make('Persoane de contact')
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
                                ->copyable(),

                            Infolists\Components\TextEntry::make('phone')
                                ->label('Telefon')
                                ->icon('heroicon-o-phone')
                                ->placeholder('—')
                                ->copyable(),

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
                    ->copyable()
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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn () => ! auth()->user()?->isConsultantVanzari()),

                Tables\Actions\Action::make('create_po')
                    ->label('Creează PO')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('success')
                    ->url(fn (Supplier $record) => \App\Filament\App\Resources\PurchaseOrderResource::getUrl('create', [
                        'supplier_id' => $record->id,
                    ])),

                Tables\Actions\Action::make('move_products')
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
                                ->visible(fn (Forms\Get $get): bool => $get('mode') === 'selected')
                                ->required(fn (Forms\Get $get): bool => $get('mode') === 'selected'),
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
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (Tables\Actions\DeleteBulkAction $action, \Illuminate\Database\Eloquent\Collection $records): void {
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
