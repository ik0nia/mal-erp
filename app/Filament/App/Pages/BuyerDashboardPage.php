<?php

namespace App\Filament\App\Pages;
use App\Models\RolePermission;
use App\Filament\App\Concerns\HasDynamicNavSort;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\Location;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class BuyerDashboardPage extends Page
{
    use HasDynamicNavSort;

    protected string $view = 'filament.app.pages.buyer-dashboard';

    protected static bool    $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel = 'Generează comandă';
    protected static string|\UnitEnum|null $navigationGroup = 'Achiziții';
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?int    $navigationSort  = 3;
    protected static ?string $title           = 'Generează comandă';

    public ?int  $filterSupplierId   = null;
    public ?int  $filterLocationId   = null;
    public ?int  $filterConsultantId = null;
    public string $filterNeededByFrom = '';
    public string $filterNeededByTo   = '';
    public bool  $showUrgentOnly    = false;
    public bool  $showReservedOnly  = false;

    // Calculated stats
    public int $totalPending     = 0;
    public int $totalUrgent      = 0;
    public int $totalReserved    = 0;
    public int $totalSuppliers   = 0;

    /** @var array<int, array<string, mixed>> */
    public array $supplierGroups = [];

    /** @var array<int, array<string, mixed>> */
    public array $supplierOptions = [];

    /** @var array<int, string> */
    public array $locationOptions = [];

    /** @var array<int, string> */
    public array $consultantOptions = [];

    public static function canAccess(): bool
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

    public function mount(): void
    {
        $this->loadData();
        $this->supplierOptions    = $this->buildSupplierOptions();
        $this->locationOptions    = $this->buildLocationOptions();
        $this->consultantOptions  = $this->buildConsultantOptions();
    }

    public function updatedFilterSupplierId(): void   { $this->loadData(); }
    public function updatedFilterLocationId(): void   { $this->loadData(); }
    public function updatedFilterConsultantId(): void { $this->loadData(); }
    public function updatedFilterNeededByFrom(): void { $this->loadData(); }
    public function updatedFilterNeededByTo(): void   { $this->loadData(); }
    public function updatedShowUrgentOnly(): void     { $this->loadData(); }
    public function updatedShowReservedOnly(): void   { $this->loadData(); }

    private function loadData(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) return;

        // Determină furnizorii vizibili pentru buyer
        $supplierQuery = Supplier::query()->where('is_active', true);

        if (! $user->isSuperAdmin() && ! $user->isAdmin()) {
            $supplierQuery->whereHas('buyers', fn ($q) => $q->where('users.id', $user->id));
        }

        if ($this->filterSupplierId) {
            $supplierQuery->where('id', $this->filterSupplierId);
        }

        $visibleSupplierIds = $supplierQuery->pluck('id')->all();

        // Query items pending din necesare submitted, cu cantitate rămasă > 0
        $itemsQuery = PurchaseRequestItem::query()
            ->with(['purchaseRequest.user', 'purchaseRequest.location', 'supplier', 'product'])
            ->whereIn('supplier_id', $visibleSupplierIds)
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                \App\Models\PurchaseRequest::STATUS_SUBMITTED,
                \App\Models\PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->whereRaw('quantity > COALESCE(ordered_quantity, 0)')  // exclude complet comandate
            ->where(fn ($q) => $q
                ->whereDoesntHave('product')
                ->orWhereHas('product', fn ($p) => $p->where('is_discontinued', false))
            ) // exclude produse discontinue
            ->orderByDesc('is_urgent')
            ->orderBy('needed_by');

        if ($this->showUrgentOnly) {
            $itemsQuery->where('is_urgent', true);
        }

        if ($this->showReservedOnly) {
            $itemsQuery->where('is_reserved', true);
        }

        if ($this->filterLocationId) {
            $itemsQuery->whereHas('purchaseRequest', fn ($q) =>
                $q->where('location_id', $this->filterLocationId)
            );
        }

        if ($this->filterConsultantId) {
            $itemsQuery->whereHas('purchaseRequest', fn ($q) =>
                $q->where('user_id', $this->filterConsultantId)
            );
        }

        if ($this->filterNeededByFrom) {
            $itemsQuery->where(fn ($q) =>
                $q->whereNull('needed_by')->orWhere('needed_by', '>=', $this->filterNeededByFrom)
            );
        }

        if ($this->filterNeededByTo) {
            $itemsQuery->whereNotNull('needed_by')
                ->where('needed_by', '<=', $this->filterNeededByTo);
        }

        $items = $itemsQuery->get();

        // Stats
        $this->totalPending   = $items->count();
        $this->totalUrgent    = $items->where('is_urgent', true)->count();
        $this->totalReserved  = $items->where('is_reserved', true)->count();
        $this->totalSuppliers = $items->pluck('supplier_id')->filter()->unique()->count();

        // Grupare pe furnizor
        $this->supplierGroups = $items
            ->groupBy('supplier_id')
            ->map(function (Collection $group, $supplierId): array {
                $supplier = $group->first()->supplier;

                return [
                    'supplier_id'   => $supplierId,
                    'supplier_name' => $supplier?->name ?? 'Fără furnizor',
                    'items_count'   => $group->count(),
                    'urgent_count'  => $group->where('is_urgent', true)->count(),
                    'items'         => $group->map(fn (PurchaseRequestItem $item): array => [
                        'id'               => $item->id,
                        'product_name'     => $item->product_name,
                        'sku'              => $item->sku,
                        'is_discontinued'  => (bool) $item->product?->is_discontinued,
                        'quantity'         => $item->remaining_quantity, // cantitate rămasă necomandată
                        'ordered_quantity' => (float) $item->ordered_quantity,
                        'needed_by'        => $item->needed_by?->format('d.m.Y'),
                        'is_urgent'        => $item->is_urgent,
                        'is_reserved'      => $item->is_reserved,
                        'client_reference' => $item->client_reference,
                        'notes'            => $item->notes,
                        'consultant'       => $item->purchaseRequest?->user?->name,
                        'location'         => $item->purchaseRequest?->location?->name,
                        'request_number'   => $item->purchaseRequest?->number,
                        'request_id'       => $item->purchaseRequest?->id,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function buildSupplierOptions(): array
    {
        $user = auth()->user();
        if (! $user instanceof User) return [];

        $query = Supplier::query()->where('is_active', true)->orderBy('name');

        if (! $user->isSuperAdmin() && ! $user->isAdmin()) {
            $query->whereHas('buyers', fn ($q) => $q->where('users.id', $user->id));
        }

        return $query->pluck('name', 'id')->all();
    }

    private function buildLocationOptions(): array
    {
        return Location::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function buildConsultantOptions(): array
    {
        // Consultanți care au necesare submitted cu items pending
        return User::query()
            ->whereHas('purchaseRequests', fn ($q) =>
                $q->whereIn('status', [
                    \App\Models\PurchaseRequest::STATUS_SUBMITTED,
                    \App\Models\PurchaseRequest::STATUS_PARTIALLY_ORDERED,
                ])
                ->whereHas('items', fn ($i) => $i->where('status', PurchaseRequestItem::STATUS_PENDING))
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) return null;

        $count = PurchaseRequestItem::query()
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                \App\Models\PurchaseRequest::STATUS_SUBMITTED,
                \App\Models\PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->where(fn ($q) => $q
                ->whereDoesntHave('product')
                ->orWhereHas('product', fn ($p) => $p->where('is_discontinued', false))
            )
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public function createPoForSupplier(int $supplierId): void
    {
        $this->redirect(
            PurchaseOrderResource::getUrl('create', [
                'supplier_id' => $supplierId,
            ])
        );
    }

    public function bootGuardAccess(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }

    public function resetFilters(): void
    {
        $this->filterSupplierId   = null;
        $this->filterLocationId   = null;
        $this->filterConsultantId = null;
        $this->filterNeededByFrom = '';
        $this->filterNeededByTo   = '';
        $this->showUrgentOnly     = false;
        $this->showReservedOnly   = false;
        $this->loadData();
    }

    /**
     * Comenzi WooCommerce care au generat un PNR auto (produse "la comandă").
     */
    public function getWooOrdersPendingProcurement(): \Illuminate\Support\Collection
    {
        return \App\Models\PurchaseRequest::query()
            ->where('source_type', \App\Models\PurchaseRequest::SOURCE_WOO_ORDER)
            ->whereIn('status', [
                \App\Models\PurchaseRequest::STATUS_SUBMITTED,
                \App\Models\PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ])
            ->with(['wooOrder', 'items.product'])
            ->latest()
            ->limit(50)
            ->get();
    }
}
