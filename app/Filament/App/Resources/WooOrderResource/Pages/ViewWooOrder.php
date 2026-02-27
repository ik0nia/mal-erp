<?php

namespace App\Filament\App\Resources\WooOrderResource\Pages;

use App\Filament\App\Resources\WooOrderResource;
use App\Models\ProductStock;
use App\Models\SamedayAwb;
use App\Models\WooProduct;
use App\Models\WooOrder;
use App\Services\WooCommerce\WooClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Throwable;

class ViewWooOrder extends ViewRecord
{
    protected static string $resource = WooOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('change_status')
                ->label('Schimbă status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->form([
                    Select::make('status')
                        ->label('Status nou')
                        ->options(WooOrder::STATUS_LABELS)
                        ->default(fn (): string => (string) $this->record->status)
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    /** @var WooOrder $order */
                    $order = $this->record;

                    try {
                        $client = new WooClient($order->connection);
                        $client->updateOrderStatus((int) $order->woo_id, $data['status']);

                        $order->update(['status' => $data['status']]);

                        Notification::make()->success()->title('Status actualizat')->send();
                        $this->refreshFormData(['status']);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Eroare')->body($e->getMessage())->send();
                    }
                }),

            Action::make('add_note')
                ->label('Adaugă notă')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('gray')
                ->form([
                    Textarea::make('note')
                        ->label('Notă')
                        ->required()
                        ->rows(3),
                    Toggle::make('customer_note')
                        ->label('Vizibil pentru client')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    /** @var WooOrder $order */
                    $order = $this->record;

                    try {
                        $client = new WooClient($order->connection);
                        $client->addOrderNote((int) $order->woo_id, $data['note'], (bool) ($data['customer_note'] ?? false));

                        Notification::make()->success()->title('Notă adăugată')->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Eroare')->body($e->getMessage())->send();
                    }
                }),

            Action::make('resync')
                ->label('Resync')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Resincronizare comandă')
                ->modalDescription('Datele comenzii vor fi actualizate din WooCommerce.')
                ->action(function (): void {
                    /** @var WooOrder $order */
                    $order = $this->record;

                    try {
                        $client = new WooClient($order->connection);
                        $raw    = $client->getOrder((int) $order->woo_id);

                        if (empty($raw)) {
                            Notification::make()->warning()->title('Comandă negăsită în WooCommerce')->send();

                            return;
                        }

                        $order->update([
                            'number'               => (string) ($raw['number'] ?? $order->number),
                            'status'               => (string) ($raw['status'] ?? $order->status),
                            'billing'              => $raw['billing'] ?? $order->billing,
                            'shipping'             => $raw['shipping'] ?? $order->shipping,
                            'payment_method'       => (string) ($raw['payment_method'] ?? '') ?: $order->payment_method,
                            'payment_method_title' => (string) ($raw['payment_method_title'] ?? '') ?: $order->payment_method_title,
                            'shipping_total'       => (float) ($raw['shipping_total'] ?? $order->shipping_total),
                            'discount_total'       => (float) ($raw['discount_total'] ?? $order->discount_total),
                            'tax_total'            => (float) ($raw['total_tax'] ?? $order->tax_total),
                            'total'                => (float) ($raw['total'] ?? $order->total),
                            'customer_note'        => (string) ($raw['customer_note'] ?? '') ?: null,
                            'date_paid'            => filled($raw['date_paid'] ?? null) ? \Carbon\Carbon::parse($raw['date_paid'])->toDateTimeString() : $order->date_paid,
                            'date_completed'       => filled($raw['date_completed'] ?? null) ? \Carbon\Carbon::parse($raw['date_completed'])->toDateTimeString() : $order->date_completed,
                            'data'                 => $raw,
                        ]);

                        // Re-sync items
                        $order->items()->delete();
                        foreach ($raw['line_items'] ?? [] as $item) {
                            $order->items()->create([
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

                        Notification::make()->success()->title('Comandă resincronizată')->send();
                        $this->refreshFormData(['status', 'total']);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Eroare resync')->body($e->getMessage())->send();
                    }
                }),

            Action::make('check_stock')
                ->label('Verificare stoc')
                ->icon('heroicon-o-cube')
                ->color('primary')
                ->modalHeading(fn (): string => 'Stoc curent – Comanda #'.($this->record->number ?? ''))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Închide')
                ->modalContent(function (): HtmlString {
                    /** @var WooOrder $order */
                    $order = $this->record;

                    $locationId = (int) $order->location_id;
                    $items      = $order->items()->get();

                    if ($items->isEmpty()) {
                        return new HtmlString('<p class="text-sm text-gray-500">Nu există produse pe această comandă.</p>');
                    }

                    // woo_order_items.woo_product_id = WooCommerce woo_id
                    // product_stocks.woo_product_id  = local woo_products.id
                    // So we need to map woo_id → local id first
                    $wooIds = $items->pluck('woo_product_id')->filter()->unique()->values();

                    // woo_id → local id map
                    $localIdByWooId = WooProduct::query()
                        ->whereIn('woo_id', $wooIds)
                        ->pluck('id', 'woo_id');  // [woo_id => local_id]

                    $localIds = $localIdByWooId->values();

                    $stocks = ProductStock::query()
                        ->whereIn('woo_product_id', $localIds)
                        ->when($locationId > 0, fn ($q) => $q->where('location_id', $locationId))
                        ->get()
                        ->keyBy('woo_product_id');  // keyed by local_id

                    // Remap: woo_id → stock record
                    $stockByWooId = $wooIds->mapWithKeys(function (int $wooId) use ($localIdByWooId, $stocks) {
                        $localId = $localIdByWooId->get($wooId);
                        return [$wooId => $localId ? $stocks->get($localId) : null];
                    });

                    $rows = '';
                    foreach ($items as $item) {
                        $stock = $stockByWooId->get((int) $item->woo_product_id);
                        $stockQty = $stock ? (float) $stock->quantity : null;

                        $stockLabel = $stockQty === null
                            ? '<span class="text-gray-400">–</span>'
                            : (string) number_format($stockQty, 0);

                        $ordered = (int) $item->quantity;

                        $color = 'text-gray-700';
                        if ($stockQty !== null) {
                            $color = $stockQty >= $ordered ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold';
                        }

                        $rows .= '<tr class="border-b last:border-0">'
                            .'<td class="py-2 pr-4 text-sm">'.\Illuminate\Support\Str::limit((string) $item->name, 50).'</td>'
                            .'<td class="py-2 pr-4 text-sm text-gray-500">'.e((string) ($item->sku ?? '–')).'</td>'
                            .'<td class="py-2 pr-4 text-sm text-center">'.$ordered.'</td>'
                            .'<td class="py-2 text-sm text-center '.$color.'">'.$stockLabel.'</td>'
                            .'</tr>';
                    }

                    return new HtmlString('
                        <table class="w-full">
                            <thead>
                                <tr class="border-b text-xs text-gray-400 uppercase">
                                    <th class="pb-2 pr-4 text-left font-medium">Produs</th>
                                    <th class="pb-2 pr-4 text-left font-medium">SKU</th>
                                    <th class="pb-2 pr-4 text-center font-medium">Comandat</th>
                                    <th class="pb-2 text-center font-medium">Stoc curent</th>
                                </tr>
                            </thead>
                            <tbody>'.$rows.'</tbody>
                        </table>
                    ');
                }),

            Action::make('create_awb')
                ->label('Creare AWB Sameday')
                ->icon('heroicon-o-truck')
                ->color('success')
                ->url(fn (): string => $this->buildCreateAwbUrl())
                ->openUrlInNewTab(false),
        ];
    }

    private function buildCreateAwbUrl(): string
    {
        /** @var WooOrder $order */
        $order = $this->record;

        $params = array_filter([
            'woo_order_id'      => $order->id,
            'recipient_name'    => $order->customer_name,
            'recipient_phone'   => $order->customer_phone,
            'recipient_email'   => $order->customer_email,
            'recipient_address' => (string) data_get($order->shipping, 'address_1', data_get($order->billing, 'address_1', '')),
            'recipient_city'    => (string) data_get($order->shipping, 'city', data_get($order->billing, 'city', '')),
            'recipient_county'  => (string) data_get($order->shipping, 'state', data_get($order->billing, 'state', '')),
            'recipient_postal_code' => (string) data_get($order->shipping, 'postcode', data_get($order->billing, 'postcode', '')),
            'cod_amount'        => $order->payment_method === 'cod' ? (string) $order->total : null,
            'reference'         => $order->number,
        ]);

        return route('filament.app.resources.sameday-awbs.create').'?'.http_build_query($params);
    }
}
