<?php

namespace App\Console\Commands\BI;

use Anthropic\Client as AnthropicClient;
use App\Models\AiUsageLog;
use App\Models\BiAnalysis;
use App\Models\User;
use Filament\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeneratePeriodBiReportCommand extends Command
{
    protected $signature = 'bi:generate-period-report
                            {--type=          : Tipul raportului: quarterly | semiannual | annual}
                            {--from=          : Data de start YYYY-MM-DD (implicit: calculat din type)}
                            {--to=            : Data de end YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Generează automat raportul BI trimestrial, semestrial sau anual';

    public int $timeout = 600;

    // ── Configurare per tip ───────────────────────────────────────────────────

    private const CONFIG = [
        'quarterly' => [
            'days'       => 90,
            'label'      => 'Trimestrial',
            'maxTokens'  => 12000,
            'maxWords'   => 2200,
            'grouping'   => 'weekly',   // granularitate date BI
        ],
        'semiannual' => [
            'days'       => 180,
            'label'      => 'Semestrial',
            'maxTokens'  => 16000,
            'maxWords'   => 2800,
            'grouping'   => 'monthly',
        ],
        'annual' => [
            'days'       => 365,
            'label'      => 'Anual',
            'maxTokens'  => 16000,
            'maxWords'   => 3500,
            'grouping'   => 'monthly',
        ],
    ];

    public function handle(): int
    {
        $type = $this->option('type');

        if (! isset(self::CONFIG[$type])) {
            $this->error("--type trebuie să fie: quarterly | semiannual | annual. Primit: '{$type}'");
            return self::FAILURE;
        }

        $cfg = self::CONFIG[$type];

        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY lipsește din .env');
            return self::FAILURE;
        }

        $to   = $this->option('to')
            ? Carbon::parse($this->option('to'))->endOfDay()
            : Carbon::yesterday()->endOfDay();

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()
            : $to->copy()->subDays($cfg['days'] - 1)->startOfDay();

        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        $this->line("=== BI {$cfg['label']} Report — <info>{$fromStr} → {$toStr}</info> ===");

        $kpiCount = DB::table('bi_inventory_kpi_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->count();

        if ($kpiCount === 0) {
            $this->warn("Nicio dată BI pentru intervalul {$fromStr} → {$toStr}. Abort.");
            Log::warning("bi:generate-period-report --type={$type}: no KPI data", compact('fromStr', 'toStr'));
            return self::SUCCESS;
        }

        $this->line("  Date disponibile: {$kpiCount} zile KPI.");

        $title    = "Raport {$cfg['label']} BI — {$fromStr} → {$toStr}";
        $analysis = BiAnalysis::create([
            'type'         => $type,
            'generated_by' => null,
            'title'        => $title,
            'content'      => '',
            'status'       => 'pending',
            'generated_at' => now(),
        ]);

        try {
            $metrics     = $this->gatherMetrics($fromStr, $toStr, $cfg['grouping']);
            $pastReports = $this->fetchPastReports($type, $fromStr, $toStr, $analysis->id);

            $this->line("  Context: " . count($pastReports) . " rapoarte anterioare.");

            $prompt = $this->buildPrompt($metrics, $pastReports, $fromStr, $toStr, $type, $cfg);

            $this->line('  → Trimit la Claude (' . number_format(mb_strlen($prompt), 0, '.', '') . ' caractere)...');

            $claude  = new AnthropicClient(apiKey: $apiKey);
            $message = $claude->messages->create(
                maxTokens: $cfg['maxTokens'],
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
                throw new \RuntimeException('Claude nu a returnat niciun conținut.');
            }

            $inputTokens  = $message->usage->inputTokens  ?? 0;
            $outputTokens = $message->usage->outputTokens ?? 0;
            $costUsd      = round($inputTokens / 1_000_000 * 3 + $outputTokens / 1_000_000 * 15, 5);

            AiUsageLog::record('bi_' . $type, config('app.malinco.ai.models.sonnet', 'claude-sonnet-4-6'), $inputTokens, $outputTokens, [
                'analysis_id' => $analysis->id,
                'period_type' => $type,
            ]);

            $analysis->update([
                'content'          => $content,
                'status'           => 'done',
                'generated_at'     => now(),
                'metrics_snapshot' => [
                    'type'               => $type,
                    'from'               => $fromStr,
                    'to'                 => $toStr,
                    'kpi_days'           => $kpiCount,
                    'total_p0'           => $metrics['lastP0'],
                    'total_p1'           => $metrics['lastP1'],
                    'total_p2'           => $metrics['lastP2'],
                    'stock_value_start'  => $metrics['stockValueStart'],
                    'stock_value_end'    => $metrics['stockValueEnd'],
                    'past_reports_count' => count($pastReports),
                    'tokens_input'       => $inputTokens,
                    'tokens_output'      => $outputTokens,
                    'cost_usd'           => $costUsd,
                ],
            ]);

            $this->info(sprintf(
                '  ✓ Raport %s generat (ID: %d) — P0=%d P1=%d P2=%d — $%.4f',
                $type, $analysis->id,
                $metrics['lastP0'], $metrics['lastP1'], $metrics['lastP2'],
                $costUsd,
            ));

            $this->notifySuperAdmins($analysis, $cfg['label']);

        } catch (\Throwable $e) {
            $analysis->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'generated_at'  => now(),
            ]);
            $this->error('Eroare: ' . $e->getMessage());
            Log::error("bi:generate-period-report --type={$type}: failed", ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ── Date gathering ────────────────────────────────────────────────────────

    private function gatherMetrics(string $fromStr, string $toStr, string $grouping): array
    {
        // ── Data maturity — câte zile calendaristice de date există global ────
        $maturityRaw        = DB::table('daily_stock_metrics')
            ->selectRaw('COUNT(DISTINCT day) as days_of_history, MIN(day) as first_day, MAX(day) as last_day')
            ->first();
        $daysOfHistory      = (int) ($maturityRaw->days_of_history ?? 0);
        $firstDayWithData   = $maturityRaw->first_day ?? 'N/A';
        $lastDayWithData    = $maturityRaw->last_day  ?? 'N/A';
        $coverageRatio30d   = (int) round(min($daysOfHistory, 30) / 30 * 100);
        $coverageRatio90d   = (int) round(min($daysOfHistory, 90) / 90 * 100);
        $dataMaturityStatus = match(true) {
            $daysOfHistory < 30 => 'BOOTSTRAP',
            $daysOfHistory < 90 => 'PARTIAL_HISTORY',
            default             => 'MATURE',
        };

        // ── KPI agregat ───────────────────────────────────────────────────────
        if ($grouping === 'weekly') {
            $kpiGrouped = DB::table('bi_inventory_kpi_daily')
                ->whereBetween('day', [$fromStr, $toStr])
                ->selectRaw("
                    YEARWEEK(day, 1) as group_key,
                    MIN(day) as period_start, MAX(day) as period_end,
                    ROUND(AVG(inventory_value_closing_total), 0) as avg_stock_value,
                    ROUND(SUM(inventory_value_variation_total), 0) as total_variation,
                    ROUND(AVG(products_in_stock), 0) as avg_in_stock,
                    ROUND(AVG(products_out_of_stock), 0) as avg_out_of_stock
                ")
                ->groupByRaw('YEARWEEK(day, 1)')
                ->orderBy('period_start')
                ->get();
            $groupLabel = 'Săptămâna';
        } else {
            $kpiGrouped = DB::table('bi_inventory_kpi_daily')
                ->whereBetween('day', [$fromStr, $toStr])
                ->selectRaw("
                    DATE_FORMAT(MIN(day), '%Y-%m') as group_key,
                    MIN(day) as period_start, MAX(day) as period_end,
                    ROUND(AVG(inventory_value_closing_total), 0) as avg_stock_value,
                    ROUND(SUM(inventory_value_variation_total), 0) as total_variation,
                    ROUND(AVG(products_in_stock), 0) as avg_in_stock,
                    ROUND(AVG(products_out_of_stock), 0) as avg_out_of_stock
                ")
                ->groupByRaw('YEAR(day), MONTH(day)')
                ->orderBy('period_start')
                ->get();
            $groupLabel = 'Luna';
        }

        $kpiAll = DB::table('bi_inventory_kpi_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->orderBy('day')->get();
        $stockValueStart = (float) ($kpiAll->first()?->inventory_value_closing_total ?? 0);
        $stockValueEnd   = (float) ($kpiAll->last()?->inventory_value_closing_total  ?? 0);

        // ── Alerte agregate — COUNT DISTINCT pentru SKU unice per perioadă ──
        if ($grouping === 'weekly') {
            $alertsGrouped = DB::table('bi_inventory_alert_candidates_daily')
                ->whereBetween('day', [$fromStr, $toStr])
                ->selectRaw("YEARWEEK(day,1) as group_key, MIN(day) as period_start, MAX(day) as period_end, risk_level, COUNT(DISTINCT reference_product_id) as cnt")
                ->groupByRaw('YEARWEEK(day,1), risk_level')
                ->orderBy('period_start')
                ->get()->groupBy('group_key');
        } else {
            $alertsGrouped = DB::table('bi_inventory_alert_candidates_daily')
                ->whereBetween('day', [$fromStr, $toStr])
                ->selectRaw("DATE_FORMAT(MIN(day),'%Y-%m') as group_key, MIN(day) as period_start, MAX(day) as period_end, risk_level, COUNT(DISTINCT reference_product_id) as cnt")
                ->groupByRaw('YEAR(day), MONTH(day), risk_level')
                ->orderBy('period_start')
                ->get()->groupBy('group_key');
        }

        $lastAlertDay = DB::table('bi_inventory_alert_candidates_daily')
            ->whereBetween('day', [$fromStr, $toStr])->max('day');

        $lastP0 = $lastP1 = $lastP2 = 0;
        if ($lastAlertDay) {
            $counts = DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)
                ->selectRaw('risk_level, COUNT(*) as cnt')
                ->groupBy('risk_level')->get()->keyBy('risk_level');
            $lastP0 = (int) ($counts->get('P0')?->cnt ?? 0);
            $lastP1 = (int) ($counts->get('P1')?->cnt ?? 0);
            $lastP2 = (int) ($counts->get('P2')?->cnt ?? 0);
        }

        $topP0 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily as alc')
            ->where('alc.day', $lastAlertDay)->where('alc.risk_level', 'P0')
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'alc.reference_product_id')
            ->leftJoin('product_suppliers as ps', function ($join) {
                $join->on('ps.woo_product_id', '=', 'wp.id')->where('ps.is_preferred', 1);
            })
            ->leftJoin('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->select('alc.*', 's.name as supplier_name', 'ps.lead_days as supplier_lead_days')
            ->orderByRaw('COALESCE(alc.days_left_estimate, 9999) ASC, alc.stock_value DESC')
            ->limit(20)->get() : collect();

        $topP1 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily as alc')
            ->where('alc.day', $lastAlertDay)->where('alc.risk_level', 'P1')
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'alc.reference_product_id')
            ->leftJoin('product_suppliers as ps', function ($join) {
                $join->on('ps.woo_product_id', '=', 'wp.id')->where('ps.is_preferred', 1);
            })
            ->leftJoin('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->select('alc.*', 's.name as supplier_name', 'ps.lead_days as supplier_lead_days')
            ->orderByRaw('COALESCE(alc.days_left_estimate, 9999) ASC')
            ->limit(15)->get() : collect();

        $topP2 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', $lastAlertDay)->where('risk_level', 'P2')
            ->orderByDesc('stock_value')->limit(20)->get() : collect();

        // ── Flags ─────────────────────────────────────────────────────────────
        $flagCounts = [];
        DB::table('bi_inventory_alert_candidates_daily')
            ->whereBetween('day', [$fromStr, $toStr])->pluck('reason_flags')
            ->each(function ($json) use (&$flagCounts) {
                foreach (json_decode($json, true) ?? [] as $flag) {
                    $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
                }
            });
        arsort($flagCounts);

        // ── Velocity ──────────────────────────────────────────────────────────
        $velocityDay = DB::table('bi_product_velocity_current')->max('calculated_for_day');

        $topFastMovers = DB::table('bi_product_velocity_current as v')
            ->leftJoin('woo_products as p', 'p.sku', '=', 'v.reference_product_id')
            ->whereRaw("COALESCE(p.product_type, 'shop') = 'shop'")
            ->select('v.reference_product_id', 'p.name as product_name',
                     'v.avg_out_qty_7d', 'v.avg_out_qty_30d', 'v.avg_out_qty_90d',
                     'v.out_qty_30d', 'v.out_qty_90d')
            ->where('v.avg_out_qty_90d', '>', 0)
            ->orderByDesc('v.avg_out_qty_90d')
            ->limit(20)->get();

        $topSlowMovers = DB::table('bi_product_velocity_current as v')
            ->leftJoin('woo_products as p', 'p.sku', '=', 'v.reference_product_id')
            ->whereRaw("COALESCE(p.product_type, 'shop') = 'shop'")
            ->select('v.reference_product_id', 'p.name as product_name',
                     'v.days_since_last_movement', 'v.last_movement_day', 'v.out_qty_90d')
            ->where(fn ($q) => $q->where('v.days_since_last_movement', '>=', 30)->orWhereNull('v.days_since_last_movement'))
            ->orderByRaw('COALESCE(v.days_since_last_movement, 9999) DESC')
            ->limit(15)->get();

        // ── Executive card — metrici suplimentare ─────────────────────────────
        $totalShopProducts = \App\Models\WooProduct::where('product_type', \App\Models\WooProduct::TYPE_SHOP)
            ->whereNotNull('sku')->where('sku', '!=', '')->count();

        $noConsumption30dEnd = $lastAlertDay
            ? DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)
                ->whereRaw("JSON_CONTAINS(reason_flags, '\"no_consumption_30d\"')")
                ->count()
            : 0;

        $p2ValueEnd = $lastAlertDay
            ? (float) DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)->where('risk_level', 'P2')
                ->sum('stock_value')
            : 0.0;

        $top10P2Value = $lastAlertDay
            ? (float) DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)->where('risk_level', 'P2')
                ->orderByDesc('stock_value')->limit(10)->pluck('stock_value')->sum()
            : 0.0;

        // P0 breakdown pe reason_flags (ultima zi)
        $p0AllFlags = $lastAlertDay
            ? DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)->where('risk_level', 'P0')
                ->pluck('reason_flags')
            : collect();
        $p0Breakdown = ['out_of_stock' => 0, 'critical_stock' => 0, 'price_spike' => 0];
        foreach ($p0AllFlags as $json) {
            $flags = json_decode($json, true) ?? [];
            foreach ($p0Breakdown as $flag => &$cnt) {
                if (in_array($flag, $flags)) $cnt++;
            }
            unset($cnt);
        }

        // SKU-uri cu P0 recurent (2+ perioade distincte în interval) — util pentru quarterly
        $p0RecurringSkus = DB::table('bi_inventory_alert_candidates_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->where('risk_level', 'P0')
            ->selectRaw("reference_product_id, product_name, COUNT(DISTINCT YEARWEEK(day,1)) as p0_weeks")
            ->groupBy('reference_product_id', 'product_name')
            ->havingRaw('p0_weeks >= 2')
            ->orderByDesc('p0_weeks')
            ->limit(20)
            ->get();

        // ── Modificări de preț pe perioadă ───────────────────────────────────
        $priceChanges = collect(DB::select("
            SELECT
                a.reference_product_id,
                wp.name as product_name,
                a.price_start,
                b.price_end,
                ROUND((b.price_end - a.price_start) / a.price_start * 100, 1) as price_change_pct
            FROM (
                SELECT reference_product_id, opening_sell_price as price_start
                FROM (
                    SELECT reference_product_id, opening_sell_price,
                           ROW_NUMBER() OVER (PARTITION BY reference_product_id ORDER BY day ASC) as rn
                    FROM daily_stock_metrics
                    WHERE day BETWEEN ? AND ? AND opening_sell_price > 0
                ) ranked WHERE rn = 1
            ) a
            JOIN (
                SELECT reference_product_id, closing_sell_price as price_end
                FROM (
                    SELECT reference_product_id, closing_sell_price,
                           ROW_NUMBER() OVER (PARTITION BY reference_product_id ORDER BY day DESC) as rn
                    FROM daily_stock_metrics
                    WHERE day BETWEEN ? AND ? AND closing_sell_price > 0
                ) ranked WHERE rn = 1
            ) b ON a.reference_product_id = b.reference_product_id
            LEFT JOIN woo_products wp ON wp.sku = a.reference_product_id
            WHERE COALESCE(wp.product_type, 'shop') = 'shop'
                AND ABS(b.price_end - a.price_start) / a.price_start >= 0.03
            ORDER BY ABS(ROUND((b.price_end - a.price_start) / a.price_start * 100, 1)) DESC
            LIMIT 20
        ", [$fromStr, $toStr, $fromStr, $toStr]));

        return compact(
            'kpiGrouped', 'groupLabel', 'alertsGrouped', 'lastAlertDay',
            'lastP0', 'lastP1', 'lastP2', 'topP0', 'topP1', 'topP2',
            'flagCounts', 'stockValueStart', 'stockValueEnd',
            'velocityDay', 'topFastMovers', 'topSlowMovers',
            'totalShopProducts', 'noConsumption30dEnd', 'p2ValueEnd', 'top10P2Value',
            'p0Breakdown', 'p0RecurringSkus', 'priceChanges',
            // Data maturity
            'daysOfHistory', 'firstDayWithData', 'lastDayWithData',
            'coverageRatio30d', 'coverageRatio90d', 'dataMaturityStatus',
        );
    }

    // ── Context rapoarte anterioare (stratificat per tip) ─────────────────────

    private function fetchPastReports(string $type, string $fromStr, string $toStr, int $excludeId): array
    {
        $from = Carbon::parse($fromStr)->startOfDay();
        $to   = Carbon::parse($toStr)->endOfDay();

        // Fiecare tip de raport include sumarii din tipurile de mai jos (mai granulare)
        $sources = match($type) {
            'quarterly'  => [
                ['types' => ['monthly'],              'limit' => 3, 'chars' => 3000],
                ['types' => ['weekly'],               'limit' => 4, 'chars' => 2000],
            ],
            'semiannual' => [
                ['types' => ['quarterly'],            'limit' => 2, 'chars' => 3500],
                ['types' => ['monthly'],              'limit' => 3, 'chars' => 2500],
            ],
            'annual'     => [
                ['types' => ['semiannual'],           'limit' => 2, 'chars' => 4000],
                ['types' => ['quarterly'],            'limit' => 4, 'chars' => 3000],
                ['types' => ['monthly'],              'limit' => 2, 'chars' => 2000],
            ],
            default => [],
        };

        $collected = collect();
        foreach ($sources as $src) {
            $rows = BiAnalysis::where('status', 'done')
                ->whereIn('type', $src['types'])
                ->where('id', '!=', $excludeId)
                ->whereBetween('generated_at', [$from, $to])
                ->latest('generated_at')
                ->limit($src['limit'])
                ->get()
                ->map(fn ($a) => [
                    'type'         => $a->type,
                    'title'        => $a->title,
                    'generated_at' => $a->generated_at->format('d.m.Y'),
                    'content'      => Str::limit($a->content, $src['chars']),
                ]);
            $collected = $collected->concat($rows);
        }

        // Ordonăm cronologic pentru context coeziv
        return $collected->sortBy('generated_at')->values()->toArray();
    }

    // ── Prompt builder ────────────────────────────────────────────────────────

    private function buildPrompt(array $m, array $pastReports, string $fromStr, string $toStr, string $type, array $cfg): string
    {
        $today     = Carbon::today()->format('d.m.Y');
        $periodStr = Carbon::parse($fromStr)->format('d.m.Y') . ' – ' . Carbon::parse($toStr)->format('d.m.Y');
        $label     = $cfg['label'];
        $maxWords  = $cfg['maxWords'];

        // ── Data maturity variables ──────────────────────────────────────────
        $daysOfHistory = (int) $m['daysOfHistory'];
        $isBootstrap   = $m['dataMaturityStatus'] === 'BOOTSTRAP';
        $isPartial     = $m['dataMaturityStatus'] === 'PARTIAL_HISTORY';
        $dilution30d   = $daysOfHistory > 0 ? (int) round(min($daysOfHistory, 30) / 30 * 100) : 0;
        $dilution90d   = $daysOfHistory > 0 ? (int) round(min($daysOfHistory, 90) / 90 * 100) : 0;

        $maturityStatusLabel = match($m['dataMaturityStatus']) {
            'BOOTSTRAP'       => "BOOTSTRAP ({$daysOfHistory} zile date)",
            'PARTIAL_HISTORY' => "PARTIAL_HISTORY ({$daysOfHistory} zile date, acoperire 30d: {$m['coverageRatio30d']}%)",
            default           => "MATURE ({$daysOfHistory} zile date — analize statistice valide)",
        };

        $p2ContextNote = $isBootstrap
            ? "⚠ BOOTSTRAP: P2 = «fără mișcare în {$daysOfHistory} zile», NU «dead stock» confirmat. Nu recomanda lichidare."
            : ($isPartial
                ? "ℹ PARTIAL_HISTORY: P2 = «fără mișcare 30d» — preliminar, confirmare la 90 zile."
                : "MATURE: P2 = «dead stock» confirmat (≥90 zile date). Recomandări de lichidare aplicabile.");

        $velocityNote = $isBootstrap
            ? "⚠ BOOTSTRAP: avg_30d diluat {$dilution30d}% (baza reală: {$daysOfHistory} zile). Folosește EXCLUSIV avg_7d pentru semnal rapid. Spike detection: dezactivat."
            : ($isPartial
                ? "ℹ PARTIAL_HISTORY: avg_30d valid. avg_90d diluat {$dilution90d}% — nu folosi pentru spike detection."
                : "MATURE: avg_30d și avg_90d valide. Spike detection activat (avg_7d/avg_90d > 3×).");

        $avg30dStatus      = $isBootstrap ? "LOW confidence (diluție {$dilution30d}%)" : 'MEDIUM/HIGH confidence';
        $avg90dStatus      = ($daysOfHistory < 90) ? "LOW confidence (diluție {$dilution90d}%)" : 'HIGH confidence';
        $p2ContextLabel    = $isBootstrap ? '«candidat potențial de dead stock»' : '«dead stock confirmat»';
        $p2LiquidationNote = $isBootstrap
            ? 'Nu recomanda lichidare — insuficiente date. Raportează: «candidat potențial — de verificat la 30 zile».'
            : ($isPartial ? 'Lichidare: preliminar aplicabilă, confirmare la 90 zile.' : 'Lichidare aplicabilă.');
        $bootstrapDaysLeftNote = $isBootstrap
            ? "\n  [BOOTSTRAP: days_left_cal=qty÷(out30d÷30) | days_left_obs=qty÷(out30d÷{$daysOfHistory}) — raportează ambele]"
            : '';

        $firstDay = $m['firstDayWithData'];
        $lastDay  = $m['lastDayWithData'];
        $cov30    = $m['coverageRatio30d'];
        $cov90    = $m['coverageRatio90d'];

        // Secțiuni condiționale quarterly (Part 8 — bootstrap-safe):
        $recurringP0Section = $daysOfHistory >= 28
            ? "- **SKU recurente (P0 în 2+ săptămâni):** riscuri sistemice, nu incidentale — acțiuni prioritare\n"
            : "- **SKU recurente:** date insuficiente ({$daysOfHistory} zile < 28 necesare). Nu interpreta recurența ca pattern sistemic.\n";
        $supplierQNote = $daysOfHistory >= 30
            ? "- Furnizori cu lead_days mare și P0 recurent: planifică comenzi cu buffer\n"
            : "- Analiza furnizori: date insuficiente ({$daysOfHistory} zile < 30). Omite analiza de performanță furnizori.\n";
        $portfolioQNote = $daysOfHistory >= 60
            ? "- Categorii cu dead stock sistematic: recomandă restructurare portofoliu\n"
            : "- Restructurare portofoliu: date insuficiente ({$daysOfHistory} zile < 60). Nu elimina produse — verifică la 60 zile.\n";

        $maturityBlock = <<<MATURITY
## STATUS MATURITATE DATE

- Status: **{$maturityStatusLabel}**
- Interval date: {$firstDay} → {$lastDay} ({$daysOfHistory} zile calendaristice)
- Acoperire fereastră 30 zile: {$cov30}% | Acoperire fereastră 90 zile: {$cov90}%

**Clasificare confidence metrici:**
- ✅ HIGH: qty stoc, out_of_stock (stoc 0), valoare stoc, variații zilnice preț
- ⚠ MEDIUM: avg_7d (HIGH confidence), P1 bazat pe avg_7d
- ❌ LOW (date insuficiente): avg_30d ({$avg30dStatus}), avg_90d ({$avg90dStatus}), days_left estimate, P2 dead stock

{$p2ContextNote}
MATURITY;

        // ── KPI linii ─────────────────────────────────────────────────────────
        $kpiLines = $m['kpiGrouped']->map(fn ($w) => sprintf(
            '  %s %s → %s: stoc mediu %.0f RON (variație %+.0f RON) | avg în stoc: %.0f | avg fără stoc: %.0f',
            $m['groupLabel'], $w->period_start, $w->period_end,
            (float) $w->avg_stock_value, (float) $w->total_variation,
            (float) $w->avg_in_stock, (float) $w->avg_out_of_stock,
        ))->implode("\n");

        $stockDelta = $m['stockValueEnd'] - $m['stockValueStart'];
        $deltaSign  = $stockDelta >= 0 ? '+' : '';
        $deltaFmt   = $deltaSign . number_format(abs($stockDelta), 0, ',', '.');
        $startFmt   = number_format($m['stockValueStart'], 0, ',', '.');
        $endFmt     = number_format($m['stockValueEnd'], 0, ',', '.');
        $deltaPct   = $m['stockValueStart'] > 0
            ? $deltaSign . round(abs($stockDelta) / $m['stockValueStart'] * 100, 1) . '%'
            : '—';

        // ── Alerte linii ──────────────────────────────────────────────────────
        $alertLines = $m['alertsGrouped']->map(function ($rows, $key) use ($m) {
            $first   = $rows->first();
            $byLevel = $rows->keyBy('risk_level');
            return sprintf('  %s %s → %s: P0=%d P1=%d P2=%d (SKU distincte)',
                $m['groupLabel'], $first->period_start, $first->period_end,
                (int) ($byLevel->get('P0')?->cnt ?? 0),
                (int) ($byLevel->get('P1')?->cnt ?? 0),
                (int) ($byLevel->get('P2')?->cnt ?? 0),
            );
        })->implode("\n");

        // Flags
        $flagLines = collect($m['flagCounts'])->map(fn ($cnt, $flag) =>
            "  {$flag}: {$cnt} rânduri-zi (SKU×zi)"
        )->implode("\n");

        // ── Top P0 — cu două valori days_left în bootstrap ────────────────────
        $p0Lines = $m['topP0']->map(function ($r, $i) use ($isBootstrap, $daysOfHistory) {
            $daysLeftCal = $r->days_left_estimate !== null ? number_format((float) $r->days_left_estimate, 1, '.', '') : '∞';
            if ($isBootstrap && $r->days_left_estimate !== null) {
                $daysLeftObs = number_format((float) $r->days_left_estimate * $daysOfHistory / 30, 1, '.', '');
                $daysLeftStr = "{$daysLeftCal}d (cal) / {$daysLeftObs}d (obs)";
            } else {
                $daysLeftStr = $daysLeftCal;
            }
            return sprintf(
                '  %d. [%s] %s | qty: %.0f | %.0f RON | zile: %s | flags: %s | furnizor: %s (lead: %s zile)',
                $i + 1, $r->reference_product_id,
                mb_substr($r->product_name ?? 'N/A', 0, 40),
                (float) $r->closing_qty, (float) $r->stock_value,
                $daysLeftStr,
                implode(', ', json_decode($r->reason_flags ?? '[]', true) ?? []),
                $r->supplier_name ?? 'N/A',
                $r->supplier_lead_days ?? '?',
            );
        })->implode("\n");

        // ── Top P1 — cu două valori days_left în bootstrap ────────────────────
        $p1Lines = $m['topP1']->map(function ($r, $i) use ($isBootstrap, $daysOfHistory) {
            $daysLeftCal = $r->days_left_estimate !== null ? number_format((float) $r->days_left_estimate, 1, '.', '') : '—';
            if ($isBootstrap && $r->days_left_estimate !== null) {
                $daysLeftObs = number_format((float) $r->days_left_estimate * $daysOfHistory / 30, 1, '.', '');
                $daysLeftStr = "{$daysLeftCal}d (cal) / {$daysLeftObs}d (obs)";
            } else {
                $daysLeftStr = $daysLeftCal;
            }
            return sprintf(
                '  %d. [%s] %s | zile: %s | %.0f RON | consum/zi: %.3f | furnizor: %s (lead: %s zile)',
                $i + 1, $r->reference_product_id,
                mb_substr($r->product_name ?? 'N/A', 0, 40),
                $daysLeftStr,
                (float) $r->stock_value, (float) $r->avg_out_30d,
                $r->supplier_name ?? 'N/A',
                $r->supplier_lead_days ?? '?',
            );
        })->implode("\n");

        // ── Top P2 ────────────────────────────────────────────────────────────
        $p2Lines = $m['topP2']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | capital blocat: %.0f RON | qty: %.0f buc',
            $i + 1, $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->stock_value, (float) $r->closing_qty,
        ))->implode("\n");

        // ── Velocity ──────────────────────────────────────────────────────────
        $fastLines = $m['topFastMovers']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | avg/zi 7d: %.3f | avg/zi 30d: %.3f | avg/zi 90d: %.3f | ieșit 90d: %.0f buc',
            $i + 1, $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->avg_out_qty_7d, (float) $r->avg_out_qty_30d, (float) $r->avg_out_qty_90d,
            (float) $r->out_qty_90d,
        ))->implode("\n");

        $slowLines = $m['topSlowMovers']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | fără mișcare: %s zile | ultima: %s | ieșit 90d: %.0f buc',
            $i + 1, $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            $r->days_since_last_movement ?? 'niciodată',
            $r->last_movement_day ?? 'N/A',
            (float) $r->out_qty_90d,
        ))->implode("\n");

        $velocityDay = $m['velocityDay'] ?? 'N/A';

        // ── P0 recurent ───────────────────────────────────────────────────────
        $p0RecurLines = $m['p0RecurringSkus']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s — P0 în %d săptămâni distincte',
            $i + 1, $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (int) $r->p0_weeks,
        ))->implode("\n") ?: '  (niciun SKU cu P0 recurent în perioadă)';

        // ── Modificări prețuri ────────────────────────────────────────────────
        $priceLines = $m['priceChanges']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | %.2f → %.2f RON (%+.1f%%)',
            $i + 1, $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->price_start, (float) $r->price_end, (float) $r->price_change_pct,
        ))->implode("\n");
        $priceUpCount   = $m['priceChanges']->where('price_change_pct', '>', 0)->count();
        $priceDownCount = $m['priceChanges']->where('price_change_pct', '<', 0)->count();
        $priceSpikeRZ   = $m['flagCounts']['price_spike'] ?? 0;

        // ── Context rapoarte anterioare ───────────────────────────────────────
        $pastContext = '';
        if (! empty($pastReports)) {
            $typeLabels = [
                'weekly'     => '[SĂPTĂMÂNAL]',
                'monthly'    => '[LUNAR]',
                'quarterly'  => '[TRIMESTRIAL]',
                'semiannual' => '[SEMESTRIAL]',
            ];
            $pastContext = "\n---\n\n## RAPOARTE BI ANTERIOARE DIN PERIOADĂ (" . count($pastReports) . " rapoarte)\n\n";
            $pastContext .= "Folosește-le ca bază. Nu repeta concluzii deja stabilite — sintetizează pattern-urile pe {$label}.\n\n";
            foreach ($pastReports as $r) {
                $typeLabel = $typeLabels[$r['type']] ?? '[RAPORT]';
                $pastContext .= "### {$typeLabel} {$r['title']} ({$r['generated_at']})\n{$r['content']}\n\n";
            }
        }

        // ── Executive card — valori formatate ────────────────────────────────
        $noConsumptionPct = $m['totalShopProducts'] > 0
            ? round($m['noConsumption30dEnd'] / $m['totalShopProducts'] * 100, 1) : 0;
        $p2WeightPct      = $m['stockValueEnd'] > 0
            ? round($m['p2ValueEnd'] / $m['stockValueEnd'] * 100, 1) : 0;
        $top10ConcentrPct = $m['p2ValueEnd'] > 0
            ? round($m['top10P2Value'] / $m['p2ValueEnd'] * 100, 1) : 0;
        $p2ValueEndFmt    = number_format($m['p2ValueEnd'], 0, ',', '.');
        $top10P2Fmt       = number_format($m['top10P2Value'], 0, ',', '.');

        // ── P0 breakdown formatat ─────────────────────────────────────────────
        $p0BrkStr = sprintf(
            '- out_of_stock (qty=0): %d SKU%s' . "\n" .
            '- critical_stock (days_left ≤ 7, avg_out_30d > 0): %d SKU%s' . "\n" .
            '- price_spike (variație preț ≥ prag): %d SKU%s',
            $m['p0Breakdown']['out_of_stock'],
            $m['p0Breakdown']['out_of_stock'] === $m['lastP0'] ? ' ← toți P0' : '',
            $m['p0Breakdown']['critical_stock'],
            $m['p0Breakdown']['critical_stock'] === $m['lastP0'] ? ' ← toți P0' : '',
            $m['p0Breakdown']['price_spike'],
            $m['p0Breakdown']['price_spike'] === $m['lastP0'] ? ' ← toți P0' : '',
        );

        $lastAlertDay = $m['lastAlertDay'];
        $lastP0       = $m['lastP0'];
        $lastP1       = $m['lastP1'];
        $lastP2       = $m['lastP2'];
        $totalShop    = $m['totalShopProducts'];
        $noConsum     = $m['noConsumption30dEnd'];
        $groupLabel   = $m['groupLabel'];

        // ── Instrucțiuni specifice per tip ────────────────────────────────────
        $typeInstructions = match($type) {
            'quarterly' => <<<INSTR
## 1. Status Maturitate Date (1–2 fraze)
Copiază exact: «{$maturityStatusLabel} | interval: {$firstDay} → {$lastDay} | acoperire 30d: {$cov30}% | 90d: {$cov90}%»
Urmează cu o frază despre implicațiile pentru interpretarea raportului trimestrial.

## 2. Card Executiv & Rezumat trimestrial (6–8 fraze)
Copiază EXACT cardul executiv de mai sus ca bloc citat Markdown.
- Tendința trimestrului: stoc, risc, capital blocat — cifrele din card
- Ce s-a îmbunătățit / deteriorat față de trimestrul anterior (din context)
- Top 3 concluzii acționabile

## 3. Riscuri Operaționale Imediate — P0 qty=0 [HIGH CONFIDENCE]
- EXCLUSIV produse out_of_stock — date exacte, fără estimare
- **Breakdown P0 (R4):** out_of_stock / critical_stock / price_spike — exact din datele furnizate
{$recurringP0Section}{$supplierQNote}
## 4. Risc pe Termen Scurt — P0 critical_stock + P1 [{$avg30dStatus}]
- P0 critical_stock: days_left ≤ 7 (avg_30d — {$avg30dStatus}){$bootstrapDaysLeftNote}
- Moderate (P1): care riscă să devină Critice (P0) trimestrul viitor?

## 5. Capital Alocat — {$p2ContextLabel} [{$avg30dStatus}]
- Valoare totală {$p2ValueEndFmt} RON ({$p2WeightPct}% din stoc) — confirmat din card
- Top 10 concentrare: {$top10ConcentrPct}% din valoarea P2
- {$portfolioQNote}- {$p2LiquidationNote}

## 6. Velocity & Trend [{$velocityNote}]
- avg_30d = baza comenzilor. avg_90d = perspectivă trimestrială [{$avg90dStatus}]
- Produse cu ritm accelerat vs decelerant față de avg 90d
- Overlap fast movers cu P0: risc de ruptură iminent?

## 7. Dinamica Prețurilor pe trimestru
- Creșteri/scăderi semnificative față de start perioadă
- price_spike ({$priceSpikeRZ} rânduri-zi): sistemic sau izolat?
- Impactul variațiilor de preț (R7: cauze = ipoteze)

## 8. Recomandări pentru trimestrul următor
- 5–8 acțiuni prioritizate cu referință la date (SKU, RON, count)
- Perspectivă sezonieră: ce produse vor fi cerute?
INSTR,
            'semiannual' => <<<INSTR
## 1. Status Maturitate Date (1–2 fraze)
Copiază exact: «{$maturityStatusLabel} | interval: {$firstDay} → {$lastDay} | acoperire 30d: {$cov30}% | 90d: {$cov90}%»

## 2. Card Executiv & Rezumat semestrial (7–10 fraze)
Copiază EXACT cardul executiv de mai sus ca bloc citat Markdown.
- Tendința semestrului: performanță generală vs semestrul anterior
- Schimbări structurale în stoc, risc, capital blocat
- Top 3 concluzii strategice

## 3. Riscuri Operaționale & Pattern-uri Semestriale — Breakdown P0 obligatoriu (R4)
- **Breakdown P0 (R4):** out_of_stock / critical_stock / price_spike
- Pattern-uri recurente P0 pe întreaga perioadă (SKU-uri sistemice)
- Ce categorii generează sistematic alerte?

## 4. Capital Blocat — {$p2ContextLabel} [{$avg30dStatus}]
- Valoare totală {$p2ValueEndFmt} RON — a crescut / scăzut față de semestrul anterior?
- {$p2LiquidationNote}

## 5. Dinamica Prețurilor semestru
- Top produse cu variații de preț ≥3% în perioada analizată
- Impactul pe valoarea stocului existent (R7: cauze = ipoteze)

## 6. Velocity — perspectivă semestrială [{$velocityNote}]
- Top produse rapid mișcătoare: susțin stocurile curente ritmul?
- Slow movers cronici: ce acțiuni sunt necesare?

## 7. Evaluare strategică stoc
- Valoarea totală: tendință (supra-stocare / sub-stocare?)
- Categorii cu performanță bună vs problematică

## 8. Recomandări strategice pentru semestrul următor
- 6–10 acțiuni prioritizate cu impact estimat
- Perspective sezoniere pentru următoarele 6 luni
INSTR,
            'annual' => <<<INSTR
## 1. Status Maturitate Date (1–2 fraze)
Copiază exact: «{$maturityStatusLabel} | interval: {$firstDay} → {$lastDay} | acoperire 30d: {$cov30}% | 90d: {$cov90}%»

## 2. Card Executiv & Rezumat anual (8–12 fraze)
Copiază EXACT cardul executiv de mai sus ca bloc citat Markdown.
- Performanța anului: stoc, risc, capital blocat
- Ce s-a îmbunătățit structural față de anul anterior (din context)
- Top 5 concluzii strategice cu impact pe termen mediu

## 3. Evoluție KPI — pe luni (întreg anul)
- Valoarea stocului: min / max / medie anuală, trend
- Sezonalitate: care luni au fost peak / valley?

## 4. Riscuri Anuale — Breakdown P0 obligatoriu (R4)
- **Breakdown P0 (R4):** out_of_stock / critical_stock / price_spike
- Există produse cu risc cronic (recurent pe mai multe trimestre)?

## 5. Capital Blocat & Portofoliu
- Valoare totală anuală, evoluție
- {$p2LiquidationNote}
- Categorii profitabile vs problematice

## 6. Dinamica Prețurilor anual
- Top produse cu variații semnificative față de începutul anului
- Pattern-uri la prețuri: sezonalitate, furnizori cu variații mari (R7)

## 7. Velocity — dinamica anuală [{$velocityNote}]
- Top produse: ritmul de consum justifică politica actuală de stocare?
- Produse cu sezonalitate pronunțată

## 8. Plan Strategic pentru anul următor
- 8–12 acțiuni prioritizate cu impact estimat
- Obiective KPI propuse (stoc target, reducere P0, reducere P2)
- Perspective de piață pentru materiale de construcții în România
INSTR,
            default => '',
        };

        return <<<PROMPT
