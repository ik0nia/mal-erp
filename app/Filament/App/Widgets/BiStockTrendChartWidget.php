<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class BiStockTrendChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Evoluție valoare stoc (ultimele 30 zile)';

    protected static ?string $maxHeight = '240px';

    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $rows = DB::table('bi_inventory_kpi_daily')
            ->orderByDesc('day')
            ->limit(30)
            ->get()
            ->reverse()
            ->values();

        return [
            'datasets' => [
                [
                    'label'           => 'Valoare stoc (RON)',
                    'data'            => $rows->map(fn ($r) => round((float) $r->inventory_value_closing_total, 0))->toArray(),
                    'borderColor'     => 'rgb(239, 68, 68)',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.07)',
                    'fill'            => true,
                    'tension'         => 0.35,
                    'pointRadius'     => 2,
                    'pointHoverRadius'=> 4,
                ],
            ],
            'labels' => $rows->pluck('day')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'callbacks' => [],
                ],
            ],
            'scales' => [
                'y' => [
                    'ticks' => [
                        'callback' => 'function(v){return new Intl.NumberFormat("ro-RO").format(v)+" RON"}',
                    ],
                    'grid' => ['color' => 'rgba(0,0,0,0.04)'],
                ],
                'x' => [
                    'ticks' => ['maxRotation' => 45, 'minRotation' => 30],
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
