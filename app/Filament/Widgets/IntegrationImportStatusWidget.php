<?php

namespace App\Filament\Widgets;

use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class IntegrationImportStatusWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $runningCount = SyncRun::query()
            ->whereIn('status', [SyncRun::STATUS_QUEUED, SyncRun::STATUS_RUNNING])
            ->count();

        $failedLast24h = SyncRun::query()
            ->where('status', SyncRun::STATUS_FAILED)
            ->where('started_at', '>=', now()->subDay())
            ->count();

        $successLast24h = SyncRun::query()
            ->where('status', SyncRun::STATUS_SUCCESS)
            ->where('started_at', '>=', now()->subDay())
            ->count();

        $latestRun = SyncRun::query()
            ->with('connection')
            ->latest('started_at')
            ->first();

        $latestProvider = $latestRun ? (IntegrationConnection::providerOptions()[$latestRun->provider] ?? $latestRun->provider) : '-';
        $latestDescription = $latestRun
            ? sprintf(
                '#%d • %s • %s',
                $latestRun->id,
                $latestProvider,
                (string) $latestRun->type
            )
            : 'Nicio execuție';

        return [
            Stat::make('Importuri în curs', (string) $runningCount)
                ->description($runningCount > 0 ? 'Există job-uri active / în coadă' : 'Nu există job-uri active')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($runningCount > 0 ? 'warning' : 'success'),
            Stat::make('Fail ultimele 24h', (string) $failedLast24h)
                ->description('Verifică Integrări -> Sync Runs')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failedLast24h > 0 ? 'danger' : 'success'),
            Stat::make('Succes ultimele 24h', (string) $successLast24h)
                ->description('Importuri finalizate cu succes')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Ultimul import', $latestRun?->started_at?->diffForHumans() ?? '-')
                ->description($latestDescription)
                ->descriptionIcon('heroicon-m-clock')
                ->color('gray'),
        ];
    }
}
