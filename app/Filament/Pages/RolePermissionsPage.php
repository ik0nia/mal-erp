<?php

namespace App\Filament\Pages;

use App\Models\RolePermission;
use App\Models\User;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class RolePermissionsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-lock-closed';
    protected static ?string $navigationLabel = 'Permisiuni roluri';
    protected static ?string $title           = 'Permisiuni per rol';
    protected static ?int    $navigationSort  = 98;
    protected string  $view            = 'filament.pages.role-permissions';

    public array   $data         = [];
    public ?string $selectedRole = null;

    public static function resources(): array
    {
        return [
            // Administrare magazin
            'App\\Filament\\App\\Resources\\WooProductResource'            => ['label' => 'Produse',               'group' => 'Administrare magazin'],
            'App\\Filament\\App\\Resources\\WooCategoryResource'           => ['label' => 'Categorii',             'group' => 'Administrare magazin'],
            'App\\Filament\\App\\Resources\\EanAssociationRequestResource' => ['label' => 'Cereri asociere EAN',   'group' => 'Administrare magazin'],
            'App\\Filament\\App\\Resources\\ProductPriceLogResource'       => ['label' => 'Modificări prețuri',    'group' => 'Administrare magazin'],
            'App\\Filament\\App\\Pages\\SkuDiscrepancyReport'              => ['label' => 'Discrepanțe SKU',       'group' => 'Administrare magazin'],
            // Achiziții
            'App\\Filament\\App\\Resources\\BrandResource'                 => ['label' => 'Branduri',              'group' => 'Achiziții'],
            'App\\Filament\\App\\Resources\\SupplierResource'              => ['label' => 'Furnizori',             'group' => 'Achiziții'],
            'supplier_feeds_view'                                          => ['label' => 'Feed-uri prețuri: vizualizare',          'group' => 'Achiziții'],
            'supplier_feeds_manage'                                        => ['label' => 'Feed-uri prețuri: configurare',          'group' => 'Achiziții'],
            'supplier_feeds_sync'                                          => ['label' => 'Feed-uri prețuri: sincronizare manuală', 'group' => 'Achiziții'],
            'App\\Filament\\App\\Resources\\PurchaseRequestResource'       => ['label' => 'Necesare',              'group' => 'Achiziții'],
            'App\\Filament\\App\\Resources\\PurchaseOrderResource'         => ['label' => 'Comenzi furnizori',     'group' => 'Achiziții'],
            'App\\Filament\\App\\Pages\\BuyerDashboardPage'                => ['label' => 'Tablou comenzi',        'group' => 'Achiziții'],
            'App\\Filament\\App\\Pages\\UnassignedItemsPage'               => ['label' => 'Iteme neasignate',      'group' => 'Achiziții'],
            'App\\Filament\\App\\Pages\\ProductsWithoutSupplier'           => ['label' => 'Fără furnizor',         'group' => 'Achiziții'],
            'App\\Filament\\App\\Pages\\SupplierPriceIntelligencePage'     => ['label' => 'Prețuri din Emailuri',  'group' => 'Comunicare'],
            // Comunicare
            'App\\Filament\\App\\Pages\\ChatLogsPage'                      => ['label' => 'Chat logs',             'group' => 'Comunicare'],
            'App\\Filament\\App\\Pages\\EmailInboxPage'                    => ['label' => 'Email inbox',           'group' => 'Comunicare'],
            'App\\Filament\\App\\Pages\\EmailCommunicationStatsPage'       => ['label' => 'Statistici email',      'group' => 'Comunicare'],
            // Vânzări
            'App\\Filament\\App\\Resources\\CustomerResource'              => ['label' => 'Clienți',               'group' => 'Vânzări'],
            'App\\Filament\\App\\Resources\\OfferResource'                 => ['label' => 'Oferte',                'group' => 'Vânzări'],
            // Comenzi
            'App\\Filament\\App\\Resources\\WooOrderResource'              => ['label' => 'Comenzi online',        'group' => 'Comenzi'],
            'App\\Filament\\App\\Pages\\ComenziMagazin'                    => ['label' => 'Comenzi Magazin',       'group' => 'Comenzi'],
            // Livrare
            'App\\Filament\\App\\Resources\\SamedayAwbResource'            => ['label' => 'AWB Sameday',           'group' => 'Livrare'],
            // Rapoarte
            'App\\Filament\\App\\Pages\\NecesarMarfa'                      => ['label' => 'Necesar de marfă',      'group' => 'Rapoarte'],
            'App\\Filament\\App\\Pages\\StockMovementsReport'              => ['label' => 'Mișcări stocuri',       'group' => 'Rapoarte'],
            'App\\Filament\\App\\Pages\\OnlineShopReport'                  => ['label' => 'Raport Magazin Online', 'group' => 'Rapoarte'],
            'App\\Filament\\App\\Pages\\BiDashboardPage'                   => ['label' => 'Dashboard BI',          'group' => 'Rapoarte'],
            'App\\Filament\\App\\Pages\\BiAnalysisPage'                    => ['label' => 'Analiză BI',            'group' => 'Rapoarte'],
            // Social Media
            'App\\Filament\\App\\Resources\\SocialPostResource'            => ['label' => 'Postări sociale',       'group' => 'Social Media'],
            'App\\Filament\\App\\Resources\\GraphicTemplateResource'       => ['label' => 'Template-uri grafice',  'group' => 'Social Media'],
            'App\\Filament\\App\\Pages\\GraphicTemplateEditorPage'         => ['label' => 'Editor Template',       'group' => 'Social Media'],
            'App\\Filament\\App\\Pages\\GraphicTemplateVisualEditorPage'   => ['label' => 'Editor Vizual',         'group' => 'Social Media'],
            'App\\Filament\\App\\Pages\\SocialAccountsPage'                => ['label' => 'Conturi sociale',       'group' => 'Social Media'],
            // Produse
            'App\\Filament\\App\\Pages\\NewWinmentorProducts'              => ['label' => 'Produse noi WinMentor',      'group' => 'Produse'],
            'App\\Filament\\App\\Pages\\ProductReviewRequestsPage'         => ['label' => 'Reverificări produse',        'group' => 'Produse'],
            'App\\Filament\\App\\Pages\\ToyaImportPage'                    => ['label' => 'Import Toya',                 'group' => 'Produse'],
            'App\\Filament\\App\\Pages\\ToyaCategoryMappingPage'           => ['label' => 'Categorii Toya (mapping AI)', 'group' => 'Produse'],
            'App\\Filament\\App\\Pages\\ProductSubstitutionMatchingPage'   => ['label' => 'Matching înlocuitori Toya',   'group' => 'Produse'],
            // Secțiuni pagina produs (View)
            'woo_product_section_descriere'          => ['label' => 'Card: Descriere',             'group' => 'Pagina produs'],
            'woo_product_section_atribute_tehnice'   => ['label' => 'Card: Atribute tehnice',      'group' => 'Pagina produs'],
            'woo_product_section_istoric_stoc'       => ['label' => 'Card: Istoric variație stoc', 'group' => 'Pagina produs'],
            'woo_product_section_rezumat_variatii'   => ['label' => 'Card: Rezumat variații',      'group' => 'Pagina produs'],
            'woo_product_section_payload_brut'       => ['label' => 'Card: Payload brut (Woo)',    'group' => 'Pagina produs'],
            // Dashboard — widget-uri
            'App\\Filament\\App\\Widgets\\SalesChartWidget'                => ['label' => 'Grafic vânzări',               'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\OrderStatusChartWidget'          => ['label' => 'Grafic comenzi pe status',      'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\StockMovementChartWidget'        => ['label' => 'Grafic variație stoc',          'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\PriceMovementChartWidget'        => ['label' => 'Grafic variație prețuri',       'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\SupplierMovementChartWidget'     => ['label' => 'Grafic mișcări furnizori',      'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\RecentPriceChangesWidget'        => ['label' => 'Modificări prețuri recente',    'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\NewWinmentorProductsWidget'      => ['label' => 'Card produse noi WinMentor',    'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\BiStockTrendChartWidget'         => ['label' => 'Grafic evoluție valoare stoc',  'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\ChatLeadsWidget'                 => ['label' => 'Widget leads chat',             'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\PendingPurchaseItemsWidget'      => ['label' => 'Widget iteme pendinte',         'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\StockOutSupplierWidget'          => ['label' => 'Widget produse fără stoc',      'group' => 'Dashboard'],
            'App\\Filament\\App\\Widgets\\LowStockSupplierWidget'          => ['label' => 'Widget produse epuizare',       'group' => 'Dashboard'],
        ];
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Selectează rolul')
                    ->schema([
                        Select::make('selected_role')
                            ->label('Rol')
                            ->options(User::roleOptions())
                            ->live()
                            ->afterStateUpdated(fn (?string $state) => $this->loadPermissions($state))
                            ->placeholder('Alege un rol pentru a vedea permisiunile...'),
                    ]),

                ...$this->buildStaticSchema(),
            ])
            ->statePath('data');
    }

    protected function buildStaticSchema(): array
    {
        $groups  = [];
        $schemas = [];

        foreach (static::resources() as $key => $meta) {
            $groups[$meta['group']][] = ['key' => $key, 'label' => $meta['label']];
        }

        foreach ($groups as $groupName => $items) {
            $fields = [];

            foreach ($items as $item) {
                $safeKey = $this->safeKey($item['key']);

                // Widget-urile și secțiunile paginii de produs au doar can_access (vizibil/ascuns)
                $isWidget = str_contains($item['key'], '\\Widgets\\')
                    || $groupName === 'Pagina produs';

                $toggles = [
                    Toggle::make("perms.{$safeKey}.can_access")->label('Vizibil'),
                ];

                if (! $isWidget) {
                    $toggles[] = Toggle::make("perms.{$safeKey}.can_view")->label('Poate vizualiza');
                    $toggles[] = Toggle::make("perms.{$safeKey}.can_create")->label('Poate crea');
                    $toggles[] = Toggle::make("perms.{$safeKey}.can_edit")->label('Poate edita');
                    $toggles[] = Toggle::make("perms.{$safeKey}.can_delete")->label('Poate șterge');
                }

                $fields[] = Section::make($item['label'])
                    ->columns($isWidget ? 1 : 5)
                    ->compact()
                    ->schema($toggles);
            }

            $schemas[] = Section::make($groupName)
                ->schema($fields)
                ->collapsible()
                ->visible(fn (): bool => filled($this->selectedRole));
        }

        return $schemas;
    }

    protected function loadPermissions(?string $role): void
    {
        if (! $role) {
            return;
        }

        $this->selectedRole = $role;

        $existing = RolePermission::where('role', $role)->get()->keyBy('resource');
        $perms    = [];

        foreach (static::resources() as $key => $meta) {
            $perm    = $existing->get($key);
            $safeKey = $this->safeKey($key);

            $perms[$safeKey] = [
                'can_access' => $perm ? (bool) $perm->can_access : false,
                'can_view'   => $perm ? (bool) $perm->can_view   : false,
                'can_create' => $perm ? (bool) $perm->can_create : false,
                'can_edit'   => $perm ? (bool) $perm->can_edit   : false,
                'can_delete' => $perm ? (bool) $perm->can_delete : false,
            ];
        }

        $this->data = array_merge($this->data, ['perms' => $perms]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $role  = $state['selected_role'] ?? null;

        if (! $role) {
            Notification::make()->warning()->title('Selectează un rol înainte de salvare.')->send();
            return;
        }

        foreach (static::resources() as $key => $meta) {
            $safeKey = $this->safeKey($key);
            $p       = $state['perms'][$safeKey] ?? [];

            RolePermission::updateOrCreate(
                ['role' => $role, 'resource' => $key],
                [
                    'can_access' => (bool) ($p['can_access'] ?? false),
                    'can_view'   => (bool) ($p['can_view']   ?? false),
                    'can_create' => (bool) ($p['can_create'] ?? false),
                    'can_edit'   => (bool) ($p['can_edit']   ?? false),
                    'can_delete' => (bool) ($p['can_delete'] ?? false),
                ]
            );
        }

        RolePermission::clearCache($role);

        $label = User::roleOptions()[$role] ?? $role;
        Notification::make()->success()->title("Permisiuni salvate pentru: {$label}")->send();
    }

    protected function safeKey(string $key): string
    {
        return str_replace(['\\', '/'], '_', $key);
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Salvează permisiunile')
                ->action('save')
                ->color('primary')
                ->visible(fn (): bool => (bool) $this->selectedRole),
        ];
    }
}
