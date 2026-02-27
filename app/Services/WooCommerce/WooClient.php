<?php

namespace App\Services\WooCommerce;

use App\Models\IntegrationConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class WooClient
{
    private readonly string $apiBase;

    private readonly PendingRequest $http;

    public function __construct(private readonly IntegrationConnection $connection)
    {
        $this->apiBase = rtrim((string) $this->connection->base_url, '/').'/wp-json/wc/v3';
        $this->http = Http::acceptJson()
            ->asJson()
            ->withBasicAuth(
                (string) $this->connection->consumer_key,
                (string) $this->connection->consumer_secret,
            )
            ->timeout($this->connection->resolveTimeoutSeconds())
            ->withOptions([
                'verify' => $this->connection->verify_ssl,
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(int $page, int $perPage = 100): array
    {
        return $this->get('products/categories', [
            'page' => $page,
            'per_page' => max(1, min(100, $perPage)),
            'orderby' => 'id',
            'order' => 'asc',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCategory(int $id): array
    {
        return $this->get("products/categories/{$id}");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(int $page, int $perPage = 100): array
    {
        return $this->get('products', [
            'page' => $page,
            'per_page' => max(1, min(100, $perPage)),
            'orderby' => 'id',
            'order' => 'asc',
        ]);
    }

    /**
     * @throws Throwable
     */
    public function testConnection(): bool
    {
        try {
            $this->get('system_status');

            return true;
        } catch (Throwable) {
            // Fallback endpoint for stores that restrict system_status.
            $this->get('products', ['per_page' => 1]);

            return true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function updateProductPrice(int $productId, string $regularPrice): array
    {
        $response = $this->http->put(
            $this->apiBase.'/products/'.max(1, $productId),
            [
                'regular_price' => $regularPrice,
            ],
        );
        $response->throw();

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<int, array{id:int, regular_price:string}>  $updates
     * @return array<string, mixed>
     */
    public function updateProductPricesBatch(array $updates): array
    {
        $payload = [];

        foreach ($updates as $update) {
            $productId = (int) ($update['id'] ?? 0);
            $regularPrice = trim((string) ($update['regular_price'] ?? ''));

            if ($productId <= 0 || $regularPrice === '') {
                continue;
            }

            $payload[] = [
                'id' => $productId,
                'regular_price' => $regularPrice,
            ];
        }

        if ($payload === []) {
            return [];
        }

        $response = $this->http->post(
            $this->apiBase.'/products/batch',
            [
                'update' => $payload,
            ],
        );
        $response->throw();

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set the product image to the given URL.
     * WooCommerce will sideload (download + store) the image and return its new media URL.
     *
     * @return string  The new image URL after sideloading (typically on the WooCommerce domain).
     */
    public function sideloadProductImage(int $wooProductId, string $imageUrl): string
    {
        $response = $this->http->put(
            $this->apiBase.'/products/'.max(1, $wooProductId),
            [
                'images' => [['src' => $imageUrl]],
            ],
        );
        $response->throw();

        $payload = $response->json();
        $newSrc  = data_get($payload, 'images.0.src', '');

        return is_string($newSrc) ? $newSrc : '';
    }

    /**
     * Create products in bulk via the WooCommerce batch API.
     * Returns the list of created product objects (each contains 'id', 'sku', etc.).
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    /**
     * Create products in bulk via the WooCommerce batch API.
     * Returns ['created' => [...], 'errors' => [...]] where errors are per-item failures.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array{created: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     */
    public function createProductsBatch(array $products): array
    {
        if ($products === []) {
            return ['created' => [], 'errors' => []];
        }

        $response = $this->http->post(
            $this->apiBase.'/products/batch',
            ['create' => $products],
        );
        $response->throw();

        $payload = $response->json();

        $created = [];
        $errors  = [];

        foreach ($payload['create'] ?? [] as $item) {
            if (isset($item['error']) || isset($item['code'])) {
                $errors[] = $item;
            } elseif (isset($item['id']) && (int) $item['id'] > 0) {
                $created[] = $item;
            } else {
                $errors[] = $item;
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function getOrders(int $page, int $perPage = 100, array $params = []): array
    {
        return $this->get('orders', array_merge([
            'page'     => $page,
            'per_page' => max(1, min(100, $perPage)),
            'orderby'  => 'date',
            'order'    => 'desc',
        ], $params));
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(int $orderId): array
    {
        $response = $this->http->get($this->apiBase.'/orders/'.max(1, $orderId));
        $response->throw();

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateOrderStatus(int $orderId, string $status): array
    {
        $response = $this->http->put(
            $this->apiBase.'/orders/'.max(1, $orderId),
            ['status' => $status],
        );
        $response->throw();

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function addOrderNote(int $orderId, string $note, bool $customerNote = false): array
    {
        $response = $this->http->post(
            $this->apiBase.'/orders/'.max(1, $orderId).'/notes',
            [
                'note'             => $note,
                'customer_note'    => $customerNote,
            ],
        );
        $response->throw();

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateOrderMeta(int $orderId, string $key, string $value): array
    {
        $response = $this->http->put(
            $this->apiBase.'/orders/'.max(1, $orderId),
            [
                'meta_data' => [['key' => $key, 'value' => $value]],
            ],
        );
        $response->throw();

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function get(string $endpoint, array $query = []): array
    {
        $response = $this->http->get($this->apiBase.'/'.ltrim($endpoint, '/'), $query);
        $response->throw();

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }
}
