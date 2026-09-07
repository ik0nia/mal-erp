<?php

namespace App\Console\Commands;

use App\Models\SamedayAwb;
use App\Models\WooOrder;
use App\Services\WooCommerce\WooDirectSqlService;
use Illuminate\Console\Command;

/**
 * Aduce în ERP AWB-urile Sameday create DE PE SITE (pluginul samedaycourier —
 * tabelul wp_sameday_awb), ca orice AWB să fie vizibil pe comanda din ERP,
 * indiferent unde a fost creat. Direcția inversă (ERP → site) e acoperită de
 * SamedayAwbSiteMirror la crearea AWB-ului din ERP.
 */
class SyncSamedayAwbsFromSiteCommand extends Command
{
    protected $signature = 'sameday:sync-awbs-from-site';

    protected $description = 'Sincronizează AWB-urile create pe site (wp_sameday_awb) în ERP';

    public function handle(WooDirectSqlService $site): int
    {
        try {
            $siteAwbs = $site->querySite(
                'SELECT order_id, awb_number, awb_cost FROM wp_sameday_awb WHERE awb_number IS NOT NULL'
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $existing = SamedayAwb::whereNotNull('awb_number')->pluck('awb_number')->flip();
        $created  = 0;

        foreach ($siteAwbs as $row) {
            $awbNumber = trim((string) ($row['awb_number'] ?? ''));

            if ($awbNumber === '' || isset($existing[$awbNumber]) || ! is_numeric($row['order_id'] ?? null)) {
                continue;
            }

            $order = WooOrder::where('woo_id', $row['order_id'])->first();
            if (! $order) {
                continue;
            }

            SamedayAwb::create([
                'location_id'           => $order->location_id ?: $order->connection?->location_id ?: 1,
                'woo_order_id'          => $order->id,
                'provider'              => \App\Models\IntegrationConnection::PROVIDER_SAMEDAY,
                'status'                => 'created_on_site',
                'awb_number'            => $awbNumber,
                'recipient_name'        => $order->customer_name ?: '—',
                'recipient_phone'       => $order->customer_phone ?: '—',
                'recipient_county'      => (string) data_get($order->shipping, 'state', data_get($order->billing, 'state', '—')) ?: '—',
                'recipient_city'        => (string) data_get($order->shipping, 'city', data_get($order->billing, 'city', '—')) ?: '—',
                'recipient_address'     => (string) data_get($order->shipping, 'address_1', data_get($order->billing, 'address_1', '—')) ?: '—',
                'recipient_postal_code' => (string) data_get($order->shipping, 'postcode', '') ?: null,
                'package_count'         => 1,
                'package_weight_kg'     => 1,
                'shipping_cost'         => is_numeric($row['awb_cost'] ?? null) ? (float) $row['awb_cost'] : null,
                'reference'             => $order->number,
                'observation'           => 'AWB creat din site (plugin Sameday) — importat automat în ERP',
            ]);
            $created++;
        }

        $this->info("✓ {$created} AWB-uri noi importate de pe site.");
        return self::SUCCESS;
    }
}
