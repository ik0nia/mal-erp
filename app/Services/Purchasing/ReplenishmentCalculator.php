<?php

namespace App\Services\Purchasing;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Serviciul UNIC de recomandare de achiziție.
 *
 * O singură formulă, folosită peste tot (Necesar, PO, alerte). Regula de fier:
 * orice câmp opțional gol se rezolvă la valoarea NEUTRĂ (identitate) — efect zero
 * asupra cifrei, niciodată o distorsiune. Vezi documentul de plan.
 *
 *   viteză(corectată ruptură) × acoperire(lead+ciclu) × sezon(fereastra viitoare)
 *   + safety − stoc − onOrder − rezervat
 *   → rotunjire ambalaj (order_multiple → qty_per_carton → buc) → MOQ → plafoane → UoM
 */
class ReplenishmentCalculator
{
    /** Zile-tampon implicite pe clasă ABC (fallback dacă produsul n-are safety_stock). */
    private const SAFETY_DAYS = ['A' => 5, 'B' => 3, 'C' => 2];
    private const SAFETY_DAYS_DEFAULT = 3;

    private const COVER_DEFAULT = 10;   // fallback acoperire când n-avem lead nici ciclu
    private const CYCLE_MIN = 7;
    private const CYCLE_MAX = 45;
    private const SEASON_MIN = 0.25;
    private const SEASON_MAX = 3.0;

    /** cache per-request pentru date partajate (lead stats, sezon, categorie). */
    private array $leadCache = [];
    private array $seasonRowCache = [];
    private array $catCache = [];

    /**
     * Recomandare pentru un (produs, furnizor).
     *
     * @param  array  $opts  coverDaysOverride?:int, referenceDate?:string(Y-m-d), locationId?:int
     * @return array  structură completă (vezi cheile de la final)
     */
    public function recommend(int $productId, int $supplierId, array $opts = []): array
    {
        $product  = DB::table('woo_products')->where('id', $productId)->first();
        $supplier = DB::table('suppliers')->where('id', $supplierId)->first();
        $ps       = DB::table('product_suppliers')
            ->where('woo_product_id', $productId)->where('supplier_id', $supplierId)->first();
        $vel      = ($product && $product->sku) ? DB::table('bi_product_velocity_current')
            ->where('reference_product_id', $product->sku)->first() : null;

        return $this->compute($productId, $supplierId, $product, $supplier, $ps, $vel,
            $this->stock($productId, $opts['locationId'] ?? null),
            $this->onOrder($productId), $this->reserved($productId), $opts);
    }

