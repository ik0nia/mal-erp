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

class GenerateMonthlyBiReportCommand extends Command
{
    protected $signature = 'bi:generate-monthly-report
                            {--from= : Data de start YYYY-MM-DD (implicit: acum 30 zile)}
                            {--to=   : Data de end YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Generează automat raportul BI lunar (30 zile, grupat săptămânal + velocity + context rapoarte)';

    public int $timeout = 300;

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY lipsește din .env');
            return self::FAILURE;
        }

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->endOfDay()
            : Carbon::yesterday()->endOfDay();

        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        $this->line("=== BI Monthly Report — <info>{$fromStr} → {$toStr}</info> ===");

        $kpiCount = DB::table('bi_inventory_kpi_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->count();

        if ($kpiCount === 0) {
            $this->warn("Nicio dată BI pentru intervalul {$fromStr} → {$toStr}. Abort.");
            return self::SUCCESS;
        }

        $this->line("  Date disponibile: {$kpiCount} zile KPI.");

        $title    = "Raport Lunar BI — {$fromStr} → {$toStr}";
        $analysis = BiAnalysis::create([
            'type'         => 'monthly',
            'generated_by' => null,
            'title'        => $title,
            'content'      => '',
            'status'       => 'pending',
            'generated_at' => now(),
        ]);

        try {
            $metrics     = $this->gatherMetrics($fromStr, $toStr);
            $pastReports = $this->fetchPastReports($fromStr, $toStr, $analysis->id);
            $prompt      = $this->buildPrompt($metrics, $pastReports, $fromStr, $toStr);

            $this->line('  Context: ' . count($pastReports) . ' rapoarte anterioare.');
            $this->line('  → Trimit datele la Claude (' . number_format(mb_strlen($prompt), 0, '.', '') . ' caractere prompt)...');

            $claude  = new AnthropicClient(apiKey: $apiKey);
            $message = $claude->messages->create(
                maxTokens: 16000,
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

            AiUsageLog::record('bi_monthly', config('app.malinco.ai.models.sonnet', 'claude-sonnet-4-6'), $inputTokens, $outputTokens, [
                'analysis_id' => $analysis->id,
            ]);

            $analysis->update([
                'content'          => $content,
                'status'           => 'done',
                'generated_at'     => now(),
                'metrics_snapshot' => [
                    'type'                => 'monthly',
                    'from'                => $fromStr,
                    'to'                  => $toStr,
                    'kpi_days'            => $kpiCount,
                    'total_p0'            => $metrics['lastP0'],
                    'total_p1'            => $metrics['lastP1'],
                    'total_p2'            => $metrics['lastP2'],
                    'stock_value_start'   => $metrics['stockValueStart'],
                    'stock_value_end'     => $metrics['stockValueEnd'],
                    'past_reports_count'  => count($pastReports),
                    'tokens_input'        => $inputTokens,
                    'tokens_output'       => $outputTokens,
                    'cost_usd'            => $costUsd,
                ],
            ]);

            $this->info(sprintf(
                '  ✓ Raport lunar generat (ID: %d) — context: %d rapoarte — $%.4f',
                $analysis->id,
                count($pastReports),
                $costUsd,
            ));

            $this->notifySuperAdmins($analysis);

        } catch (\Throwable $e) {
            $analysis->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'generated_at'  => now(),
            ]);
            $this->error('Eroare: ' . $e->getMessage());
            Log::error('bi:generate-monthly-report: failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ── Date gathering ────────────────────────────────────────────────────────

    private function gatherMetrics(string $fromStr, string $toStr): array
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

        // KPI grupat săptămânal
        $weeklyKpi = DB::table('bi_inventory_kpi_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->selectRaw("
                YEARWEEK(day, 1) as week_key,
                MIN(day) as week_start,
                MAX(day) as week_end,
                ROUND(AVG(inventory_value_closing_total), 0) as avg_stock_value,
                ROUND(SUM(inventory_value_variation_total), 0) as total_variation,
                ROUND(AVG(products_in_stock), 0) as avg_in_stock,
                ROUND(AVG(products_out_of_stock), 0) as avg_out_of_stock,
                MAX(inventory_value_closing_total) as max_stock_value,
                MIN(inventory_value_closing_total) as min_stock_value
            ")
            ->groupByRaw('YEARWEEK(day, 1)')
            ->orderBy('week_start')
            ->get();

        // Stocul la start și end
        $kpiAll = DB::table('bi_inventory_kpi_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->orderBy('day')
            ->get();
        $stockValueStart = (float) ($kpiAll->first()?->inventory_value_closing_total ?? 0);
        $stockValueEnd   = (float) ($kpiAll->last()?->inventory_value_closing_total  ?? 0);

        // Alerte grupate săptămânal — COUNT DISTINCT pentru SKU unice pe săptămână
        $weeklyAlerts = DB::table('bi_inventory_alert_candidates_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->selectRaw("
                YEARWEEK(day, 1) as week_key,
                MIN(day) as week_start,
                MAX(day) as week_end,
                risk_level,
                COUNT(DISTINCT reference_product_id) as cnt
            ")
            ->groupByRaw('YEARWEEK(day, 1), risk_level')
            ->orderBy('week_start')
            ->get()
            ->groupBy('week_key');

        // Ultima zi disponibilă pentru listele de produse
        $lastAlertDay = DB::table('bi_inventory_alert_candidates_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->max('day');

        // Totale ultima zi
        $lastP0 = $lastP1 = $lastP2 = 0;
        if ($lastAlertDay) {
            $counts = DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)
                ->selectRaw('risk_level, COUNT(*) as cnt')
                ->groupBy('risk_level')
                ->get()->keyBy('risk_level');
            $lastP0 = (int) ($counts->get('P0')?->cnt ?? 0);
            $lastP1 = (int) ($counts->get('P1')?->cnt ?? 0);
            $lastP2 = (int) ($counts->get('P2')?->cnt ?? 0);
        }

        // Top 20 P0 (ultima zi) — cu furnizor preferat
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

        // Top 15 P1 (ultima zi) — cu furnizor preferat
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

        // Top 20 P2 (ultima zi, după stock_value)
        $topP2 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', $lastAlertDay)->where('risk_level', 'P2')
            ->orderByDesc('stock_value')
            ->limit(20)->get() : collect();

        // Distribuție flags pe întreaga perioadă
        $flagCounts = [];
        DB::table('bi_inventory_alert_candidates_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->pluck('reason_flags')
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
            ->select(
                'v.reference_product_id',
                'p.name as product_name',
                'v.avg_out_qty_7d',
                'v.avg_out_qty_30d',
                'v.avg_out_qty_90d',
                'v.out_qty_30d',
                'v.out_qty_90d',
            )
            ->where('v.avg_out_qty_30d', '>', 0)
            ->orderByDesc('v.avg_out_qty_30d')
            ->limit(20)
            ->get();

        $topSlowMovers = DB::table('bi_product_velocity_current as v')
            ->leftJoin('woo_products as p', 'p.sku', '=', 'v.reference_product_id')
            ->whereRaw("COALESCE(p.product_type, 'shop') = 'shop'")
            ->select(
                'v.reference_product_id',
                'p.name as product_name',
                'v.days_since_last_movement',
                'v.last_movement_day',
                'v.out_qty_90d',
            )
            ->where(function ($q) {
                $q->where('v.days_since_last_movement', '>=', 30)
                  ->orWhereNull('v.days_since_last_movement');
            })
            ->orderByRaw('COALESCE(v.days_since_last_movement, 9999) DESC')
            ->limit(15)
            ->get();

        // ── Executive card — metrici suplimentare ─────────────────────────────
        // Total produse shop active (cu SKU)
        $totalShopProducts = \App\Models\WooProduct::where('product_type', \App\Models\WooProduct::TYPE_SHOP)
            ->whereNotNull('sku')->where('sku', '!=', '')->count();

        // SKU distincte cu no_consumption_30d la final de perioadă
        $noConsumption30dEnd = $lastAlertDay
            ? DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)
                ->whereRaw("JSON_CONTAINS(reason_flags, '\"no_consumption_30d\"')")
                ->count()
            : 0;

        // Valoarea totală P2 la final de perioadă (din toate P2, nu doar top 20)
        $p2ValueEnd = $lastAlertDay
            ? (float) DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)
                ->where('risk_level', 'P2')
                ->sum('stock_value')
            : 0.0;

        // Top 10 P2 după stock_value — concentrare (pluck().sum() — sum() ignoră limit()!)
        $top10P2Value = $lastAlertDay
            ? (float) DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)
                ->where('risk_level', 'P2')
                ->orderByDesc('stock_value')
                ->limit(10)
                ->pluck('stock_value')
                ->sum()
            : 0.0;

        // P0 breakdown pe reason_flags (ultima zi)
        $p0AllFlags = $lastAlertDay
            ? DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)
                ->where('risk_level', 'P0')
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

        // ── Modificări de preț pe perioadă ───────────────────────────────────
        // Preț la start vs end perioadă — produse cu variație > 3%
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
            'weeklyKpi', 'weeklyAlerts', 'lastAlertDay',
            'lastP0', 'lastP1', 'lastP2',
            'topP0', 'topP1', 'topP2',
            'flagCounts', 'stockValueStart', 'stockValueEnd',
            'velocityDay', 'topFastMovers', 'topSlowMovers',
            'totalShopProducts', 'noConsumption30dEnd', 'p2ValueEnd', 'top10P2Value', 'p0Breakdown',
            'priceChanges',
            // Data maturity
            'daysOfHistory', 'firstDayWithData', 'lastDayWithData',
            'coverageRatio30d', 'coverageRatio90d', 'dataMaturityStatus',
        );
    }