Ești un analist BI senior pentru Malinco — distribuitor de materiale de construcții și bricolaj, România (Bihor).
Data de azi: {$today}. Perioadă analizată: {$periodStr} (Raport {$label}).

## REGULI OBLIGATORII (respectă-le fără excepție)

**R0 — Statutul maturității datelor conduce interpretarea.**
Raportul include obligatoriu blocul «STATUS MATURITATE DATE» (secțiunea 1). Toți indicatorii marcați LOW confidence se raportează cu avertisment explicit. Nu trage concluzii strategice din date BOOTSTRAP.

**R1 — Scop {$label} = strategic/termen mediu-lung.**
Agregări, pattern-uri, tendințe. Max 20 Critice (P0), 15 Moderate (P1), 20 Capital Blocat (P2). Nu liste detaliate operaționale.

**R2 — "SKU" = produse distincte (unique). Niciodată count de rânduri fără etichetă.**
Dacă un count depășește total_produse_active ({$totalShop}), reformulează automat ca "rânduri-zi (SKU×zi)".

**R3 — no_consumption_30d și dead_stock: raportează end-state în rezumat.**
În rezumat: SKU distincte la final de perioadă ({$lastAlertDay}). Volum în perioadă → notă cu "rânduri-zi (SKU×zi)".

