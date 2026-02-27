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

class GenerateWeeklyBiReportCommand extends Command
{
    protected $signature = 'bi:generate-weekly-report
                            {--from= : Data de start YYYY-MM-DD (implicit: acum 7 zile)}
                            {--to=   : Data de end YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Generează automat raportul BI săptămânal (P0/P1/P2, din tabelele BI)';

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
            $metrics = $this->gatherMetrics($fromStr, $toStr);

            // ── Prompt ───────────────────────────────────────────────────────
            $prompt = $this->buildPrompt($metrics, $fromStr, $toStr);

            // ── Apel Claude ───────────────────────────────────────────────────
            $this->line('  → Trimit datele la Claude...');
            $claude  = new AnthropicClient(apiKey: $apiKey);
            $message = $claude->messages->create(
                maxTokens: 3000,
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
                    'type'              => 'weekly',
                    'from'              => $fromStr,
                    'to'                => $toStr,
                    'kpi_days'          => $kpiCount,
                    'total_p0'          => $metrics['totalP0'],
                    'total_p1'          => $metrics['totalP1'],
                    'total_p2'          => $metrics['totalP2'],
                    'stock_value_start' => $metrics['stockValueStart'],
                    'stock_value_end'   => $metrics['stockValueEnd'],
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

        // Top 20 P0 (ultima zi)
        $topP0 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', $lastAlertDay)
            ->where('risk_level', 'P0')
            ->orderByRaw('COALESCE(days_left_estimate, 9999) ASC, stock_value DESC')
            ->limit(20)
            ->get() : collect();

        // Top 20 P1 (ultima zi)
        $topP1 = $lastAlertDay ? DB::table('bi_inventory_alert_candidates_daily')
            ->where('day', $lastAlertDay)
            ->where('risk_level', 'P1')
            ->orderByRaw('COALESCE(days_left_estimate, 9999) ASC')
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

        return compact(
            'kpiRows', 'alertByDay', 'lastAlertDay',
            'totalP0', 'totalP1', 'totalP2',
            'topP0', 'topP1', 'topP2',
            'flagCounts', 'stockValueStart', 'stockValueEnd',
        );
    }

    // ── Prompt builder ────────────────────────────────────────────────────────

    private function buildPrompt(array $m, string $fromStr, string $toStr): string
    {
        $today     = Carbon::today()->format('d.m.Y');
        $periodStr = Carbon::parse($fromStr)->format('d.m.Y') . ' – ' . Carbon::parse($toStr)->format('d.m.Y');

        // KPI trend lines
        $kpiLines = $m['kpiRows']->map(fn ($r) => sprintf(
            '  %s: stoc %.0f RON (Δ %.0f RON) | în stoc: %d | fără stoc: %d',
            $r->day,
            (float) $r->inventory_value_closing_total,
            (float) $r->inventory_value_variation_total,
            (int) $r->products_in_stock,
            (int) $r->products_out_of_stock,
        ))->implode("\n");

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
            "  {$flag}: {$cnt} apariții"
        )->implode("\n");

        // Top P0
        $p0Lines = $m['topP0']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | stoc: %.0f buc | %.0f RON | zile rămase: %s | flags: %s',
            $i + 1,
            $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            (float) $r->closing_qty,
            (float) $r->stock_value,
            $r->days_left_estimate !== null ? number_format((float) $r->days_left_estimate, 1) : '∞',
            implode(', ', json_decode($r->reason_flags ?? '[]', true) ?? []),
        ))->implode("\n");

        // Top P1
        $p1Lines = $m['topP1']->map(fn ($r, $i) => sprintf(
            '  %d. [%s] %s | zile rămase: %s | stoc: %.0f RON',
            $i + 1,
            $r->reference_product_id,
            mb_substr($r->product_name ?? 'N/A', 0, 45),
            $r->days_left_estimate !== null ? number_format((float) $r->days_left_estimate, 1) : '—',
            (float) $r->stock_value,
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

        $stockDelta = $m['stockValueEnd'] - $m['stockValueStart'];
        $stockDeltaSign = $stockDelta >= 0 ? '+' : '';

        return <<<PROMPT
Ești un analist BI expert pentru Malinco — distribuitor de materiale de construcții și bricolaj din România (Bihor).
Data de azi: {$today}. Perioadă analizată: {$periodStr}.

## CONTEXT BUSINESS (citește cu atenție)

1. **Canalul principal de vânzări este magazinul FIZIC**, nu cel online. Mișcările de stoc reflectă vânzările fizice + B2B.
2. **Scop prioritar al raportului:** prevenire rupturi de stoc (P0/P1) + reducere capital blocat (P2).
3. **Date folosite:** exclusiv din tabelele BI pre-calculate (bi_inventory_kpi_daily + bi_inventory_alert_candidates_daily).

---

## DATE KPI ZILNIC

Evoluție valoare stoc și produse in/out-of-stock:
{$kpiLines}

Rezumat perioadă: stoc {$stockDeltaSign}%.0f RON față de start ({start} → {$m['stockValueEnd']} RON)

---

## EVOLUȚIE ALERTE PE ZI

{$alertTrendLines}

**Starea finală ({$m['lastAlertDay']}):** P0={$m['totalP0']} | P1={$m['totalP1']} | P2={$m['totalP2']}

---

## DISTRIBUȚIE REASON FLAGS (perioadă)

{$flagLines}

---

## TOP 20 PRODUSE P0 — CRITICE (stoc 0 sau < 7 zile rămase)

{$p0Lines}

---

## TOP 20 PRODUSE P1 — MODERATE (7–14 zile rămase)

{$p1Lines}

---

## TOP 20 PRODUSE P2 — DEAD STOCK (capital blocat, fără consum 30 zile)

{$p2Lines}

---

## CERINȚE RAPORT

Scrie raportul în ROMÂNĂ, Markdown. **Maxim 1800 cuvinte.** Fii direct, pragmatic, orientat pe acțiuni. Evită repetițiile.

### Structură obligatorie:

# Raport Săptămânal BI — {$periodStr}

## 1. Rezumat executiv (5–8 fraze)
- Ce s-a schimbat față de săptămâna anterioară (pe baza trendului din date)
- Tendință stoc (creștere/scădere/stagnare și ce înseamnă asta)
- Nivel risc general

## 2. KPI & Trend
- Valoare stoc: start vs sfârșit, variație
- Evoluție produse out-of-stock (cresc? scad?)
- Evoluție alerte P0/P1/P2 în cursul săptămânii

## 3. Alerte Critice P0 — Acțiuni imediate
- Grupează pe tip: stoc epuizat / epuizare iminentă / price spike
- Pentru fiecare produs din top: SKU + zile rămase + acțiune concretă sugerată
- Max 20 produse listate

## 4. Monitorizare P1 — Atenție în săptămâna următoare
- Produse cu 7–14 zile rămase — care sunt cele mai urgente?

## 5. Capital Blocat P2 — Top valoare
- Top produse cu capital imobilizat
- Sugestii acționabile: promoție, reducere preț, lichidare, returnare furnizor

## 6. Recomandări pentru săptămâna următoare
- 3–7 acțiuni concrete și acționabile imediat, prioritizate
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
                Action::make('view')
                    ->label('Vezi raportul')
                    ->url('/bi-analysis-page')
                    ->button(),
            ])
            ->sendToDatabase($superAdmins);

        $this->line('  → Notificări trimise la ' . $superAdmins->count() . ' superadmin(i).');
    }
}
