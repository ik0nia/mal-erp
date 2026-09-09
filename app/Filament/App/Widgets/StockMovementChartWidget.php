<?php

namespace App\Filament\App\Widgets;

use App\Models\DailyStockMetric;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class StockMovementChartWidget extends ChartWidget
{
    protected ?string $heading = 'Variație stoc zilnică';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    /** Controlled by Filament's filter dropdown: 'qty' or 'value' */
    public ?string $filter = 'qty';

    /** Synced from page pills via Livewire event */
    public int $days = 7;

    #[On('stockMovementsSetDays')]
    public function syncDays(int $days): void
    {
        $this->days = $days;
        $this->cachedData = null;
        $this->updateChartData();
    }

    protected function getFilters(): ?array
    {
        return [
            'qty'   => 'Cantitate (buc)',
            'value' => 'Valoare (lei)',
        ];
    }

    protected function getData(): array
    {
        $days = max(1, $this->days);
        $mode = $this->filter ?? 'qty';
        $from = Carbon::now()->subDays($days - 1)->startOfDay()->toDateString();

        if ($mode === 'value') {
            $rows = DailyStockMetric::query()
                ->select([
                    'day',
                    DB::raw('SUM(CASE WHEN daily_total_variation > 0 THEN daily_total_variation * COALESCE(closing_sell_price, 0) ELSE 0 END) as total_in'),
                    DB::raw('SUM(CASE WHEN daily_total_variation < 0 THEN ABS(daily_total_variation) * COALESCE(closing_sell_price, 0) ELSE 0 END) as total_out'),
                ])
                ->where('day', '>=', $from)
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->keyBy(fn ($row): string => Carbon::parse($row->day)->toDateString());

            $labelIn  = 'Intrări (lei)';
            $labelOut = 'Ieșiri (lei)';
            $round    = 2;
        } else {
            $rows = DailyStockMetric::query()
                ->select([
                    'day',
                    DB::raw('SUM(CASE WHEN daily_total_variation > 0 THEN daily_total_variation ELSE 0 END) as total_in'),
                    DB::raw('SUM(CASE WHEN daily_total_variation < 0 THEN ABS(daily_total_variation) ELSE 0 END) as total_out'),
                ])
                ->where('day', '>=', $from)
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->keyBy(fn ($row): string => Carbon::parse($row->day)->toDateString());

            $labelIn  = 'Intrări (buc)';
            $labelOut = 'Ieșiri (buc)';
            $round    = 0;
        }

        $labels = [];
        $in     = [];
        $out    = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = Carbon::now()->subDays($i)->toDateString();
            $labels[] = Carbon::parse($date)->format('d.m');
            $row      = $rows->get($date);
            $in[]     = $row ? round((float) $row->total_in, $round) : 0;
            $out[]    = $row ? round((float) $row->total_out, $round) : 0;
        }

        return [
            'datasets' => [
                [
                    'label'           => $labelIn,
                    'data'            => $in,
                    'backgroundColor' => 'rgba(5, 150, 105, 0.75)',
                    'borderColor'     => 'rgb(5, 150, 105)',
                    'borderWidth'     => 0,
                    'borderRadius'    => 4,
                    'maxBarThickness' => 26,
                ],
                [
                    'label'           => $labelOut,
                    'data'            => $out,
                    'backgroundColor' => 'rgba(220, 38, 38, 0.7)',
                    'borderColor'     => 'rgb(220, 38, 38)',
                    'borderWidth'     => 0,
                    'borderRadius'    => 4,
                    'maxBarThickness' => 26,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels'   => ['boxWidth' => 12, 'font' => ['size' => 11], 'usePointStyle' => true, 'pointStyle' => 'rectRounded'],
                ],
            ],
            'scales' => [
                'y' => [
                    'grid'  => ['color' => 'rgba(15,23,42,0.05)'],
                    'ticks' => ['color' => '#94a3b8', 'font' => ['size' => 11]],
                ],
                'x' => [
                    'grid'  => ['display' => false],
                    'ticks' => ['color' => '#94a3b8', 'font' => ['size' => 11]],
                ],
            ],
        ];
    }

    public static function canView(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

}