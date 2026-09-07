<?php

namespace App\Services\Courier;

use App\Models\SamedayAwb;
use App\Services\WooCommerce\WooDirectSqlService;
use Illuminate\Support\Facades\Log;

/**
 * Oglindește AWB-urile Sameday create din ERP în tabelul pluginului de pe site
 * (wp_sameday_awb), ca AWB-ul să fie vizibil și în admin-ul WooCommerce —
 * indiferent de unde a fost creat, comanda arată la fel în ambele sisteme.
 *
 * Formatul `parcels` folosește exact clasa SDK-ului (Sameday\Objects\PostAwb\
 * ParcelObject, serializată) — identic cu ce scrie pluginul însuși.
 */
class SamedayAwbSiteMirror
{
    public function __construct(private readonly WooDirectSqlService $site)
    {
    }

    public function push(SamedayAwb $awb): void
    {
        $wooId = $awb->wooOrder?->woo_id;

        if (! $wooId || ! filled($awb->awb_number)) {
            return;
        }

        try {
            // Idempotent: nu dublăm dacă există deja (ex. re-rulare / creat și pe site)
            $existing = $this->site->querySite(sprintf(
                "SELECT id FROM wp_sameday_awb WHERE awb_number = '%s' LIMIT 1",
                addslashes($awb->awb_number)
            ));

            if (! empty($existing)) {
                return;
            }

            $parcels = [];
            for ($i = 1; $i <= max(1, (int) $awb->package_count); $i++) {
                $parcels[] = new \Sameday\Objects\PostAwb\ParcelObject($i, $awb->awb_number.sprintf('%03d', $i));
            }

            $sql = sprintf(
                "INSERT INTO wp_sameday_awb (order_id, awb_number, parcels, awb_cost) VALUES (%d, '%s', '%s', %s);",
                (int) $wooId,
                addslashes($awb->awb_number),
                addslashes(serialize($parcels)),
                $awb->shipping_cost !== null ? number_format((float) $awb->shipping_cost, 2, '.', '') : 'NULL'
            );

            $this->site->executeSiteSql($sql, 'sameday_awb_mirror');

            Log::info('[SamedayMirror] AWB oglindit pe site', [
                'awb'       => $awb->awb_number,
                'woo_order' => $wooId,
            ]);
        } catch (\Throwable $e) {
            // AWB-ul e creat la Sameday — oglindirea nu are voie să strice fluxul
            Log::warning('[SamedayMirror] Oglindirea pe site a eșuat (AWB-ul există la Sameday)', [
                'awb'   => $awb->awb_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
