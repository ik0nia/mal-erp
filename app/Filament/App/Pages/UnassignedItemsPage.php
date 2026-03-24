<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Models\RolePermission;
use App\Models\ProductSupplier;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class UnassignedItemsPage extends Page
{
    use HasDynamicNavSort;

    protected string $view = 'filament.app.pages.unassigned-items';

    protected static ?string $navigationLabel = 'Alocare furnizori';
    protected static string|\UnitEnum|null $navigationGroup = 'Achiziții';
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-user-plus';
    protected static ?int    $navigationSort  = 2;
    protected static ?string $title           = 'Alocare furnizori — items fără furnizor';

    /** Furnizorul selectat per item: [item_id => supplier_id] */
    public array $selectedSuppliers = [];

    /** Items de afișat */
    public array $items = [];

    /** Toți furnizorii activi */
    public array $allSuppliers = [];

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return RolePermission::check(static::class, 'can_access');
    }

    public function mount(): void
    {
        $this->allSuppliers = Supplier::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        $this->loadItems();
    }

    public function loadItems(): void
    {
        $requestItems = PurchaseRequestItem::query()
            ->with(['purchaseRequest.user', 'purchaseRequest.location', 'product'])
            ->whereNull('supplier_id')
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                PurchaseRequest::STATUS_SUBMITTED,
                PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->whereRaw('quantity > COALESCE(ordered_quantity, 0)')
            ->orderByDesc('is_urgent')
            ->orderBy('needed_by')
            ->get();

        // Preîncărcăm sugestiile din ProductSupplier per produs
        $productIds = $requestItems->whereNotNull('woo_product_id')->pluck('woo_product_id')->unique();
        $suggestions = ProductSupplier::whereIn('woo_product_id', $productIds)
            ->with('supplier')
            ->orderByDesc('is_preferred')
            ->get()
            ->groupBy('woo_product_id');

        $this->items = $requestItems->map(function (PurchaseRequestItem $item) use ($suggestions): array {
            $remaining = max(0, (float) $item->quantity - (float) $item->ordered_quantity);

            // Sugestii furnizori pentru acest produs
            $suggestedIds   = [];
            $suggestedNames = [];
            if ($item->woo_product_id && isset($suggestions[$item->woo_product_id])) {
                foreach ($suggestions[$item->woo_product_id] as $ps) {
                    if ($ps->supplier) {
                        $suggestedIds[]   = $ps->supplier_id;
                        $suggestedNames[] = [
                            'id'           => $ps->supplier_id,
                            'name'         => $ps->supplier->name,
                            'is_preferred' => (bool) $ps->is_preferred,
                            'price'        => $ps->purchase_price,
                        ];
                    }
                }
            }

            return [
                'id'               => $item->id,
                'product_name'     => $item->product_name,
                'sku'              => $item->sku,
                'quantity'         => $remaining,
                'needed_by'        => $item->needed_by?->format('d.m.Y'),
                'is_urgent'        => $item->is_urgent,
                'is_reserved'      => $item->is_reserved,
                'client_reference' => $item->client_reference,
                'notes'            => $item->notes,
                'consultant'       => $item->purchaseRequest?->user?->name,
                'location'         => $item->purchaseRequest?->location?->name,
                'request_number'   => $item->purchaseRequest?->number,
                'request_id'       => $item->purchaseRequest?->id,
                'suggested_ids'    => $suggestedIds,
                'suggested'        => $suggestedNames,
            ];
        })->all();

        // Inițializăm selectedSuppliers cu preferatul dacă există
        foreach ($this->items as $item) {
            if (! isset($this->selectedSuppliers[$item['id']])) {
                $preferred = collect($item['suggested'])->firstWhere('is_preferred', true);
                $this->selectedSuppliers[$item['id']] = $preferred ? $preferred['id'] : null;
            }
        }
    }

    /**
     * Salvează furnizorul pentru un singur item.
     */
    public function saveAssignment(int $itemId): void
    {
        $supplierId = $this->selectedSuppliers[$itemId] ?? null;

        if (! $supplierId) {
            Notification::make()->warning()
                ->title('Selectați un furnizor înainte de alocare.')
                ->send();
            return;
        }

        $item = PurchaseRequestItem::find($itemId);
        if (! $item) {
            return;
        }

        $item->update(['supplier_id' => $supplierId]);

        $supplierName = $this->allSuppliers[$supplierId] ?? 'furnizor';

        Notification::make()->success()
            ->title("Furnizor alocat: {$supplierName}")
            ->body("Produsul \"{$item->product_name}\" a fost alocat și apare acum în coada de cumpărare.")
            ->send();

        $this->loadItems();
    }

    /**
     * Salvează toți itemii care au un furnizor selectat.
     */
    public function saveAll(): void
    {
        $saved = 0;

        foreach ($this->items as $item) {
            $supplierId = $this->selectedSuppliers[$item['id']] ?? null;
            if (! $supplierId) continue;

            PurchaseRequestItem::where('id', $item['id'])->update(['supplier_id' => $supplierId]);
            $saved++;
        }

        if ($saved === 0) {
            Notification::make()->warning()
                ->title('Niciun item nu are furnizor selectat.')
                ->send();
            return;
        }

        Notification::make()->success()
            ->title("{$saved} item(e) alocate cu succes.")
            ->send();

        $this->loadItems();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) return null;

        $count = PurchaseRequestItem::query()
            ->whereNull('supplier_id')
            ->where('status', PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                PurchaseRequest::STATUS_SUBMITTED,
                PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->whereRaw('quantity > COALESCE(ordered_quantity, 0)')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }
}
