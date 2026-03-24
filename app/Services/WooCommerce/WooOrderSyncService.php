<?php

namespace App\Services\WooCommerce;

use App\Models\PurchaseRequest;
use App\Models\WooOrder;
use App\Models\WooOrderItem;
use Illuminate\Support\Facades\Log;
use Throwable;

class WooOrderSyncService
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function upsertOrder(int $connectionId, ?int $locationId, array $raw): ?WooOrder
    {
        $wooId = (int) ($raw['id'] ?? 0);
        if ($wooId <= 0) {
            return null;
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

        // Auto-creare PNR pentru produse "la comandă" dacă e comandă nouă (pending/processing)
        if ($order->wasRecentlyCreated && in_array($order->status, ['pending', 'processing', 'on-hold'])) {
            try {
                PurchaseRequest::createFromWooOrder($order);
            } catch (Throwable) {
                // Nu blocăm sync-ul dacă crearea PNR eșuează
            }
        }

        return $order;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function syncItems(WooOrder $order, array $lineItems): void
    {
        $existingCount = $order->items()->count();

        // SAFEGUARD: Nu șterge itemele dacă WooCommerce a returnat o listă goală
        // dar comanda are deja iteme salvate local — semn că datele sunt incomplete.
        if (count($lineItems) === 0 && $existingCount > 0) {
            Log::warning('WooOrderSyncService: safeguard activat — line_items gol pentru comanda #' . $order->woo_id . ' care are ' . $existingCount . ' iteme locale. Ștergere anulată.');

            return;
        }

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

    public function parseDate(mixed $value): ?string
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
}
