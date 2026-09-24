<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Bandă îngustă cu timpii medii de procesare pe ANUL CURENT, toți măsurați de la
 * primirea comenzii: comandă → AWB creat → finalizare → livrare efectivă Sameday.
 */
class WooOrderTimelineWidget extends Widget
{
    protected string $view = 'filament.app.woo-order-timeline';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1; // deasupra celuilalt widget

    protected function getViewData(): array
    {
        $y = now()->year;

        // Folosim MEDIANA (timp tipic) — media e denaturată de câteva comenzi vechi cu
        // AWB/livrare întârziată (outlieri) și dădea valori ilogice.
        $gaps = function (string $sql) use ($y): array {
            return array_map(fn ($r) => (float) $r->t, array_filter(
                DB::select($sql, [$y]),
                fn ($r) => $r->t !== null
            ));
        };

        // „Ridicat de curier" — timp REAL din tracking-ul AWB (picked_up_at), NU created_at
        // (care e momentul creării înregistrării la noi / backfill = nesigur).
        $awb = $gaps('SELECT TIMESTAMPDIFF(HOUR, o.order_date, MIN(a.picked_up_at)) t
            FROM woo_orders o JOIN sameday_awbs a ON a.woo_order_id = o.id
            WHERE YEAR(o.order_date) = ? AND a.picked_up_at IS NOT NULL
            GROUP BY o.id');

        $done = $gaps('SELECT TIMESTAMPDIFF(HOUR, order_date, date_completed) t
            FROM woo_orders WHERE YEAR(order_date) = ? AND date_completed IS NOT NULL');

        $deliv = $gaps('SELECT TIMESTAMPDIFF(HOUR, o.order_date, MIN(a.delivered_at)) t
            FROM woo_orders o JOIN sameday_awbs a ON a.woo_order_id = o.id
            WHERE YEAR(o.order_date) = ? AND a.delivered_at IS NOT NULL
            GROUP BY o.id');

        $median = function (array $v): ?float {
            sort($v);
            $n = count($v);
            if ($n === 0) {
                return null;
            }
            $mid = intdiv($n, 2);

            return $n % 2 ? $v[$mid] : ($v[$mid - 1] + $v[$mid]) / 2;
        };

        $fmt = function (?float $h): string {
            if ($h === null) {
                return '—';
            }

            return $h < 48 ? round($h).' h' : number_format($h / 24, 1, ',', '').' zile';
        };

        return [
            'year'        => $y,
            'toAwb'       => $fmt($median($awb)),   'nAwb'       => count($awb),
            'toDone'      => $fmt($median($done)),  'nDone'      => count($done),
            'toDelivered' => $fmt($median($deliv)), 'nDelivered' => count($deliv),
        ];
    }
}
