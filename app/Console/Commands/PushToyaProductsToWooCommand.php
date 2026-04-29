<?php

namespace App\Console\Commands;

use App\Jobs\PushToyaImageToWooJob;
use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PushToyaProductsToWooCommand extends Command
{
    protected $signature = 'toya:push-to-woo
                            {--batch=20 : Produse per batch}
                            {--sleep=20 : Secunde pauza intre batch-uri}
                            {--limit= : Limita totala de produse (omit = toate)}
                            {--dry-run : Afiseaza payload fara a trimite}
                            {--skip-images : Nu trimite imagini (util pentru primul import)}
                            {--sku= : Publica doar SKU-ul specificat}
                            {--min-id= : Proceseaza doar produse cu id >= valoare}
                            {--max-id= : Proceseaza doar produse cu id <= valoare}';

    protected $description = 'Publică produsele Toya (fără woo_id) în WooCommerce cu backorders activate';

    private WooClient $client;

    public function handle(): int
    {
        $conn = IntegrationConnection::where('provider', 'woocommerce')->firstOrFail();
        $this->client = new WooClient($conn);

        $batchSize  = (int) $this->option('batch');
        $sleep      = (int) $this->option('sleep');
        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun     = (bool) $this->option('dry-run');
        $skipImages = (bool) $this->option('skip-images');
        $sku        = $this->option('sku');
        $minId      = $this->option('min-id') ? (int) $this->option('min-id') : null;
        $maxId      = $this->option('max-id') ? (int) $this->option('max-id') : null;

        $query = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNull('woo_id')
            ->whereNotNull('name')
            ->whereNotNull('regular_price')
            ->where('regular_price', '>', 0);

        if ($sku) {
            $query->where('sku', $sku);
        }

        if ($minId) {
            $query->where('id', '>=', $minId);
        }

        if ($maxId) {
            $query->where('id', '<=', $maxId);
        }

        $total = $query->count();

        if ($limit) {
            $total = min($total, $limit);
        }

        $this->info("Produse de publicat: {$total} | Batch: {$batchSize} | Pauza: {$sleep}s" . ($skipImages ? ' | fara imagini' : ''));

        if ($total === 0) {
            $this->warn('Niciun produs de publicat.');
            return self::SUCCESS;
        }

        $processed = 0;
        $created   = 0;
        $errors    = 0;

        $query->orderBy('id')->chunkById($batchSize, function ($products) use (
            $batchSize, $sleep, $dryRun, $skipImages, $limit, &$processed, &$created, &$errors
        ) {
            if ($limit && $processed >= $limit) {
                return false; // stop
            }

            $payloads = [];
            $idMap    = []; // sku => local_id

            foreach ($products as $product) {
                if ($limit && $processed >= $limit) {
                    break;
                }

                $payload = $this->buildPayload($product, $skipImages);
                if ($payload === null) {
                    continue;
                }

                $payloads[]            = $payload;
                $idMap[$product->sku]  = $product->id;
                $processed++;
            }

            if (empty($payloads)) {
                return true;
            }

            if ($dryRun) {
                $this->line(json_encode($payloads[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info("--dry-run: batch de " . count($payloads) . " produse NU s-a trimis.");
                return true;
            }

            try {
                $result = $this->client->createProductsBatch($payloads);

                foreach ($result['created'] as $item) {
                    $sku = $item['sku'] ?? null;
                    if ($sku && isset($idMap[$sku])) {
                        $localId = $idMap[$sku];
                        WooProduct::where('id', $localId)->update([
                            'woo_id'         => $item['id'],
                            'status'         => 'publish',
                            'is_placeholder' => false,
                        ]);
                        $created++;

                        // If image already downloaded locally, push it now
                        $p = WooProduct::find($localId);
                        if ($p && $p->main_image_url && str_contains($p->main_image_url, config('app.url'))) {
                            PushToyaImageToWooJob::dispatch($localId, $item['id'])
                                ->onQueue('default');
                        }
                    }
                }

                foreach ($result['errors'] as $err) {
                    $errData = $err['error'] ?? $err;
                    $code    = $errData['code'] ?? '';

                    // SKU already exists → recover woo_id
                    if (in_array($code, ['product_invalid_sku', 'woocommerce_rest_product_not_created'])) {
                        $recovered = false;

                        // Try resource_id first
                        if (isset($errData['data']['resource_id'])) {
                            $resourceId = (int) $errData['data']['resource_id'];
                            try {
                                $wooProduct = $this->client->getProduct($resourceId);
                                $existSku   = $wooProduct['sku'] ?? '';
                                if ($existSku && isset($idMap[$existSku])) {
                                    $localId = $idMap[$existSku];
                                    WooProduct::where('id', $localId)->whereNull('woo_id')
                                        ->update(['woo_id' => $resourceId, 'status' => 'publish', 'is_placeholder' => false]);
                                    $p = WooProduct::find($localId);
                                    if ($p?->main_image_url && str_contains($p->main_image_url, config('app.url'))) {
                                        PushToyaImageToWooJob::dispatch($localId, $resourceId)->onQueue('default');
                                    }
                                    $created++;
                                    $recovered = true;
                                }
                            } catch (\Throwable) {}
                        }

                        // Fallback: extract SKU from error message and search WooCommerce
                        if (! $recovered) {
                            preg_match('/SKU \(([^)]+)\)/', $errData['message'] ?? '', $m);
                            $msgSku = $m[1] ?? null;
                            if ($msgSku && isset($idMap[$msgSku])) {
                                try {
                                    $found = $this->client->findProductBySku($msgSku);
                                    if ($found && ! empty($found['id'])) {
                                        $resourceId = $found['id'];
                                        $localId    = $idMap[$msgSku];
                                        WooProduct::where('id', $localId)->whereNull('woo_id')
                                            ->update(['woo_id' => $resourceId, 'status' => 'publish', 'is_placeholder' => false]);
                                        $p = WooProduct::find($localId);
                                        if ($p?->main_image_url && str_contains($p->main_image_url, config('app.url'))) {
                                            PushToyaImageToWooJob::dispatch($localId, $resourceId)->onQueue('default');
                                        }
                                        $created++;
                                        $recovered = true;
                                    }
                                } catch (\Throwable) {}
                            }
                        }

                        if ($recovered) {
                            continue;
                        }
                    }

                    $this->warn("Eroare: " . json_encode($err, JSON_UNESCAPED_UNICODE));
                    $errors++;
                }

                $this->info("Batch OK: +" . count($result['created']) . " create, {$errors} erori | Total: {$processed}/{$this->getTotalRemaining()}");

            } catch (\Throwable $e) {
                $this->error("Batch failed: " . $e->getMessage());
                $errors += count($payloads);
            }

            if ($sleep > 0) {
                sleep($sleep);
            }

            return true;
        });

        $this->info("\nFinalizat. Create: {$created} | Erori: {$errors} | Procesate: {$processed}");

        return self::SUCCESS;
    }

    private function getTotalRemaining(): int
    {
        return WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNull('woo_id')
            ->whereNotNull('name')
            ->whereNotNull('regular_price')
            ->where('regular_price', '>', 0)
            ->count();
    }

    private function buildPayload(WooProduct $product, bool $skipImages = false): ?array
    {
        // Categorii
        $categories = DB::table('woo_product_category as wpc')
            ->join('woo_categories as wc', 'wc.id', '=', 'wpc.woo_category_id')
            ->where('wpc.woo_product_id', $product->id)
            ->where('wc.woo_id', '>', 0)
            ->pluck('wc.woo_id')
            ->map(fn ($id) => ['id' => (int) $id])
            ->values()
            ->all();

        // Atribute (inclusiv Brand)
        $rawAttrs = DB::table('woo_product_attributes')
            ->where('woo_product_id', $product->id)
            ->orderBy('position')
            ->get(['name', 'value', 'is_visible', 'position']);

        $attrsGrouped = [];
        foreach ($rawAttrs as $attr) {
            $key = mb_strtolower(trim($attr->name));
            if (! isset($attrsGrouped[$key])) {
                $attrsGrouped[$key] = [
                    'name'      => $attr->name,
                    'visible'   => (bool) $attr->is_visible,
                    'variation' => false,
                    'position'  => (int) $attr->position,
                    'options'   => [],
                ];
            }
            if ($attr->value !== null && $attr->value !== '') {
                $attrsGrouped[$key]['options'][] = $attr->value;
            }
        }
        $attributes = array_values($attrsGrouped);

        // Imagine — include ONLY local images (already on our server); skip pim.toya.pl links
        $images = [];
        $appUrl = config('app.url');
        if (! $skipImages && $product->main_image_url && str_contains($product->main_image_url, $appUrl)) {
            $seen   = [$product->main_image_url => true];
            $images[] = ['src' => $product->main_image_url];

            // Include gallery images
            $galleryUrls = \DB::table('product_images')
                ->where('woo_product_id', $product->id)
                ->where('source', 'toya')
                ->whereNotNull('local_path')
                ->where('local_path', 'like', '%' . $appUrl . '%')
                ->orderBy('sort_order')
                ->pluck('local_path')
                ->toArray();

            foreach ($galleryUrls as $url) {
                if (! isset($seen[$url])) {
                    $images[]    = ['src' => $url];
                    $seen[$url]  = true;
                }
            }
        }

        // Dimensiuni
        $dimensions = ['length' => '', 'width' => '', 'height' => ''];
        if ($product->dim_length || $product->dim_width || $product->dim_height) {
            $dimensions = [
                'length' => (string) ($product->dim_length ?? ''),
                'width'  => (string) ($product->dim_width  ?? ''),
                'height' => (string) ($product->dim_height ?? ''),
            ];
        }

        // on_demand_label → short description dacă nu există
        $shortDesc = $product->short_description ?? '';
        if (! $shortDesc && $product->on_demand_label) {
            $shortDesc = $product->on_demand_label;
        }

        return [
            'name'             => $product->name,
            'type'             => 'simple',
            'status'           => 'publish',
            'sku'              => $product->sku,
            'slug'             => $product->slug,
            'description'      => $product->description ?? '',
            'short_description' => $shortDesc,
            'regular_price'    => (string) ($product->regular_price ?? ''),
            'manage_stock'     => true,
            'backorders'       => 'yes',      // permite comenzi fără stoc
            'stock_quantity'   => 0,
            'stock_status'     => 'onbackorder',
            'weight'           => $product->weight ? (string) $product->weight : '',
            'dimensions'       => $dimensions,
            'categories'       => $categories,
            'images'           => $images,
            'attributes'       => $attributes,
            'tax_status'       => 'taxable',
            'tax_class'        => '',
            'meta_data'        => [
                ['key' => '_toya_source',       'value' => '1'],
                ['key' => '_procurement_type',  'value' => 'on_demand'],
                ['key' => '_toya_sku',          'value' => $product->sku],
            ],
        ];
    }
}
