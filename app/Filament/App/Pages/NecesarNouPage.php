<?php

namespace App\Filament\App\Pages;

use App\Services\Purchasing\ReplenishmentCalculator;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Necesar de marfă — versiune nouă, intuitivă (PILOT codrut).
 * Filtre proprii + afișare cu indicii simple + mini-grafic sezonier.
 * Cantitatea vine din serviciul unic (lead real + sezon + netting + ambalaj).
 */
class NecesarNouPage extends Page
{
    protected string $view = 'filament.app.pages.necesar-nou';

    protected static ?string $navigationLabel = 'Necesar (nou)';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $title = 'Necesar de marfă';
    protected static ?string $slug = 'necesar-nou';
    protected static ?int $navigationSort = -2;

    private const PILOT_EMAILS = ['codrut@ikonia.ro'];

    public string $search = '';
    public ?int $supplierId = null;
    public ?int $categoryId = null;
    public string $urgency = 'all';   // all | zero | d7 | d14
    public bool $onlyNeeded = true;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->email, self::PILOT_EMAILS, true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->supplierId = null;
        $this->categoryId = null;
        $this->urgency = 'all';
        $this->onlyNeeded = true;
    }

    /** Opțiuni pentru dropdown-uri (furnizori & categorii cu produse). */
    public function getFilterOptions(): array
    {
        $suppliers = DB::table('suppliers')->where('is_active', true)
            ->orderBy('name')->pluck('name', 'id')->all();
        $categories = DB::table('woo_categories as c')
            ->join('woo_product_category as pc', 'pc.woo_category_id', '=', 'c.id')
            ->select('c.id', 'c.name')->distinct()->orderBy('c.name')->pluck('name', 'id')->all();

        return ['suppliers' => $suppliers, 'categories' => $categories];
    }

    public function getData(): array
    {
        $calc = new ReplenishmentCalculator();
        $now  = now();
        $curMonth = (int) $now->format('n');

        // ---- candidați: eligibili, cu viteză, stoc sub un orizont larg ----
        $q = DB::table('woo_products as wp')
            ->join('bi_product_velocity_current as b', 'b.reference_product_id', '=', 'wp.sku')
            ->leftJoin(DB::raw('(SELECT woo_product_id, SUM(quantity) qty FROM product_stocks GROUP BY woo_product_id) stk'), 'stk.woo_product_id', '=', 'wp.id')
            ->where('wp.is_discontinued', false)
            ->whereRaw("COALESCE(wp.procurement_type,'stock') <> 'on_demand'")
            ->whereRaw("COALESCE(wp.product_type,'shop') = 'shop'")
            ->where('b.avg_out_qty_30d', '>', 0)
            ->whereRaw('COALESCE(stk.qty,0) < b.avg_out_qty_30d * 60');

        if (trim($this->search) !== '') {
            $s = '%' . trim($this->search) . '%';
            $q->where(function ($w) use ($s) {
                $w->where('wp.name', 'like', $s)->orWhere('wp.sku', 'like', $s)
                  ->orWhereExists(fn ($e) => $e->from('product_suppliers as psx')
                      ->whereColumn('psx.woo_product_id', 'wp.id')->where('psx.supplier_sku', 'like', $s));
            });
        }
        if ($this->categoryId) {
            $q->whereExists(fn ($e) => $e->from('woo_product_category as pc')
                ->whereColumn('pc.woo_product_id', 'wp.id')->where('pc.woo_category_id', $this->categoryId));
        }
        if ($this->supplierId) {
            $q->whereExists(fn ($e) => $e->from('product_suppliers as psx')
                ->whereColumn('psx.woo_product_id', 'wp.id')->where('psx.supplier_id', $this->supplierId));
        }

        $cands = $q->select('wp.id', 'wp.sku', 'wp.name', 'wp.brand', DB::raw('COALESCE(stk.qty,0) as stock'))
            ->limit(1000)->get();

        if ($cands->isEmpty()) {
            return ['rows' => [], 'total' => 0, 'to_order' => 0];
        }

        // ---- furnizor preferat per produs ----
        $pids = $cands->pluck('id')->all();
        $sup = DB::table('product_suppliers as ps')->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->whereIn('ps.woo_product_id', $pids)->where('s.is_active', true)
            ->select('ps.woo_product_id', 'ps.supplier_id', 'ps.is_preferred', 's.name')
            ->orderByDesc('ps.is_preferred')->orderBy('s.name')->get()->groupBy('woo_product_id');

        $pairs = []; $supName = [];
        foreach ($cands as $c) {
            $list = $sup->get($c->id);
            if (! $list) continue;
            $chosen = $this->supplierId ? $list->firstWhere('supplier_id', $this->supplierId) : $list->first();
            if (! $chosen) continue;
            $pairs[$c->id] = [$c->id, (int) $chosen->supplier_id];
            $supName[$c->id] = $chosen->name;
        }
        if (empty($pairs)) {
            return ['rows' => [], 'total' => 0, 'to_order' => 0];
        }

        $rec = $calc->recommendBatch(array_values($pairs));

        // curbele de sezon ale categoriilor implicate
        $catIds = collect($rec)->pluck('category_id')->filter()->unique()->values()->all();
        $curves = DB::table('bi_seasonality_category')->whereIn('woo_category_id', $catIds ?: [0])
            ->get()->groupBy('woo_category_id')
            ->map(fn ($g) => $g->pluck('seasonal_index', 'month')->map(fn ($v) => round((float) $v, 2))->all());

        $rows = [];
        foreach ($cands as $c) {
            if (! isset($pairs[$c->id])) continue;
            $r = $rec[$c->id . '_' . $pairs[$c->id][1]] ?? null;
            if (! $r) continue;

            $days = $r['days_until_stockout'];

            // filtre pe rezultat
            if ($this->urgency === 'zero' && (float) $c->stock > 0) continue;
            if ($this->urgency === 'd7'  && ! ($days !== null && $days < 7)) continue;
            if ($this->urgency === 'd14' && ! ($days !== null && $days < 14)) continue;
            if ($this->onlyNeeded && (int) $r['recommended_qty'] <= 0) continue;

            // indicii simple
            $cues = [];
            if ($days !== null && $days < 7)       $cues[] = ['i' => '🔴', 't' => 'stoc critic'];
            elseif ($days !== null && $days < 14)  $cues[] = ['i' => '🟠', 't' => 'stoc redus'];
            if ((float) $r['season'] > 1.1)        $cues[] = ['i' => '🔺', 't' => 'intră în sezon'];
            elseif ((float) $r['season'] < 0.9)    $cues[] = ['i' => '🔻', 't' => 'extrasezon'];
            if (in_array('suprastoc', $r['flags'] ?? [])) $cues[] = ['i' => '📦', 't' => 'suprastoc'];

            $rows[] = [
                'id' => $c->id, 'name' => $c->name, 'sku' => $c->sku, 'brand' => $c->brand,
                'supplier' => $supName[$c->id] ?? '—',
                'stock' => (float) $c->stock, 'days' => $days,
                'qty' => (int) $r['recommended_qty'],
                'purchase_qty' => $r['purchase_qty'], 'purchase_uom' => $r['purchase_uom'],
                'cover' => $r['cover_days'], 'lead' => $r['lead_days'], 'season' => (float) $r['season'],
                'confidence' => $r['confidence'], 'cues' => $cues,
                'curve' => $curves[$r['category_id']] ?? null,
                'cur_month' => $curMonth,
                'win_start' => (int) $now->copy()->addDays((int) ($r['lead_days'] ?? 0))->format('n'),
                'win_end'   => (int) $now->copy()->addDays((int) ($r['lead_days'] ?? 0) + (int) ($r['cover_days'] ?? 0))->format('n'),
            ];
        }

        usort($rows, fn ($a, $b) => ($a['days'] ?? 99999) <=> ($b['days'] ?? 99999));
        $total = count($rows);
        $toOrder = count(array_filter($rows, fn ($r) => $r['qty'] > 0));

        return ['rows' => array_slice($rows, 0, 200), 'total' => $total, 'to_order' => $toOrder];
    }
}
