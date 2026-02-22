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