    private function fetchPastReports(string $fromStr, string $toStr, int $excludeId): array
    {
        // Raportul lunar primește ca și context rapoartele SĂPTĂMÂNALE automate din perioadă —
        // exact 1 per săptămână, nu analizele manuale care pot fi zeci și adaugă zgomot.
        return BiAnalysis::where('status', 'done')
            ->where('type', 'weekly')
            ->where('id', '!=', $excludeId)
            ->whereBetween('generated_at', [
                Carbon::parse($fromStr)->startOfDay(),
                Carbon::parse($toStr)->endOfDay(),
            ])
            ->oldest('generated_at')   // cronologic: săpt 1 → săpt 2 → săpt 3 → săpt 4
            ->get()
            ->map(fn ($a) => [
                'title'        => $a->title,
                'generated_at' => $a->generated_at->format('d.m.Y H:i'),
                'content'      => Str::limit($a->content, 3000),
            ])
            ->toArray();
    }

    // ── Prompt builder ────────────────────────────────────────────────────────

    private function buildPrompt(array $m, array $pastReports, string $fromStr, string $toStr): string
    {
        $today     = Carbon::today()->format('d.m.Y');
        $periodStr = Carbon::parse($fromStr)->format('d.m.Y') . ' – ' . Carbon::parse($toStr)->format('d.m.Y');

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
            ? "⚠ BOOTSTRAP: P2 = «fără mișcare în {$daysOfHistory} zile», NU «dead stock» confirmat. Nu recomanda lichidare. Interpretare: «candidat potențial — necesită minim 30 zile date»."
            : ($isPartial
                ? "ℹ PARTIAL_HISTORY: P2 = «fără mișcare 30d» — preliminar, confirmare la 90 zile."
                : "MATURE: P2 = «dead stock» confirmat (≥90 zile date). Recomandări de lichidare aplicabile.");

        $velocityNote = $isBootstrap
            ? "⚠ BOOTSTRAP: avg_30d diluat {$dilution30d}% (baza reală: {$daysOfHistory} zile). Folosește EXCLUSIV avg_7d pentru semnal rapid și ranking relativ. Spike detection: dezactivat."
            : ($isPartial
                ? "ℹ PARTIAL_HISTORY: avg_30d valid. avg_90d diluat {$dilution90d}% — nu folosi pentru spike detection."
                : "MATURE: avg_30d și avg_90d valide. Spike detection activat (avg_7d/avg_90d > 3×).");

        $avg30dStatus          = $isBootstrap ? "LOW confidence (diluție {$dilution30d}%)" : 'MEDIUM/HIGH confidence';
        $avg90dStatus          = ($daysOfHistory < 90) ? "LOW confidence (diluție {$dilution90d}%)" : 'HIGH confidence';
        $p2ContextLabel        = $isBootstrap ? '«candidat potențial de dead stock»' : '«dead stock confirmat»';
        $p2LiquidationNote     = $isBootstrap
            ? 'Nu recomanda lichidare — insuficiente date. Raportează: «candidat potențial — de verificat la 30 zile».'
            : ($isPartial ? 'Lichidare: preliminar aplicabilă, confirmare la 90 zile.' : 'Lichidare aplicabilă pentru confirmed dead stock.');
        $velocityBootstrapLine = $isBootstrap
            ? 'BOOTSTRAP: nu calcula comenzi pe avg_30d. Folosește avg_7d pentru semnal rapid și ranking relativ.'
            : 'avg_30d justifică stocul? Zile rămase estimate (qty / avg_30d)?';
        $bootstrapDaysLeftNote = $isBootstrap
            ? "\n  [BOOTSTRAP: days_left_cal=qty÷(out30d÷30) | days_left_obs=qty÷(out30d÷{$daysOfHistory}) — raportează ambele]"
            : '';

        $firstDay = $m['firstDayWithData'];
        $lastDay  = $m['lastDayWithData'];
        $cov30    = $m['coverageRatio30d'];
        $cov90    = $m['coverageRatio90d'];

        $maturityBlock = <<<MATURITY
## STATUS MATURITATE DATE

- Status: **{$maturityStatusLabel}**
- Interval date: {$firstDay} → {$lastDay} ({$daysOfHistory} zile calendaristice)
- Acoperire fereastră 30 zile: {$cov30}% | Acoperire fereastră 90 zile: {$cov90}%

**Clasificare confidence metrici:**
- ✅ HIGH: qty stoc, out_of_stock (stoc 0), valoare stoc, variații zilnice preț
- ⚠ MEDIUM: avg_7d (HIGH confidence), P1 bazat pe avg_7d
- ❌ LOW (date insuficiente): avg_30d ({$avg30dStatus}), avg_90d ({$avg90dStatus}), days_left estimate, no_consumption_30d, P2 dead stock

{$p2ContextNote}
MATURITY;

        // KPI săptămânal
        $kpiWeekLines = $m['weeklyKpi']->map(fn ($w) => sprintf(
            '  Săpt. %s → %s: stoc mediu %.0f RON (min %.0f / max %.0f) | variație totală %+.0f RON | avg out-of-stock: %.0f produse',
            $w->week_start, $w->week_end,
            (float) $w->avg_stock_value,
            (float) $w->min_stock_value,
            (float) $w->max_stock_value,
            (float) $w->total_variation,
            (float) $w->avg_out_of_stock,
        ))->implode("\n");

        // Alerte săptămânal
        $alertWeekLines = $m['weeklyAlerts']->map(function ($rows, $weekKey) {
            $first   = $rows->first();
            $byLevel = $rows->keyBy('risk_level');
            return sprintf('  %s → %s: P0=%d P1=%d P2=%d',
                $first->week_start, $first->week_end,
                (int) ($byLevel->get('P0')?->cnt ?? 0),
                (int) ($byLevel->get('P1')?->cnt ?? 0),
                (int) ($byLevel->get('P2')?->cnt ?? 0),
            );
        })->implode("\n");

        // Stoc start/end
        $stockDelta     = $m['stockValueEnd'] - $m['stockValueStart'];
        $stockDeltaSign = $stockDelta >= 0 ? '+' : '';
        $stockDeltaFmt  = $stockDeltaSign . number_format(abs($stockDelta), 0, ',', '.');
        $stockStartFmt  = number_format($m['stockValueStart'], 0, ',', '.');
        $stockEndFmt    = number_format($m['stockValueEnd'], 0, ',', '.');
        $stockDeltaPct  = $m['stockValueStart'] > 0
            ? $stockDeltaSign . round(abs($stockDelta) / $m['stockValueStart'] * 100, 1)
            : '0';

        // Top P0 — cu două valori days_left în bootstrap
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
                $i + 1,
                $r->reference_product_id,
                mb_substr($r->product_name ?? 'N/A', 0, 40),
                (float) $r->closing_qty,
                (float) $r->stock_value,
                $daysLeftStr,
                implode(', ', json_decode($r->reason_flags ?? '[]', true) ?? []),
                $r->supplier_name ?? 'N/A',
                $r->supplier_lead_days ?? '?',
            );
        })->implode("\n");

        // Top P1 — cu două valori days_left în bootstrap
        $p1Lines = $m['topP1']->map(function ($r, $i) use ($isBootstrap, $daysOfHistory) {
            $daysLeftCal = $r->days_left_estimate !== null ? number_format((float) $r->days_left_estimate, 1, '.', '') : '—';
            if ($isBootstrap && $r->days_left_estimate !== null) {
                $daysLeftObs = number_format((float) $r->days_left_estimate * $daysOfHistory / 30, 1, '.', '');
                $daysLeftStr = "{$daysLeftCal}d (cal) / {$daysLeftObs}d (obs)";
            } else {
                $daysLeftStr = $daysLeftCal;
            }
            return sprintf(
                '  %d. [%s] %s | zile rămase: %s | stoc: %.0f RON | consum/zi: %.2f | furnizor: %s (lead: %s zile)',
                $i + 1,
                $r->reference_product_id,
                mb_substr($r->product_name ?? 'N/A', 0, 40),
                $daysLeftStr,
                (float) $r->stock_value,
                (float) $r->avg_out_30d,
                $r->supplier_name ?? 'N/A',
                $r->supplier_lead_days ?? '?',
            );
        })->implode("\n");

        // Top P2
        $p2Lines = $m['topP2']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | capital blocat: %.0f RON | qty: %.0f buc',
            $i + 1,
            $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->stock_value,
            (float) $r->closing_qty,
        ))->implode("\n");

        // Velocity — fast movers
        $fastLines = $m['topFastMovers']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | avg/zi 7d: %.3f | avg/zi 30d: %.3f | avg/zi 90d: %.3f | ieșit 30d: %.0f | ieșit 90d: %.0f buc',
            $i + 1,
            $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->avg_out_qty_7d,
            (float) $r->avg_out_qty_30d,
            (float) $r->avg_out_qty_90d,
            (float) $r->out_qty_30d,
            (float) $r->out_qty_90d,
        ))->implode("\n");

        // Velocity — slow movers
        $slowLines = $m['topSlowMovers']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | fără mișcare: %s zile | ultima mișcare: %s | ieșit 90d: %.0f buc',
            $i + 1,
            $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            $r->days_since_last_movement !== null ? $r->days_since_last_movement : 'niciodată',
            $r->last_movement_day ?? 'N/A',
            (float) $r->out_qty_90d,
        ))->implode("\n");

        $velocityDay = $m['velocityDay'] ?? 'N/A';

        // Modificări prețuri
        $priceLines = $m['priceChanges']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | preț start: %.2f RON → end: %.2f RON | variație: %+.1f%%',
            $i + 1,
            $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->price_start,
            (float) $r->price_end,
            (float) $r->price_change_pct,
        ))->implode("\n");
        $priceUpCount   = $m['priceChanges']->where('price_change_pct', '>', 0)->count();
        $priceDownCount = $m['priceChanges']->where('price_change_pct', '<', 0)->count();
        $priceSpikeRZ   = $m['flagCounts']['price_spike'] ?? 0;

        // Context rapoarte anterioare din perioadă
        $pastContext = '';
        if (! empty($pastReports)) {
            $pastContext = "\n---\n\n## CONTEXT: RAPOARTE BI DIN PERIOADA ANALIZATĂ (" . count($pastReports) . " rapoarte)\n\n";
            $pastContext .= "Folosește-le ca referință pentru evoluție și pattern-uri. Nu repeta ceea ce e deja cunoscut — sintetizează și evidențiază schimbările la nivel lunar.\n\n";
            foreach ($pastReports as $r) {
                $pastContext .= "### {$r['title']} ({$r['generated_at']})\n{$r['content']}\n\n";
            }
        }

        // Executive card — calcule formatate
        $noConsumptionPct = $m['totalShopProducts'] > 0
            ? round($m['noConsumption30dEnd'] / $m['totalShopProducts'] * 100, 1) : 0;
        $p2WeightPct      = $m['stockValueEnd'] > 0
            ? round($m['p2ValueEnd'] / $m['stockValueEnd'] * 100, 1) : 0;
        $top10ConcentrPct = $m['p2ValueEnd'] > 0
            ? round($m['top10P2Value'] / $m['p2ValueEnd'] * 100, 1) : 0;
        $p2ValueEndFmt    = number_format($m['p2ValueEnd'], 0, ',', '.');
        $top10P2ValueFmt  = number_format($m['top10P2Value'], 0, ',', '.');

        // P0 breakdown formatat
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
        $nextMonth    = $this->nextMonthRo();

        return <<<PROMPT
