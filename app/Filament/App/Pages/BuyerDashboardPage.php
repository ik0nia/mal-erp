<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class BuyerDashboardPage extends Page
{
    protected static string $view = 'filament.app.pages.buyer-dashboard';

    protected static ?string $navigationLabel = 'Tablou comenzi';
    protected static ?string $navigationGroup = 'Achiziții';
    protected static ?string $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?int    $navigationSort  = 20;
    protected static ?string $title           = 'Tablou comenzi achiziții';

    public ?int  $filterSupplierId  = null;
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
        $this->supplierOptions = $this->buildSupplierOptions();
    }

    public function updatedFilterSupplierId(): void
    {
        $this->loadData();
    }

    public function updatedShowUrgentOnly(): void
    {
        $this->loadData();
    }

    public function updatedShowReservedOnly(): void
    {
        $this->loadData();
    }

    private function loadData(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) return;

        // Determină furnizorii vizibili pentru buyer
        $supplierQuery = Supplier::query()->where('is_active', true);

        if (! $user->isSuperAdmin() && ! $user->isAdmin()) {
            // Buyer vede doar furnizorii asignați lui
            $supplierQuery->where('buyer_id', $user->id);
        }

        if ($this->filterSupplierId) {
            $supplierQuery->where('id', $this->filterSupplierId);
        }

        $visibleSupplierIds = $supplierQuery->pluck('id')->all();

        // Query items pending din necesare submitted
        $itemsQuery = PurchaseRequestItem::query()
            ->with(['purchaseRequest.user', 'purchaseRequest.location', 'supplier', 'product'])
            ->whereIn('supplier_id', $visibleSupplierIds)
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->where('status', 'submitted'))
            ->orderByDesc('is_urgent')
            ->orderBy('needed_by');

        if ($this->showUrgentOnly) {
            $itemsQuery->where('is_urgent', true);
        }

        if ($this->showReservedOnly) {
            $itemsQuery->where('is_reserved', true);
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
                    'supplier_logo' => $supplier?->logo_url,
                    'items_count'   => $group->count(),
                    'urgent_count'  => $group->where('is_urgent', true)->count(),
                    'items'         => $group->map(fn (PurchaseRequestItem $item): array => [
                        'id'               => $item->id,
                        'product_name'     => $item->product_name,
                        'sku'              => $item->sku,
                        'quantity'         => $item->quantity,
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
            $query->where('buyer_id', $user->id);
        }

        return $query->pluck('name', 'id')->all();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) return null;

        $count = PurchaseRequestItem::query()
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->where('status', 'submitted'))
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
}
