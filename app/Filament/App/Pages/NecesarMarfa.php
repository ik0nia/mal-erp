<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Models\IntegrationConnection;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class NecesarMarfa extends Page
{
    use EnforcesLocationScope;

    protected static ?string $navigationIcon  = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Achiziții';
    protected static ?string $navigationLabel = 'Necesar de marfă';
    protected static ?int    $navigationSort  = 0;

    protected static string $view = 'filament.app.pages.necesar-marfa';

    #[Url]
    public int $threshold = 5;

    /** @var Collection<int, object> */
    public Collection $suppliers;

    public int $statSuppliers    = 0;
    public int $statProducts     = 0;
    public int $statZeroStock    = 0;

    public function mount(): void
    {
        $this->suppliers = collect();
        $this->load();
    }

    public function updatedThreshold(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $threshold = max(0, (int) $this->threshold);

        // Produse cu stoc total < threshold, cu cel puțin un furnizor
        $rows = DB::table('product_suppliers as ps')
            ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->join('woo_products as wp', 'wp.id', '=', 'ps.woo_product_id')
            ->leftJoin(
                DB::raw('(SELECT woo_product_id, COALESCE(SUM(quantity),0) as total_qty
                          FROM product_stocks GROUP BY woo_product_id) stk'),
                'stk.woo_product_id', '=', 'wp.id'
            )
            ->where('s.is_active', true)
            ->where(DB::raw('COALESCE(stk.total_qty, 0)'), '<', $threshold)
            ->select(
                's.id       as supplier_id',
                's.name     as supplier_name',
                's.logo_url as supplier_logo',
                'wp.id  as product_id',
                'wp.sku',
                'wp.name',
                'wp.brand',
                'wp.unit',
                'ps.is_preferred',
                DB::raw('COALESCE(stk.total_qty, 0) as stock'),
                DB::raw('EXISTS(SELECT 1 FROM daily_stock_metrics dsm WHERE dsm.woo_product_id = wp.id) as has_history')
            )
            ->orderBy('s.name')
            ->orderBy(DB::raw('COALESCE(stk.total_qty, 0)'))
            ->orderBy('wp.name')
            ->get();

        // Grupare pe furnizor, deduplicare produs per furnizor
        $this->suppliers = $rows
            ->groupBy('supplier_id')
            ->map(function (Collection $items) {
                $first    = $items->first();
                $products = $items->unique('product_id')->values();
                return (object) [
                    'id'             => $first->supplier_id,
                    'name'           => $first->supplier_name,
                    'logo'           => $first->supplier_logo,
                    'products'       => $products->filter(fn ($p) => (bool) $p->has_history)->values(),
                    'extraProducts'  => $products->filter(fn ($p) => ! (bool) $p->has_history)->values(),
                ];
            })
            ->values()
            ->sortBy('name');

        $allProducts = $rows->unique('product_id');

        $this->statSuppliers = $this->suppliers->count();
        $this->statProducts  = $allProducts->count();
        $this->statZeroStock = $allProducts->where('stock', 0)->count();
    }

    private function getConnectionIds(): array
    {
        $user = static::currentUser();
        if (! $user) {
            return [];
        }
        $query = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE);
        if (! $user->isSuperAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->whereIn('location_id', $user->operationalLocationIds())
                  ->orWhereNull('location_id');
            });
        }
        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
