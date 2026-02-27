<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class OnlineShopReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Rapoarte';

    protected static ?string $navigationLabel = 'Raport Magazin Online';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.app.pages.online-shop-report';

    public int $year;

    public ?int $month = null;

    // Stat cards
    public float $statRevenue = 0.0;

    public int $statOrders = 0;

    public float $statAvgOrder = 0.0;

    public int $statCompleted = 0;

    public int $statProcessing = 0;

    // Inline data arrays
    /** @var array<int, array{name: string, revenue: float, orders: int}> */
    public array $categoryData = [];

    /** @var array<int, array{name: string, revenue: float, orders: int}> */
    public array $supplierData = [];

    /** @var array<int, array{name: string, revenue: float, orders: int}> */
    public array $brandData = [];

    /** @var array<int, array{status: string, cnt: int, revenue: float}> */
    public array $statusData = [];

    /** @var array<int, int> */
    public array $availableYears = [];

    public function mount(): void
    {
        $this->year = now()->year;

        $this->availableYears = DB::table('woo_orders')
            ->selectRaw('YEAR(order_date) as yr')
            ->whereNotNull('order_date')
            ->groupBy('yr')
            ->orderByDesc('yr')
            ->pluck('yr')
            ->map(fn ($y) => (int) $y)
            ->toArray();

        if (empty($this->availableYears)) {
            $this->availableYears = [$this->year];
        }

        $this->computeStats();
    }

    public function setYear(int $year): void
    {
        $this->year  = $year;
        $this->month = null;
        $this->computeStats();
        $this->dispatch('onlineShopSetPeriod', year: $this->year, month: null);
    }

    public function setMonth(?int $month): void
    {
        $this->month = $month;
        $this->computeStats();
        $this->dispatch('onlineShopSetPeriod', year: $this->year, month: $this->month);
    }

    private function computeStats(): void
    {
        $excluded = ['cancelled', 'refunded', 'failed'];

        // Global revenue stats
        $row = DB::table('woo_orders')
            ->whereRaw('YEAR(order_date) = ?', [$this->year])
            ->when($this->month, fn ($q) => $q->whereRaw('MONTH(order_date) = ?', [$this->month]))
            ->whereNotIn('status', $excluded)
            ->selectRaw('COUNT(*) as cnt, SUM(total) as revenue, AVG(total) as avg_total')
            ->first();

        $this->statRevenue  = round((float) ($row->revenue ?? 0), 2);
        $this->statOrders   = (int) ($row->cnt ?? 0);
        $this->statAvgOrder = round((float) ($row->avg_total ?? 0), 2);

        $baseCount = fn (string $status): int => (int) DB::table('woo_orders')
            ->whereRaw('YEAR(order_date) = ?', [$this->year])
            ->when($this->month, fn ($q) => $q->whereRaw('MONTH(order_date) = ?', [$this->month]))
            ->where('status', $status)
            ->count();

        $this->statCompleted  = $baseCount('completed');
        $this->statProcessing = $baseCount('processing');

        // Status breakdown (all statuses)
        $this->statusData = DB::table('woo_orders')
            ->whereRaw('YEAR(order_date) = ?', [$this->year])
            ->when($this->month, fn ($q) => $q->whereRaw('MONTH(order_date) = ?', [$this->month]))
            ->selectRaw('status, COUNT(*) as cnt, SUM(total) as revenue')
            ->groupBy('status')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($r) => [
                'status'  => $r->status,
                'cnt'     => (int) $r->cnt,
                'revenue' => round((float) $r->revenue, 2),
            ])
            ->toArray();

        // Root categories
        $this->categoryData = DB::table('woo_orders as o')
            ->join('woo_order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('woo_products as wp', function ($j) {
                $j->on('wp.woo_id', '=', 'oi.woo_product_id')
                  ->whereColumn('wp.connection_id', 'o.connection_id');
            })
            ->join('woo_product_category as wpc', 'wpc.woo_product_id', '=', 'wp.id')
            ->join('woo_categories as wc', 'wc.id', '=', 'wpc.woo_category_id')
            ->leftJoin('woo_categories as par', 'par.id', '=', 'wc.parent_id')
            ->leftJoin('woo_categories as gp', 'gp.id', '=', 'par.parent_id')
            ->whereNotIn('o.status', $excluded)
            ->whereRaw('YEAR(o.order_date) = ?', [$this->year])
            ->when($this->month, fn ($q) => $q->whereRaw('MONTH(o.order_date) = ?', [$this->month]))
            ->selectRaw('
                COALESCE(gp.name, par.name, wc.name) as cat_name,
                SUM(oi.total) as revenue,
                COUNT(DISTINCT o.id) as orders
            ')
            ->groupBy('cat_name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'name'    => $r->cat_name ?? '(fără categorie)',
                'revenue' => round((float) $r->revenue, 2),
                'orders'  => (int) $r->orders,
            ])
            ->toArray();

        // Top suppliers
        $this->supplierData = DB::table('woo_orders as o')
            ->join('woo_order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('woo_products as wp', function ($j) {
                $j->on('wp.woo_id', '=', 'oi.woo_product_id')
                  ->whereColumn('wp.connection_id', 'o.connection_id');
            })
            ->join('product_suppliers as ps', 'ps.woo_product_id', '=', 'wp.id')
            ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->whereNotIn('o.status', $excluded)
            ->whereRaw('YEAR(o.order_date) = ?', [$this->year])
            ->when($this->month, fn ($q) => $q->whereRaw('MONTH(o.order_date) = ?', [$this->month]))
            ->selectRaw('s.id, s.name, SUM(oi.total) as revenue, COUNT(DISTINCT o.id) as orders')
            ->groupBy('s.id', 's.name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'name'    => $r->name,
                'revenue' => round((float) $r->revenue, 2),
                'orders'  => (int) $r->orders,
            ])
            ->toArray();

        // Top brands (via wp.brand varchar)
        $this->brandData = DB::table('woo_orders as o')
            ->join('woo_order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('woo_products as wp', function ($j) {
                $j->on('wp.woo_id', '=', 'oi.woo_product_id')
                  ->whereColumn('wp.connection_id', 'o.connection_id');
            })
            ->whereNotIn('o.status', $excluded)
            ->whereRaw('YEAR(o.order_date) = ?', [$this->year])
            ->when($this->month, fn ($q) => $q->whereRaw('MONTH(o.order_date) = ?', [$this->month]))
            ->whereNotNull('wp.brand')
            ->where('wp.brand', '!=', '')
            ->selectRaw('wp.brand as brand_name, SUM(oi.total) as revenue, COUNT(DISTINCT o.id) as orders')
            ->groupBy('wp.brand')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'name'    => $r->brand_name,
                'revenue' => round((float) $r->revenue, 2),
                'orders'  => (int) $r->orders,
            ])
            ->toArray();
    }
}