    /**
     * Recomandare în masă pentru multe (produs, furnizor) — pre-încarcă tot în
     * câteva interogări (fără N+1). Cheie rezultat: "{productId}_{supplierId}".
     *
     * @param  array<int, array{0:int,1:int}>  $pairs
     */
    public function recommendBatch(array $pairs, array $opts = []): array
    {
        $pairs = array_values(array_filter($pairs, fn ($p) => (int) ($p[0] ?? 0) > 0 && (int) ($p[1] ?? 0) >= 0));
        if (empty($pairs)) {
            return [];
        }
        $pids = array_values(array_unique(array_map(fn ($p) => (int) $p[0], $pairs)));
        $sids = array_values(array_unique(array_map(fn ($p) => (int) $p[1], $pairs)));

        $products  = DB::table('woo_products')->whereIn('id', $pids)->get()->keyBy('id');
        $skus      = $products->pluck('sku')->filter()->values()->all();
        $vels      = $skus ? DB::table('bi_product_velocity_current')->whereIn('reference_product_id', $skus)->get()->keyBy('reference_product_id') : collect();
        $psByProd  = DB::table('product_suppliers')->whereIn('woo_product_id', $pids)->get()->groupBy('woo_product_id');
        $suppliers = DB::table('suppliers')->whereIn('id', $sids)->get()->keyBy('id');

        $stock = DB::table('product_stocks')->whereIn('woo_product_id', $pids)
            ->when($opts['locationId'] ?? null, fn ($q) => $q->where('location_id', $opts['locationId']))
            ->select('woo_product_id', DB::raw('SUM(quantity) q'))->groupBy('woo_product_id')->pluck('q', 'woo_product_id');
        $onOrder = DB::table('purchase_order_items as poi')->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereIn('poi.woo_product_id', $pids)->whereIn('po.status', ['pending_approval', 'approved', 'sent', 'partially_received'])
            ->select('poi.woo_product_id', DB::raw('SUM(GREATEST(0, poi.quantity - COALESCE(poi.received_quantity,0))) q'))
            ->groupBy('poi.woo_product_id')->pluck('q', 'woo_product_id');
        $reserved = DB::table('purchase_request_items as pri')->join('purchase_requests as pr', 'pr.id', '=', 'pri.purchase_request_id')
            ->whereIn('pri.woo_product_id', $pids)->where('pri.status', 'pending')->whereNotNull('pri.client_reference')
            ->whereIn('pr.status', ['submitted', 'partially_ordered'])
            ->select('pri.woo_product_id', DB::raw('SUM(GREATEST(0, pri.quantity - COALESCE(pri.ordered_quantity,0))) q'))
            ->groupBy('pri.woo_product_id')->pluck('q', 'woo_product_id');

        $results = [];
        foreach ($pairs as $pair) {
            $pid = (int) $pair[0]; $sid = (int) $pair[1];
            $product = $products->get($pid);
            $vel     = $product && $product->sku ? $vels->get($product->sku) : null;
            $psRow   = ($psByProd->get($pid) ?? collect())->firstWhere('supplier_id', $sid);
            $results[$pid . '_' . $sid] = $this->compute($pid, $sid, $product, $suppliers->get($sid), $psRow, $vel,
                (float) ($stock[$pid] ?? 0), (float) ($onOrder[$pid] ?? 0), (float) ($reserved[$pid] ?? 0), $opts);
        }
        return $results;
    }

