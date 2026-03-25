<?php

namespace App\Jobs;

use Anthropic\Client as AnthropicClient;
use App\Models\AiUsageLog;
use App\Models\BiAnalysis;
use App\Models\DailyStockMetric;
use App\Models\ProductPriceLog;
use App\Models\WooOrder;
use App\Models\WooOrderItem;
use App\Models\WooProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GenerateBiAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1;

    public function __construct(
        private readonly int     $analysisId,
        private readonly string  $sections = 'both',   // both | stock | online
        private ?Carbon          $dateFrom = null,
        private ?Carbon          $dateTo   = null,
    ) {}

    public function handle(): void
    {
        $this->dateFrom ??= Carbon::now()->subDays(30);
        $this->dateTo   ??= Carbon::now();

        $analysis = BiAnalysis::findOrFail($this->analysisId);

        try {
            $apiKey = config('services.anthropic.api_key');

            if (empty($apiKey)) {
                throw new \RuntimeException('ANTHROPIC_API_KEY lipsește din .env');
            }

            $metrics = $this->gatherMetrics();

            $pastAnalyses = $this->fetchPastAnalyses();

            $prompt = $this->buildPrompt($metrics, $pastAnalyses);

            $claude = new AnthropicClient(apiKey: $apiKey);

            $message = $claude->messages->create(
                maxTokens: 12000,
                messages:  [['role' => 'user', 'content' => $prompt]],
                model:     config('app.malinco.ai.models.sonnet', 'claude-sonnet-4-6'),
            );

            $content = '';
            foreach ($message->content as $block) {
                if (isset($block->text)) {
                    $content .= $block->text;
                }
            }

            if (empty(trim($content))) {
                throw new \RuntimeException('Claude nu a returnat niciun răspuns.');
            }

            $inputTokens  = $message->usage->inputTokens  ?? 0;
            $outputTokens = $message->usage->outputTokens ?? 0;
            $costUsd      = round($inputTokens / 1_000_000 * 3 + $outputTokens / 1_000_000 * 15, 5);

            AiUsageLog::record('bi_daily', config('app.malinco.ai.models.sonnet', 'claude-sonnet-4-6'), $inputTokens, $outputTokens, [
                'analysis_id' => $analysis->id,
            ]);

            $analysis->update([
                'content'          => $content,
                'metrics_snapshot' => array_merge($this->snapshotForStorage($metrics), [
                    'tokens_input'  => $inputTokens,
                    'tokens_output' => $outputTokens,
                    'cost_usd'      => $costUsd,
                ]),
                'status'           => 'done',
                'generated_at'     => now(),
            ]);

        } catch (\Throwable $e) {
            $analysis->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'generated_at'  => now(),
            ]);
        }
    }

    // ── Data gathering ────────────────────────────────────────────────────────

    private function gatherMetrics(): array
    {
        $from = $this->dateFrom;
        $to   = $this->dateTo;

        $isSingleDay = $from->toDateString() === $to->toDateString();

        // daily_stock_metrics stochează snapshot-uri de zi încheiată.
        // Dacă $to este azi sau în viitor, cel mai recent snapshot e de ieri.
        // Dacă $to e o zi trecută, folosim acea zi direct.
        $metricsTo = ($to->isToday() || $to->isFuture())
            ? Carbon::yesterday()->toDateString()
            : $to->toDateString();
        $metricsFrom = $from->toDateString();

        $includeStock  = in_array($this->sections, ['both', 'stock']);
        $includeOnline = in_array($this->sections, ['both', 'online']);

        // ── Stoc & prețuri ────────────────────────────────────────────────────
        $stockDaily            = collect();
        $stockTrend            = collect();
        $stockContext          = collect();
        $stockGranularity      = 'zilnic';
        $trendGranularity      = '';
        $stockDroppers         = collect();
        $priceChanges          = collect();
        $biggestPriceIncreases = collect();
        $biggestPriceDecreases = collect();
        $inStockCount          = 0;
        $outOfStockCount       = 0;
        $totalProducts         = 0;
        $activeProducts        = 0;

        if ($includeStock) {
            $days = max(1, $from->diffInDays($to));

            // Toate query-urile de stoc filtrează strict pe produse de tip 'shop'
            // (exclud producție internă și garanție palet din rapoarte)
            if ($days <= 30) {
                // Perioadă scurtă: totul zilnic
                $stockDaily = DailyStockMetric::from('daily_stock_metrics')
                    ->whereBetween('daily_stock_metrics.day', [$metricsFrom, $metricsTo])
                    ->leftJoin('woo_products as wp_f', 'wp_f.sku', '=', 'daily_stock_metrics.reference_product_id')
                    ->whereRaw("COALESCE(wp_f.product_type, 'shop') = 'shop'")
                    ->selectRaw('daily_stock_metrics.day as period, SUM(daily_stock_metrics.closing_available_qty * daily_stock_metrics.closing_sell_price) as stock_value, SUM(daily_stock_metrics.closing_available_qty) as total_qty')
                    ->groupBy('daily_stock_metrics.day')->orderBy('daily_stock_metrics.day')->get();
                $stockTrend       = collect();
                $stockGranularity = 'zilnic';
            } elseif ($days <= 180) {
                // 31–180 zile: tot săptămânal uniform.
                $stockDaily = DailyStockMetric::from('daily_stock_metrics')
                    ->whereBetween('daily_stock_metrics.day', [$metricsFrom, $metricsTo])
                    ->leftJoin('woo_products as wp_f', 'wp_f.sku', '=', 'daily_stock_metrics.reference_product_id')
                    ->whereRaw("COALESCE(wp_f.product_type, 'shop') = 'shop'")
                    ->selectRaw('DATE_FORMAT(MIN(daily_stock_metrics.day), "%Y-%m (săpt. %u)") as period, SUM(daily_stock_metrics.closing_available_qty * daily_stock_metrics.closing_sell_price) / COUNT(DISTINCT daily_stock_metrics.day) as stock_value, SUM(daily_stock_metrics.closing_available_qty) / COUNT(DISTINCT daily_stock_metrics.day) as total_qty')
                    ->groupByRaw('YEARWEEK(daily_stock_metrics.day, 1)')->orderByRaw('MIN(daily_stock_metrics.day)')->get();
                $stockGranularity = 'săptămânal (medie zilnică)';
                $stockTrend       = collect();
                $trendGranularity = '';
            } else {
                // > 180 zile: tot lunar uniform.
                $stockDaily = DailyStockMetric::from('daily_stock_metrics')
                    ->whereBetween('daily_stock_metrics.day', [$metricsFrom, $metricsTo])
                    ->leftJoin('woo_products as wp_f', 'wp_f.sku', '=', 'daily_stock_metrics.reference_product_id')
                    ->whereRaw("COALESCE(wp_f.product_type, 'shop') = 'shop'")
                    ->selectRaw('DATE_FORMAT(MIN(daily_stock_metrics.day), "%Y-%m") as period, SUM(daily_stock_metrics.closing_available_qty * daily_stock_metrics.closing_sell_price) / COUNT(DISTINCT daily_stock_metrics.day) as stock_value, SUM(daily_stock_metrics.closing_available_qty) / COUNT(DISTINCT daily_stock_metrics.day) as total_qty')
                    ->groupByRaw('YEAR(daily_stock_metrics.day), MONTH(daily_stock_metrics.day)')->orderByRaw('MIN(daily_stock_metrics.day)')->get();
                $stockGranularity = 'lunar (medie zilnică)';
                $stockTrend       = collect();
                $trendGranularity = '';
            }

            // Context precedent pentru o zi anume: ultimele 30 de zile înainte de ziua selectată
            $stockContext = collect();
            if ($isSingleDay) {
                $ctxFrom = $from->copy()->subDays(30)->toDateString();
                $ctxTo   = $from->copy()->subDay()->toDateString();
                $stockContext = DailyStockMetric::from('daily_stock_metrics')
                    ->whereBetween('daily_stock_metrics.day', [$ctxFrom, $ctxTo])
                    ->leftJoin('woo_products as wp_f', 'wp_f.sku', '=', 'daily_stock_metrics.reference_product_id')
                    ->whereRaw("COALESCE(wp_f.product_type, 'shop') = 'shop'")
                    ->selectRaw('daily_stock_metrics.day as period, SUM(daily_stock_metrics.closing_available_qty * daily_stock_metrics.closing_sell_price) as stock_value, SUM(daily_stock_metrics.closing_available_qty) as total_qty')
                    ->groupBy('daily_stock_metrics.day')
                    ->orderBy('daily_stock_metrics.day')
                    ->get();
            }

            $stockDroppers = DailyStockMetric::whereBetween('daily_stock_metrics.day', [$metricsFrom, $metricsTo])
                ->leftJoin('woo_products', 'woo_products.sku', '=', 'daily_stock_metrics.reference_product_id')
                ->whereRaw("COALESCE(woo_products.product_type, 'shop') = 'shop'")
                ->where(fn ($q) => $q->whereNull('woo_products.is_discontinued')->orWhere('woo_products.is_discontinued', false))
                ->whereRaw("COALESCE(woo_products.procurement_type, 'stock') != 'on_demand'")
                ->selectRaw('daily_stock_metrics.reference_product_id, woo_products.name as product_name, SUM(daily_stock_metrics.daily_available_variation) as total_variation, MIN(daily_stock_metrics.closing_available_qty) as min_qty, MAX(daily_stock_metrics.closing_available_qty) as max_qty')
                ->groupBy('daily_stock_metrics.reference_product_id', 'woo_products.name')
                ->orderBy('total_variation')
                ->limit(15)
                ->get();

            $inStockCount    = WooProduct::where('stock_status', 'instock')->where('product_type', WooProduct::TYPE_SHOP)->where('is_discontinued', false)->where(fn ($q) => $q->whereNull('procurement_type')->orWhere('procurement_type', '!=', WooProduct::PROCUREMENT_ON_DEMAND))->count();
            $outOfStockCount = WooProduct::where('stock_status', 'outofstock')->where('product_type', WooProduct::TYPE_SHOP)->where('is_discontinued', false)->where(fn ($q) => $q->whereNull('procurement_type')->orWhere('procurement_type', '!=', WooProduct::PROCUREMENT_ON_DEMAND))->count();
            $totalProducts   = WooProduct::where('product_type', WooProduct::TYPE_SHOP)->where('is_discontinued', false)->where(fn ($q) => $q->whereNull('procurement_type')->orWhere('procurement_type', '!=', WooProduct::PROCUREMENT_ON_DEMAND))->count();
            $activeProducts  = WooProduct::where('status', 'publish')->where('product_type', WooProduct::TYPE_SHOP)->where('is_discontinued', false)->where(fn ($q) => $q->whereNull('procurement_type')->orWhere('procurement_type', '!=', WooProduct::PROCUREMENT_ON_DEMAND))->count();

            // Prețuri: aceeași granularitate ca stocul (prag 30, nu 45)
            if ($days <= 30) {
                $priceChanges = ProductPriceLog::whereBetween('changed_at', [$from, $to])
                    ->selectRaw('DATE(changed_at) as period, COUNT(*) as cnt, SUM(CASE WHEN new_price > old_price THEN 1 ELSE 0 END) as up, SUM(CASE WHEN new_price < old_price THEN 1 ELSE 0 END) as dn')
                    ->groupByRaw('DATE(changed_at)')
                    ->orderByRaw('DATE(changed_at)')
                    ->get();
            } elseif ($days <= 180) {
                $priceChanges = ProductPriceLog::whereBetween('changed_at', [$from, $to])
                    ->selectRaw('DATE_FORMAT(MIN(changed_at), "%Y-%m (săpt. %u)") as period, COUNT(*) as cnt, SUM(CASE WHEN new_price > old_price THEN 1 ELSE 0 END) as up, SUM(CASE WHEN new_price < old_price THEN 1 ELSE 0 END) as dn')
                    ->groupByRaw('YEARWEEK(changed_at, 1)')
                    ->orderByRaw('MIN(changed_at)')
                    ->get();
            } else {
                $priceChanges = ProductPriceLog::whereBetween('changed_at', [$from, $to])
                    ->selectRaw('DATE_FORMAT(MIN(changed_at), "%Y-%m") as period, COUNT(*) as cnt, SUM(CASE WHEN new_price > old_price THEN 1 ELSE 0 END) as up, SUM(CASE WHEN new_price < old_price THEN 1 ELSE 0 END) as dn')
                    ->groupByRaw('YEAR(changed_at), MONTH(changed_at)')
                    ->orderByRaw('MIN(changed_at)')
                    ->get();
            }

            $biggestPriceIncreases = ProductPriceLog::with('product:id,name,sku')
                ->whereBetween('changed_at', [$from, $to])
                ->whereRaw('new_price > old_price AND old_price > 0')
                ->orderByRaw('((new_price - old_price) / old_price) DESC')
                ->limit(10)
                ->get();

            $biggestPriceDecreases = ProductPriceLog::with('product:id,name,sku')
                ->whereBetween('changed_at', [$from, $to])
                ->whereRaw('new_price < old_price AND old_price > 0')
                ->orderByRaw('((new_price - old_price) / old_price) ASC')
                ->limit(10)
                ->get();
        }

        // ── Comenzi online ────────────────────────────────────────────────────
        $ordersByDay      = collect();
        $ordersTotal      = 0;
        $ordersValue      = 0.0;
        $statusBreakdown  = collect();
        $topProducts      = collect();
        $ordersGranularity = 'zilnic';

        if ($includeOnline) {
            $days = $days ?? $from->diffInDays($to);

            if ($days <= 30) {
                // Perioadă scurtă: totul zilnic
                $ordersRaw = WooOrder::whereBetween('order_date', [$from, $to])
                    ->selectRaw('DATE(order_date) as period, status, COUNT(*) as cnt, SUM(total) as value')
                    ->groupByRaw('DATE(order_date), status')
                    ->orderByRaw('DATE(order_date)')
                    ->get();
                $ordersByDay      = $ordersRaw->groupBy('period')->map(fn ($r) => ['count' => $r->sum('cnt'), 'value' => round($r->sum('value'), 2)]);
                $ordersTrend      = collect();
                $ordersGranularity = 'zilnic';
            } elseif ($days <= 180) {
                // 31–180 zile: tot săptămânal uniform
                $ordersRaw = WooOrder::whereBetween('order_date', [$from, $to])
                    ->selectRaw('DATE_FORMAT(MIN(order_date), "%Y-%m (săpt. %u)") as period, status, COUNT(*) as cnt, SUM(total) as value')
                    ->groupByRaw('YEARWEEK(order_date, 1), status')
                    ->orderByRaw('MIN(order_date)')
                    ->get();
                $ordersByDay       = $ordersRaw->groupBy('period')->map(fn ($r) => ['count' => $r->sum('cnt'), 'value' => round($r->sum('value'), 2)]);
                $ordersGranularity = 'săptămânal';
                $ordersTrend       = collect();
                $ordersTrendGranularity = '';
            } else {
                // > 180 zile: tot lunar uniform
                $ordersRaw = WooOrder::whereBetween('order_date', [$from, $to])
                    ->selectRaw('DATE_FORMAT(MIN(order_date), "%Y-%m") as period, status, COUNT(*) as cnt, SUM(total) as value')
                    ->groupByRaw('YEAR(order_date), MONTH(order_date), status')
                    ->orderByRaw('MIN(order_date)')
                    ->get();
                $ordersByDay       = $ordersRaw->groupBy('period')->map(fn ($r) => ['count' => $r->sum('cnt'), 'value' => round($r->sum('value'), 2)]);
                $ordersGranularity = 'lunar';
                $ordersTrend       = collect();
                $ordersTrendGranularity = '';
            }

            $ordersTotal     = $ordersRaw->sum('cnt');
            $ordersValue     = round($ordersRaw->sum('value'), 2);
            $statusBreakdown = $ordersRaw
                ->groupBy('status')
                ->map(fn ($rows) => ['cnt' => $rows->sum('cnt'), 'value' => round($rows->sum('value'), 2)]);

            $topProducts = WooOrderItem::query()
                ->join('woo_orders', 'woo_orders.id', '=', 'woo_order_items.order_id')
                ->whereBetween('woo_orders.order_date', [$from, $to])
                ->whereNotIn('woo_orders.status', ['cancelled', 'refunded', 'failed'])
                ->groupBy('woo_order_items.sku', 'woo_order_items.name')
                ->selectRaw('woo_order_items.sku, woo_order_items.name, SUM(woo_order_items.quantity) as qty, SUM(woo_order_items.total) as value')
                ->orderByDesc('qty')
                ->limit(15)
                ->get();
        }

        $ordersTrend            = $ordersTrend            ?? collect();
        $ordersTrendGranularity = $ordersTrendGranularity ?? '';

        return compact(
            'isSingleDay', 'includeStock', 'includeOnline',
            'stockDaily', 'stockTrend', 'stockGranularity', 'trendGranularity', 'stockDroppers', 'stockContext',
            'inStockCount', 'outOfStockCount', 'totalProducts', 'activeProducts',
            'priceChanges', 'biggestPriceIncreases', 'biggestPriceDecreases',
            'ordersByDay', 'ordersTrend', 'ordersTotal', 'ordersValue', 'statusBreakdown', 'topProducts',
            'ordersGranularity', 'ordersTrendGranularity',
        );
    }

    // ── Context rapoarte anterioare ───────────────────────────────────────────

    private function fetchPastAnalyses(): Collection
    {
        $from = $this->dateFrom->copy()->startOfDay();
        $to   = $this->dateTo->copy()->endOfDay();
        $excludeId = $this->analysisId;

        // Strategie pe tip — prioritizăm rapoartele automate comprehensive:
        //   lunar  (type=monthly) → max 2, rezumat 30 zile, 3000 chars fiecare
        //   săptămânal (type=weekly) → max 4 cele mai recente, 2500 chars fiecare
        //   manual (type=manual)  → max 2 cele mai recente, 1500 chars fiecare
        // Ordinea finală: cronologic (cel mai vechi → cel mai nou) pentru context coeziv.

        $monthly = BiAnalysis::where('status', 'done')
            ->where('type', 'monthly')
            ->where('id', '!=', $excludeId)
            ->whereBetween('generated_at', [$from, $to])
            ->latest('generated_at')
            ->limit(2)
            ->get()
            ->map(fn ($a) => $a->setAttribute('_chars', 3000));

        $weekly = BiAnalysis::where('status', 'done')
            ->where('type', 'weekly')
            ->where('id', '!=', $excludeId)
            ->whereBetween('generated_at', [$from, $to])
            ->latest('generated_at')
            ->limit(4)
            ->get()
            ->map(fn ($a) => $a->setAttribute('_chars', 2500));

        $manual = BiAnalysis::where('status', 'done')
            ->where('type', 'manual')
            ->where('id', '!=', $excludeId)
            ->whereBetween('generated_at', [$from, $to])
            ->latest('generated_at')
            ->limit(2)
            ->get()
            ->map(fn ($a) => $a->setAttribute('_chars', 1500));

        return $monthly->concat($weekly)->concat($manual)
            ->sortBy('generated_at')
            ->values();
    }

    // ── Prompt builder ────────────────────────────────────────────────────────

    private function buildPrompt(array $m, Collection $pastAnalyses): string
    {
        $today     = Carbon::today()->format('d.m.Y');
        $periodStr = $this->dateFrom->format('d.m.Y') . ' – ' . $this->dateTo->format('d.m.Y');

        // ── Context analize anterioare ────────────────────────────────────────
        $pastContext = '';
        if ($pastAnalyses->isNotEmpty()) {
            $pastContext = "\n---\n\n## RAPOARTE BI DIN PERIOADA ANALIZATĂ (context)\n\n";
            $pastContext .= "Folosește-le ca referință pentru evoluție și pentru a evita să repeți concluzii deja cunoscute.\n\n";
            foreach ($pastAnalyses as $analysis) {
                $chars = $analysis->_chars ?? 2000;
                $typeLabel = match($analysis->type) {
                    'monthly' => '[LUNAR]',
                    'weekly'  => '[SĂPTĂMÂNAL]',
                    default   => '[MANUAL]',
                };
                $pastContext .= "### {$typeLabel} {$analysis->title} ({$analysis->generated_at->format('d.m.Y')})\n";
                $pastContext .= Str::limit($analysis->content, $chars) . "\n\n";
            }
        }

        // ── Date stoc ─────────────────────────────────────────────────────────
        $stockSection = '';
        if ($m['includeStock']) {
            $stockDayLines = $m['stockDaily']->map(fn ($d) =>
                "  {$d->day}: valoare stoc " . number_format($d->stock_value, 0, ',', '.') . ' RON, cantitate totală ' . number_format($d->total_qty, 0, '.', '')
            )->implode("\n");

            $dropLines = $m['stockDroppers']->map(fn ($d) =>
                "  [{$d->reference_product_id}] " . ($d->product_name ? html_entity_decode($d->product_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '(fără denumire)') .
                ": variație totală = {$d->total_variation} buc (min: {$d->min_qty}, max: {$d->max_qty})"
            )->implode("\n");

            $priceLines = $m['priceChanges']->map(fn ($p) =>
                "  {$p->period}: {$p->cnt} modificări ({$p->up} creșteri, {$p->dn} scăderi)"
            )->implode("\n");

            $priceUpLines = $m['biggestPriceIncreases']->map(fn ($l) => sprintf(
                '  [%s] %s: %.2f → %.2f RON (+%.1f%%)',
                $l->product?->sku ?? 'N/A',
                Str::limit($l->product?->name ?? 'Produs necunoscut', 40),
                $l->old_price, $l->new_price,
                $l->old_price > 0 ? ($l->new_price - $l->old_price) / $l->old_price * 100 : 0,
            ))->implode("\n");

            $priceDnLines = $m['biggestPriceDecreases']->map(fn ($l) => sprintf(
                '  [%s] %s: %.2f → %.2f RON (%.1f%%)',
                $l->product?->sku ?? 'N/A',
                Str::limit($l->product?->name ?? 'Produs necunoscut', 40),
                $l->old_price, $l->new_price,
                $l->old_price > 0 ? ($l->new_price - $l->old_price) / $l->old_price * 100 : 0,
            ))->implode("\n");

            $gran      = $m['stockGranularity'];
            $trendGran = $m['trendGranularity'];

            $stockTrendBlock = '';
            if ($m['stockTrend']->isNotEmpty()) {
                $trendLines = $m['stockTrend']->map(fn ($d) =>
                    "  {$d->period}: valoare stoc " . number_format($d->stock_value, 0, ',', '.') . ' RON, cantitate ' . number_format($d->total_qty, 0, '.', '')
                )->implode("\n");
                $stockTrendBlock = <<<TREND

### Trend stoc perioadă anterioară ({$trendGran}):
{$trendLines}

TREND;
            }

            // Context precedent (doar pentru analiza pe o zi)
            $contextBlock = '';
            if ($m['isSingleDay'] && $m['stockContext']->isNotEmpty()) {
                $ctxLines = $m['stockContext']->map(fn ($d) =>
                    "  {$d->period}: valoare stoc " . number_format($d->stock_value, 0, ',', '.') . ' RON, cantitate ' . number_format($d->total_qty, 0, '.', '')
                )->implode("\n");
                $ctxFrom = $m['stockContext']->first()->period;
                $ctxTo   = $m['stockContext']->last()->period;
                $contextBlock = <<<CTX

### Context: evoluție stoc în cele 30 de zile anterioare ({$ctxFrom} → {$ctxTo}):
{$ctxLines}

CTX;
            }

            $stockSection = <<<STOCK

## DATE STOC

Produse în stoc: {$m['inStockCount']} | Fără stoc: {$m['outOfStockCount']}
Total catalog: {$m['totalProducts']} | Active (publish): {$m['activeProducts']}

{$stockTrendBlock}### Evoluție valoare stoc ({$gran}):
{$stockDayLines}
{$contextBlock}
### Top 15 produse cu cele mai mari scăderi de stoc în perioadă:
{$dropLines}

---

## DATE PREȚURI

### Modificări zilnice de preț:
{$priceLines}

### Cele mai mari CREȘTERI de preț:
{$priceUpLines}

### Cele mai mari SCĂDERI de preț:
{$priceDnLines}

STOCK;
        }

        // ── Date comenzi online ───────────────────────────────────────────────
        $onlineSection = '';
        if ($m['includeOnline']) {
            $ordersDayLines = $m['ordersByDay']->map(fn ($d, $day) =>
                "  {$day}: {$d['count']} comenzi, " . number_format($d['value'], 2, ',', '.') . ' RON'
            )->implode("\n");

            $statusLines = $m['statusBreakdown']->map(fn ($s, $st) =>
                "  {$st}: {$s['cnt']} comenzi, " . number_format($s['value'], 2, ',', '.') . ' RON'
            )->implode("\n");

            $topProdLines = $m['topProducts']->map(fn ($p, $i) =>
                '  ' . ($i + 1) . ". [{$p->sku}] {$p->name} — {$p->qty} buc, " . number_format($p->value, 2, ',', '.') . ' RON'
            )->implode("\n");

            $totalStr      = number_format($m['ordersValue'], 2, ',', '.');
            $ordGran       = $m['ordersGranularity'];
            $ordTrendGran  = $m['ordersTrendGranularity'];

            $ordersTrendBlock = '';
            if ($m['ordersTrend']->isNotEmpty()) {
                $trendOrdLines = $m['ordersTrend']->map(fn ($d) =>
                    "  {$d->period}: {$d->cnt} comenzi, " . number_format($d->value, 2, ',', '.') . ' RON'
                )->implode("\n");
                $ordersTrendBlock = <<<OTREND

### Trend comenzi perioadă anterioară ({$ordTrendGran}):
{$trendOrdLines}

OTREND;
            }

            // Context comenzi precedente pentru analiza pe o zi
            $ordersContextBlock = '';
            if ($m['isSingleDay']) {
                $ctxOrders = WooOrder::whereBetween('order_date', [
                        $this->dateFrom->copy()->subDays(30)->startOfDay(),
                        $this->dateFrom->copy()->subDay()->endOfDay(),
                    ])
                    ->selectRaw('DATE(order_date) as day, COUNT(*) as cnt, SUM(total) as value')
                    ->groupByRaw('DATE(order_date)')
                    ->orderBy('day')
                    ->get();

                if ($ctxOrders->isNotEmpty()) {
                    $ctxOrdLines = $ctxOrders->map(fn ($d) =>
                        "  {$d->day}: {$d->cnt} comenzi, " . number_format($d->value, 2, ',', '.') . ' RON'
                    )->implode("\n");
                    $ordersContextBlock = <<<OCTX

### Context: comenzi online în cele 30 de zile anterioare:
{$ctxOrdLines}

OCTX;
                }
            }

            $onlineSection = <<<ONLINE

## DATE MAGAZIN ONLINE

Total perioadă: {$m['ordersTotal']} comenzi, {$totalStr} RON

{$ordersTrendBlock}### Evoluție comenzi {$ordGran}:
{$ordersDayLines}
{$ordersContextBlock}
### Breakdown după status:
{$statusLines}

### Top 15 produse vândute online (fără anulate/rambursate):
{$topProdLines}

ONLINE;
        }

        // ── Instrucțiuni raport ───────────────────────────────────────────────
        $focusNote = match($this->sections) {
            'stock'  => 'Această analiză acoperă EXCLUSIV stocurile și prețurile. Nu există date de comenzi online.',
            'online' => 'Această analiză acoperă EXCLUSIV activitatea magazinului online. Nu există date de stoc.',
            default  => 'Prioritatea analizei este: stocuri și mișcări de stoc > prețuri > activitate magazin online (canal secundar în prezent).',
        };

        if ($m['isSingleDay']) {
            $focusNote .= "\n\n**Analiză pe o singură zi ({$this->dateFrom->format('d.m.Y')}).** "
                . "Datele principale sunt cele ale zilei selectate. "
                . "Am inclus și contextul celor 30 de zile anterioare — folosește-l ca referință pentru a evalua dacă ziua analizată este normală, excepțională sau îngrijorătoare față de medie.";
        }

        $orderingSection = <<<ORDERING

### Recomandări de aprovizionare și context de piață

**A. Cantități recomandate pentru reaprovizionare**
Pe baza ritmului de consum observat în datele de mai sus, recomandă cantități concrete de reaprovizionat pentru produsele cu risc de ruptură de stoc. Pentru fiecare produs:
- Calculează consumul mediu zilnic/săptămânal din variațiile de stoc
- Estimează stocul necesar pentru acoperirea a 30-45 de zile
- Propune o cantitate de comandat (rotunjită practic)
- Semnalează dacă nu ai suficiente date pentru o estimare sigură

Prezintă sub formă de tabel Markdown cu coloanele: Produs | SKU | Consum mediu/săpt. | Stoc curent estimat | Cantitate recomandată | Acoperire estimată (zile)

**B. Sezonalitate și tendințe de piață — perspectiva ta**
Bazat pe cunoștințele tale generale despre piața de materiale de construcții și bricolaj din România și din Europa, oferă-ți perspectiva despre:
- Ce produse sau categorii sunt tipic solicitate în această perioadă a anului (luna/sezonul curent)?
- Ce tendințe de piață sunt relevante acum pentru un distribuitor din România (prețuri materii prime, cerere construcții, tendințe renovare)?
- Există produse în datele noastre al căror consum sugerează că Malinco urmează un trend de piață pozitiv sau negativ?
- Ce categorii ar merita aprovizionate suplimentar anticipând cererea sezonieră din lunile următoare?

Fii explicit că acestea sunt estimări bazate pe cunoștințe generale de piață, nu pe date Malinco specifice, și că managerul trebuie să valideze cu experiența sa locală.
ORDERING;

        $sectionsInstructions = match($this->sections) {
            'stock' => <<<INSTR
### 1. Rezumat executiv (3-5 propoziții)
### 2. Analiza stocurilor (detaliată)
- Tendința valorii totale a stocului — cu cât a crescut/scăzut și ce înseamnă asta
- Produsele cu cele mai mari scăderi — calculează ritmul de consum și estimează când se epuizează
- Produse fără stoc — sunt în creștere față de perioadele anterioare?
- Pattern-uri: categorii sau tipuri de produse care se epuizează sistematic
### 3. Analiza prețurilor (moderată)
- Frecvența modificărilor — normală sau neobișnuită?
- Produse cu variații mari — posibile probleme (erori de import, schimbare furnizor)?
- Tendința generală a prețurilor în catalog
### 4. Produse care necesită atenție urgentă
Listează 3-7 produse specifice cu risc concret și motivul explicit
### 5. Recomandări operaționale (3-5 acțiuni concrete și acționabile imediat)
{$orderingSection}
INSTR,
            'online' => <<<INSTR
### 1. Rezumat executiv (3-5 propoziții)
### 2. Analiza comenzilor (detaliată)
- Tendință: cresc, scad sau stagnează vânzările? Identifică pattern-uri (zile, săptămâni)
- Statusul comenzilor — procent anulări, comenzi în procesare
- Produsele cele mai vândute — există concentrare pe câteva produse?
### 3. Produse care necesită atenție
Produse cu vânzări mari dar posibil stoc insuficient, sau produse care nu se vând deloc
### 4. Recomandări (3-5 acțiuni concrete)
INSTR,
            default => <<<INSTR
### 1. Rezumat executiv (3-5 propoziții)
### 2. Analiza stocurilor (secțiunea principală — detaliată)
- Tendința valorii totale — cu cât a crescut/scăzut
- Produsele cu cele mai mari scăderi — calculează ritmul de consum și estimează când se epuizează
- Numărul de produse fără stoc — în creștere?
- Pattern-uri: categorii care se epuizează sistematic
### 3. Analiza prețurilor (moderată)
- Frecvența modificărilor și tendința generală
- Produse cu variații mari de preț — posibile probleme?
### 4. Produse care necesită atenție urgentă
Listează 3-7 produse specifice cu risc concret și motivul explicit
### 5. Magazin online — sumar scurt (1 paragraf)
Câte comenzi, ce valoare, vreo anomalie evidentă. Fără analiză detaliată.
### 6. Recomandări operaționale (3-5 acțiuni concrete și acționabile imediat)
{$orderingSection}
INSTR,
        };

        return <<<PROMPT
Ești un analist de business expert care analizează datele unui distribuitor de materiale de construcții și bricolaj din România (Malinco).
Data de azi este {$today}. Perioada analizată: {$periodStr}.

## CONTEXT DE BUSINESS — citește cu atenție înainte de analiză

**1. Canalul principal de vânzări este magazinul FIZIC, nu cel online.**
Mișcările de stoc (scăderile de cantitate disponibilă) reflectă în cea mai mare parte vânzările din magazinul fizic și livrările către clienți B2B, nu comenzile online. Când analizezi scăderile de stoc, nu le asocia automat cu activitatea online — ele sunt indicatorul real al cererii totale din piață. Magazinul online este un canal secundar și în curs de dezvoltare.

**2. Problemă recurentă la comenzile online: produse cu volum atipic livrate în afara județului Bihor.**
Deși pe site este afișat explicit că produsele cu volum atipic (produse mari, greoaie sau cu dimensiuni speciale — ex: plăci, profile lungi, materiale vrac etc.) nu pot fi livrate prin curier standard în afara județului Bihor (decât dacă clientul vine să le ridice sau trimite propria mașină), se primesc în continuare multe comenzi din toată țara pentru aceste produse. Aceste comenzi generează comenzi problematice care cel mai probabil sunt anulate sau nu pot fi onorate normal. Când analizezi comenzile online, ține cont de acest pattern și semnalează dacă observi semne ale acestei probleme (ex: comenzi anulate în număr mare, comenzi pe produse voluminoase din județe îndepărtate).

{$focusNote}
{$pastContext}
---
{$stockSection}{$onlineSection}
---

## CERINȚE RAPORT

Scrie raportul în ROMÂNĂ, formatat în Markdown (folosește ##, ###, **, liste cu bullet points).

{$sectionsInstructions}

Fii direct și pragmatic. Evită formulările vagi. Dacă datele nu permit o concluzie clară, spune asta explicit.
PROMPT;
    }

    public function failed(\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error(class_basename(static::class) . ' failed', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }

    private function snapshotForStorage(array $metrics): array
    {
        return [
            'sections'        => $this->sections,
            'dateFrom'        => $this->dateFrom->toDateString(),
            'dateTo'          => $this->dateTo->toDateString(),
            'inStockCount'    => $metrics['inStockCount'],
            'outOfStockCount' => $metrics['outOfStockCount'],
            'totalProducts'   => $metrics['totalProducts'],
            'ordersLast7'     => WooOrder::where('order_date', '>=', Carbon::now()->subDays(7))->count(),
            'ordersTotal'     => $metrics['ordersTotal'],
            'ordersValue'     => $metrics['ordersValue'],
            'ordersValue30'   => (float) WooOrder::where('order_date', '>=', Carbon::now()->subDays(30))
                                    ->whereNotIn('status', ['cancelled', 'refunded', 'failed'])
                                    ->sum('total'),
        ];
    }
}
