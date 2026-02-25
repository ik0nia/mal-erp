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

    /** Controlled by Filament's filter dropdown: 'count' or 'value' */
    public ?string $filter = 'count';

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
            'count' => 'Nr. produse',
            'value' => 'Impact valoric (lei)',
        ];
    }

    protected function getData(): array
    {
        $days = max(1, $this->days);
        $mode = $this->filter ?? 'count';
        $from = Carbon::now()->subDays($days - 1)->startOfDay()->toDateString();

        if ($mode === 'value') {
            // Monetary impact of price changes: (Δprice) * closing_qty
            $rows = DailyStockMetric::query()
                ->select([
                    'day',
                    DB::raw('SUM(CASE WHEN closing_sell_price > opening_sell_price THEN (closing_sell_price - opening_sell_price) * COALESCE(closing_total_qty, 0) ELSE 0 END) as impact_up'),
                    DB::raw('SUM(CASE WHEN closing_sell_price < opening_sell_price THEN (opening_sell_price - closing_sell_price) * COALESCE(closing_total_qty, 0) ELSE 0 END) as impact_down'),
                ])
                ->where('day', '>=', $from)
                ->whereNotNull('opening_sell_price')
                ->whereNotNull('closing_sell_price')
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->keyBy(fn ($row): string => Carbon::parse($row->day)->toDateString());

            $labelUp   = 'Creșteri (lei)';
            $labelDown = 'Scăderi (lei)';
            $round     = 2;
            $castUp    = fn ($row) => round((float) $row->impact_up, 2);
            $castDown  = fn ($row) => round((float) $row->impact_down, 2);
        } else {
            // Count of products with price up / down
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

            $labelUp   = 'Creșteri preț (nr)';
            $labelDown = 'Scăderi preț (nr)';
            $round     = 0;
            $castUp    = fn ($row) => (int) $row->price_up;
            $castDown  = fn ($row) => (int) $row->price_down;
        }

        $labels = [];
        $up     = [];
        $down   = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = Carbon::now()->subDays($i)->toDateString();
            $labels[] = Carbon::parse($date)->format('d.m');
            $row      = $rows->get($date);
            $up[]     = $row ? $castUp($row) : 0;
            $down[]   = $row ? $castDown($row) : 0;
        }

        return [
            'datasets' => [
                [
                    'label'           => $labelUp,
                    'data'            => $up,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.7)',
                    'borderColor'     => 'rgb(34, 197, 94)',
                    'borderWidth'     => 1,
                ],
                [
                    'label'           => $labelDown,
                    'data'            => $down,
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
