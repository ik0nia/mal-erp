<?php

namespace App\Filament\App\Widgets;

use App\Models\DailyStockMetric;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class PriceMovementChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Variație prețuri zilnică';

    protected static ?string $maxHeight = '300px';

    protected static ?string $pollingInterval = null;

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
                DB::raw('SUM(CASE WHEN closing_sell_price > opening_sell_price THEN 1 ELSE 0 END) as price_up'),
                DB::raw('SUM(CASE WHEN closing_sell_price < opening_sell_price THEN 1 ELSE 0 END) as price_down'),
            ])
            ->where('day', '>=', $from)
            ->whereNotNull('opening_sell_price')
            ->whereNotNull('closing_sell_price')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($row): string => Carbon::parse($row->day)->toDateString());

        $labels = [];
        $up = [];
        $down = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->toDateString();
            $labels[] = Carbon::parse($date)->format('d.m');
            $row = $rows->get($date);
            $up[] = $row ? (int) $row->price_up : 0;
            $down[] = $row ? (int) $row->price_down : 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Creșteri preț',
                    'data' => $up,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.7)',
                    'borderColor' => 'rgb(34, 197, 94)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Scăderi preț',
                    'data' => $down,
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
