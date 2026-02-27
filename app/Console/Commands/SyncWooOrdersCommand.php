<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooOrder;
use App\Models\WooOrderItem;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Throwable;

class SyncWooOrdersCommand extends Command
{
    protected $signature = 'woo:sync-orders
                            {--connection= : ID conexiune specifică (dacă lipsește, toate conexiunile WooCommerce active)}
                            {--pages=5 : Număr pagini de fetch (100 comenzi/pagină)}
                            {--status= : Filtrează după status (ex: processing)}';

    protected $description = 'Sincronizează comenzile WooCommerce local';

    public function handle(): int
    {
        $connections = $this->resolveConnections();

        if ($connections->isEmpty()) {
            $this->warn('Nu există conexiuni WooCommerce active.');

            return self::SUCCESS;
        }

        $pages  = max(1, (int) $this->option('pages'));
        $status = (string) ($this->option('status') ?? '');

        foreach ($connections as $connection) {
            $this->info("Conexiune: {$connection->name} (#{$connection->id})");
            $this->syncConnection($connection, $pages, $status);
        }

        return self::SUCCESS;
    }

    private function syncConnection(IntegrationConnection $connection, int $pages, string $status): void
    {
        $client      = new WooClient($connection);
        $locationId  = $connection->location_id;
        $totalSynced = 0;

        $params = [];
        if ($status !== '') {
            $params['status'] = $status;
        }

        for ($page = 1; $page <= $pages; $page++) {
            try {
                $orders = $client->getOrders($page, 100, $params);
            } catch (Throwable $e) {
                $this->error("  Pagina {$page}: {$e->getMessage()}");
                break;
            }

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $raw) {
                $this->upsertOrder($connection->id, $locationId, $raw);
                $totalSynced++;
            }

            $this->line("  Pagina {$page}: ".count($orders).' comenzi');

            if (count($orders) < 100) {
                break;
            }
        }

        $this->info("  Total sincronizate: {$totalSynced}");
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function upsertOrder(int $connectionId, ?int $locationId, array $raw): void
    {
        $wooId = (int) ($raw['id'] ?? 0);
        if ($wooId <= 0) {
            return;
        }

        $orderData = [
            'connection_id'        => $connectionId,
            'location_id'          => $locationId,
            'number'               => (string) ($raw['number'] ?? ''),
            'status'               => (string) ($raw['status'] ?? 'pending'),
            'currency'             => (string) ($raw['currency'] ?? 'RON'),
            'customer_note'        => (string) ($raw['customer_note'] ?? '') ?: null,
            'billing'              => $raw['billing'] ?? null,
            'shipping'             => $raw['shipping'] ?? null,
            'payment_method'       => (string) ($raw['payment_method'] ?? '') ?: null,
            'payment_method_title' => (string) ($raw['payment_method_title'] ?? '') ?: null,
            'subtotal'             => (float) ($raw['subtotals'] ?? data_get($raw, 'totals.subtotal', 0)),
            'shipping_total'       => (float) ($raw['shipping_total'] ?? 0),
            'discount_total'       => (float) ($raw['discount_total'] ?? 0),
            'fee_total'            => (float) data_get($raw, 'fee_lines.0.total', 0),
            'tax_total'            => (float) ($raw['total_tax'] ?? 0),
            'total'                => (float) ($raw['total'] ?? 0),
            'date_paid'            => $this->parseDate($raw['date_paid'] ?? null),
            'date_completed'       => $this->parseDate($raw['date_completed'] ?? null),
            'order_date'           => $this->parseDate($raw['date_created'] ?? null) ?? now(),
            'data'                 => $raw,
        ];

        // Compute subtotal from line items if not directly available
        if ((float) $orderData['subtotal'] === 0.0) {
            $subtotal = 0.0;
            foreach ($raw['line_items'] ?? [] as $item) {
                $subtotal += (float) ($item['subtotal'] ?? $item['total'] ?? 0);
            }
            $orderData['subtotal'] = $subtotal;
        }

        /** @var WooOrder $order */
        $order = WooOrder::updateOrCreate(
            ['connection_id' => $connectionId, 'woo_id' => $wooId],
            $orderData,
        );

        $this->syncItems($order, $raw['line_items'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function syncItems(WooOrder $order, array $lineItems): void
    {
        $order->items()->delete();

        foreach ($lineItems as $item) {
            WooOrderItem::create([
                'order_id'       => $order->id,
                'woo_item_id'    => (int) ($item['id'] ?? 0) ?: null,
                'woo_product_id' => (int) ($item['product_id'] ?? 0) ?: null,
                'name'           => (string) ($item['name'] ?? ''),
                'sku'            => (string) ($item['sku'] ?? '') ?: null,
                'quantity'       => (int) ($item['quantity'] ?? 1),
                'price'          => (float) ($item['price'] ?? 0),
                'subtotal'       => (float) ($item['subtotal'] ?? 0),
                'total'          => (float) ($item['total'] ?? 0),
                'tax'            => (float) ($item['total_tax'] ?? 0),
                'data'           => $item,
            ]);
        }
    }

    private function parseDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, IntegrationConnection>
     */
    private function resolveConnections(): \Illuminate\Database\Eloquent\Collection
    {
        $connectionId = $this->option('connection');

        $query = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
            ->where('is_active', true);

        if ($connectionId) {
            $query->where('id', (int) $connectionId);
        }

        return $query->get();
    }
}