**R4 — P0 obligatoriu descompus pe reason_flags (breakdownul calculat mai jos).**
Citează exact numerele furnizate. Nu generaliza fără breakdown.

**R5 — Division-by-zero / dead stock.**
avg_out_30d = 0 → days_left_estimate = NULL → P2 "no_consumption_30d". Nu calcula din ferestre mai scurte.

**R6 — Velocity: avg_30d = baza comenzilor. avg_7d = semnal alertă. avg_90d = perspectivă perioadă lungă.**
Ratio avg_7d/avg_90d > 3× → posibil spike punctual. Recomandă verificare, nu comandă.

**R7 — Cauze = ipoteze, nu certitudini.**
Nu afirma "B2B", "vânzări fizice", "corecții" ca certitudini. Scrie "(ipoteză)" + alternativă.

**R8 — Nomenclatură obligatorie:**
- «Critice (P0)» | «Moderate (P1)» | «Capital Blocat — Dead Stock (P2)»

**R9 — Produse excluse din retail.**
Date EXCLUSIV retail (shop). Producție internă și garanții palet — excluse. Nu le menționezi.

---

## CONTEXT BUSINESS

Malinco — distribuitor materiale construcții & bricolaj, România (Bihor).
Canal de ieșire stoc (ipoteză): magazin fizic + B2B — alternativă: transferuri / ajustări inventar.
Scop raport {$label}: evaluare strategică — tendințe pe termen mediu/lung, pattern-uri sistemice, capital blocat.
Date: exclusiv tabelele BI pre-calculate (retail only, agregate {$groupLabel}).
{$pastContext}
---

