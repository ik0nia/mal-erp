<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooOrderSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WooWebhookController extends Controller
{
    public function handle(Request $request, IntegrationConnection $connection): Response
    {
        if (! $connection->isWooCommerce() || ! $connection->is_active) {
            return response('Not found', 404);
        }

        $secret = $connection->webhook_secret;

        if (! $secret) {
            return response('Webhook not configured', 400);
        }

        $rawBody   = $request->getContent();
        $signature = trim($request->header('X-WC-Webhook-Signature', ''));
        $topic     = trim($request->header('X-WC-Webhook-Topic', ''));

        // Ping WooCommerce (la salvarea webhook-ului): nu are semnătură, body = "webhook_id=X"
        // Răspundem 200 ca să confirme că URL-ul e valid.
        if ($signature === '' && $topic === '') {
            return response('OK', 200);
        }

        // Verificare semnătură HMAC-SHA256 pentru webhook-urile reale
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        if (! hash_equals($expected, $signature)) {
            Log::warning('WooWebhook: signature mismatch', [
                'connection_id' => $connection->id,
                'topic'         => $topic,
                'body_length'   => strlen($rawBody),
            ]);

            return response('Invalid signature', 401);
        }

        $data = $request->json()->all();

        if (in_array($topic, ['product.updated', 'product.created'], true)) {
            try {
                return $this->syncProduct($connection, $data);
            } catch (\Throwable $e) {
                Log::error('WooWebhook: syncProduct failed', [
                    'connection_id' => $connection->id,
                    'woo_id'        => $data['id'] ?? null,
                    'error'         => $e->getMessage(),
                ]);

                // Returnăm 200 să nu dezactiveze WooCommerce webhook-ul — cron-ul de fallback compensează.
                return response('OK', 200);
            }
        }

        if (in_array($topic, ['order.created', 'order.updated'], true)) {
            try {
                return $this->syncOrder($connection, $data);
            } catch (\Throwable $e) {
                Log::error('WooWebhook: syncOrder failed', [
                    'connection_id' => $connection->id,
                    'woo_id'        => $data['id'] ?? null,
                    'error'         => $e->getMessage(),
                ]);

                return response('OK', 200);
            }
        }

        return response('OK', 200);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProduct(IntegrationConnection $connection, array $data): Response
    {
        $wooId = (int) ($data['id'] ?? 0);

        if ($wooId === 0) {
            return response('Missing product ID', 400);
        }

        $product = WooProduct::where('connection_id', $connection->id)
            ->where('woo_id', $wooId)
            ->first();

        if (! $product) {
            // Produs necunoscut — returnăm 200 să nu retriggere WooCommerce
            return response('OK', 200);
        }

        $dim = $data['dimensions'] ?? [];

        // Brand: din câmpul brands[] (plugin) sau din atributul "Brand"
        $brand = null;
        if (! empty($data['brands'][0]['name'])) {
            $brand = $data['brands'][0]['name'];
        } else {
            foreach ($data['attributes'] ?? [] as $attr) {
                if (strtolower($attr['name'] ?? '') === 'brand' && ! empty($attr['options'][0])) {
                    $brand = $attr['options'][0];
                    break;
                }
            }
        }

        $product->update([
            'name'              => $data['name'] ?? $product->name,
            'slug'              => filled($data['slug'] ?? '') ? $data['slug'] : $product->slug,
            'type'              => $data['type'] ?? $product->type,
            'status'            => $data['status'] ?? $product->status,
            'sku'               => filled($data['sku'] ?? '') ? $data['sku'] : $product->sku,
            'description'       => $data['description'] ?? $product->description,
            'short_description' => $data['short_description'] ?? $product->short_description,
            'regular_price'     => isset($data['regular_price']) && $data['regular_price'] !== ''
                                    ? (float) $data['regular_price']
                                    : $product->regular_price,
            'sale_price'        => isset($data['sale_price']) && $data['sale_price'] !== ''
                                    ? (float) $data['sale_price']
                                    : null,
            'price'             => isset($data['price']) && $data['price'] !== ''
                                    ? (float) $data['price']
                                    : $product->price,
            'stock_status'      => $data['stock_status'] ?? $product->stock_status,
            'manage_stock'      => $data['manage_stock'] ?? $product->manage_stock,
            'weight'            => isset($data['weight']) && $data['weight'] !== ''
                                    ? (float) $data['weight']
                                    : $product->weight,
            'dim_length'        => isset($dim['length']) && $dim['length'] !== ''
                                    ? (float) $dim['length']
                                    : $product->dim_length,
            'dim_width'         => isset($dim['width']) && $dim['width'] !== ''
                                    ? (float) $dim['width']
                                    : $product->dim_width,
            'dim_height'        => isset($dim['height']) && $dim['height'] !== ''
                                    ? (float) $dim['height']
                                    : $product->dim_height,
            'brand'             => $brand ?? $product->brand,
            'main_image_url'    => data_get($data, 'images.0.src') ?? $product->main_image_url,
            'data'              => $data,
        ]);

        // Sincronizare categorii
        $wooIds = array_values(array_filter(array_map('intval', array_column($data['categories'] ?? [], 'id'))));
        if (! empty($wooIds)) {
            // Categorii care nu există încă în ERP — le aducem din API înainte de sync
            $existingWooIds = \App\Models\WooCategory::where('connection_id', $connection->id)
                ->whereIn('woo_id', $wooIds)
                ->pluck('woo_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $missingWooIds = array_diff($wooIds, $existingWooIds);

            if (! empty($missingWooIds)) {
                $client = new \App\Services\WooCommerce\WooClient($connection);

                foreach ($missingWooIds as $missingWooId) {
                    try {
                        $cat = $client->getCategory($missingWooId);

                        if (empty($cat['id'])) {
                            continue;
                        }

                        $parentWooId = (int) ($cat['parent'] ?? 0) ?: null;
                        $parentId    = $parentWooId
                            ? \App\Models\WooCategory::where('connection_id', $connection->id)
                                ->where('woo_id', $parentWooId)
                                ->value('id')
                            : null;

                        \App\Models\WooCategory::updateOrCreate(
                            ['connection_id' => $connection->id, 'woo_id' => (int) $cat['id']],
                            [
                                'name'          => html_entity_decode((string) ($cat['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                                'slug'          => filled($cat['slug'] ?? '') ? $cat['slug'] : null,
                                'description'   => filled($cat['description'] ?? '') ? $cat['description'] : null,
                                'parent_woo_id' => $parentWooId,
                                'parent_id'     => $parentId,
                                'image_url'     => data_get($cat, 'image.src') ?: null,
                                'menu_order'    => isset($cat['menu_order']) ? (int) $cat['menu_order'] : null,
                                'count'         => isset($cat['count']) ? (int) $cat['count'] : null,
                                'data'          => $cat,
                            ],
                        );
                    } catch (\Throwable) {
                        // Dacă nu putem aduce categoria, continuăm — nu blocăm sync-ul produsului
                    }
                }
            }

            $categoryIds = \App\Models\WooCategory::where('connection_id', $connection->id)
                ->whereIn('woo_id', $wooIds)
                ->pluck('id');

            $product->categories()->sync($categoryIds);
        }

        // Sincronizare furnizor din meta_data
        $metaData     = collect($data['meta_data'] ?? []);
        $furnizorNume = data_get($metaData->firstWhere('key', '_furnizor_nume'), 'value')
                     ?? data_get($metaData->firstWhere('key', '_supplier_name'), 'value');
        $supplierSku  = data_get($metaData->firstWhere('key', '_supplier_sku'), 'value');

        if (filled($furnizorNume)) {
            $supplier = \App\Models\Supplier::whereRaw('LOWER(name) = LOWER(?)', [trim($furnizorNume)])->first();

            if ($supplier) {
                DB::table('product_suppliers')->upsert(
                    [
                        'woo_product_id' => $product->id,
                        'supplier_id'    => $supplier->id,
                        'supplier_sku'   => $supplierSku ?: null,
                        'is_preferred'   => true,
                        'currency'       => 'RON',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ],
                    ['woo_product_id', 'supplier_id'],
                    ['supplier_sku', 'is_preferred', 'updated_at'],
                );
            }
        }

        return response('OK', 200);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncOrder(IntegrationConnection $connection, array $data): Response
    {
        $wooId = (int) ($data['id'] ?? 0);

        if ($wooId === 0) {
            return response('Missing order ID', 400);
        }

        (new WooOrderSyncService())->upsertOrder($connection->id, $connection->location_id, $data);

        return response('OK', 200);
    }
}