    /** Miezul formulei — primește datele deja încărcate (folosit de recommend + recommendBatch). */
    private function compute(int $productId, int $supplierId, $product, $supplier, $ps, $vel, float $stock, float $onOrder, float $reserved, array $opts): array
    {
        $today = isset($opts['referenceDate']) ? Carbon::parse($opts['referenceDate']) : now();

        $out = $this->emptyResult($productId, $supplierId);
        if (! $product) {
            $out['reasons'][] = 'produs inexistent';
            return $out;
        }

        // ---- Eligibilitate ----
        if ($product->is_discontinued || (int) ($product->is_placeholder ?? 0) === 1
            || in_array($product->procurement_type ?? 'stock', ['on_demand'], true)
            || ($product->product_type ?? 'shop') !== 'shop') {
            $out['eligible'] = false;
            $out['reasons'][] = 'produs neeligibil pt. reaprovizionare de stoc';
            return $out;
        }

        // ---- Viteză (cerere zilnică, corectată la ruptură) — $vel primit ----
        $avg7  = (float) ($vel->avg_out_qty_7d ?? 0);
        $avg30 = (float) ($vel->avg_out_qty_30d ?? 0);
        $avg90 = (float) ($vel->avg_out_qty_90d ?? 0);

        $sustained = max($avg30, $avg90);
        $base = $sustained > 0 ? max($sustained, min($avg7, 2 * $sustained)) : $avg7;
        $trend = ($avg30 > 0 && $avg7 > 0 && $avg7 < $avg30 * 0.85) ? max(0.5, $avg7 / $avg30) : 1.0;
        $velocity = $base * $trend; // buc/zi

        $hasRecentSales = $avg7 > 0 || $avg30 > 0;

        // ---- Poziția curentă (netting) — $stock / $onOrder / $reserved primite ----
        $out['velocity']    = round($velocity, 3);
        $out['stock']       = $stock;
        $out['on_order']    = $onOrder;
        $out['reserved']    = $reserved;
        $out['days_until_stockout'] = $velocity > 0 ? round($stock / $velocity, 1) : null;

        if (! $hasRecentSales || $velocity <= 0) {
            $out['reasons'][] = 'fără vânzări recente → nu se reaprovizionează (candidat lichidare)';
            $out['recommended_qty'] = 0;
            $out['confidence'] = $vel ? 0.4 : 0.1;
            return $out;
        }

        // ---- Acoperire = lead + ciclu (per furnizor) ----
        $lead = $this->leadStats($supplierId, $ps);
        $coverDays = $opts['coverDaysOverride'] ?? ($lead['lead'] + $lead['cycle']);
        $coverDays = max(1, (int) round($coverDays));

        // ---- Sezonalitate pe fereastra VIITOARE [azi+lead, azi+lead+cover] ----
        $catId  = $this->primaryCategory($productId);
        $season = $this->seasonForward($catId, $lead['lead'], $coverDays, $today);

        // ---- Țintă & safety ----
        $safetyDays = $this->safetyDays($product, $lead);
        $safety = max((float) ($product->safety_stock ?? 0), $velocity * $safetyDays);

        $target = $velocity * $coverDays * $season + $safety;

        // plafon max stoc (dacă setat — altfel neutru: fără plafon)
        $maxStock = $this->num($product->max_stock_qty ?? null);
        if ($maxStock !== null && $maxStock > 0) {
            $target = min($target, $maxStock);
            $out['flags'][] = 'plafon_max_stoc';
        }

        // ---- Nevoie brută ----
        $available = $stock + $onOrder - $reserved;
        $need = $target - $available;

        $out['cover_days'] = $coverDays;
        $out['lead_days']  = $lead['lead'];
        $out['cycle_days'] = $lead['cycle'];
        $out['lead_source'] = $lead['source'];
        $out['season']     = round($season, 3);
        $out['safety']     = round($safety, 2);
        $out['target']     = round($target, 2);

        if ($need <= 0) {
            $out['recommended_qty'] = 0;
            $out['reasons'][] = 'acoperit (stoc + pe drum ≥ țintă)';
            // semnal suprastoc: cumpărat/rezervă peste țintă și viteză mică
            if ($available > $target * 1.5 && $velocity > 0 && ($stock / $velocity) > $coverDays * 3) {
                $out['flags'][] = 'suprastoc';
            }
            $out['confidence'] = $this->confidence($vel, $lead, $catId, $ps);
            return $out;
        }

        // ---- Constrângeri de comandă (lanț de fallback neutru) ----
        $needRaw = $need;

        // multiplu ambalaj: order_multiple → qty_per_carton → 1 (buc)
        $multiple = $this->num($ps->order_multiple ?? null)
            ?? $this->num($product->qty_per_carton ?? null)
            ?? 1.0;
        $need = $multiple > 1 ? ceil($need / $multiple) * $multiple : ceil($need);

        // MOQ (doar dacă setat)
        $moq = $this->num($ps->min_order_qty ?? null);
        if ($moq !== null && $moq > 0) {
            $need = max($need, $moq);
        }

        // plafon cantitate/produs → aprobare (doar dacă setat)
        $poMax = $this->num($ps->po_max_qty ?? null);
        if ($poMax !== null && $poMax > 0 && $need > $poMax) {
            $out['flags'][] = 'peste_plafon_produs';
        }

        // conversie în unitatea de achiziție (neutru = 1 buc)
        $conv = $this->num($ps->conversion_factor ?? null);
        $conv = ($conv !== null && $conv > 0) ? $conv : 1.0;
        $purchaseUom = $ps->purchase_uom ?? null;

        // cost estimat
        $cost = $ps ? ($this->num($ps->purchase_price ?? null) ?? $this->num($ps->last_purchase_price ?? null)) : null;

        $out['recommended_qty']       = (int) $need;               // în buc
        $out['recommended_qty_raw']   = round($needRaw, 2);
        $out['order_multiple_used']   = $multiple;
        $out['carton_qty']            = $this->num($product->qty_per_carton ?? null);
        $out['purchase_qty']          = $conv > 1 ? round($need / $conv, 2) : (int) $need;
        $out['purchase_uom']          = $purchaseUom;
        $out['unit_cost']             = $cost;
        $out['est_value']             = $cost !== null ? round($need * $cost, 2) : null;
        $out['weight_kg']             = $this->num($product->weight ?? null) !== null ? round($need * (float) $product->weight, 2) : null;
        $out['confidence']            = $this->confidence($vel, $lead, $catId, $ps);

        // flag-uri de calitate
        if (! $vel)          $out['flags'][] = 'fara_viteza';
        if ($lead['source'] === 'fallback') $out['flags'][] = 'lead_estimat';
        if ($conv <= 1 && $this->num($ps->purchase_uom ?? null) === null && ($product->qty_per_carton ?? null)) {
            // are carton dar fără conversie furnizor — informativ
        }

        return $out;
    }

