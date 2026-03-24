<?php

namespace App\Filament\App\Pages;

use App\Models\SupplierPriceQuote;
use App\Models\WooProduct;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Pagină de price intelligence: prețuri extrase din emailuri,
 * cu comparație față de prețul curent din catalog.
 *
 * Afișează:
 *  - Furnizor + produs menționat în email
 *  - Prețul oferit și data ofertei
 *  - Prețul curent din catalog (dacă produsul a fost potrivit)
 *  - Delta % (mai ieftin / mai scump față de catalog)
 */
class SupplierPriceIntelligencePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Prețuri din Emailuri';
    protected static string|\UnitEnum|null $navigationGroup = 'Comunicare';
    protected static ?int    $navigationSort  = 3;
    protected string  $view            = 'filament.app.pages.supplier-price-intelligence';

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public string $filterSupplier = '';
    public string $filterMatched  = '';   // '' | matched | unmatched
    public string $search         = '';

    public function getQuotes(): \Illuminate\Support\Collection
    {
        return SupplierPriceQuote::with(['supplier', 'product', 'email'])
            ->when($this->filterSupplier, fn ($q) => $q->where('supplier_id', $this->filterSupplier))
            ->when($this->filterMatched === 'matched',   fn ($q) => $q->whereNotNull('woo_product_id'))
            ->when($this->filterMatched === 'unmatched', fn ($q) => $q->whereNull('woo_product_id'))
            ->when($this->search, fn ($q) => $q->where('product_name_raw', 'like', "%{$this->search}%"))
            ->orderByDesc('quoted_at')
            ->limit(200)
            ->get()
            ->map(function ($quote) {
                $currentPrice = $quote->product?->price
                    ?? $quote->product?->regular_price;

                $delta = null;
                $deltaColor = 'gray';

                if ($currentPrice && $currentPrice > 0) {
                    $delta = round(($quote->unit_price - $currentPrice) / $currentPrice * 100, 1);
                    $deltaColor = $delta < -5 ? 'green' : ($delta > 5 ? 'red' : 'yellow');
                }

                return [
                    'quote'        => $quote,
                    'currentPrice' => $currentPrice,
                    'delta'        => $delta,
                    'deltaColor'   => $deltaColor,
                ];
            });
    }

    public function getSupplierOptions(): array
    {
        return SupplierPriceQuote::with('supplier')
            ->select('supplier_id')
            ->distinct()
            ->get()
            ->mapWithKeys(fn ($q) => [$q->supplier_id => $q->supplier?->name ?? "#{$q->supplier_id}"])
            ->toArray();
    }

    public function getStats(): array
    {
        $total    = SupplierPriceQuote::count();
        $matched  = SupplierPriceQuote::whereNotNull('woo_product_id')->count();
        $cheaper  = SupplierPriceQuote::whereNotNull('woo_product_id')
            ->whereHas('product', fn ($q) => $q->whereRaw('supplier_price_quotes.unit_price < woo_products.price * 0.95'))
            ->count();

        return compact('total', 'matched', 'cheaper');
    }
}