Ești un analist BI senior pentru Malinco — distribuitor de materiale de construcții și bricolaj, România (Bihor).
Data de azi: {$today}. Perioadă analizată: {$periodStr}.

## REGULI OBLIGATORII (respectă-le fără excepție)

**R0 — Statutul maturității datelor conduce interpretarea.**
Raportul include obligatoriu blocul «STATUS MATURITATE DATE» (secțiunea 1). Toți indicatorii marcați LOW confidence se raportează cu avertisment explicit. Nu trage concluzii strategice din date BOOTSTRAP.

**R1 — Scop monthly = strategic.**
Fără liste kilometrice. Agregări + concluzii. Max 20 Critice (P0), max 15 Moderate (P1), max 20 Capital Blocat (P2). Prioritate: pattern lunar, tendințe, capital blocat, risc sistemic.

**R2 — "SKU" = produse distincte (unique). Niciodată count de rânduri fără etichetă.**
Dacă un count depășește total_produse_active ({$totalShop}), reformulează automat ca "rânduri-zi (SKU×zi)".

**R3 — no_consumption_30d și dead_stock: raportează end-state în rezumat.**
În rezumat: SKU distincte la final de perioadă ({$lastAlertDay}). Dacă vrei volum în perioadă, notează: "În interval: X rânduri-zi (SKU×zi)".

