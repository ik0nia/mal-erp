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
