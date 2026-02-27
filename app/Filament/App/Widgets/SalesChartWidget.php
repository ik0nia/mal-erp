<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class SalesChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Magazin online: Vânzări';

    protected static ?string $maxHeight = '300px';

    protected static ?string $pollingInterval = null;

    protected static ?int $sort = -2;

    protected static string $view = 'filament.widgets.sales-chart-widget';

    public string $mode = 'revenue';

    public int $year;

    public ?int $month = null;

    public function mount(): void
    {
        $this->year = now()->year;
    }

    #[On('onlineShopSetPeriod')]
    public function syncPeriod(int $year, ?int $month): void
    {
        $this->year  = $year;
        $this->month = $month;
        $this->updateChartData();
    }

    public function updatedMode(): void
    {
        $this->updateChartData();
    }

    public function updatedYear(): void
    {
        $this->updateChartData();
    }

    /** @return array<string, string> */
    public function getAvailableYears(): array
    {
        $currentYear = now()->year;
        $years       = range($currentYear, max($currentYear - 3, 2024));
        $result      = [];

        foreach ($years as $yr) {
            $result[(string) $yr] = (string) $yr;
        }

        return $result;
    }

    // No Filament-managed filter dropdown — we render our own in the custom view
    protected function getFilters(): ?array
    {
        return null;
    }

    protected function getData(): array
    {
        $excluded = ['cancelled', 'refunded', 'failed'];

        if ($this->month) {
            $daysInMonth = (int) date('t', mktime(0, 0, 0, $this->month, 1, $this->year));

            $rows = DB::table('woo_orders')
                ->whereRaw('YEAR(order_date) = ?', [$this->year])
                ->whereRaw('MONTH(order_date) = ?', [$this->month])
                ->whereNotIn('status', $excluded)
                ->selectRaw('DAY(order_date) as day_no, COUNT(*) as cnt, SUM(total) as revenue')
                ->groupBy('day_no')
                ->get()
                ->keyBy('day_no');

            $labels = [];
            $data   = [];

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $labels[] = str_pad((string) $d, 2, '0', STR_PAD_LEFT) . '.' . str_pad((string) $this->month, 2, '0', STR_PAD_LEFT);
                $row      = $rows->get($d);
                $data[]   = $this->mode === 'revenue'
                    ? round((float) ($row->revenue ?? 0), 2)
                    : (int) ($row->cnt ?? 0);
            }
        } else {
            $rows = DB::table('woo_orders')
                ->whereRaw('YEAR(order_date) = ?', [$this->year])
                ->whereNotIn('status', $excluded)
                ->selectRaw('MONTH(order_date) as mo, COUNT(*) as cnt, SUM(total) as revenue')
                ->groupBy('mo')
                ->get()
                ->keyBy('mo');

            $labels = ['Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $data   = [];

            for ($m = 1; $m <= 12; $m++) {
                $row    = $rows->get($m);
                $data[] = $this->mode === 'revenue'
                    ? round((float) ($row->revenue ?? 0), 2)
                    : (int) ($row->cnt ?? 0);
            }
        }

        $total       = array_sum($data);
        $periodLabel = $this->month
            ? str_pad((string) $this->month, 2, '0', STR_PAD_LEFT) . '.' . $this->year
            : (string) $this->year;

        $datasetLabel = $this->mode === 'revenue'
            ? 'Vânzări ' . $periodLabel . ' (' . number_format(round($total, 2), 2, ',', '.') . ' lei)'
            : 'Comenzi ' . $periodLabel . ' (' . number_format((int) $total) . ' comenzi)';

        return [
            'datasets' => [
                [
                    'label'           => $datasetLabel,
                    'data'            => $data,
                    'backgroundColor' => 'rgba(99, 102, 241, 0.7)',
                    'borderColor'     => 'rgb(99, 102, 241)',
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