{$maturityBlock}

---

## CARD EXECUTIV — INDICATORI CHEIE ({$lastAlertDay})
Copiază-l EXACT la începutul raportului (imediat sub titlu), formatat ca bloc citat Markdown:

> **INDICATORI CHEIE ({$label} — {$periodStr})**
> - Produse retail active cu SKU: {$totalShop}
> - SKU fără consum 30 zile (end state {$lastAlertDay}): {$noConsum} / {$totalShop} = {$noConsumptionPct}%
> - Critice (P0) / Moderate (P1) la final: {$lastP0} / {$lastP1}  |  Capital Blocat (P2): {$lastP2} SKU
> - Valoare Capital Blocat (P2, toate SKU la final): {$p2ValueEndFmt} RON  |  Pondere din stoc: {$p2WeightPct}%
> - Concentrare P2: Top 10 SKU = {$top10P2Fmt} RON / {$p2ValueEndFmt} RON = {$top10ConcentrPct}%

---

## KPI STOC — AGREGAT {$groupLabel}

{$kpiLines}

**Rezumat perioadă:** {$startFmt} RON → {$endFmt} RON ({$deltaFmt} RON, {$deltaPct})

---

## EVOLUȚIE ALERTE — AGREGAT {$groupLabel} (SKU distincte per perioadă)