**R4 — P0 obligatoriu descompus pe reason_flags (breakdownul calculat mai jos).**
Citează exact numerele furnizate. Nu spune "toți P0 sunt stoc 0" fără să confirmi prin breakdown.

**R5 — Division-by-zero / dead stock.**
avg_out_30d = 0 → days_left_estimate = NULL → P2 "no_consumption_30d". Nu calcula din ferestre mai scurte.

**R6 — Velocity: avg_30d = baza comenzilor. avg_7d = semnal de alertă.**
Ratio avg_7d/avg_90d > 3× → posibil spike punctual. Recomandă verificare, nu comandă.

**R7 — Cauze = ipoteze, nu certitudini.**
Nu afirma "B2B", "vânzări fizice", "corecții" fără câmp explicit. Scrie "(ipoteză)" + alternativă.

**R8 — Nomenclatură obligatorie:**
- «Critice (P0)» | «Moderate (P1)» | «Capital Blocat — Dead Stock (P2)»

**R9 — Produse excluse din retail.**
Date EXCLUSIV retail (shop). Producție internă și garanții palet — excluse. Nu le menționezi.

---

## CONTEXT BUSINESS

Malinco — distribuitor materiale construcții & bricolaj, România (Bihor).
Canal principal (ipoteză): magazin fizic + B2B — alternativă: transferuri / ajustări inventar.
Date: exclusiv tabelele BI pre-calculate (grupate săptămânal) + velocity curent. Retail only.
{$pastContext}
---

