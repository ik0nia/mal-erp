<?php

namespace App\Services\WooCommerce;

use App\Models\AppSetting;
use App\Models\IntegrationConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client HTTP pentru plugin-ul WordPress "malinco-erp-bridge".
 * Apelează endpoint-urile custom de sub /wp-json/malinco-erp/v1/.
 */
class WooPluginClient
{
    private readonly string $baseUrl;

    private readonly string $apiKey;

    private const NAMESPACE = 'wp-json/malinco-erp/v1';

    private const TIMEOUT = 15;

    public function __construct()
    {
        // URL-ul site-ului WooCommerce (ex: https://malinco.ro)
        $siteUrl = rtrim(
            IntegrationConnection::query()
                ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
                ->where('is_active', true)
                ->value('base_url') ?? '',
            '/'
        );

        $this->baseUrl = $siteUrl . '/' . self::NAMESPACE;
        $this->apiKey  = (string) AppSetting::getEncrypted(AppSetting::KEY_WOO_PLUGIN_API_KEY);
    }

    /**
     * Verifică dacă plugin-ul e disponibil și returnează versiunea.
     *
     * @return array{version: string, status: string}|null  null dacă plugin-ul nu răspunde
     */
    public function getVersion(): ?array
    {
        try {
            $response = $this->http()->get($this->url('version'));
            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable) {
            // Plugin indisponibil
        }

        return null;
    }

    /**
     * Actualizează meta-datele unui produs WooCommerce.
     *
     * @param  int                    $wooId  ID produs în WooCommerce (0 dacă nu se știe)
     * @param  string                 $sku    SKU produs (alternativă la wooId)
     * @param  array<string, mixed>   $meta   cheie → valoare
     */
    public function updateProductMeta(int $wooId, string $sku, array $meta): bool
    {
        if (empty($meta) || $this->apiKey === '') {
            return false;
        }

        try {
            $response = $this->http()->post($this->url('product/meta'), [
                'woo_id' => $wooId,
                'sku'    => $sku,
                'meta'   => $meta,
            ]);

            return $response->successful() && ($response->json('success') === true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Actualizează meta-datele pentru mai multe produse dintr-un singur request.
     *
     * @param  array<int, array{woo_id: int, sku: string, meta: array<string, mixed>}>  $items
     * @return array{updated: int, failed: int, errors: array<int, mixed>}
     */
    public function bulkUpdateProductMeta(array $items): array
    {
        $empty = ['updated' => 0, 'failed' => count($items), 'errors' => []];

        if (empty($items) || $this->apiKey === '') {
            return $empty;
        }

        try {
            $response = $this->http()->post($this->url('product/bulk-meta'), ['items' => $items]);

            if ($response->successful()) {
                return $response->json('results') ?? $empty;
            }
        } catch (Throwable) {
            // Ignorăm excepțiile de rețea
        }

        return $empty;
    }

    /**
     * Declanșează recalcularea disponibilității/prețului pentru una sau mai multe produse.
     * Apelează endpoint-ul /product/recalculate din malinco-availability-pricing.
     *
     * @param  int[]  $productIds  ID-uri WooCommerce (post ID)
     */
    public function recalculateProducts(array $productIds): bool
    {
        if (empty($productIds) || $this->apiKey === '') {
            return false;
        }

        try {
            $response = $this->http()->post($this->url('product/recalculate'), [
                'product_ids' => array_values(array_map('intval', $productIds)),
            ]);

            return $response->successful() && ($response->json('success') === true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Trimite un ZIP cu noua versiune a plugin-ului și declanșează self-update.
     */
    public function deployPlugin(string $zipPath): bool
    {
        if (! file_exists($zipPath) || $this->apiKey === '') {
            return false;
        }

        try {
            $response = Http::withHeader('X-ERP-Api-Key', $this->apiKey)
                ->timeout(60)
                ->attach('plugin', fopen($zipPath, 'r'), 'malinco-erp-bridge.zip')
                ->post($this->url('self-update'));

            return $response->successful() && ($response->json('success') === true);
        } catch (Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------------------

    private function url(string $endpoint): string
    {
        return $this->baseUrl . '/' . ltrim($endpoint, '/');
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeader('X-ERP-Api-Key', $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT);
    }
}
