<?php

namespace App\Console\Commands\BI;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComputeBiVelocityCommand extends Command
{
    protected $signature = 'bi:compute-velocity
                            {--day= : Data în format YYYY-MM-DD (implicit: ieri)}';

    protected $description = 'Calculează bi_product_velocity_current (1 rând/produs) la o dată dată';

    public function handle(): int
    {
        $day         = $this->resolveDay();
        $windowStart = Carbon::parse($day)->subDays(89)->toDateString(); // fereastră 90 zile

        $this->line("Velocity → <info>{$day}</info> (fereastră {$windowStart} → {$day})");

        $sourceCount = DB::table('daily_stock_metrics')
            ->whereBetween('day', [$windowStart, $day])
            ->count();

        if ($sourceCount === 0) {
            $this->warn("  Nicio dată în daily_stock_metrics pentru fereastra {$windowStart} → {$day}. Skip.");
            Log::warning('bi:compute-velocity: no source data', ['day' => $day, 'window_start' => $windowStart]);
            return self::SUCCESS;
        }

        // Query single-pass cu conditional SUM pe ferestre 7/30/90 zile.
        // Ieșiri = GREATEST(0, -daily_available_variation) (scăderile de stoc disponibil).
        // avg_out_qty_Xd = out_qty_Xd / X — chiar dacă există mai puțin de X zile de istoric,
        // se divide tot la X pentru a nu supraevalua ritmul; semnalul este că out_qty_Xd e mic.
        $rows = DB::table('daily_stock_metrics')
            ->whereBetween('day', [$windowStart, $day])
            ->selectRaw('
                reference_product_id,
                SUM(CASE WHEN `day` >= DATE_SUB(?, INTERVAL  6 DAY)
                         THEN GREATEST(0, -daily_available_variation) ELSE 0 END) AS out_qty_7d,
                SUM(CASE WHEN `day` >= DATE_SUB(?, INTERVAL 29 DAY)
                         THEN GREATEST(0, -daily_available_variation) ELSE 0 END) AS out_qty_30d,
                SUM(GREATEST(0, -daily_available_variation))                       AS out_qty_90d,
                MAX(CASE WHEN daily_available_variation <> 0 THEN `day` END)       AS last_movement_day
            ', [$day, $day])
            ->groupBy('reference_product_id')
            ->get();

        $this->line("  {$rows->count()} produse procesate...");

        $now       = now();
        $dayCarbon = Carbon::parse($day);

        $upsertRows = $rows->map(function ($row) use ($day, $now, $dayCarbon) {
            return [
                'reference_product_id'     => $row->reference_product_id,
                'calculated_for_day'       => $day,
                'out_qty_7d'               => round((float) $row->out_qty_7d, 3),
                'out_qty_30d'              => round((float) $row->out_qty_30d, 3),
                'out_qty_90d'              => round((float) $row->out_qty_90d, 3),
                'avg_out_qty_7d'           => round((float) $row->out_qty_7d  /  7, 4),
                'avg_out_qty_30d'          => round((float) $row->out_qty_30d / 30, 4),
                'avg_out_qty_90d'          => round((float) $row->out_qty_90d / 90, 4),
                'last_movement_day'        => $row->last_movement_day ?: null,
                'days_since_last_movement' => $row->last_movement_day
                    ? (int) Carbon::parse($row->last_movement_day)->diffInDays($dayCarbon)
                    : null,
                'updated_at'               => $now,
            ];
        })->all();

        $updateCols = [
            'calculated_for_day', 'out_qty_7d', 'out_qty_30d', 'out_qty_90d',
            'avg_out_qty_7d', 'avg_out_qty_30d', 'avg_out_qty_90d',
            'last_movement_day', 'days_since_last_movement', 'updated_at',
        ];

        foreach (array_chunk($upsertRows, 500) as $chunk) {
            DB::table('bi_product_velocity_current')->upsert($chunk, ['reference_product_id'], $updateCols);
        }

        $this->info("  ✓ {$rows->count()} produse actualizate în bi_product_velocity_current.");

        return self::SUCCESS;
    }

    private function resolveDay(): string
    {
        $opt = $this->option('day');
        return $opt ? Carbon::parse($opt)->toDateString() : Carbon::yesterday()->toDateString();
    }
}
