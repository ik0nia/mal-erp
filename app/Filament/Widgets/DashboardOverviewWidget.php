<?php

namespace App\Filament\Widgets;

use App\Models\BreachIncident;
use App\Models\ProductPriceLog;
use App\Models\ProductStock;
use App\Models\UptimeProbe;
use App\Models\WooOrder;
use App\Models\WooProduct;
use App\Services\Winmentor\WinmentorBridgeClient;
use Carbon\Carbon;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardOverviewWidget extends Widget
{
    protected string $view = 'filament.widgets.dashboard-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    public function system(): array
    {
        $bridge = Cache::remember('dash_bridge', 60, function () {
            try {
                return app(WinmentorBridgeClient::class)->isReachable();
            } catch (\Throwable) {
                return false;
            }
        });

        $uq = UptimeProbe::where('target', 'app')->whereBetween('checked_at', [now()->startOfMonth(), now()]);
        $ut = (clone $uq)->count();
        $uptime = $ut > 0 ? round((clone $uq)->where('is_up', true)->count() / $ut * 100, 2) : null;

        try {
            $files = glob('/mnt/backups/db/*.sql.gz') ?: [];
            $latest = $files ? max(array_map('filemtime', $files)) : null;
        } catch (\Throwable) {
            $latest = null;
        }

        return [
            'bridge' => $bridge,
            'uptime' => $uptime,
            'failed' => (int) DB::table('failed_jobs')->count(),
            'backup_age' => $latest ? Carbon::createFromTimestamp($latest)->diffForHumans() : null,
            'backup_stale' => $latest ? Carbon::createFromTimestamp($latest)->lt(now()->subHours(30)) : true,
            'breaches' => BreachIncident::whereNotIn('status', ['closed'])->count(),
        ];
    }

    public function business(): array
    {
        return [
            'published' => Cache::remember('dash_pub', 300, fn () => WooProduct::where('status', 'publish')->count()),
            'total' => Cache::remember('dash_tot', 300, fn () => WooProduct::count()),
            'stock_value' => Cache::remember('dash_sv', 300, fn () => (float) ProductStock::selectRaw('SUM(quantity*price) v')->value('v')),
            'zero_stock' => Cache::remember('dash_zs', 300, fn () => ProductStock::where('quantity', '<=', 0)->distinct('woo_product_id')->count('woo_product_id')),
            'price_today' => ProductPriceLog::whereDate('changed_at', today())->count(),
            'price_7d' => ProductPriceLog::where('changed_at', '>=', now()->subDays(7))->count(),
        ];
    }

    public function sales(): array
    {
        $sum = fn ($q) => ['n' => (clone $q)->count(), 'v' => round((float) (clone $q)->sum('total'))];

        return [
            'today' => $sum(WooOrder::whereDate('order_date', today())),
            'week' => $sum(WooOrder::where('order_date', '>=', now()->subDays(7)->startOfDay())),
            'month' => $sum(WooOrder::whereYear('order_date', now()->year)->whereMonth('order_date', now()->month)),
            'statuses' => WooOrder::whereYear('order_date', now()->year)->whereMonth('order_date', now()->month)
                ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->toArray(),
        ];
    }

    public function supplierStockouts(): array
    {
        return Cache::remember('dash_oos', 300, fn () => DB::select(
            'SELECT sup.name, COUNT(DISTINCT ps.woo_product_id) cnt
             FROM product_stocks ps
             JOIN product_suppliers psup ON psup.woo_product_id = ps.woo_product_id
             JOIN suppliers sup ON sup.id = psup.supplier_id
             WHERE ps.quantity <= 0
             GROUP BY sup.id, sup.name ORDER BY cnt DESC LIMIT 6'
        ));
    }

    public function priceChart(): array
    {
        $rows = ProductPriceLog::query()
            ->selectRaw('DATE(changed_at) as d, COUNT(*) as c')
            ->where('changed_at', '>=', now()->subDays(29)->startOfDay())
            ->groupBy('d')->pluck('c', 'd');

        $labels = [];
        $data = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $labels[] = $day->format('d.m');
            $data[] = (int) ($rows[$day->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
