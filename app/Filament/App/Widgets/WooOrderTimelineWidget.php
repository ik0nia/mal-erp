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

        // 1. Comandă → Finalizare (procesarea internă, până predăm comanda).
        $toDone = $gaps('SELECT TIMESTAMPDIFF(HOUR, order_date, date_completed) t
            FROM woo_orders WHERE YEAR(order_date) = ? AND date_completed IS NOT NULL');

        // 2. Finalizare → Ridicare Sameday (în cât timp ridică curierul după finalizare).
        $samedayPickup = array_filter($gaps('SELECT TIMESTAMPDIFF(HOUR, o.date_completed, MIN(a.picked_up_at)) t
            FROM woo_orders o JOIN sameday_awbs a ON a.woo_order_id = o.id
            WHERE YEAR(o.order_date) = ? AND o.date_completed IS NOT NULL AND a.picked_up_at IS NOT NULL
            GROUP BY o.id'), fn ($v) => $v >= 0);

        // 3. Finalizare → Livrare Sameday (durata REALĂ a curierului DUPĂ finalizare).
        $samedayDeliv = array_filter($gaps('SELECT TIMESTAMPDIFF(HOUR, o.date_completed, MIN(a.delivered_at)) t
            FROM woo_orders o JOIN sameday_awbs a ON a.woo_order_id = o.id
            WHERE YEAR(o.order_date) = ? AND o.date_completed IS NOT NULL AND a.delivered_at IS NOT NULL
            GROUP BY o.id'), fn ($v) => $v >= 0);

        // 3. Comandă → Livrare reală (cât așteaptă clientul total): Sameday (delivered_at real)
        //    + flotă proprie (custom_shipping completat) presupusă la 8h/livrare.
        $realSameday = $gaps('SELECT TIMESTAMPDIFF(HOUR, o.order_date, MIN(a.delivered_at)) t
            FROM woo_orders o JOIN sameday_awbs a ON a.woo_order_id = o.id
            WHERE YEAR(o.order_date) = ? AND a.delivered_at IS NOT NULL
            GROUP BY o.id');

        $fleetCount = (int) (DB::selectOne(
            'SELECT COUNT(*) n FROM woo_orders
             WHERE YEAR(order_date) = ? AND status = "completed"
               AND data LIKE \'%custom_shipping%\'',
            [$y]
        )->n ?? 0);

        $realDeliv = array_merge($realSameday, array_fill(0, $fleetCount, 8.0));

        $median = function (array $v): ?float {
            $v = array_values($v);
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
            'year'         => $y,
            'toDone'        => $fmt($median($toDone)),        'nDone'    => count($toDone),
            'samedayPickup' => $fmt($median($samedayPickup)), 'nPickup'  => count($samedayPickup),
            'samedayDeliv'  => $fmt($median($samedayDeliv)),  'nSameday' => count($samedayDeliv),
            'realDeliv'    => $fmt($median($realDeliv)),    'nReal'     => count($realDeliv),
        ];
    }
}
