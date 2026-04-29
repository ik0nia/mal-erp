<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooCategory;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportFischerProductsCommand extends Command
{
    protected $signature = 'fischer:import
                            {--batch=20 : Produse per batch WooCommerce}
                            {--sleep=15 : Secunde pauza intre batch-uri}
                            {--limit= : Limita totala}
                            {--dry-run : Afiseaza payload fara a trimite}
                            {--skip-images : Fara imagini}
                            {--json=/tmp/fischer-import-data.json : Calea catre JSON}';

    protected $description = 'Importa produse Fischer din JSON în ERP + WooCommerce';

    private const SUPPLIER_ID   = 75;
    private const CONNECTION_ID = 1;
    private const BRAND_TERM_ID = 1130; // pa_brand: FISCHER
    private const IMAGE_BASE    = 'https://erp.malinco.ro/fischer-images/';
    private const SOURCE        = 'fischer_import';

    private WooClient $woo;

    public function handle(): int
    {
        $jsonPath   = $this->option('json');
        $batchSize  = (int) $this->option('batch');
        $sleep      = (int) $this->option('sleep');
        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun     = (bool) $this->option('dry-run');
        $skipImages = (bool) $this->option('skip-images');

        if (! file_exists($jsonPath)) {
            $this->error("JSON nu există: {$jsonPath}");
            return self::FAILURE;
        }

        $products = json_decode(file_get_contents($jsonPath), true);
        if (! $products) {
            $this->error('JSON invalid sau gol');
            return self::FAILURE;
        }

        $this->info('Produse în JSON: ' . count($products));

        $conn      = IntegrationConnection::where('provider', 'woocommerce')->firstOrFail();
        $this->woo = new WooClient($conn);

        // Step 1: Insert into ERP DB (skip existing)
        $this->info('--- Etapa 1: ERP DB ---');
        $inserted = $this->insertErpRecords($products, $limit);
        $this->info("Inserate în ERP: {$inserted}");

        if ($dryRun) {
            $this->info('--dry-run: Nu continuăm cu WooCommerce.');
            return self::SUCCESS;
        }

        // Step 2: Push to WooCommerce
        $this->info('--- Etapa 2: WooCommerce ---');
        $this->pushToWooCommerce($batchSize, $sleep, $skipImages);

        return self::SUCCESS;
    }

    private function insertErpRecords(array $products, ?int $limit): int
    {
        $inserted = 0;

        foreach ($products as $p) {
            if ($limit && $inserted >= $limit) {
                break;
            }

            $sku = $p['ean'] ?: null; // EAN = SKU în WooCommerce
            $supplierSku = $p['supplier_sku'];

            // Skip if already in ERP
            $exists = DB::table('product_suppliers')
                ->where('supplier_id', self::SUPPLIER_ID)
                ->where('supplier_sku', $supplierSku)
                ->exists();
            if ($exists) {
                continue;
            }

            $name = $p['name'];
            $slug = $this->makeSlug($name, $supplierSku);

            // Main image URL
            $mainImageUrl = null;
            if (! empty($p['main_image'])) {
                $mainImageUrl = self::IMAGE_BASE . $p['main_image'];
            }

            $product = WooProduct::create([
                'connection_id'    => self::CONNECTION_ID,
                'source'           => self::SOURCE,
                'type'             => WooProduct::TYPE_SHOP,
                'status'           => 'publish',
                'is_placeholder'   => false,
                'sku'              => $sku,
                'name'             => $name,
                'slug'             => $slug,
                'description'      => $p['description'] ?: null,
                'regular_price'    => $p['regular_price'] > 0 ? $p['regular_price'] : null,
                'price'            => $p['regular_price'] > 0 ? $p['regular_price'] : null,
                'stock_status'     => 'onbackorder',
                'manage_stock'     => true,
                'main_image_url'   => $mainImageUrl,
                'procurement_type' => WooProduct::PROCUREMENT_ON_DEMAND,
                'brand'            => 'Fischer',
                'data'             => json_encode([
                    'ean'          => $p['ean'],
                    'supplier_sku' => $supplierSku,
                    'woo_cat_id'   => $p['woo_cat_id'],
                    'erp_cat_id'   => $p['erp_cat_id'],
                    'attrs'        => $p['attrs'],
                    'youtube'      => $p['youtube'],
                    'sds_url'      => $p['sds_url'],
                    'gallery'      => $p['gallery_files'],
                    'publish'      => $p['publish'],
                ], JSON_UNESCAPED_UNICODE),
            ]);

            // Category
            if ($p['erp_cat_id']) {
                DB::table('woo_product_category')->insertOrIgnore([
                    'woo_product_id'  => $product->id,
                    'woo_category_id' => $p['erp_cat_id'],
                ]);
            }

            // Supplier record with purchase price
            DB::table('product_suppliers')->insert([
                'woo_product_id'  => $product->id,
                'supplier_id'     => self::SUPPLIER_ID,
                'supplier_sku'    => $supplierSku,
                'purchase_price'  => $p['purchase_price'] > 0 ? $p['purchase_price'] : null,
                'currency'        => 'RON',
                'is_preferred'    => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $inserted++;
        }

        return $inserted;
    }

    private function pushToWooCommerce(int $batchSize, int $sleep, bool $skipImages): void
    {
        $query = WooProduct::where('source', self::SOURCE)
            ->whereNull('woo_id');

        $total = $query->count();
        $this->info("Produse de publicat: {$total}");

        if ($total === 0) {
            $this->warn('Niciun produs de publicat.');
            return;
        }

        $processed = 0;
        $created   = 0;
        $errors    = 0;

        $query->orderBy('id')->chunkById($batchSize, function ($products) use (
            $batchSize, $sleep, $skipImages, &$processed, &$created, &$errors
        ) {
            $payloads = [];
            $slugMap  = []; // slug => local_id
            $skuMap   = []; // sku  => local_id

            foreach ($products as $product) {
                $payload = $this->buildPayload($product, $skipImages);
                if ($payload === null) {
                    continue;
                }
                $payloads[] = $payload;
                $slugMap[$product->slug] = $product->id;
                if ($product->sku) {
                    $skuMap[$product->sku] = $product->id;
                }
                $processed++;
            }

            if (empty($payloads)) {
                return true;
            }

            try {
                $result = $this->woo->createProductsBatch($payloads);

                foreach ($result['created'] as $item) {
                    $wooId    = $item['id'];
                    $itemSku  = $item['sku'] ?? null;
                    $itemSlug = $item['slug'] ?? null;

                    // Match by SKU first (most reliable), fallback to slug
                    $localId = ($itemSku && isset($skuMap[$itemSku]))
                        ? $skuMap[$itemSku]
                        : (($itemSlug && isset($slugMap[$itemSlug])) ? $slugMap[$itemSlug] : null);

                    if ($localId) {
                        WooProduct::where('id', $localId)->update([
                            'woo_id'         => $wooId,
                            'is_placeholder' => false,
                        ]);
                        $created++;
                    }
                }

                foreach ($result['errors'] as $err) {
                    $errData = $err['error'] ?? $err;
                    $code    = $errData['code'] ?? '';

                    if (in_array($code, ['product_invalid_sku', 'woocommerce_rest_product_not_created'])) {
                        // Try to recover existing woo_id
                        if (isset($errData['data']['resource_id'])) {
                            $resourceId = (int) $errData['data']['resource_id'];
                            try {
                                $wooProduct = $this->woo->getProduct($resourceId);
                                $existSku   = $wooProduct['sku'] ?? '';
                                if ($existSku && isset($skuMap[$existSku])) {
                                    $localId = $skuMap[$existSku];
                                    WooProduct::where('id', $localId)->whereNull('woo_id')
                                        ->update(['woo_id' => $resourceId, 'is_placeholder' => false]);
                                    $created++;
                                    continue;
                                }
                            } catch (\Throwable) {}
                        }
                    }

                    $this->warn('Eroare: ' . json_encode($errData, JSON_UNESCAPED_UNICODE));
                    $errors++;
                }

                $this->info("Batch OK: +" . count($result['created']) . " | erori: {$errors} | total: {$processed}");

            } catch (\Throwable $e) {
                $this->error('Batch failed: ' . $e->getMessage());
                $errors += count($payloads);
            }

            if ($sleep > 0) {
                sleep($sleep);
            }

            return true;
        });

        $this->line('');
        $this->info("=== REZULTAT ===");
        $this->info("Procesate: {$processed}");
        $this->info("Create în WooCommerce: {$created}");
        $this->warn("Erori: {$errors}");

        if ($errors === 0 && $created > 0) {
            $sample = WooProduct::where('source', self::SOURCE)
                ->whereNotNull('woo_id')
                ->latest('id')
                ->value('woo_id');
            if ($sample) {
                $slug = $this->getWooSlug($sample);
                $this->info("Exemplu produs: https://malinco.ro/p/{$slug}/");
            }
        }
    }

    private function buildPayload(WooProduct $product, bool $skipImages): ?array
    {
        $data = is_array($product->data) ? $product->data : json_decode($product->data ?? '{}', true);

        $woo_cat_id = $data['woo_cat_id'] ?? 384;
        $attrs      = $data['attrs'] ?? [];
        $youtube    = $data['youtube'] ?? null;
        $sdsUrl     = $data['sds_url'] ?? null;
        $gallery    = $data['gallery'] ?? [];

        // Images
        $images = [];
        if (! $skipImages && $product->main_image_url) {
            $images[] = ['src' => $product->main_image_url, 'position' => 0];
        }
        if (! $skipImages && ! empty($gallery)) {
            foreach (array_slice($gallery, 0, 5) as $pos => $galleryFile) {
                $filename = basename($galleryFile);
                $images[] = ['src' => self::IMAGE_BASE . $filename, 'position' => $pos + 1];
            }
        }

        // Attributes (dimensions + brand); skip Greutate (packaging weight, not product weight)
        $wooAttrs = [
            [
                'id'      => 2,    // pa_brand
                'options' => ['FISCHER'],
                'visible' => true,
            ],
        ];
        foreach ($attrs as $name => $value) {
            if ($name === 'Greutate') {
                continue; // packaging weight, not per-piece weight
            }
            $wooAttrs[] = [
                'name'    => $name,
                'options' => [$value],
                'visible' => true,
            ];
        }

        // Meta data
        $meta = [
            ['key' => '_procurement_type', 'value' => 'on_demand'],
        ];
        if ($youtube) {
            $meta[] = ['key' => '_youtube_url', 'value' => $youtube];
        }
        if ($sdsUrl) {
            $meta[] = ['key' => 'wcpoa_attachment_name',    'value' => ['Fișă de securitate (SDS) – Fischer']];
            $meta[] = ['key' => 'wcpoa_attachment_url',     'value' => [0]];
            $meta[] = ['key' => 'wcpoa_attach_type',        'value' => ['external_url']];
            $meta[] = ['key' => 'wcpoa_attachment_ext_url', 'value' => [$sdsUrl]];
        }

        $publish = $data['publish'] ?? true;

        return [
            'name'             => $product->name,
            'type'             => 'simple',
            'status'           => $publish ? 'publish' : 'draft',
            'sku'              => $product->sku ?? '',
            'slug'             => $product->slug,
            'description'      => $product->description ?? '',
            'regular_price'    => $product->regular_price ? (string) $product->regular_price : '',
            'manage_stock'     => true,
            'backorders'       => 'yes',
            'stock_quantity'   => 0,
            'stock_status'     => 'onbackorder',
            'categories'       => [['id' => $woo_cat_id]],
            'images'           => $images,
            'attributes'       => $wooAttrs,
            'tax_status'       => 'taxable',
            'tax_class'        => '',
            'meta_data'        => $meta,
        ];
    }

    private function makeSlug(string $name, string $code): string
    {
        $base = Str::slug(strtolower($name));
        if (empty($base)) {
            $base = Str::slug(strtolower($code));
        }
        // Ensure uniqueness in ERP
        $slug  = $base;
        $count = 1;
        while (DB::table('woo_products')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $count++;
        }
        return $slug;
    }

    private function getWooSlug(int $wooId): string
    {
        try {
            $p = $this->woo->getProduct($wooId);
            return $p['slug'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }
}
