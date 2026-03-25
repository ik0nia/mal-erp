<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Models\RolePermission;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class BiReplenishmentPage extends Page
{
    use HasDynamicNavSort;

    protected string $view = 'filament.app.pages.bi-replenishment';

    protected static ?string $navigationLabel = 'Sugestii reaprovizionare';
    protected static string|\UnitEnum|null $navigationGroup = 'Rapoarte';
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-arrow-path';
    protected static ?int    $navigationSort  = 92;
    protected static ?string $title           = 'Sugestii reaprovizionare';

    // ── Tab filtru ──────────────────────────────────────────────────────────
    public string $tab = 'urgent';

    // ── KPI stats ───────────────────────────────────────────────────────────
    public string $calcDay        = '—';
    public float  $totalQty       = 0;
    public float  $totalCost      = 0;
    public int    $countUrgent    = 0;
    public int    $countSoon      = 0;
    public int    $countPlanned   = 0;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    // ── Access ──────────────────────────────────────────────────────────────

    public static function shouldRegisterNavigation(): bool
    {
        return RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return RolePermission::check(static::class, 'can_access');
    }

    public function mount(): void
    {
        $this->loadStats();
        $this->loadRows();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->loadRows();
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function loadStats(): void
    {
        $day = DB::table('bi_replenishment_suggestions')
            ->orderByDesc('calculated_for_day')
            ->value('calculated_for_day');

        if (! $day) {
            return;
        }

        $this->calcDay = $day;

        $stats = DB::table('bi_replenishment_suggestions')
            ->where('calculated_for_day', $day)
            ->selectRaw("
                SUM(suggested_qty) as total_qty,
                SUM(estimated_cost) as total_cost,
                SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as cnt_urgent,
                SUM(CASE WHEN priority = 'soon' THEN 1 ELSE 0 END) as cnt_soon,
                SUM(CASE WHEN priority = 'planned' THEN 1 ELSE 0 END) as cnt_planned
            ")
            ->first();

        if ($stats) {
            $this->totalQty     = round((float) $stats->total_qty, 0);
            $this->totalCost    = round((float) $stats->total_cost, 2);
            $this->countUrgent  = (int) $stats->cnt_urgent;
            $this->countSoon    = (int) $stats->cnt_soon;
            $this->countPlanned = (int) $stats->cnt_planned;
        }
    }

    private function loadRows(): void
    {
        if ($this->calcDay === '—') {
            $this->rows = [];
            return;
        }

        $query = DB::table('bi_replenishment_suggestions')
            ->where('calculated_for_day', $this->calcDay);

        if ($this->tab !== 'all') {
            $query->where('priority', $this->tab);
        }

        $this->rows = $query
            ->orderByRaw("FIELD(priority, 'urgent', 'soon', 'planned')")
            ->orderBy('days_of_stock')
            ->orderByDesc('estimated_cost')
            ->limit(300)
            ->get()
            ->map(fn ($r) => [
                'woo_product_id'        => (int) $r->woo_product_id,
                'sku'                   => $r->reference_product_id,
                'name'                  => $r->product_name ?? '—',
                'current_stock'         => (float) $r->current_stock,
                'days_of_stock'         => (float) $r->days_of_stock,
                'avg_daily_consumption' => (float) $r->avg_daily_consumption,
                'reorder_point'         => (float) $r->reorder_point,
                'suggested_qty'         => (float) $r->suggested_qty,
                'estimated_cost'        => (float) $r->estimated_cost,
                'margin_pct'            => $r->margin_pct !== null ? (float) $r->margin_pct : null,
                'abc_class'             => $r->abc_class,
                'priority'              => $r->priority,
                'supplier_name'         => $r->supplier_name,
            ])
            ->toArray();
    }

    public function bootGuardAccess(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }
}
