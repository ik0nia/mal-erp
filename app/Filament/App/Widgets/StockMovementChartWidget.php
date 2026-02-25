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

    public ?string $filter = '7';

    #[On('stockMovementsSetDays')]
    public function syncDays(int $days): void
    {
        $this->filter = (string) $days;
    }

    protected function getFilters(): ?array
    {
        return null;
    }

    protected function getData(): array
    {
        $days = max(1, (int) ($this->filter ?? 7));
        $from = Carbon::now()->subDays($days - 1)->startOfDay()->toDateString();

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

        // Fill all days in range (including days with no data)
        $labels = [];
        $in = [];
        $out = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->toDateString();
            $labels[] = Carbon::parse($date)->format('d.m');
            $row = $rows->get($date);
            $in[] = $row ? round((float) $row->total_in, 2) : 0;
            $out[] = $row ? round((float) $row->total_out, 2) : 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Intrări',
                    'data' => $in,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.7)',
                    'borderColor' => 'rgb(34, 197, 94)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Ieșiri',
                    'data' => $out,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.7)',
                    'borderColor' => 'rgb(239, 68, 68)',
                    'borderWidth' => 1,
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
