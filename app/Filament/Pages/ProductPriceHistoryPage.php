<?php

namespace App\Filament\Pages;

use App\Models\ProductPriceLog;
use App\Models\ProductPurchasePriceLog;
use App\Models\User;
use App\Models\WooProduct;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class ProductPriceHistoryPage extends Page
{
    use \Livewire\WithPagination;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?string $navigationLabel = 'Istoric preț produs';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.product-price-history';

    public string $search = '';

    #[\Livewire\Attributes\Url(as: 'product', history: true)]
    public ?int $productId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin();
    }

    public function results(): Collection
    {
        $s = trim($this->search);
        if ($this->productId || mb_strlen($s) < 2) {
            return collect();
        }

        return WooProduct::query()
            ->where(fn ($q) => $q->where('sku', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'sku', 'name']);
    }

    /** Ultimele 30 de produse distincte cu modificare de preț vânzare (cel mai recent log per produs). */
    public function recentlyChanged(): Collection
    {
        $productIds = ProductPriceLog::query()
            ->selectRaw('woo_product_id, MAX(changed_at) as mx')
            ->groupBy('woo_product_id')
            ->orderByDesc('mx')
            ->limit(30)
            ->pluck('woo_product_id');

        if ($productIds->isEmpty()) {
            return collect();
        }

        $lastLogIds = ProductPriceLog::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('woo_product_id', $productIds)
            ->groupBy('woo_product_id')
            ->pluck('id');

        return ProductPriceLog::query()
            ->whereIn('id', $lastLogIds)
            ->with('product:id,sku,name')
            ->orderByDesc('changed_at')
            ->get(['id', 'woo_product_id', 'old_price', 'new_price', 'source', 'changed_at']);
    }

    public function select(int $id): void
    {
        $this->productId = $id;
        $this->search = '';
        $this->resetPage('salePage');
        $this->resetPage('purchasePage');
    }

    public function clearProduct(): void
    {
        $this->productId = null;
        $this->search = '';
        $this->resetPage('salePage');
        $this->resetPage('purchasePage');
    }

    public function getProductProperty(): ?WooProduct
    {
        return $this->productId ? WooProduct::find($this->productId) : null;
    }

    public function saleLogs(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return ProductPriceLog::where('woo_product_id', $this->productId)
            ->orderByDesc('changed_at')->paginate(25, ['*'], 'salePage');
    }

    public function purchaseLogs(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return ProductPurchasePriceLog::where('woo_product_id', $this->productId)
            ->with('supplier:id,name')
            ->orderByDesc('acquired_at')->paginate(25, ['*'], 'purchasePage');
    }

    /** Ultima achiziție (independent de paginare) — pentru antet și adaos. */
    public function latestPurchase(): ?ProductPurchasePriceLog
    {
        return ProductPurchasePriceLog::where('woo_product_id', $this->productId)
            ->orderByDesc('acquired_at')->first();
    }

    /** Cota TVA a produsului (din vat_rate; implicit 21%). */
    public function vatRate(): float
    {
        $r = $this->product?->vat_rate;

        return $r !== null && (float) $r > 0 ? (float) $r : 21.0;
    }

    /** Preț vânzare net (fără TVA) — pentru comparație corectă cu achiziția (care e netă). */
    public function currentSaleNet(): ?float
    {
        $gross = $this->currentSalePrice();

        return $gross !== null ? round($gross / (1 + $this->vatRate() / 100), 2) : null;
    }

    /** Prețul curent de vânzare — sursa de adevăr: stocul WinMentor (ProductStock.price), fallback pe regular_price. */
    public function currentSalePrice(): ?float
    {
        $stockPrice = \App\Models\ProductStock::where('woo_product_id', $this->productId)
            ->whereNotNull('price')->where('price', '>', 0)
            ->orderByDesc('synced_at')->value('price');

        $price = $stockPrice ?? $this->product?->regular_price;

        return $price !== null ? (float) $price : null;
    }

    public function chartPayload(): array
    {
        $sale = ProductPriceLog::where('woo_product_id', $this->productId)
            ->whereNotNull('changed_at')->orderBy('changed_at')->get(['old_price', 'new_price', 'changed_at']);
        $purchase = ProductPurchasePriceLog::where('woo_product_id', $this->productId)
            ->whereNotNull('acquired_at')->orderBy('acquired_at')->get(['unit_price', 'acquired_at']);

        $current = $this->currentSalePrice();

        // etichete = zilele cu modificări de vânzare + zilele de achiziție + AZI
        $saleChanges = [];
        foreach ($sale as $l) {
            $saleChanges[$l->changed_at->toDateString()] = (float) $l->new_price;
        }
        $purByDate = [];
        foreach ($purchase as $l) {
            $purByDate[$l->acquired_at->toDateString()] = (float) $l->unit_price;
        }
        $today = now()->toDateString();
        $labels = collect(array_keys($saleChanges))->merge(array_keys($purByDate))->push($today)
            ->unique()->sort()->values();

        // VÂNZARE = preț ținut (forward-fill): valoarea se menține între modificări, ancorată la prețul curent azi
        $initial = $sale->isNotEmpty() ? (float) $sale->first()->old_price : $current;
        $last = $initial;
        $saleSeries = $labels->map(function ($d) use (&$last, $saleChanges, $today, $current) {
            if (isset($saleChanges[$d])) {
                $last = $saleChanges[$d];
            }
            if ($d === $today && $current !== null) {
                $last = $current; // ancorează la prețul curent real
            }

            return $last;
        })->all();

        // ACHIZIȚIE = puncte discrete (fiecare intrare la prețul ei)
        $purSeries = $labels->map(fn ($d) => $purByDate[$d] ?? null)->all();

        return [
            'labels' => $labels->all(),
            'sale' => $saleSeries,
            'purchase' => $purSeries,
        ];
    }
}