{$maturityBlock}

---

## CARD EXECUTIV — INDICATORI CHEIE ({$lastAlertDay})
Copiază-l EXACT la începutul raportului (imediat sub titlu), formatat ca bloc citat Markdown:

> **INDICATORI CHEIE (Monthly — {$periodStr})**
> - Produse retail active cu SKU: {$totalShop}
> - SKU fără consum 30 zile (end state {$lastAlertDay}): {$noConsum} / {$totalShop} = {$noConsumptionPct}%
> - Critice (P0) / Moderate (P1) la final: {$lastP0} / {$lastP1}  |  Capital Blocat (P2): {$lastP2} SKU
> - Valoare Capital Blocat (P2, toate SKU la final): {$p2ValueEndFmt} RON  |  Pondere din stoc: {$p2WeightPct}%
> - Concentrare P2: Top 10 SKU = {$top10P2ValueFmt} RON / {$p2ValueEndFmt} RON = {$top10ConcentrPct}%

---

## KPI LUNAR — GRUPATE SĂPTĂMÂNAL

{$kpiWeekLines}

**Rezumat:** stoc {$stockStartFmt} RON → {$stockEndFmt} RON ({$stockDeltaFmt} RON, {$stockDeltaPct}%)

---

## EVOLUȚIE ALERTE — SKU DISTINCTE PE SĂPTĂMÂNĂ
Notă: valorile reprezintă SKU distincte cu risc activ în cel puțin o zi din săptămână.
{$alertWeekLines}

