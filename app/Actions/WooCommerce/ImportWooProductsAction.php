<?php

namespace App\Actions\WooCommerce;

use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use App\Models\WooCategory;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Throwable;

class ImportWooProductsAction
{
    public function __construct(private readonly ImportWooCategoriesAction $importCategories) {}

    public function execute(IntegrationConnection $connection): SyncRun
    {
        // Sincronizăm categoriile înainte de produse — garantăm că asocierile funcționează corect
        try {
            $this->importCategories->execute($connection);
        } catch (Throwable) {
            // Dacă importul de categorii eșuează, continuăm cu produsele — nu blocăm
        }

        DB::connection()->disableQueryLog();

        $run = SyncRun::query()->create([
            'provider' => IntegrationConnection::PROVIDER_WOOCOMMERCE,
            'location_id' => $connection->location_id,
            'connection_id' => $connection->id,
            'type' => SyncRun::TYPE_PRODUCTS,
            'status' => SyncRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
            'stats' => [
                'created' => 0,
                'updated' => 0,
                'pages' => 0,
            ],
            'errors' => [],
        ]);

        $stats = [
            'created' => 0,
            'updated' => 0,
            'pages' => 0,
        ];
        $errors = [];
        $page = 1;
        $client = new WooClient($connection);
        $perPage = $connection->resolvePerPage();

        try {
            while (true) {
                if ($this->isRunCancellationRequested((int) $run->id)) {
                    $errors[] = [
                        'page' => $page,
                        'message' => 'Import oprit manual din platformă.',
                    ];

                    $run->update([
                        'status' => SyncRun::STATUS_CANCELLED,
                        'finished_at' => Carbon::now(),
                        'stats' => $stats,
                        'errors' => $errors,
                    ]);

                    return $run;
                }

                $products = $client->getProducts($page, $perPage);

                if ($products === []) {
                    break;
                }

                $stats['pages']++;

                foreach ($products as $productPayload) {
                    if (! is_array($productPayload)) {
                        continue;
                    }

                    $wooId = (int) ($productPayload['id'] ?? 0);

                    if ($wooId <= 0) {
                        continue;
                    }

                    $sku = $this->nullableString($productPayload['sku'] ?? null);

                    $product = WooProduct::query()
                        ->where('connection_id', $connection->id)
                        ->where('woo_id', $wooId)
                        ->first();

                    if ($product) {
                        $stats['updated']++;
                    } else {
                        $placeholder = null;

                        if ($sku !== null) {
                            $placeholder = WooProduct::query()
                                ->where('connection_id', $connection->id)
                                ->where('sku', $sku)
                                ->where('is_placeholder', true)
                                ->first();
                        }

                        if ($placeholder) {
                            $product = $placeholder;
                            $product->woo_id = $wooId;
                            $stats['updated']++;
                        } else {
                            $product = new WooProduct([
                                'connection_id' => $connection->id,
                                'woo_id' => $wooId,
                            ]);
                            $stats['created']++;
                        }
                    }

                    $product->fill([
                        'type' => $this->nullableString($productPayload['type'] ?? null),
                        'status' => $this->nullableString($productPayload['status'] ?? null),
                        'sku' => $sku,
                        'name' => $this->decodeHtmlEntityText((string) ($productPayload['name'] ?? '')),
                        'slug' => $this->nullableString($productPayload['slug'] ?? null),
                        'short_description' => $this->nullableString($productPayload['short_description'] ?? null),
                        'description' => $this->nullableString($productPayload['description'] ?? null),
                        'regular_price' => $this->nullableString($productPayload['regular_price'] ?? null),
                        'sale_price' => $this->nullableString($productPayload['sale_price'] ?? null),
                        'price' => $this->nullableString($productPayload['price'] ?? null),
                        'stock_status' => $this->nullableString($productPayload['stock_status'] ?? null),
                        'manage_stock' => $this->nullableBool($productPayload['manage_stock'] ?? null),
                        'woo_parent_id' => $this->nullableInt($productPayload['parent_id'] ?? null),
                        'main_image_url' => $this->extractMainImageUrl($productPayload),
                        'data' => $productPayload,
                        'source' => WooProduct::SOURCE_WOOCOMMERCE,
                        'is_placeholder' => false,
                        'unit' => $this->extractUnit($productPayload),
                        'brand' => $this->extractBrand($productPayload),
                        'weight' => $this->extractWeight($productPayload),
                        'dim_length' => $this->nullableString($productPayload['dimensions']['length'] ?? null),
                        'dim_width' => $this->nullableString($productPayload['dimensions']['width'] ?? null),
                        'dim_height' => $this->nullableString($productPayload['dimensions']['height'] ?? null),
                    ]);
                    $product->save();

                    $categoryWooIds = collect($productPayload['categories'] ?? [])
                        ->pluck('id')
                        ->filter()
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    if ($categoryWooIds === []) {
                        $product->categories()->sync([]);

                        continue;
                    }

                    $categoryIds = WooCategory::query()
                        ->where('connection_id', $connection->id)
                        ->whereIn('woo_id', $categoryWooIds)
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    $product->categories()->sync($categoryIds);

                    if (count($categoryIds) !== count(array_unique($categoryWooIds))) {
                        $errors[] = [
                            'page' => $page,
                            'product_woo_id' => $wooId,
                            'message' => 'One or more categories are missing locally. Run categories import first.',
                        ];
                    }
                }

                $page++;
            }

            $run->update([
                'status' => SyncRun::STATUS_SUCCESS,
                'finished_at' => Carbon::now(),
                'stats' => $stats,
                'errors' => $errors,
            ]);
        } catch (Throwable $exception) {
            $errors[] = [
                'page' => $page,
                'message' => $exception->getMessage(),
            ];

            $run->update([
                'status' => SyncRun::STATUS_FAILED,
                'finished_at' => Carbon::now(),
                'stats' => $stats,
                'errors' => $errors,
            ]);

            throw $exception;
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function extractUnit(array $product): ?string
    {
        foreach ($product['meta_data'] ?? [] as $meta) {
            if (($meta['key'] ?? '') === 'woodmart_price_unit_of_measure') {
                $val = trim((string) ($meta['value'] ?? ''));

                return $val !== '' ? $val : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function extractBrand(array $product): ?string
    {
        // 1. Din atributele produsului
        foreach ($product['attributes'] ?? [] as $attr) {
            if (strcasecmp(trim((string) ($attr['name'] ?? '')), 'Brand') === 0) {
                $opts = $attr['options'] ?? [];
                if (! empty($opts[0])) {
                    return trim((string) $opts[0]);
                }
            }
        }

        // 2. Fallback: fb_brand din meta_data
        foreach ($product['meta_data'] ?? [] as $meta) {
            if (($meta['key'] ?? '') === 'fb_brand') {
                $val = trim((string) ($meta['value'] ?? ''));

                return $val !== '' ? $val : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function extractWeight(array $product): ?string
    {
        $raw = trim((string) ($product['weight'] ?? ''));

        return ($raw !== '' && $raw !== '0') ? $raw : null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function extractMainImageUrl(array $product): ?string
    {
        $src = data_get($product, 'images.0.src');

        return is_string($src) && $src !== '' ? $src : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function decodeHtmlEntityText(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function isRunCancellationRequested(int $runId): bool
    {
        return SyncRun::query()
            ->whereKey($runId)
            ->where('status', SyncRun::STATUS_CANCELLED)
            ->exists();
    }
}
