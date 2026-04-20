<?php

namespace App\Filament\App\Pages;
use App\Models\RolePermission;
use App\Filament\App\Concerns\HasDynamicNavSort;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class BiDashboardPage extends Page
{
    use HasDynamicNavSort;

    protected string $view = 'filament.app.pages.bi-dashboard';

    protected static ?string $navigationLabel = 'Dashboard BI';
    protected static string|\UnitEnum|null $navigationGroup = 'Rapoarte';
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-chart-bar-square';
    protected static ?int    $navigationSort  = 89;
    protected static ?string $title           = 'Dashboard BI';

    // ── Filtru tab alerte ─────────────────────────────────────────────────────
    public string $tab = 'P0';

    // ── Filtru tab velocity ────────────────────────────────────────────────────
    public string $velocityTab = 'fast';

    /** @var array<int, array<string, mixed>> */
    public array $velocityRows = [];

    // ── KPI stoc ─────────────────────────────────────────────────────────────
    public string $kpiDay        = '—';
    public float  $stockValue    = 0.0;
    public float  $stockDelta    = 0.0;
    public float  $stockDeltaPct = 0.0;
    public int    $inStock       = 0;
    public int    $outOfStock    = 0;

    // ── Margin KPI ─────────────────────────────────────────────────────────
    public float  $stockValueCost       = 0;
    public float  $grossMarginTotal     = 0;
    public float  $grossMarginPct       = 0;
    public int    $productsWithCostData = 0;
    public string $marginTab            = 'top_margin';

    /** @var array<int, array<string, mixed>> */
    public array  $marginRows           = [];

    // ── Stoc & mișcări per furnizor ──────────────────────────────────────
    /** @var array<int, array<string, mixed>> */
    public array $supplierRows = [];

    // ── KPI Yesterday (for trend indicators) ──────────────────────────────
    public array $kpiYesterday = [];
    public array $kpiDeltas    = [];

    // ── Alerte ───────────────────────────────────────────────────────────────
    public string $alertDay = '—';
    public int    $countP0  = 0;
    public int    $countP1  = 0;
    public int    $countP2  = 0;

    /** @var array<int, array<string, mixed>> */
    public array $alertRows = [];

    // ─────────────────────────────────────────────────────────────────────────

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public function mount(): void
    {
        $this->loadKpi();
        $this->loadMarginKpi();
        $this->loadMarginRows();
        $this->loadSupplierBreakdown();
        $this->loadAlerts();
        $this->loadVelocityRows();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->loadAlertRows();
    }

    public function setVelocityTab(string $tab): void
    {
        $this->velocityTab = $tab;
        $this->loadVelocityRows();
    }

    public function setMarginTab(string $tab): void
    {
        $this->marginTab = $tab;
        $this->loadMarginRows();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function loadKpi(): void
    {
        $last = DB::table('bi_inventory_kpi_daily')->orderByDesc('day')->first();

        if (! $last) {
            return;
        }

        $this->kpiDay     = $last->day;
        $this->stockValue = round((float) $last->inventory_value_closing_total, 2);
        $this->inStock    = (int) $last->products_in_stock;
        $this->outOfStock = (int) $last->products_out_of_stock;

        $prev = DB::table('bi_inventory_kpi_daily')
            ->where('day', '<', $last->day)
            ->orderByDesc('day')
            ->first();

        if ($prev) {
            $prevVal          = (float) $prev->inventory_value_closing_total;
            $this->stockDelta = round($this->stockValue - $prevVal, 2);
            $this->stockDeltaPct = $prevVal > 0
                ? round($this->stockDelta / $prevVal * 100, 2)
                : 0.0;

            // Yesterday KPI data for trend indicators
            $this->kpiYesterday = [
                'stock_value'    => round($prevVal, 2),
                'in_stock'       => (int) $prev->products_in_stock,
                'out_of_stock'   => (int) $prev->products_out_of_stock,
            ];

            $this->kpiDeltas = [
                'stock_value'    => round($this->stockValue - $prevVal, 2),
                'in_stock'       => $this->inStock - (int) $prev->products_in_stock,
                'out_of_stock'   => $this->outOfStock - (int) $prev->products_out_of_stock,
            ];
        }
    }

    private function loadMarginKpi(): void
    {
        $last = DB::table('bi_inventory_kpi_daily')->orderByDesc('day')->first();

        if (! $last) {
            return;
        }

        $this->stockValueCost       = round((float) ($last->inventory_value_cost_closing_total ?? 0), 2);
        $this->grossMarginTotal     = round((float) ($last->gross_margin_total ?? 0), 2);
        $this->grossMarginPct       = round((float) ($last->gross_margin_pct ?? 0), 2);
        $this->productsWithCostData = (int) ($last->products_with_cost_data ?? 0);
    }

    private function loadMarginRows(): void
    {
        $query = DB::table('bi_product_margin_current as bpm')
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'bpm.reference_product_id')
            ->select(
                'bpm.reference_product_id as sku',
                'wp.name as product_name',
                'wp.id as product_id',
                'bpm.selling_price as sale_price',
                'bpm.purchase_price',
                'bpm.margin_pct',
                'bpm.stock_qty',
                'bpm.stock_margin_total',
                'bpm.supplier_name',
                'bpm.calculated_for_day',
            );

        if ($this->marginTab === 'top_margin') {
            $query->orderByDesc('bpm.margin_pct')->orderByDesc('bpm.stock_margin_total');
        } else {
            $query->where('bpm.margin_pct', '<', 10)
                  ->orderBy('bpm.margin_pct')
                  ->orderByDesc('bpm.stock_margin_total');
        }

        $this->marginRows = $query
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'sku'                => $r->sku,
                'product_name'       => $r->product_name ?? '—',
                'sale_price'         => (float) $r->sale_price,
                'purchase_price'     => (float) ($r->purchase_price ?? 0),
                'margin_pct'         => (float) $r->margin_pct,
                'stock_qty'          => (float) $r->stock_qty,
                'stock_margin_total' => (float) ($r->stock_margin_total ?? 0),
                'supplier_name'      => $r->supplier_name,
                'product_id'         => $r->product_id,
                'calculated_for_day' => $r->calculated_for_day,
            ])
            ->toArray();
    }

    private function loadAlerts(): void
    {
        $alertDay = DB::table('bi_inventory_alert_candidates_daily')
            ->orderByDesc('day')
            ->value('day');

        if (! $alertDay) {
            return;
        }

        $this->alertDay = $alertDay;

        $counts = DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', $alertDay)
            ->selectRaw('risk_level, COUNT(*) as cnt')
            ->groupBy('risk_level')
            ->get()
            ->keyBy('risk_level');

        $this->countP0 = (int) ($counts->get('P0')?->cnt ?? 0);
        $this->countP1 = (int) ($counts->get('P1')?->cnt ?? 0);
        $this->countP2 = (int) ($counts->get('P2')?->cnt ?? 0);

        // Previous day alert counts for trend indicators
        $prevAlertDay = DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', '<', $alertDay)
            ->orderByDesc('day')
            ->value('day');

        if ($prevAlertDay) {
            $prevCounts = DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $prevAlertDay)
                ->selectRaw('risk_level, COUNT(*) as cnt')
                ->groupBy('risk_level')
                ->get()
                ->keyBy('risk_level');

            $this->kpiDeltas['countP0'] = $this->countP0 - (int) ($prevCounts->get('P0')?->cnt ?? 0);
            $this->kpiDeltas['countP1'] = $this->countP1 - (int) ($prevCounts->get('P1')?->cnt ?? 0);
            $this->kpiDeltas['countP2'] = $this->countP2 - (int) ($prevCounts->get('P2')?->cnt ?? 0);
        }

        $this->loadAlertRows();
    }

    private function loadVelocityRows(): void
    {
        $baseQuery = DB::table('bi_product_velocity_current as v')
            ->leftJoin('woo_products as p', 'p.sku', '=', 'v.reference_product_id')
            ->select(
                'v.reference_product_id as sku',
                'p.id as product_id',
                'p.name as product_name',
                'v.avg_out_qty_7d',
                'v.avg_out_qty_30d',
                'v.avg_out_qty_90d',
                'v.out_qty_30d',
                'v.out_qty_90d',
                'v.last_movement_day',
                'v.days_since_last_movement',
                'v.calculated_for_day',
            );

        if ($this->velocityTab === 'fast') {
            $this->velocityRows = $baseQuery
                ->where('v.avg_out_qty_30d', '>', 0)
                ->orderByDesc('v.avg_out_qty_30d')
                ->limit(50)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->toArray();
        } else {
            // Lente: nu au avut mișcare în ultimele 30+ zile (sau deloc) și nu sunt în "fast"
            $this->velocityRows = $baseQuery
                ->where(function ($q) {
                    $q->where('v.days_since_last_movement', '>=', 30)
                      ->orWhereNull('v.days_since_last_movement');
                })
                ->orderByRaw('COALESCE(v.days_since_last_movement, 9999) DESC')
                ->limit(50)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->toArray();
        }
    }

    private function loadAlertRows(): void
    {
        if ($this->alertDay === '—') {
            $this->alertRows = [];
            return;
        }

        $this->alertRows = DB::table('bi_inventory_alert_candidates_daily as a')
            ->leftJoin('woo_products as p', 'p.sku', '=', 'a.reference_product_id')
            ->where('a.day', $this->alertDay)
            ->where('a.risk_level', $this->tab)
            ->where(fn ($q) => $q->whereNull('p.product_type')->orWhere('p.product_type', '!=', 'production'))
            ->where(fn ($q) => $q->whereNull('p.is_discontinued')->orWhere('p.is_discontinued', false))
            ->where(fn ($q) => $q->whereNull('p.procurement_type')->orWhere('p.procurement_type', '!=', 'on_demand'))
            ->orderByRaw('COALESCE(a.days_left_estimate, 9999) ASC, a.stock_value DESC')
            ->limit(200)
            ->select('a.*', 'p.id as product_id')
            ->get()
            ->map(fn ($r) => [
                'sku'              => $r->reference_product_id,
                'product_id'       => $r->product_id,
                'name'             => $r->product_name ?? '—',
                'closing_qty'      => (float) $r->closing_qty,
                'closing_price'    => $r->closing_price !== null ? (float) $r->closing_price : null,
                'stock_value'      => (float) $r->stock_value,
                'avg_out_30d'      => (float) $r->avg_out_30d,
                'days_left'        => $r->days_left_estimate !== null ? (float) $r->days_left_estimate : null,
                'reason_flags'     => json_decode($r->reason_flags ?? '[]', true) ?? [],
            ])
            ->toArray();
    }

    private function loadSupplierBreakdown(): void
    {
        // Stoc per furnizor din bi_product_margin_current
        $stockRows = DB::table('bi_product_margin_current as m')
            ->selectRaw("
                COALESCE(m.supplier_id, 0)                                     AS supplier_id,
                COALESCE(m.supplier_name, 'Fără furnizor')                     AS supplier_name,
                COUNT(DISTINCT m.reference_product_id)                         AS product_count,
                ROUND(SUM(m.stock_qty), 0)                                     AS stock_qty,
                ROUND(SUM(m.stock_value_retail), 0)                            AS value_retail,
                ROUND(SUM(m.stock_value_cost), 0)                              AS value_cost,
                ROUND(SUM(m.stock_margin_total), 0)                            AS margin_total,
                ROUND(
                    CASE WHEN SUM(m.stock_value_retail) > 0
                         THEN SUM(m.stock_margin_total) / SUM(m.stock_value_retail) * 100
                         ELSE 0 END, 1)                                        AS margin_pct
            ")
            ->where('m.stock_value_cost', '>', 0)
            ->groupByRaw("COALESCE(m.supplier_id, 0), COALESCE(m.supplier_name, 'Fără furnizor')")
            ->orderByRaw("ROUND(SUM(m.stock_value_cost), 0) DESC")
            ->get()
            ->keyBy('supplier_id');

        // Mișcări ultimele 7 zile per furnizor (ieșiri + intrări)
        $since = now()->subDays(30)->toDateString();
        $movRows = DB::table('daily_stock_metrics as dsm')
            ->join('product_suppliers as ps', function ($j) {
                $j->on('ps.woo_product_id', '=', 'dsm.woo_product_id')
                  ->where('ps.is_preferred', true);
            })
            ->leftJoin('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->where('dsm.day', '>=', $since)
            ->selectRaw("
                COALESCE(ps.supplier_id, 0)                                                          AS supplier_id,
                ROUND(SUM(CASE WHEN dsm.daily_available_variation < 0
                               THEN ABS(dsm.daily_available_variation * COALESCE(dsm.closing_sell_price,0))
                               ELSE 0 END) / 1.21, 0)                                               AS out_value,
                ROUND(SUM(CASE WHEN dsm.daily_available_variation > 0
                               THEN dsm.daily_available_variation * COALESCE(dsm.closing_sell_price,0)
                               ELSE 0 END) / 1.21, 0)                                               AS in_value,
                ROUND(SUM(CASE WHEN dsm.daily_available_variation < 0
                               THEN ABS(dsm.daily_available_variation) ELSE 0 END), 0)              AS out_qty,
                ROUND(SUM(CASE WHEN dsm.daily_available_variation > 0
                               THEN dsm.daily_available_variation ELSE 0 END), 0)                   AS in_qty
            ")
            ->groupBy('ps.supplier_id')
            ->get()
            ->keyBy('supplier_id');

        $this->supplierRows = $stockRows->map(function ($row) use ($movRows) {
            $mov = $movRows->get($row->supplier_id);
            return [
                'supplier_id'   => (int) $row->supplier_id,
                'supplier_name' => $row->supplier_name,
                'product_count' => (int) $row->product_count,
                'stock_qty'     => (float) $row->stock_qty,
                'value_retail'  => (float) $row->value_retail,
                'value_cost'    => (float) $row->value_cost,
                'margin_total'  => (float) $row->margin_total,
                'margin_pct'    => (float) $row->margin_pct,
                'out_value'     => $mov ? (float) $mov->out_value : 0,
                'in_value'      => $mov ? (float) $mov->in_value  : 0,
                'out_qty'       => $mov ? (float) $mov->out_qty   : 0,
                'in_qty'        => $mov ? (float) $mov->in_qty    : 0,
            ];
        })->values()->toArray();
    }

    public function bootGuardAccess(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }
}
