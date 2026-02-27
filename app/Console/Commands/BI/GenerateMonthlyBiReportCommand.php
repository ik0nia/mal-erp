<?php

namespace App\Console\Commands\BI;

use Anthropic\Client as AnthropicClient;
use App\Models\BiAnalysis;
use App\Models\User;
use Filament\Notifications\Actions\Action;
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

    protected $description = 'Generează automat raportul BI lunar (30 zile, grupat săptămânal + context rapoarte zilnice)';

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
            $metrics    = $this->gatherMetrics($fromStr, $toStr);
            $pastReports = $this->fetchDailyReports($fromStr, $toStr, $analysis->id);
            $prompt     = $this->buildPrompt($metrics, $pastReports, $fromStr, $toStr);

            $this->line('  → Trimit datele la Claude (' . number_format(mb_strlen($prompt)) . ' caractere prompt)...');

            $claude  = new AnthropicClient(apiKey: $apiKey);
            $message = $claude->messages->create(
                maxTokens: 4000,
                messages:  [['role' => 'user', 'content' => $prompt]],
                model:     'claude-sonnet-4-6',
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
                '  ✓ Raport lunar generat (ID: %d) — context: %d rapoarte zilnice — $%.4f',
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

        // Alerte grupate săptămânal
        $weeklyAlerts = DB::table('bi_inventory_alert_candidates_daily')
            ->whereBetween('day', [$fromStr, $toStr])
            ->selectRaw("
                YEARWEEK(day, 1) as week_key,
                MIN(day) as week_start,
                MAX(day) as week_end,
                risk_level,
                COUNT(*) as cnt
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

        // Top 20 P0 și P2 (ultima zi)
        $topP0 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', $lastAlertDay)->where('risk_level', 'P0')
            ->orderByRaw('COALESCE(days_left_estimate, 9999) ASC, stock_value DESC')
            ->limit(20)->get() : collect();

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

        return compact(
            'weeklyKpi', 'weeklyAlerts', 'lastAlertDay',
            'lastP0', 'lastP1', 'lastP2',
            'topP0', 'topP2',
            'flagCounts', 'stockValueStart', 'stockValueEnd',
        );
    }

    private function fetchDailyReports(string $fromStr, string $toStr, int $excludeId): array
    {
        return BiAnalysis::where('status', 'done')
            ->where('id', '!=', $excludeId)
            ->whereBetween('generated_at', [
                Carbon::parse($fromStr)->startOfDay(),
                Carbon::parse($toStr)->endOfDay(),
            ])
            ->latest('generated_at')
            ->get()
            ->map(fn ($a) => [
                'title'        => $a->title,
                'generated_at' => $a->generated_at->format('d.m.Y H:i'),
                'content'      => Str::limit($a->content, 1200),
            ])
            ->toArray();
    }

    // ── Prompt builder ────────────────────────────────────────────────────────

    private function buildPrompt(array $m, array $pastReports, string $fromStr, string $toStr): string
    {
        $today     = Carbon::today()->format('d.m.Y');
        $periodStr = Carbon::parse($fromStr)->format('d.m.Y') . ' – ' . Carbon::parse($toStr)->format('d.m.Y');

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
            $first = $rows->first();
            $byLevel = $rows->keyBy('risk_level');
            return sprintf('  %s → %s: P0=%d P1=%d P2=%d',
                $first->week_start, $first->week_end,
                (int) ($byLevel->get('P0')?->cnt ?? 0),
                (int) ($byLevel->get('P1')?->cnt ?? 0),
                (int) ($byLevel->get('P2')?->cnt ?? 0),
            );
        })->implode("\n");

        // Flags
        $flagLines = collect($m['flagCounts'])->map(fn ($cnt, $flag) =>
            "  {$flag}: {$cnt} apariții"
        )->implode("\n");

        // Top P0
        $p0Lines = $m['topP0']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | qty: %.0f | %.0f RON | zile: %s | %s',
            $i + 1,
            $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->closing_qty,
            (float) $r->stock_value,
            $r->days_left_estimate !== null ? number_format((float) $r->days_left_estimate, 1) : '∞',
            implode(', ', json_decode($r->reason_flags ?? '[]', true) ?? []),
        ))->implode("\n");

        // Top P2
        $p2Lines = $m['topP2']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | capital blocat: %.0f RON | qty: %.0f buc',
            $i + 1,
            $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->stock_value,
            (float) $r->closing_qty,
        ))->implode("\n");

        $stockDelta     = $m['stockValueEnd'] - $m['stockValueStart'];
        $stockDeltaSign = $stockDelta >= 0 ? '+' : '';
        $stockDeltaPct  = $m['stockValueStart'] > 0
            ? round($stockDelta / $m['stockValueStart'] * 100, 1)
            : 0;

        // Context rapoarte zilnice/săptămânale anterioare
        $pastContext = '';
        if (! empty($pastReports)) {
            $pastContext = "\n---\n\n## CONTEXT: RAPOARTE BI DIN PERIOADA ANALIZATĂ (" . count($pastReports) . " rapoarte)\n\n";
            $pastContext .= "Folosește-le ca referință pentru evoluție și pattern-uri. Nu repeta ceea ce e deja cunoscut — evidențiază schimbările.\n\n";
            foreach ($pastReports as $r) {
                $pastContext .= "### {$r['title']} ({$r['generated_at']})\n{$r['content']}\n\n";
            }
        }

        return <<<PROMPT
