<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class OrderStatusChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Magazin online: Comenzi pe status';

    protected static ?string $maxHeight = '300px';

    protected static ?string $pollingInterval = null;

    protected static ?int $sort = -1;

    /** Year as string, used as the Filament filter value */
    public ?string $filter = null;

    public ?int $month = null;

    public function mount(): void
    {
        $this->filter = (string) now()->year;
    }

    #[On('onlineShopSetPeriod')]
    public function syncPeriod(int $year, ?int $month): void
    {
        $this->filter = (string) $year;
        $this->month  = $month;
    }

    protected function getFilters(): ?array
    {
        $currentYear = now()->year;
        $filters     = [];

        foreach (range($currentYear, max($currentYear - 3, 2024)) as $yr) {
            $filters[(string) $yr] = (string) $yr;
        }

        return $filters;
    }

    protected function getData(): array
    {
        $year = (int) ($this->filter ?? now()->year);

        $rows = DB::table('woo_orders')
            ->whereRaw('YEAR(order_date) = ?', [$year])
            ->when($this->month, fn ($q) => $q->whereRaw('MONTH(order_date) = ?', [$this->month]))
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->orderByDesc('cnt')
            ->get();

        $statusColors = [
            'completed'  => 'rgba(34, 197, 94, 0.8)',
            'processing' => 'rgba(99, 102, 241, 0.8)',
            'on-hold'    => 'rgba(234, 179, 8, 0.8)',
            'cancelled'  => 'rgba(239, 68, 68, 0.8)',
            'refunded'   => 'rgba(249, 115, 22, 0.8)',
            'failed'     => 'rgba(127, 29, 29, 0.8)',
            'pending'    => 'rgba(107, 114, 128, 0.8)',
        ];

        $labels = [];
        $data   = [];
        $colors = [];

        $statusLabels = [
            'completed'  => 'Finalizate',
            'processing' => 'În procesare',
            'cancelled'  => 'Anulate',
            'on-hold'    => 'În așteptare',
            'refunded'   => 'Rambursate',
            'failed'     => 'Eșuate',
            'pending'    => 'În așteptare plată',
        ];

        foreach ($rows as $row) {
            $labels[] = ($statusLabels[$row->status] ?? $row->status) . ' (' . number_format((int) $row->cnt) . ')';
            $data[]   = (int) $row->cnt;
            $colors[] = $statusColors[$row->status] ?? 'rgba(107, 114, 128, 0.8)';
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Comenzi',
                    'data'            => $data,
                    'backgroundColor' => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
