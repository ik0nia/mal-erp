<?php

namespace App\Filament\App\Widgets;

use App\Models\DailyStockMetric;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class StockMovementChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Variație stoc zilnică';

    protected static ?string $maxHeight = '300px';

    protected static ?string $pollingInterval = null;

    /** Controlled by Filament's filter dropdown: 'qty' or 'value' */
    public ?string $filter = 'qty';

    /** Synced from page pills via Livewire event */
    public int $days = 7;

    #[On('stockMovementsSetDays')]
    public function syncDays(int $days): void
    {
        $this->days = $days;
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
                    'backgroundColor' => 'rgba(34, 197, 94, 0.7)',
                    'borderColor'     => 'rgb(34, 197, 94)',
                    'borderWidth'     => 1,
                ],
                [
                    'label'           => $labelOut,
                    'data'            => $out,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.7)',
                    'borderColor'     => 'rgb(239, 68, 68)',
                    'borderWidth'     => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