**Stare finală ({$lastAlertDay}):** Critice (P0)={$lastP0} SKU | Moderate (P1)={$lastP1} SKU | Capital Blocat (P2)={$lastP2} SKU

---

## BREAKDOWN CRITICE (P0) PE REASON_FLAGS — stare {$lastAlertDay} (SKU distincte, multi-flag posibil)

{$p0BrkStr}

---

## TOP 20 CRITICE (P0) — stare {$lastAlertDay}
Format: rang. [SKU] denumire | qty | RON | zile rămase | flags | furnizor
{$p0Lines}

---

## TOP 15 MODERATE (P1) — stare {$lastAlertDay}
Format: rang. [SKU] denumire | zile rămase | RON stoc | consum/zi (30d) | furnizor
{$p1Lines}

---

## TOP 20 CAPITAL BLOCAT — DEAD STOCK (P2) — stare {$lastAlertDay}
Interpretare: {$p2ContextNote}
Valoare totală (toate P2): {$p2ValueEndFmt} RON | Top 10 concentrare: {$top10P2ValueFmt} RON ({$top10ConcentrPct}%)
Format: rang. [SKU] denumire | capital blocat RON | qty
{$p2Lines}

---

## MODIFICĂRI PREȚURI — perioadă {$periodStr} (variație ≥3% față de start)
Statistici: {$priceUpCount} produse cu creștere preț | {$priceDownCount} produse cu scădere preț | {$priceSpikeRZ} rânduri-zi cu price_spike (intra-zi ≥ prag)
{$priceLines}

