<?php

namespace App\Console\Commands;

use App\Models\SamedayAwb;
use App\Models\WooOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Aduce în ERP AWB-urile Sameday create DE PE SITE (pluginul samedaycourier —
 * tabelul wp_sameday_awb), ca orice AWB să fie vizibil pe comanda din ERP,
 * indiferent unde a fost creat.
 */
class SyncSamedayAwbsFromSiteCommand extends Command
{
    protected $signature = 'sameday:sync-awbs-from-site';

    protected $description = 'Sincronizează AWB-urile create pe site (wp_sameday_awb) în ERP';

    public function handle(): int
    {
        $home = posix_getpwuid(posix_geteuid())['dir'] ?? '/home/erp';
        $key  = $home.'/.ssh/id_ed25519';

        $result = Process::timeout(60)->run(
            "ssh -i {$key} -o StrictHostKeyChecking=no root@malinco.ro ".
            "\"wp --path=/var/www/malinco db query 'SELECT order_id, awb_number, awb_cost FROM wp_sameday_awb WHERE awb_number IS NOT NULL' --allow-root --skip-column-names\""
        );

        if (! $result->successful()) {
            $this->error('SSH/wp eșuat: '.$result->errorOutput());
            return self::FAILURE;
        }

        $existing = SamedayAwb::whereNotNull('awb_number')->pluck('awb_number')->flip();
        $created  = 0;

        foreach (explode("\n", trim($result->output())) as $line) {
            $f = explode("\t", $line);
            if (count($f) < 2 || ! is_numeric($f[0])) continue;

            [$wooOrderId, $awbNumber] = [$f[0], trim($f[1])];
            $cost = isset($f[2]) && is_numeric($f[2]) ? (float) $f[2] : null;

            if ($awbNumber === '' || isset($existing[$awbNumber])) continue;

            $order = WooOrder::where('woo_id', $wooOrderId)->first();
            if (! $order) continue;

            SamedayAwb::create([
                'location_id'           => $order->location_id ?: 1,
                'woo_order_id'          => $order->id,
                'provider'              => 'sameday',
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
                'shipping_cost'         => $cost,
                'reference'             => $order->number,
                'observation'           => 'AWB creat din site (plugin Sameday) — importat automat în ERP',
            ]);
            $created++;
        }

        $this->info("✓ {$created} AWB-uri noi importate de pe site.");
        return self::SUCCESS;
    }
}
