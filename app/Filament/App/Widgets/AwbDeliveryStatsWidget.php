<?php

namespace App\Filament\App\Widgets;

use App\Models\SamedayAwb;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Sumar livrări (AWB Sameday) — afișat deasupra listei de comenzi:
 * în livrare acum, media de livrare, ramburs livrat dar neîncasat, livrate 30 zile.
 */
class AwbDeliveryStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 5; // toate pe un singur rând — ocupă minim din pagină
    }

    protected function getStats(): array
    {
        $active = SamedayAwb::query()
            ->whereNotNull('awb_number')->where('awb_number', '!=', '')
            ->where('status', SamedayAwb::STATUS_CREATED)
            ->whereNull('delivered_at')
            // DOAR date certe: AWB-uri cu status confirmat de curier, în tranzit
            ->whereNotNull('courier_status')
            ->where('courier_status', '!=', 'indisponibil')
            ->whereRaw("courier_status NOT REGEXP 'retur|anulat|refuz'")
            ->count();

        $avgHours = DB::table('sameday_awbs')
            ->whereNotNull('picked_up_at')->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subDays(90))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, picked_up_at, delivered_at)) h')
            ->value('h');
        $avgText = $avgHours === null
            ? '—'
            : ($avgHours < 48 ? round($avgHours) . ' ore' : number_format($avgHours / 24, 1, ',', '') . ' zile');

        $codPending = DB::table('sameday_awbs')
            ->where('cod_amount', '>', 0)
            ->whereNotNull('delivered_at')
            ->where(function ($q) {
                $q->whereNull('courier_status')->orWhereRaw("courier_status NOT REGEXP 'rambur'");
            })
            ->selectRaw('COUNT(*) c, COALESCE(SUM(cod_amount), 0) s')
            ->first();

        // în rotația de verificare automată (non-terminale, inclusiv cele încă neinterogate)
        $inTracking = DB::table('sameday_awbs')
            ->whereNotNull('awb_number')->where('awb_number', '!=', '')
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->where(function ($q) {
                $q->whereNull('courier_status')
                    ->orWhere(function ($w) {
                        $w->whereRaw("courier_status NOT REGEXP 'retur|rambur|anulat|refuz'")
                            ->where('courier_status', '!=', 'indisponibil')
                            ->where(function ($v) {
                                $v->whereRaw("courier_status NOT REGEXP 'livrat'")
                                    ->orWhere('cod_amount', '>', 0);
                            });
                    });
            })
            ->count();

        $delivered30 = DB::table('sameday_awbs')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subDays(30))
            ->count();

        return [
            Stat::make('În livrare acum', $active)
                ->description('cu status confirmat de curier')
                ->color($active > 0 ? 'warning' : 'success'),
            Stat::make('Media de livrare', $avgText)
                ->description('ridicare → livrare, ultimele 90 zile')
                ->color('info'),
            Stat::make('Ramburs neîncasat', number_format((float) ($codPending->s ?? 0), 0, ',', '.') . ' RON')
                ->description(($codPending->c ?? 0) . ' colete livrate, banii pe drum')
                ->color(($codPending->c ?? 0) > 0 ? 'danger' : 'success'),
            Stat::make('Livrate (30 zile)', $delivered30)
                ->description('confirmate de curier')
                ->color('success'),
            Stat::make('În urmărire', $inTracking)
                ->description('verificate automat la 30 min')
                ->color('gray'),
        ];
    }
}
