<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class BiDashboardPage extends Page
{
    protected static string $view = 'filament.app.pages.bi-dashboard';

    protected static ?string $navigationLabel = 'Dashboard BI';
    protected static ?string $navigationGroup = 'Rapoarte';
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar-square';
    protected static ?int    $navigationSort  = 89;
    protected static ?string $title           = 'Dashboard BI';

    // ── Filtru tab alerte ─────────────────────────────────────────────────────
    public string $tab = 'P0';

    // ── KPI stoc ─────────────────────────────────────────────────────────────
    public string $kpiDay        = '—';
    public float  $stockValue    = 0.0;
    public float  $stockDelta    = 0.0;
    public float  $stockDeltaPct = 0.0;
    public int    $inStock       = 0;
    public int    $outOfStock    = 0;

    // ── Alerte ───────────────────────────────────────────────────────────────
    public string $alertDay = '—';
    public int    $countP0  = 0;
    public int    $countP1  = 0;
    public int    $countP2  = 0;

    /** @var array<int, array<string, mixed>> */
    public array $alertRows = [];

    // ─────────────────────────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->loadKpi();
        $this->loadAlerts();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->loadAlertRows();
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
        }
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

        $this->loadAlertRows();
    }

    private function loadAlertRows(): void
    {
        if ($this->alertDay === '—') {
            $this->alertRows = [];
            return;
        }

        $this->alertRows = DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', $this->alertDay)
            ->where('risk_level', $this->tab)
            ->orderByRaw('COALESCE(days_left_estimate, 9999) ASC, stock_value DESC')
            ->limit(200)
            ->get()
            ->map(fn ($r) => [
                'sku'              => $r->reference_product_id,
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
}
