<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class BiStockTrendChartWidget extends ChartWidget
{
    protected ?string $heading = 'Evoluție valoare stoc';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    /** @var int Numărul de zile afișate: 30 sau 90 */
    public int $period = 30;

    protected function getData(): array
    {
        $rows = DB::table('bi_inventory_kpi_daily')
            ->orderByDesc('day')
            ->limit($this->period)
            ->get()
            ->reverse()
            ->values();

        return [
            'datasets' => [
                [
                    'label'            => 'Valoare stoc (RON)',
                    'data'             => $rows->map(fn ($r) => round((float) $r->inventory_value_closing_total, 0))->toArray(),
                    'borderColor'      => 'rgb(239, 68, 68)',
                    'backgroundColor'  => 'rgba(239, 68, 68, 0.07)',
                    'fill'             => true,
                    'tension'          => 0.35,
                    'pointRadius'      => $this->period <= 30 ? 2 : 1,
                    'pointHoverRadius' => 4,
                    'yAxisID'          => 'y',
                ],
                [
                    'label'            => 'Produse în stoc',
                    'data'             => $rows->map(fn ($r) => (int) $r->products_in_stock)->toArray(),
                    'borderColor'      => 'rgb(59, 130, 246)',
                    'backgroundColor'  => 'transparent',
                    'fill'             => false,
                    'tension'          => 0.35,
                    'pointRadius'      => $this->period <= 30 ? 2 : 1,
                    'pointHoverRadius' => 4,
                    'borderDash'       => [4, 3],
                    'yAxisID'          => 'y2',
                ],
            ],
            'labels' => $rows->pluck('day')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return [
            '30' => 'Ultimele 30 zile',
            '90' => 'Ultimele 90 zile',
        ];
    }

    public function updateChartData(): void
    {
        // Filament apelează asta când se schimbă filtrul — sincronizăm $period cu $filter
        $this->period = (int) ($this->filter ?? 30);
        $this->cachedData = null;
        parent::updateChartData();
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display'  => true,
                    'position' => 'top',
                    'labels'   => ['boxWidth' => 12, 'font' => ['size' => 11]],
                ],
            ],
            'scales' => [
                'y' => [
                    'type'     => 'linear',
                    'position' => 'left',
                    'ticks'    => [
                        'callback' => 'function(v){return new Intl.NumberFormat("ro-RO").format(v)+" RON"}',
                    ],
                    'grid' => ['color' => 'rgba(0,0,0,0.04)'],
                ],
                'y2' => [
                    'type'     => 'linear',
                    'position' => 'right',
                    'ticks'    => [
                        'callback' => 'function(v){return v+" buc"}',
                        'color'    => 'rgb(59,130,246)',
                    ],
                    'grid' => ['drawOnChartArea' => false],
                ],
                'x' => [
                    'ticks' => ['maxRotation' => 45, 'minRotation' => 30],
                    'grid'  => ['display' => false],
                ],
            ],
        ];
    }

    public static function canView(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

}