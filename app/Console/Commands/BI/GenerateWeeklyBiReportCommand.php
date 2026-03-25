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

class GenerateWeeklyBiReportCommand extends Command
{
    protected $signature = 'bi:generate-weekly-report
                            {--from= : Data de start YYYY-MM-DD (implicit: acum 7 zile)}
                            {--to=   : Data de end YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Generează automat raportul BI săptămânal (P0/P1/P2 + velocity, din tabelele BI)';

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
            : Carbon::now()->subDays(7)->startOfDay();

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->endOfDay()
            : Carbon::yesterday()->endOfDay();

        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        $this->line("=== BI Weekly Report — <info>{$fromStr} → {$toStr}</info> ===");

        // ── Sanity check ──────────────────────────────────────────────────────
        $kpiCount = DB::table('bi_inventory_kpi_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->count();

        if ($kpiCount === 0) {
            $this->warn("Nicio dată BI pentru intervalul {$fromStr} → {$toStr}. Abort.");
            Log::warning('bi:generate-weekly-report: no KPI data', compact('fromStr', 'toStr'));
            return self::SUCCESS;
        }

        $this->line("  Date disponibile: {$kpiCount} zile KPI.");

        // ── Crează înregistrarea pending ─────────────────────────────────────
        $title    = "Raport Săptămânal BI — {$fromStr} → {$toStr}";
        $analysis = BiAnalysis::create([
            'type'         => 'weekly',
            'generated_by' => null,
            'title'        => $title,
            'content'      => '',
            'status'       => 'pending',
            'generated_at' => now(),
        ]);

        try {
            // ── Colectare date ────────────────────────────────────────────────
            $metrics     = $this->gatherMetrics($fromStr, $toStr);
            $pastReports = $this->fetchPastReports($fromStr, $toStr, $analysis->id);

            $this->line("  Context: " . count($pastReports) . " rapoarte anterioare din perioadă.");

            // ── Prompt ───────────────────────────────────────────────────────
            $prompt = $this->buildPrompt($metrics, $pastReports, $fromStr, $toStr);

            // ── Apel Claude ───────────────────────────────────────────────────
            $this->line('  → Trimit datele la Claude (' . number_format(mb_strlen($prompt)) . ' caractere)...');
            $claude  = new AnthropicClient(apiKey: $apiKey);
            $message = $claude->messages->create(
                maxTokens: 10000,
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

            AiUsageLog::record('bi_weekly', config('app.malinco.ai.models.sonnet', 'claude-sonnet-4-6'), $inputTokens, $outputTokens, [
                'analysis_id' => $analysis->id,
            ]);

            $analysis->update([
                'content'          => $content,
                'status'           => 'done',
                'generated_at'     => now(),
                'metrics_snapshot' => [
                    'type'              => 'weekly',
                    'from'              => $fromStr,
                    'to'                => $toStr,
                    'kpi_days'          => $kpiCount,
                    'total_p0'          => $metrics['totalP0'],
                    'total_p1'          => $metrics['totalP1'],
                    'total_p2'          => $metrics['totalP2'],
                    'stock_value_start' => $metrics['stockValueStart'],
                    'stock_value_end'   => $metrics['stockValueEnd'],
                    'past_reports'      => count($pastReports),
                    'tokens_input'      => $inputTokens,
                    'tokens_output'     => $outputTokens,
                    'cost_usd'          => $costUsd,
                ],
            ]);

            $this->info(sprintf(
                '  ✓ Raport generat (ID: %d) — P0=%d P1=%d P2=%d — $%.4f',
                $analysis->id,
                $metrics['totalP0'],
                $metrics['totalP1'],
                $metrics['totalP2'],
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
            Log::error('bi:generate-weekly-report: failed', ['error' => $e->getMessage()]);
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

        // KPI zilnic
        $kpiRows = DB::table('bi_inventory_kpi_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->orderBy('day')
            ->get();

        $stockValueStart = (float) ($kpiRows->first()?->inventory_value_closing_total ?? 0);
        $stockValueEnd   = (float) ($kpiRows->last()?->inventory_value_closing_total  ?? 0);

        // Alerte per zi per nivel
        $alertByDay = DB::table('bi_inventory_alert_candidates_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->selectRaw('day, risk_level, COUNT(*) as cnt')
            ->groupBy('day', 'risk_level')
            ->orderBy('day')
            ->get()
            ->groupBy('day');

        // Totale perioadă (ultima zi disponibilă — starea curentă)
        $lastAlertDay = DB::table('bi_inventory_alert_candidates_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->max('day');

        $totalP0 = $totalP1 = $totalP2 = 0;
        if ($lastAlertDay) {
            $counts = DB::table('bi_inventory_alert_candidates_daily')
                ->where('day', $lastAlertDay)
                ->selectRaw('risk_level, COUNT(*) as cnt')
                ->groupBy('risk_level')
                ->get()->keyBy('risk_level');
            $totalP0 = (int) ($counts->get('P0')?->cnt ?? 0);
            $totalP1 = (int) ($counts->get('P1')?->cnt ?? 0);
            $totalP2 = (int) ($counts->get('P2')?->cnt ?? 0);
        }

        // Top 20 P0 (ultima zi) — cu furnizor preferat
        $topP0 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily as alc')
            ->where('alc.day', $lastAlertDay)
            ->where('alc.risk_level', 'P0')
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'alc.reference_product_id')
            ->leftJoin('product_suppliers as ps', function ($join) {
                $join->on('ps.woo_product_id', '=', 'wp.id')->where('ps.is_preferred', 1);
            })
            ->leftJoin('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->select('alc.*', 's.name as supplier_name', 'ps.lead_days as supplier_lead_days')
            ->orderByRaw('COALESCE(alc.days_left_estimate, 9999) ASC, alc.stock_value DESC')
            ->limit(20)
            ->get() : collect();

        // Top 20 P1 (ultima zi) — cu furnizor preferat
        $topP1 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily as alc')
            ->where('alc.day', $lastAlertDay)
            ->where('alc.risk_level', 'P1')
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'alc.reference_product_id')
            ->leftJoin('product_suppliers as ps', function ($join) {
                $join->on('ps.woo_product_id', '=', 'wp.id')->where('ps.is_preferred', 1);
            })
            ->leftJoin('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->select('alc.*', 's.name as supplier_name', 'ps.lead_days as supplier_lead_days')
            ->orderByRaw('COALESCE(alc.days_left_estimate, 9999) ASC')
            ->limit(20)
            ->get() : collect();

        // Top 20 P2 (ultima zi, după stock_value)
        $topP2 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', $lastAlertDay)
            ->where('risk_level', 'P2')
            ->orderByDesc('stock_value')
            ->limit(20)
            ->get() : collect();

        // Distribuție reason_flags (perioadă)
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

        // ── Modificări de preț pe săptămână ──────────────────────────────────
        // Preț la start vs end săptămână — produse cu variație > 3%
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
            LIMIT 15
        ", [$fromStr, $toStr, $fromStr, $toStr]));

        return compact(
            'kpiRows', 'alertByDay', 'lastAlertDay',
            'totalP0', 'totalP1', 'totalP2',
            'topP0', 'topP1', 'topP2',
            'flagCounts', 'stockValueStart', 'stockValueEnd',
            'velocityDay', 'topFastMovers', 'topSlowMovers',
            'priceChanges',
            // Data maturity
            'daysOfHistory', 'firstDayWithData', 'lastDayWithData',
            'coverageRatio30d', 'coverageRatio90d', 'dataMaturityStatus',
        );
    }

    private function fetchPastReports(string $fromStr, string $toStr, int $excludeId): array
    {
        // Raportul săptămânal include analizele MANUALE din săptămână ca și context
        // (nu există rapoarte weekly în fereastra curentă — acesta e primul).
        // Limităm la 3 cele mai recente pentru a evita zgomot dacă s-au generat multe.
        return BiAnalysis::where('status', 'done')
            ->where('type', 'manual')
            ->where('id', '!=', $excludeId)
            ->whereBetween('generated_at', [
                Carbon::parse($fromStr)->startOfDay(),
                Carbon::parse($toStr)->endOfDay(),
            ])
            ->latest('generated_at')
            ->limit(3)
            ->get()
            ->map(fn ($a) => [
                'title'        => $a->title,
                'generated_at' => $a->generated_at->format('d.m.Y H:i'),
                'content'      => Str::limit($a->content, 2000),
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
        $velocityBootstrapLine = $isBootstrap
            ? 'BOOTSTRAP: nu calcula comenzi pe avg_30d. Folosește avg_7d pentru semnal rapid și ranking relativ.'
            : 'avg_30d justifică stocul? Risc ruptură (zile rămase = qty / avg_30d)?';
        $p2LiquidationNote     = $isBootstrap
            ? 'Nu recomanda lichidare — insuficiente date. Raportează: «candidat potențial — de verificat la 30 zile».'
            : ($isPartial ? 'Lichidare: preliminar aplicabilă, confirmare la 90 zile.' : 'Lichidare aplicabilă pentru confirmed dead stock.');
        $bootstrapDaysLeftNote = $isBootstrap
            ? "\n  [BOOTSTRAP: days_left_cal=qty÷(out30d÷30) | days_left_obs=qty÷(out30d÷{$daysOfHistory}) — raportează ambele valori]"
            : '';

        $firstDay       = $m['firstDayWithData'];
        $lastDay        = $m['lastDayWithData'];
        $cov30          = $m['coverageRatio30d'];
        $cov90          = $m['coverageRatio90d'];

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

        // KPI trend lines
        $kpiLines = $m['kpiRows']->map(fn ($r) => sprintf(
            '  %s: stoc %.0f RON (Δ%+.0f RON) | în stoc: %d | fără stoc: %d',
            $r->day,
            (float) $r->inventory_value_closing_total,
            (float) $r->inventory_value_variation_total,
            (int) $r->products_in_stock,
            (int) $r->products_out_of_stock,
        ))->implode("\n");

        // Stoc start/end formatat
        $stockDelta     = $m['stockValueEnd'] - $m['stockValueStart'];
        $stockDeltaSign = $stockDelta >= 0 ? '+' : '';
        $stockDeltaFmt  = $stockDeltaSign . number_format(abs($stockDelta), 0, ',', '.');
        $stockStartFmt  = number_format($m['stockValueStart'], 0, ',', '.');
        $stockEndFmt    = number_format($m['stockValueEnd'], 0, ',', '.');

        // Alert trend per zi
        $alertTrendLines = $m['alertByDay']->map(function ($rows, $day) {
            $byLevel = $rows->keyBy('risk_level');
            return sprintf('  %s: P0=%d P1=%d P2=%d',
                $day,
                (int) ($byLevel->get('P0')?->cnt ?? 0),
                (int) ($byLevel->get('P1')?->cnt ?? 0),
                (int) ($byLevel->get('P2')?->cnt ?? 0),
            );
        })->implode("\n");

        // Reason flags
        $flagLines = collect($m['flagCounts'])->map(fn ($cnt, $flag) =>
            "  {$flag}: {$cnt} rânduri-zi (SKU×zi)"
        )->implode("\n");

        // Top P0 — cu două valori days_left în bootstrap
        $p0Lines = $m['topP0']->map(function ($r, $i) use ($isBootstrap, $daysOfHistory) {
            $daysLeftCal = $r->days_left_estimate !== null ? number_format((float) $r->days_left_estimate, 1) : '∞';
            if ($isBootstrap && $r->days_left_estimate !== null) {
                $daysLeftObs = number_format((float) $r->days_left_estimate * $daysOfHistory / 30, 1);
                $daysLeftStr = "{$daysLeftCal}d (cal) / {$daysLeftObs}d (obs)";
            } else {
                $daysLeftStr = $daysLeftCal;
            }
            return sprintf(
                '  %d. [%s] %s | stoc: %.0f buc | %.0f RON | zile rămase: %s | flags: %s | furnizor: %s (lead: %s zile)',
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
            $daysLeftCal = $r->days_left_estimate !== null ? number_format((float) $r->days_left_estimate, 1) : '—';
            if ($isBootstrap && $r->days_left_estimate !== null) {
                $daysLeftObs = number_format((float) $r->days_left_estimate * $daysOfHistory / 30, 1);
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
            '  %d. [%s] %s | avg/zi 7d: %.3f | avg/zi 30d: %.3f | avg/zi 90d: %.3f | ieșit 30d: %.0f buc',
            $i + 1,
            $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->avg_out_qty_7d,
            (float) $r->avg_out_qty_30d,
            (float) $r->avg_out_qty_90d,
            (float) $r->out_qty_30d,
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
            '  %d. [%s] %s | %.2f RON → %.2f RON (%+.1f%%)',
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
            $pastContext = "\n---\n\n## CONTEXT: ANALIZE BI DIN ACEASTĂ PERIOADĂ (" . count($pastReports) . " rapoarte)\n\n";
            $pastContext .= "Folosește-le ca referință pentru evoluție și pattern-uri. Nu repeta ceea ce e deja cunoscut — evidențiază ce s-a schimbat.\n\n";
            foreach ($pastReports as $r) {
                $pastContext .= "### {$r['title']} ({$r['generated_at']})\n{$r['content']}\n\n";
            }
            $pastContext .= "---\n\n";
        }

        // ── Breakdown P0 pe reason_flags ────────────────────────────────────
        $p0BreakdownAll = $m['lastAlertDay'] ? DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', $m['lastAlertDay'])
            ->where('risk_level', 'P0')
            ->pluck('reason_flags') : collect();

        $p0CountOutOfStock = 0; $p0CountCritical = 0; $p0CountSpike = 0;
        foreach ($p0BreakdownAll as $json) {
            $flags = json_decode($json, true) ?? [];
            if (in_array('out_of_stock',   $flags)) $p0CountOutOfStock++;
            if (in_array('critical_stock', $flags)) $p0CountCritical++;
            if (in_array('price_spike',    $flags)) $p0CountSpike++;
        }
        $p0BreakdownStr = sprintf(
            '- out_of_stock (qty=0): %d SKU%s' . "\n" .
            '- critical_stock (days_left ≤ 7, avg_out_30d > 0): %d SKU%s' . "\n" .
            '- price_spike (variație preț ≥ prag): %d SKU%s',
            $p0CountOutOfStock,
            $p0CountOutOfStock === $m['totalP0'] ? ' ← toți P0' : '',
            $p0CountCritical,
            $p0CountCritical === $m['totalP0'] ? ' ← toți P0' : '',
            $p0CountSpike,
            $p0CountSpike === $m['totalP0'] ? ' ← toți P0' : '',
        );

        $lastAlertDay = $m['lastAlertDay'];
        $totalP0      = $m['totalP0'];
        $totalP1      = $m['totalP1'];
        $totalP2      = $m['totalP2'];

        return <<<PROMPT
Ești un analist BI operațional pentru Malinco — distribuitor de materiale de construcții și bricolaj din România (Bihor).
Data de azi: {$today}. Perioadă analizată: {$periodStr}.

## REGULI OBLIGATORII (respectă-le fără excepție)

**R0 — Statutul maturității datelor conduce interpretarea.**
Raportul include obligatoriu blocul «STATUS MATURITATE DATE» (secțiunea 1). Toți indicatorii marcați LOW confidence se raportează cu avertisment explicit. Nu trage concluzii strategice din date BOOTSTRAP.

**R1 — Scop weekly = execuțional/operațional.**
Accent pe: Critice (P0) & Moderate (P1) — rupturi și epuizare iminentă. Secțiunea Capital Blocat — Dead Stock (P2) e scurtă (max 15 produse, doar top valoare). Nu extinde P2 cu analiză strategică — asta e pentru raportul lunar.

**R2 — Stare finală vs evenimente în interval.**
- În REZUMAT: raportează P0/P1/P2 ca STARE la data {$lastAlertDay} (SKU distincte active în acea zi).
- Dacă menționezi ce s-a întâmplat în interval, precizează explicit: "SKU distincte care au apărut cel puțin o zi" sau "rânduri-zi (SKU×zi)". Nu amesteca cele două noțiuni.

**R3 — "SKU" = produse distincte (unique). Niciodată count de rânduri fără etichetă.**
Dacă un count depășește total_products_active, trebuie reformulat automat ca "rânduri-zi (SKU×zi)".

**R4 — P0 obligatoriu descompus pe reason_flags (breakdownul este deja calculat mai jos).**
Citează numerele din secțiunea "BREAKDOWN P0" — nu inventa. Un SKU poate cumula mai multe flags.

**R5 — Division-by-zero / dead stock.**
Dacă avg_out_30d = 0, days_left_estimate = NULL → produs P2 "no_consumption_30d". Nu calcula days_left din ferestre mai scurte.

**R6 — Velocity: avg_30d = baza comenzilor; avg_7d = semnal de alertă.**
Nu folosi avg_7d ca bază pentru comenzi. Ratio avg_7d/avg_90d > 3× pe mai multe produse → marchează "posibil spike/outlier".

**R7 — Limbaj: fără cauze certe.**
Nu afirma "B2B", "vânzări fizice", "corecții de sistem" ca certitudini. Dacă sugerezi o cauză, scrie "(ipoteză)" + o alternativă.

**R8 — Nomenclatură obligatorie:**
- «Critice (P0)» — stoc 0, days_left ≤ 7, sau price_spike
- «Moderate (P1)» — 7–14 zile rămase
- «Capital Blocat — Dead Stock (P2)» — fără consum 30 zile, valoare ≥ 300 RON

**R9 — Produse excluse din retail.**
Date EXCLUSIV retail (shop). Producție internă și garanții palet — excluse. Nu le menționezi.

---

## CONTEXT BUSINESS

Malinco — distribuitor materiale construcții & bricolaj, România (Bihor). Canal principal de ieșire stoc (ipoteză): magazin fizic + B2B — alternativă: transferuri interne / ajustări inventar.
Scop: prevenire rupturi (P0/P1) + reducere capital blocat (P2).
Date: exclusiv tabelele BI pre-calculate (retail only). Perioadă: {$periodStr}.
{$pastContext}
---

{$maturityBlock}

---

## KPI ZILNIC — STOC ȘI PRODUSE IN/OUT-OF-STOCK

{$kpiLines}

Rezumat: stoc {$stockStartFmt} RON → {$stockEndFmt} RON ({$stockDeltaFmt} RON în {$periodStr})

---

## EVOLUȚIE ALERTE PE ZI (SKU distincte cu risc în acea zi)

{$alertTrendLines}

**STARE FINALĂ ({$lastAlertDay}) — referința pentru rezumat:**
- Critice (P0): {$totalP0} SKU distincte
- Moderate (P1): {$totalP1} SKU distincte
- Capital Blocat — Dead Stock (P2): {$totalP2} SKU distincte

---

## BREAKDOWN P0 PE REASON_FLAGS — stare {$lastAlertDay} (SKU distincte, multi-flag posibil)

{$p0BreakdownStr}

---

## TOP 20 PRODUSE CRITICE (P0) — stare {$lastAlertDay}
Format: rang. [SKU] denumire | qty | RON | zile rămase | flags | furnizor
{$p0Lines}

---

## TOP 15 PRODUSE MODERATE (P1) — stare {$lastAlertDay}
Format: rang. [SKU] denumire | zile rămase | RON stoc | consum/zi (30d) | furnizor
{$p1Lines}

---

## TOP 15 PRODUSE CAPITAL BLOCAT — DEAD STOCK (P2) — stare {$lastAlertDay}
Interpretare: {$p2ContextNote}
Format: rang. [SKU] denumire | capital blocat RON | qty
{$p2Lines}

---

## MODIFICĂRI PREȚURI SĂPTĂMÂNĂ (variație ≥3% start→end)
{$priceUpCount} produse cu creștere preț | {$priceDownCount} produse cu scădere preț | {$priceSpikeRZ} rânduri-zi cu price_spike (intra-zi)
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

Scrie în ROMÂNĂ, Markdown. **Maxim 1800 cuvinte.** Direct, pragmatic, orientat pe acțiuni. Respectă R0–R9.

### Structură obligatorie (8 secțiuni):

# Raport Săptămânal BI — {$periodStr}

## 1. Status Maturitate Date (1–2 fraze)
Copiază exact: «{$maturityStatusLabel} | interval: {$firstDay} → {$lastDay} | acoperire 30d: {$cov30}% | 90d: {$cov90}%»
Urmează cu o frază despre implicațiile acestui status pentru interpretarea raportului.

## 2. KPI Snapshot
- Valoare stoc: start → final, variație netă
- SKU active: în stoc / fără stoc la {$lastAlertDay}
- Stare alertă: Critice (P0) {$totalP0} SKU | Moderate (P1) {$totalP1} SKU | Capital Blocat (P2) {$totalP2} SKU

## 3. Riscuri Operaționale Imediate — P0 qty=0 [HIGH CONFIDENCE]
- EXCLUSIV produse out_of_stock (qty=0) — date exacte, certitudine maximă
- Prioritizare: consum pozitiv (avg_out_30d > 0) > consum necunoscut
- Max 10 produse. Acțiune: reaprovizionare + furnizor + lead time

## 4. Risc pe Termen Scurt — P0 critical_stock + P1 [{$avg30dStatus}]
- P0 critical_stock: days_left ≤ 7 zile (bazat pe avg_30d — {$avg30dStatus}){$bootstrapDaysLeftNote}
- P1: 7–14 zile rămase — care riscă să treacă în P0?
- Dacă disponibile ambele valori days_left (cal/obs), raportează-le pe ambele

## 5. Capital Alocat — {$p2ContextLabel} [LOW confidence]
- Top 15 produse după valoare imobilizată (RON)
- {$p2LiquidationNote}

## 6. Velocity & Trend [{$velocityNote}]
- {$velocityBootstrapLine}
- Accelerări notabile (avg_7d > avg_90d): marchează ca posibil spike — recomandă verificare (R6)
- Slow movers ≥30 zile: overlap cu P2?

## 7. Dinamica Prețurilor (scurt, max 5 produse)
- Creșteri ≥3%: impact stoc existent?
- Scăderi ≥3%: risc devalorizare stoc?
- price_spike intra-zi ({$priceSpikeRZ} rânduri-zi): izolat sau sistemic? (R7)

## 8. Plan de Acțiune (max 7 acțiuni, prioritizate)
- Format: [P0/P1/P2/PREȚ] Acțiune concretă — [SKU/grup] — [impact/urgență]
- Prioritate: P0 qty=0 cu consum → P0 critical_stock → P1 → price changes → P2
PROMPT;
    }

    // ── Notificări ────────────────────────────────────────────────────────────

    private function notifySuperAdmins(BiAnalysis $analysis): void
    {
        $superAdmins = User::where('is_super_admin', true)->get();

        if ($superAdmins->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('Raport Săptămânal BI generat')
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