Ești un analist BI senior pentru Malinco — distribuitor de materiale de construcții și bricolaj, România (Bihor).
Data de azi: {$today}. Perioadă analizată: {$periodStr} (30 zile).

## CONTEXT BUSINESS

1. **Canal principal = magazin FIZIC.** Mișcările de stoc = vânzări fizice + B2B. Magazinul online e secundar.
2. **Scop raport lunar:** evaluare strategică — tendințe lunare, capital blocat, produse cu risc sistemic.
3. **Date:** exclusiv din tabelele BI pre-calculate (grupate săptămânal pentru claritate).
{$pastContext}
---

## DATE KPI LUNAR — GRUPATE SĂPTĂMÂNAL

{$kpiWeekLines}

**Rezumat perioadă:** stoc {$m['stockValueStart']} RON → {$m['stockValueEnd']} RON ({$stockDeltaSign}{$stockDelta} RON, {$stockDeltaSign}{$stockDeltaPct}%)

---

## EVOLUȚIE ALERTE — GRUPATE SĂPTĂMÂNAL

{$alertWeekLines}

**Starea finală ({$m['lastAlertDay']}):** P0={$m['lastP0']} | P1={$m['lastP1']} | P2={$m['lastP2']}

---

## DISTRIBUȚIE REASON FLAGS (30 zile)

{$flagLines}

---

## TOP 20 PRODUSE P0 — CRITICE (starea curentă: {$m['lastAlertDay']})

{$p0Lines}

---

## TOP 20 PRODUSE P2 — CAPITAL BLOCAT (după valoare)

{$p2Lines}

---

## CERINȚE RAPORT

Scrie raportul în ROMÂNĂ, Markdown. **Maxim 2500 cuvinte.** Orientat strategic — nu repeta ceea ce e deja în rapoartele zilnice din context, ci sintetizează și evidențiază pattern-urile lunare.

### Structură obligatorie:

# Raport Lunar BI — {$periodStr}

## 1. Rezumat executiv (6–10 fraze)
- Tendința lunii: stoc, risc, capital blocat
- Ce s-a îmbunătățit vs ce s-a deteriorat față de luna anterioară
- Cele mai importante 2–3 concluzii acționabile

## 2. Evoluție lunară — KPI & Trend săptămânal
- Analiza pe săptămâni: ce s-a schimbat, ce e constant
- Valoare stoc: start vs end, variație totală și ce o explică
- Produse out-of-stock: trend (cresc/scad/stagnează?)

## 3. Analiza riscurilor (P0/P1) — Pattern lunar
- Ce tipuri de alerte P0 domină luna? (stoc0 / epuizare / price spike)
- Există produse care apar P0 în mai multe săptămâni? Pattern sistemic?
- Top produse critice cu acțiuni concrete

## 4. Capital Blocat P2 — Evaluare strategică
- Valoarea totală estimată de capital imobilizat
- Categorii/tipuri de produse cu dead stock sistematic
- Recomandări: promoții, reduceri, returnare furnizor, renegociere

## 5. Recomandări strategice pentru luna următoare
- 5–8 acțiuni prioritizate, cu impact estimat
- Include perspective sezoniere (ce produse vor fi cerute în luna {$this->nextMonthRo()}?)
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
                Action::make('view')
                    ->label('Vezi raportul')
                    ->url('/bi-analysis-page')
                    ->button(),
            ])
            ->sendToDatabase($superAdmins);

        $this->line('  → Notificări trimise la ' . $superAdmins->count() . ' superadmin(i).');
    }
}
