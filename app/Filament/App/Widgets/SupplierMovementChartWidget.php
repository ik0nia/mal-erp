<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

class SupplierMovementChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Top furnizori — mișcări stoc';

    protected static ?string $maxHeight = '300px';

    protected static ?string $pollingInterval = null;

    public int $days = 7;

    public ?int $supplierId = null;

    public ?string $filter = 'value';

    #[On('stockMovementsSetDays')]
    public function syncDays(int $days): void
    {
        $this->days = $days;
    }

    #[On('stockMovementsSetSupplier')]
    public function syncSupplier(?int $supplierId): void
    {
        $this->supplierId = $supplierId;
    }

    protected function getFilters(): ?array
    {
        return [
            'value' => 'Valoare (lei)',
            'qty'   => 'Cantitate (buc)',
        ];
    }

    protected function getData(): array
    {
        $days = max(1, $this->days);
        $from = Carbon::now()->subDays($days - 1)->startOfDay()->toDateString();
        $mode = $this->filter ?? 'value';

        $valueExpr = $mode === 'value'
            ? 'SUM(ABS(dsm.daily_total_variation) * COALESCE(dsm.closing_sell_price, 0))'
            : 'SUM(ABS(dsm.daily_total_variation))';

        $query = DB::table('daily_stock_metrics as dsm')
            ->join('product_suppliers as ps', 'ps.woo_product_id', '=', 'dsm.woo_product_id')
            ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->where('dsm.day', '>=', $from)
            ->where('dsm.daily_total_variation', '!=', 0)
            ->select([
                's.name as supplier_name',
                DB::raw("{$valueExpr} as total"),
            ])
            ->groupBy('s.id', 's.name')
            ->orderByDesc('total')
            ->limit(10);

        if ($this->supplierId) {
            $query->where('s.id', $this->supplierId);
        }

        $rows = $query->get();

        $labels = $rows->pluck('supplier_name')->toArray();
        $data   = $rows->map(fn ($r) => round((float) $r->total, $mode === 'value' ? 2 : 0))->toArray();

        $colors = [
            'rgba(59, 130, 246, 0.7)',
            'rgba(16, 185, 129, 0.7)',
            'rgba(245, 158, 11, 0.7)',
            'rgba(239, 68, 68, 0.7)',
            'rgba(139, 92, 246, 0.7)',
            'rgba(236, 72, 153, 0.7)',
            'rgba(20, 184, 166, 0.7)',
            'rgba(251, 146, 60, 0.7)',
            'rgba(99, 102, 241, 0.7)',
            'rgba(34, 197, 94, 0.7)',
        ];

        return [
            'datasets' => [
                [
                    'label'           => $mode === 'value' ? 'Valoare mișcări (lei)' : 'Cantitate mișcări (buc)',
                    'data'            => $data,
                    'backgroundColor' => array_slice($colors, 0, count($data)),
                    'borderWidth'     => 0,
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
