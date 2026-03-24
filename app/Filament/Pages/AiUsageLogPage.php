<?php

namespace App\Filament\Pages;

use App\Models\AiUsageLog;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class AiUsageLogPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-cpu-chip';
    protected static ?string $navigationLabel = 'Consum API Claude';
    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';
    protected static ?int    $navigationSort  = 20;
    protected string  $view            = 'filament.pages.ai-usage-log';

    public string $period = '7';

    public function getPeriodOptions(): array
    {
        return [
            '1'         => 'Azi',
            'yesterday' => 'Ieri',
            '7'         => 'Ultimele 7 zile',
            '14'        => 'Ultimele 14 zile',
            '30'        => 'Ultimele 30 zile',
        ];
    }

    private function getDateRange(): array
    {
        if ($this->period === 'yesterday') {
            return [now()->subDay()->startOfDay(), now()->startOfDay()];
        }
        return [now()->subDays((int) $this->period)->startOfDay(), null];
    }

    private function scopePeriod(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        [$from, $to] = $this->getDateRange();
        $q->where('created_at', '>=', $from);
        if ($to) {
            $q->where('created_at', '<', $to);
        }
        return $q;
    }

    public function getStats(): array
    {
        $days = $this->period === 'yesterday' ? 1 : (int) $this->period;

        return [
            'total_cost'  => round($this->scopePeriod(AiUsageLog::query())->sum('cost_usd'), 4),
            'total_input' => $this->scopePeriod(AiUsageLog::query())->sum('input_tokens'),
            'total_output'=> $this->scopePeriod(AiUsageLog::query())->sum('output_tokens'),
            'call_count'  => $this->scopePeriod(AiUsageLog::query())->count(),
            'avg_per_day' => $days > 0 ? round($this->scopePeriod(AiUsageLog::query())->sum('cost_usd') / $days, 4) : 0,
        ];
    }

    public function getBySource(): \Illuminate\Support\Collection
    {
        return $this->scopePeriod(AiUsageLog::query())
            ->select('source', 'model',
                DB::raw('COUNT(*) as calls'),
                DB::raw('SUM(input_tokens) as input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('SUM(cost_usd) as cost_usd')
            )
            ->groupBy('source', 'model')
            ->orderByDesc('cost_usd')
            ->get()
            ->map(fn ($r) => tap($r, fn ($r) => $r->source_label = AiUsageLog::SOURCE_LABELS[$r->source] ?? $r->source));
    }

    public function getByDay(): \Illuminate\Support\Collection
    {
        return $this->scopePeriod(AiUsageLog::query())
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(cost_usd) as cost_usd'),
                DB::raw('SUM(input_tokens) as input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('COUNT(*) as calls')
            )
            ->groupByRaw('DATE(created_at)')
            ->orderByDesc('day')
            ->get();
    }

    public function getRecentLogs(): \Illuminate\Support\Collection
    {
        return $this->scopePeriod(AiUsageLog::query())
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn ($r) => tap($r, fn ($r) => $r->source_label = AiUsageLog::SOURCE_LABELS[$r->source] ?? $r->source));
    }
}
