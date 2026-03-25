<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\Location;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class PendingPurchaseItemsWidget extends Widget
{
    protected string $view = 'filament.app.widgets.pending-purchase-items';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    public ?int  $filterSupplierId   = null;
    public ?int  $filterLocationId   = null;
    public ?int  $filterConsultantId = null;
    public string $filterNeededByFrom = '';
    public string $filterNeededByTo   = '';
    public bool  $showUrgentOnly    = false;
    public bool  $showReservedOnly  = false;

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

    public static function canView(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public function mount(): void
    {
        $this->loadData();
        $this->supplierOptions   = $this->buildSupplierOptions();
        $this->locationOptions   = $this->buildLocationOptions();
        $this->consultantOptions = $this->buildConsultantOptions();
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

        $supplierQuery = Supplier::query()->where('is_active', true);

        if (! $user->isSuperAdmin() && ! $user->isAdmin()) {
            $supplierQuery->whereHas('buyers', fn ($q) => $q->where('users.id', $user->id));
        }

        if ($this->filterSupplierId) {
            $supplierQuery->where('id', $this->filterSupplierId);
        }

        $visibleSupplierIds = $supplierQuery->pluck('id')->all();

        $itemsQuery = PurchaseRequestItem::query()
            ->with(['purchaseRequest.user', 'purchaseRequest.location', 'supplier', 'product'])
            ->whereIn('supplier_id', $visibleSupplierIds)
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                PurchaseRequest::STATUS_SUBMITTED,
                PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->whereRaw('quantity > COALESCE(ordered_quantity, 0)')
            ->where(fn ($q) => $q
                ->whereDoesntHave('product')
                ->orWhereHas('product', fn ($p) => $p->where('is_discontinued', false))
            )
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

        $this->totalPending   = $items->count();
        $this->totalUrgent    = $items->where('is_urgent', true)->count();
        $this->totalReserved  = $items->where('is_reserved', true)->count();
        $this->totalSuppliers = $items->pluck('supplier_id')->filter()->unique()->count();

        $this->supplierGroups = $items
            ->groupBy('supplier_id')
            ->map(function (Collection $group, $supplierId): array {
                $supplier = $group->first()->supplier;

                return [
                    'supplier_id'   => $supplierId,
                    'supplier_name' => $supplier?->name ?? 'Fără furnizor',
                    'create_po_url' => PurchaseOrderResource::getUrl('create', ['supplier_id' => $supplierId]),
                    'items_count'   => $group->count(),
                    'urgent_count'  => $group->where('is_urgent', true)->count(),
                    'items'         => $group->map(fn (PurchaseRequestItem $item): array => [
                        'id'               => $item->id,
                        'product_name'     => $item->product_name,
                        'sku'              => $item->sku,
                        'is_discontinued'  => (bool) $item->product?->is_discontinued,
                        'quantity'         => $item->remaining_quantity,
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
        return User::query()
            ->whereHas('purchaseRequests', fn ($q) =>
                $q->whereIn('status', [
                    PurchaseRequest::STATUS_SUBMITTED,
                    PurchaseRequest::STATUS_PARTIALLY_ORDERED,
                ])
                ->whereHas('items', fn ($i) => $i->where('status', PurchaseRequestItem::STATUS_PENDING))
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function createPoForSupplier(int $supplierId): void
    {
        $this->redirect(
            PurchaseOrderResource::getUrl('create', [
                'supplier_id' => $supplierId,
            ])
        );
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

    public function getWooOrdersPendingProcurement(): \Illuminate\Support\Collection
    {
        return PurchaseRequest::query()
            ->where('source_type', PurchaseRequest::SOURCE_WOO_ORDER)
            ->whereIn('status', [
                PurchaseRequest::STATUS_SUBMITTED,
                PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ])
            ->with(['wooOrder', 'items.product'])
            ->latest()
            ->limit(50)
            ->get();
    }
}
