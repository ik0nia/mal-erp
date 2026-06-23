<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DashboardOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;

class Dashboard extends BaseDashboard
{
    /** Dashboard-ul afișează DOAR widget-ul de ansamblu (nu toate widget-urile descoperite). */
    public function getWidgets(): array
    {
        return [
            DashboardOverviewWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }
}