    // ---------- surse de date ----------

    private function stock(int $productId, ?int $locationId): float
    {
        return (float) DB::table('product_stocks')
            ->where('woo_product_id', $productId)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->sum('quantity');
    }

    private function onOrder(int $productId): float
    {
        return (float) DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.woo_product_id', $productId)
            ->whereIn('po.status', ['pending_approval', 'approved', 'sent', 'partially_received'])
            ->sum(DB::raw('GREATEST(0, poi.quantity - COALESCE(poi.received_quantity,0))'));
    }

    /** Cantitate rezervată clienților (necesare pending cu referință client). */
    private function reserved(int $productId): float
    {
        return (float) DB::table('purchase_request_items as pri')
            ->join('purchase_requests as pr', 'pr.id', '=', 'pri.purchase_request_id')
            ->where('pri.woo_product_id', $productId)
            ->where('pri.status', 'pending')
            ->whereNotNull('pri.client_reference')
            ->whereIn('pr.status', ['submitted', 'partially_ordered'])
            ->sum(DB::raw('GREATEST(0, pri.quantity - COALESCE(pri.ordered_quantity,0))'));
    }

    /**
     * Lead time & ciclu per furnizor. Lead operațional = sent_at→received_at.
     * Fallback: product_suppliers.lead_days → default global.
     */
    private function leadStats(int $supplierId, $ps): array
    {
        if (isset($this->leadCache[$supplierId])) {
            return $this->leadCache[$supplierId];
        }

        $leads = DB::table('purchase_orders')
            ->where('supplier_id', $supplierId)
            ->where('status', 'received')
            ->whereNotNull('sent_at')->whereNotNull('received_at')
            ->orderByDesc('received_at')->limit(15)
            ->get(['sent_at', 'received_at']);

        $vals = [];
        foreach ($leads as $r) {
            $d = abs(Carbon::parse($r->sent_at)->startOfDay()->diffInDays(Carbon::parse($r->received_at)->startOfDay()));
            if ($d >= 0 && $d <= 180) $vals[] = $d;
        }

        // ciclul real de comandă (interval mediu între PO trimise, 12 luni)
        $sent = DB::table('purchase_orders')
            ->where('supplier_id', $supplierId)->whereNotNull('sent_at')
            ->where('sent_at', '>=', now()->subMonths(12))
            ->orderBy('sent_at')->pluck('sent_at');

        $cycle = self::CYCLE_MIN;
        if ($sent->count() >= 3) {
            $first = Carbon::parse($sent->first());
            $last  = Carbon::parse($sent->last());
            $cycle = max(self::CYCLE_MIN, min(self::CYCLE_MAX, (int) round(abs($first->diffInDays($last)) / ($sent->count() - 1))));
        }

        if (! empty($vals)) {
            sort($vals);
            $n = count($vals);
            $lead = $n % 2 ? $vals[($n - 1) / 2] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
            $mean = array_sum($vals) / $n;
            $dev  = sqrt(array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $vals)) / $n);
            $res = ['lead' => (int) ceil($lead), 'dev' => $dev, 'cycle' => $cycle, 'source' => 'real'];
        } else {
            $configured = $this->num($ps->lead_days ?? null);
            $lead = $configured !== null ? (int) $configured : (self::COVER_DEFAULT - $cycle);
            $res = ['lead' => max(1, $lead), 'dev' => 0.0, 'cycle' => $cycle, 'source' => 'fallback'];
        }

        return $this->leadCache[$supplierId] = $res;
    }

    private function safetyDays($product, array $lead): int
    {
        $abc = $product->abc_classification ?? null;
        $days = self::SAFETY_DAYS[$abc] ?? self::SAFETY_DAYS_DEFAULT;
        // lead foarte variabil → +2 zile tampon
        if ($lead['lead'] > 0 && $lead['dev'] > $lead['lead'] * 0.5) {
            $days += 2;
        }
        return $days;
    }

    private function primaryCategory(int $productId): int
    {
        if (isset($this->catCache[$productId])) {
            return $this->catCache[$productId];
        }
        $cats = DB::table('woo_product_category')->where('woo_product_id', $productId)->pluck('woo_category_id')->all();
        if (empty($cats)) {
            return $this->catCache[$productId] = 0; // global
        }
        // preferă o categorie cu sezon PROPRIU; altfel prima
        $own = DB::table('bi_seasonality_category')
            ->whereIn('woo_category_id', $cats)->where('source', 'own')
            ->orderBy('woo_category_id')->value('woo_category_id');
        return $this->catCache[$productId] = (int) ($own ?? $cats[0]);
    }

    /** Sezon ponderat pe fereastra [azi+lead, azi+lead+cover] (lunile care URMEAZĂ). */
    private function seasonForward(int $catId, int $lead, int $cover, Carbon $today): float
    {
        $idx = $this->seasonRow($catId);
        if ($idx === null) {
            return 1.0; // neutru
        }
        $start = $today->copy()->addDays($lead);
        $days = max(1, $cover);
        $sum = 0.0;
        for ($i = 0; $i < $days; $i++) {
            $m = (int) $start->copy()->addDays($i)->format('n');
            $sum += $idx[$m] ?? 1.0;
        }
        $avg = $sum / $days;
        return max(self::SEASON_MIN, min(self::SEASON_MAX, $avg));
    }

    private function seasonRow(int $catId): ?array
    {
        if (array_key_exists($catId, $this->seasonRowCache)) {
            return $this->seasonRowCache[$catId];
        }
        $rows = DB::table('bi_seasonality_category')->where('woo_category_id', $catId)->pluck('seasonal_index', 'month');
        if ($rows->isEmpty()) {
            // fallback global
            $rows = DB::table('bi_seasonality_category')->where('woo_category_id', 0)->pluck('seasonal_index', 'month');
        }
        $arr = $rows->isEmpty() ? null : $rows->map(fn ($v) => (float) $v)->all();
        return $this->seasonRowCache[$catId] = $arr;
    }

    private function confidence($vel, array $lead, int $catId, $ps): float
    {
        $c = 0.0;
        $c += $vel ? 0.4 : 0.0;                          // are viteză
        $c += $lead['source'] === 'real' ? 0.3 : 0.1;   // lead din istoric real
        $c += $catId > 0 ? 0.2 : 0.05;                  // sezon pe categorie
        $c += $ps ? 0.1 : 0.0;                          // legătură furnizor
        return round(min(1.0, $c), 2);
    }

    private function num($v): ?float
    {
        if ($v === null || $v === '') return null;
        return (float) $v;
    }

    private function emptyResult(int $productId, int $supplierId): array
    {
        return [
            'product_id' => $productId, 'supplier_id' => $supplierId,
            'eligible' => true, 'recommended_qty' => 0,
            'velocity' => 0, 'stock' => 0, 'on_order' => 0, 'reserved' => 0,
            'cover_days' => null, 'lead_days' => null, 'cycle_days' => null, 'lead_source' => null,
            'season' => 1.0, 'safety' => 0, 'target' => 0, 'days_until_stockout' => null,
            'purchase_qty' => 0, 'purchase_uom' => null, 'carton_qty' => null,
            'order_multiple_used' => 1, 'unit_cost' => null, 'est_value' => null, 'weight_kg' => null,
            'confidence' => 0, 'reasons' => [], 'flags' => [],
        ];
    }
}