{$alertLines}

**Starea finală ({$lastAlertDay}):** Critice (P0)={$lastP0} SKU | Moderate (P1)={$lastP1} SKU | Capital Blocat (P2)={$lastP2} SKU

---

## BREAKDOWN CRITICE (P0) PE REASON_FLAGS — stare {$lastAlertDay} (SKU distincte, multi-flag posibil)

{$p0BrkStr}

---

## SKU-URI CU RISC RECURENT (P0 în ≥2 săptămâni distincte din perioadă)

{$p0RecurLines}

---

## DISTRIBUȚIE REASON FLAGS (perioadă completă — rânduri-zi)

{$flagLines}

---

## TOP 20 PRODUSE CRITICE (P0) — stare {$lastAlertDay}
Format: rang. [SKU] denumire | qty | RON | zile | flags | furnizor (lead)
{$p0Lines}

---

## TOP 15 PRODUSE MODERATE (P1) — stare {$lastAlertDay}
Format: rang. [SKU] denumire | zile | RON | consum/zi | furnizor (lead)
{$p1Lines}

---

## TOP 20 CAPITAL BLOCAT — DEAD STOCK (P2) — stare {$lastAlertDay}
Interpretare: {$p2ContextNote}
Valoare totală (toate P2): {$p2ValueEndFmt} RON | Top 10 concentrare: {$top10P2Fmt} RON ({$top10ConcentrPct}%)
{$p2Lines}

