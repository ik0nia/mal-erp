<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\DB;

/**
 * Rezerva de stoc pentru comenzile Woo aflate în curs, dar NEînregistrate încă în
 * WinMentor.
 *
 * Problema pe care o rezolvă: WinMentor nu „știe" de o comandă online (ex. ramburs)
 * până când nu e facturată acolo. Push-ul nostru de stoc (sync delta + reconciliere
 * orară) trimite stocul brut din WinMentor pe site și astfel SUPRASCRIE decrementul
 * pe care WooCommerce l-a făcut singur la plasarea comenzii → produsul redevine
 * disponibil și se poate vinde a doua oară aceeași bucată (oversell).
 *
 * Soluția: împingem pe site  stoc_WinMentor − cantitatea din comenzile în curs care
 * nu au ajuns încă în WinMentor. Odată ce o comandă e sincronizată acolo
 * (winmentor_synced_at setat), stocul WinMentor scade real, iar comanda iese din
 * rezervă → nu se scade de două ori.
 *
 * Cheia din rezultat este id-ul LOCAL al produsului (woo_products.id), pentru că
 * product_stocks folosește id local, iar woo_order_items.woo_product_id e id REMOTE.
 */
class OpenOrderReservationService
{
    /** Statusuri Woo considerate „angajate" (marfa e promisă clientului, nu încă expediată/facturată). */
    public const COMMITTED_STATUSES = ['processing', 'on-hold', 'pending'];

    /**
     * @param  array<int>|null  $localProductIds  restrânge la aceste id-uri locale (null = toate)
     * @return array<int,int>  [woo_products.id => cantitate rezervată (întreagă, rotunjită în sus)]
     */
    public static function reservedByLocalProduct(?array $localProductIds = null): array
    {
        $q = DB::table('woo_order_items as oi')
            ->join('woo_orders as o', 'o.id', '=', 'oi.order_id')
            ->join('woo_products as wp', 'wp.woo_id', '=', 'oi.woo_product_id')
            ->whereIn('o.status', self::COMMITTED_STATUSES)
            ->whereNull('o.winmentor_synced_at')
            ->where('oi.quantity', '>', 0)
            ->selectRaw('wp.id as lid, SUM(oi.quantity) as q')
            ->groupBy('wp.id');

        if ($localProductIds !== null) {
            if ($localProductIds === []) {
                return [];
            }
            $q->whereIn('wp.id', $localProductIds);
        }

        return $q->get()
            ->mapWithKeys(fn ($r) => [(int) $r->lid => (int) ceil((float) $r->q)])
            ->all();
    }
}