---

## VELOCITY — TOP 20 FAST MOVERS (calculat pentru: {$velocityDay})
{$velocityNote}
{$fastLines}

---

## VELOCITY — TOP 15 SLOW MOVERS (≥30 zile fără mișcare)
{$slowLines}

---

## CERINȚE RAPORT

Scrie în ROMÂNĂ, Markdown. **Maxim 2500 cuvinte.** Strategic, fără liste kilometrice. Respectă R0–R9.

### Structură obligatorie (8 secțiuni):

# Raport Lunar BI — {$periodStr}

## 1. Status Maturitate Date (1–2 fraze)
Copiază exact: «{$maturityStatusLabel} | interval: {$firstDay} → {$lastDay} | acoperire 30d: {$cov30}% | 90d: {$cov90}%»
Urmează cu o frază despre implicațiile acestui status pentru interpretarea raportului lunar.

> [Card executiv — copiat exact din secțiunea de mai sus]

## 2. KPI Snapshot & Evoluție Lunară
- Valoare stoc: start → final, variație netă și procentuală
- Trend săptămânal: ce s-a schimbat, ce e constant (fără cauze inventate — R7)
- SKU out-of-stock: trend pe săptămâni

## 3. Riscuri Operaționale Imediate — P0 qty=0 [HIGH CONFIDENCE]
- EXCLUSIV produse out_of_stock (qty=0) — date exacte, certitudine maximă
- Breakdown P0 obligatoriu (R4): out_of_stock / critical_stock / price_spike
- Există SKU cu P0 recurent pe mai multe săptămâni? Pattern sistemic?

