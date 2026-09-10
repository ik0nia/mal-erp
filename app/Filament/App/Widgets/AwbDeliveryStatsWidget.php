<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Sumar livrări Sameday — UN singur card compact full-width deasupra listei
 * de comenzi. Toate cifrele provin exclusiv din date certe de tracking
 * (courier_status / picked_up_at / delivered_at salvate local).
 */
class AwbDeliveryStatsWidget extends Widget
{
    protected string $view = 'filament.app.awb-delivery-summary';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $t = DB::table('sameday_awbs');

        // în livrare: status confirmat, nelivrate, non-terminale
        $activeQ = (clone $t)
            ->whereNotNull('awb_number')->where('awb_number', '!=', '')
            ->where('status', 'created')
            ->whereNull('delivered_at')
            ->whereNotNull('courier_status')
            ->where('courier_status', '!=', 'indisponibil')
            ->whereRaw("courier_status NOT REGEXP 'retur|anulat|refuz'");
        $inDelivery = (clone $activeQ)->count();
        $oldestPickup = (clone $activeQ)->whereNotNull('picked_up_at')->min('picked_up_at');
        $oldestDays = $oldestPickup ? (int) \Carbon\Carbon::parse($oldestPickup)->diffInDays(now()) : null;

        // livrări încheiate (90 zile) — pentru medie și % sub 48h
        $doneQ = (clone $t)->whereNotNull('picked_up_at')->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subDays(90));
        $avgHours = (clone $doneQ)->selectRaw('AVG(TIMESTAMPDIFF(HOUR, picked_up_at, delivered_at)) h')->value('h');
        $doneTotal = (clone $doneQ)->count();
        $under48 = $doneTotal > 0
            ? (int) round((clone $doneQ)->whereRaw('TIMESTAMPDIFF(HOUR, picked_up_at, delivered_at) < 48')->count() / $doneTotal * 100)
            : null;

        $delivered7  = (clone $t)->whereNotNull('delivered_at')->where('delivered_at', '>=', now()->subDays(7))->count();
        $delivered30 = (clone $t)->whereNotNull('delivered_at')->where('delivered_at', '>=', now()->subDays(30))->count();

        $returns30 = (clone $t)->whereRaw("courier_status REGEXP 'retur|refuz'")
            ->where('courier_status_at', '>=', now()->subDays(30))->count();

        // «rambur» singur nu înseamnă încasat — eticheta «(ramburs în așteptare)» îl conține;
        // încasat = doar transferul efectiv («Rambursul a fost transferat»)
        $codPending = (clone $t)->where('cod_amount', '>', 0)->whereNotNull('delivered_at')
            ->where(fn ($q) => $q->whereNull('courier_status')->orWhereRaw("courier_status NOT REGEXP 'rambur.*transferat'"))
            ->whereRaw("COALESCE(courier_status,'') NOT REGEXP 'retur|refuz|anulat'")
            ->selectRaw('COUNT(*) c, COALESCE(SUM(cod_amount),0) s')->first();

        $codCollected30 = (clone $t)->whereRaw("courier_status REGEXP 'rambur.*transferat'")
            ->where('courier_status_at', '>=', now()->subDays(30))
            ->sum('cod_amount');

        $inTracking = (clone $t)
            ->whereNotNull('awb_number')->where('awb_number', '!=', '')
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->where(function ($q) {
                $q->whereNull('courier_status')
                    ->orWhere(function ($w) {
                        $w->whereRaw("courier_status NOT REGEXP 'retur|anulat|refuz|rambur.*transferat'")
                            ->where('courier_status', '!=', 'indisponibil')
                            ->where(fn ($v) => $v->whereRaw("courier_status NOT REGEXP 'livrat'")->orWhere('cod_amount', '>', 0));
                    });
            })->count();

        return [
            'inDelivery'     => $inDelivery,
            'oldestDays'     => $oldestDays,
            'avgText'        => $avgHours === null ? '—' : ($avgHours < 48 ? round($avgHours) . 'h' : number_format($avgHours / 24, 1, ',', '') . ' zile'),
            'under48'        => $under48,
            'delivered7'     => $delivered7,
            'delivered30'    => $delivered30,
            'returns30'      => $returns30,
            'codPendingC'    => (int) ($codPending->c ?? 0),
            'codPendingS'    => (float) ($codPending->s ?? 0),
            'codCollected30' => (float) $codCollected30,
            'inTracking'     => $inTracking,
        ];
    }
}