---

## MODIFICĂRI PREȚURI — perioadă {$periodStr} (variație ≥3% față de start)
{$priceUpCount} produse cu creștere | {$priceDownCount} produse cu scădere | {$priceSpikeRZ} rânduri-zi cu price_spike intra-zi
{$priceLines}

---

## VELOCITY — TOP 20 RAPID MIȘCĂTOARE (calculat: {$velocityDay})
{$velocityNote}
{$fastLines}

---

## VELOCITY — TOP 15 FĂRĂ MIȘCARE ≥30 ZILE

{$slowLines}

---

## CERINȚE RAPORT

Scrie raportul în ROMÂNĂ, Markdown. **Maxim {$maxWords} cuvinte.** Orientat strategic. Respectă R0–R9.
Nu repeta ceea ce e deja în rapoartele din context — sintetizează pattern-urile pe {$label}.

### Structură obligatorie (8 secțiuni):

# Raport {$label} BI — {$periodStr}

{$typeInstructions}
PROMPT;
    }

    // ── Notificări ────────────────────────────────────────────────────────────

    private function notifySuperAdmins(BiAnalysis $analysis, string $label): void
    {
        $superAdmins = User::where('is_super_admin', true)->get();
        if ($superAdmins->isEmpty()) {
            return;
        }

        Notification::make()
            ->title("Raport {$label} BI generat")
            ->body($analysis->title)
            ->success()
            ->actions([
                NotificationAction::make('view')
                    ->label('Vezi raportul')
                    ->url('/bi-analysis-page')
                    ->button(),
            ])
            ->sendToDatabase($superAdmins);

        $this->line('  → Notificări trimise la ' . $superAdmins->count() . ' superadmin(i).');
    }
}