## 4. Risc pe Termen Mediu — P0 critical_stock + P1 [{$avg30dStatus}]
- P0 critical_stock: days_left ≤ 7 (avg_30d — {$avg30dStatus}){$bootstrapDaysLeftNote}
- Moderate (P1): care riscă să devină Critice (P0) luna viitoare?
- Dacă disponibile ambele valori days_left (cal/obs), raportează-le pe ambele

## 5. Capital Alocat — {$p2ContextLabel} [LOW confidence]
- Valoare totală {$p2ValueEndFmt} RON ({$p2WeightPct}% din stoc) — confirmat din card
- Top 10 concentrare: {$top10ConcentrPct}% din valoarea P2 totală
- {$p2LiquidationNote}
- Categorii/tipuri de produse cu capital blocat sistematic

## 6. Velocity & Dinamica Stocului [{$velocityNote}]
- {$velocityBootstrapLine}
- Accelerări (avg_7d > 3× avg_90d): posibil spike — recomandă verificare (R6)
- Slow movers ≥30 zile: overlap cu P2?

## 7. Dinamica Prețurilor pe {$periodStr}
- Creșteri semnificative: impact pe marjă și stocul existent?
- Scăderi de preț: devalorizare stoc existent?
- price_spike intra-zi ({$priceSpikeRZ} rânduri-zi): sistemic sau izolat? (R7)

## 8. Recomandări Strategice pentru luna {$nextMonth}
- 5–8 acțiuni prioritizate cu referință la date (SKU, RON, count)
- Perspective sezoniere pentru luna {$nextMonth}
PROMPT;
    }

    private function nextMonthRo(): string
    {
        $months = ['', 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie',
                   'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
        return $months[(int) Carbon::now()->addMonth()->month];
    }

    private function notifySuperAdmins(BiAnalysis $analysis): void
    {
        $superAdmins = User::where('is_super_admin', true)->get();

        if ($superAdmins->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('Raport Lunar BI generat')
            ->body("Perioada: {$analysis->title}")
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
