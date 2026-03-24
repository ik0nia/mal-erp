<?php

namespace App\Filament\App\Widgets;

use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class StockOutSupplierWidget extends Widget
{
    protected string $view = 'filament.app.widgets.stock-out-supplier';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    protected static ?int $sort = 5;

    /** @var array<int, object> */
    public array $rows = [];

    public int $total = 0;

    public static function canView(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public function mount(): void
    {
        $this->loadData();
    }

    private function loadData(): void
    {
        $user = auth()->user();

        $query = DB::table('product_suppliers as ps')
            ->join('woo_products as wp', 'wp.id', '=', 'ps.woo_product_id')
            ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->leftJoin(
                DB::raw('(SELECT woo_product_id, SUM(quantity) as total_qty FROM product_stocks GROUP BY woo_product_id) stk'),
                'stk.woo_product_id', '=', 'wp.id'
            )
            ->leftJoin('bi_product_velocity_current as bpv', 'bpv.reference_product_id', '=', 'wp.sku')
            ->where('wp.is_discontinued', false)
            ->where('wp.procurement_type', '!=', 'on_demand')
            ->whereRaw('COALESCE(stk.total_qty, 0) = 0')
            ->whereRaw('COALESCE(bpv.avg_out_qty_7d, 0) > 0')
            ->select([
                'wp.id as product_id',
                'wp.sku',
                'wp.name',
                'wp.main_image_url',
                's.id as supplier_id',
                's.name as supplier_name',
                DB::raw('COALESCE(bpv.avg_out_qty_7d, 0) as velocity_day'),
                DB::raw('COALESCE(bpv.avg_out_qty_7d * 7, 0) as velocity_7d'),
            ]);

        if ($user instanceof User && $user->role === User::ROLE_CONSULTANT_VANZARI) {
            $query->whereExists(fn ($q) => $q->from('supplier_buyers')
                ->whereColumn('supplier_buyers.supplier_id', 's.id')
                ->where('supplier_buyers.user_id', $user->id));
        }

        $results = $query
            ->orderByDesc('velocity_day')
            ->limit(100)
            ->get();

        $this->total = $results->count();
        $this->rows  = $results->take(50)->all();
    }
}
