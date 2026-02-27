<?php

namespace App\Console\Commands\BI;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ComputeBiAlertsCommand extends Command
{
    protected $signature = 'bi:compute-alerts
                            {--day= : Data în format YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Calculează bi_inventory_alert_candidates_daily (P0/P1/P2) pentru o zi';

    public function handle(): int
    {
        $day = $this->resolveDay();
        $this->line("Alerts → <info>{$day}</info>");

        // Sanity check sursă
        $sourceCount = DB::table('daily_stock_metrics')->where('day', $day)->count();
        if ($sourceCount === 0) {
            $this->warn("  Nicio dată în daily_stock_metrics pentru {$day}. Skip.");
            return self::SUCCESS;
        }

        // Avertisment dacă velocity nu e calculat pentru aceeași zi (>90% din produse)
        $velocityTotal    = DB::table('bi_product_velocity_current')->count();
        $velocityForDay   = DB::table('bi_product_velocity_current')->where('calculated_for_day', $day)->count();
        if ($velocityTotal > 0 && $velocityForDay < $velocityTotal * 0.9) {
            $this->warn("  Atenție: velocity parțial pentru {$day} ({$velocityForDay}/{$velocityTotal}). Rulează bi:compute-velocity --day={$day} mai întâi.");
        }

        // Configurare praguri (din config/bi.php sau .env)
        $threshold = (int)   config('bi.dead_stock_value_threshold', 300);
        $spikePct  = (float) config('bi.alert_price_spike_pct',      20.0);
        $p0Days    = (int)   config('bi.alert_p0_days_left',         7);
        $p1Days    = (int)   config('bi.alert_p1_days_left',         14);

        // Încarcă toate produsele zilei + velocity + denumire Woo
        // LEFT JOIN velocity (poate lipsi dacă e prima rulare)
        // LEFT JOIN woo_products pe sku = reference_product_id (denumire opțională)
        $products = DB::table('daily_stock_metrics as dsm')
            ->where('dsm.day', $day)
            ->leftJoin(
                'bi_product_velocity_current as v',
                'v.reference_product_id', '=', 'dsm.reference_product_id'
            )
            ->leftJoin('woo_products as wp', 'wp.sku', '=', 'dsm.reference_product_id')
            ->select([
                'dsm.reference_product_id',
                'dsm.closing_available_qty',
                'dsm.closing_sell_price',
                'dsm.opening_sell_price',
                DB::raw('COALESCE(v.avg_out_qty_30d, 0) AS avg_out_30d'),
                'wp.name AS product_name',
            ])
            ->get();

        $this->line("  {$products->count()} produse analizate pentru alerting...");

        $upsertRows = [];
        $now        = now();

        foreach ($products as $p) {
            $closingQty   = (float) ($p->closing_available_qty ?? 0);
            $closingPrice = (float) ($p->closing_sell_price ?? 0);
            $openingPrice = (float) ($p->opening_sell_price ?? 0);
            $avgOut30d    = (float) $p->avg_out_30d;
            $stockValue   = round($closingQty * $closingPrice, 2);

            // days_left_estimate: NULL dacă avg_out_30d = 0 (division by zero safe)
            $daysLeft = $avgOut30d > 0 ? round($closingQty / $avgOut30d, 1) : null;

            // Variație preț opening → closing în aceeași zi (%)
            $priceChangePct = $openingPrice > 0
                ? round(($closingPrice - $openingPrice) / $openingPrice * 100, 2)
                : 0.0;

            // ── Reason flags ──────────────────────────────────────────────────
            $flags = [];

            if ($closingQty <= 0) {
                $flags[] = 'out_of_stock';
            }
            if ($daysLeft !== null && $daysLeft <= $p0Days) {
                $flags[] = 'critical_stock';
            }
            if ($daysLeft !== null && $daysLeft > $p0Days && $daysLeft <= $p1Days) {
                $flags[] = 'low_stock';
            }
            if ($priceChangePct >= $spikePct) {
                $flags[] = 'price_spike';
            }
            if ($avgOut30d == 0.0) {
                $flags[] = 'no_consumption_30d';
            }
            if ($avgOut30d == 0.0 && $closingQty > 0 && $stockValue >= $threshold) {
                $flags[] = 'dead_stock';
            }

            // ── Risk level (P0 ia prioritate) ────────────────────────────────
            $isP0 = $closingQty <= 0
                || ($daysLeft !== null && $daysLeft <= $p0Days)
                || $priceChangePct >= $spikePct;

            $isP1 = ! $isP0
                && $daysLeft !== null
                && $daysLeft > $p0Days
                && $daysLeft <= $p1Days;

            $isP2 = ! $isP0
                && ! $isP1
                && $avgOut30d == 0.0
                && $closingQty > 0
                && $stockValue >= $threshold;

            // Produse fără risc → skip
            if (! $isP0 && ! $isP1 && ! $isP2) {
                continue;
            }

            $upsertRows[] = [
                'day'                  => $day,
                'reference_product_id' => $p->reference_product_id,
                'product_name'         => $p->product_name
                    ? Str::limit(
                        html_entity_decode((string) $p->product_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        490
                    )
                    : null,
                'closing_qty'          => $closingQty,
                'closing_price'        => $closingPrice > 0 ? $closingPrice : null,
                'stock_value'          => $stockValue,
                'avg_out_30d'          => round($avgOut30d, 4),
                'days_left_estimate'   => $daysLeft,
                'risk_level'           => $isP0 ? 'P0' : ($isP1 ? 'P1' : 'P2'),
                'reason_flags'         => json_encode($flags),
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }

        // Idempotent: șterge rândurile existente pentru ziua asta, re-inserează
        // (tratează corect și cazul când pragurile s-au schimbat și rulăm din nou)
        DB::table('bi_inventory_alert_candidates_daily')->where('day', $day)->delete();

        foreach (array_chunk($upsertRows, 500) as $chunk) {
            DB::table('bi_inventory_alert_candidates_daily')->insert($chunk);
        }

        $counts = collect($upsertRows)->countBy('risk_level');
        $this->info(sprintf(
            '  ✓ P0=%d | P1=%d | P2=%d (total %d candidați)',
            $counts->get('P0', 0),
            $counts->get('P1', 0),
            $counts->get('P2', 0),
            count($upsertRows)
        ));

        return self::SUCCESS;
    }

    private function resolveDay(): string
    {
        $opt = $this->option('day');
        return $opt ? Carbon::parse($opt)->toDateString() : Carbon::yesterday()->toDateString();
    }
}
