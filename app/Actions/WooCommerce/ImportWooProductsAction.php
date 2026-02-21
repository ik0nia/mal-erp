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
    public function execute(IntegrationConnection $connection): SyncRun
    {
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
                $products = $client->getProducts($page, $perPage);

                if ($products === []) {
                    break;
                }

                $stats['pages']++;

                $wooIds = collect($products)
                    ->pluck('id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $existingWooIds = WooProduct::query()
                    ->where('connection_id', $connection->id)
                    ->whereIn('woo_id', $wooIds)
                    ->pluck('woo_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                $existingLookup = array_flip($existingWooIds);

                foreach ($products as $productPayload) {
                    if (! is_array($productPayload)) {
                        continue;
                    }

                    $wooId = (int) ($productPayload['id'] ?? 0);

                    if ($wooId <= 0) {
                        continue;
                    }

                    if (isset($existingLookup[$wooId])) {
                        $stats['updated']++;
                    } else {
                        $stats['created']++;
                    }

                    /** @var WooProduct $product */
                    $product = WooProduct::query()->updateOrCreate(
                        [
                            'connection_id' => $connection->id,
                            'woo_id' => $wooId,
                        ],
                        [
                            'type' => $this->nullableString($productPayload['type'] ?? null),
                            'status' => $this->nullableString($productPayload['status'] ?? null),
                            'sku' => $this->nullableString($productPayload['sku'] ?? null),
                            'name' => (string) ($productPayload['name'] ?? ''),
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
                        ]
                    );

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

        return (bool) $value;
    }
}
