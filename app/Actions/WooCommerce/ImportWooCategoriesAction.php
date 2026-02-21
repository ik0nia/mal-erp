<?php

namespace App\Actions\WooCommerce;

use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use App\Models\WooCategory;
use App\Services\WooCommerce\WooClient;
use Illuminate\Support\Carbon;
use Throwable;

class ImportWooCategoriesAction
{
    public function execute(IntegrationConnection $connection): SyncRun
    {
        $run = SyncRun::query()->create([
            'provider' => IntegrationConnection::PROVIDER_WOOCOMMERCE,
            'location_id' => $connection->location_id,
            'connection_id' => $connection->id,
            'type' => SyncRun::TYPE_CATEGORIES,
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
                $categories = $client->getCategories($page, $perPage);

                if ($categories === []) {
                    break;
                }

                $stats['pages']++;

                $wooIds = collect($categories)
                    ->pluck('id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $existingWooIds = WooCategory::query()
                    ->where('connection_id', $connection->id)
                    ->whereIn('woo_id', $wooIds)
                    ->pluck('woo_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                $existingLookup = array_flip($existingWooIds);

                foreach ($categories as $category) {
                    if (! is_array($category)) {
                        continue;
                    }

                    $wooId = (int) ($category['id'] ?? 0);

                    if ($wooId <= 0) {
                        continue;
                    }

                    if (isset($existingLookup[$wooId])) {
                        $stats['updated']++;
                    } else {
                        $stats['created']++;
                    }

                    WooCategory::query()->updateOrCreate(
                        [
                            'connection_id' => $connection->id,
                            'woo_id' => $wooId,
                        ],
                        [
                            'name' => (string) ($category['name'] ?? ''),
                            'slug' => $this->nullableString($category['slug'] ?? null),
                            'description' => $this->nullableString($category['description'] ?? null),
                            'parent_woo_id' => $this->nullableInt($category['parent'] ?? null),
                            'image_url' => $this->extractCategoryImageUrl($category),
                            'menu_order' => $this->nullableInt($category['menu_order'] ?? null),
                            'count' => $this->nullableInt($category['count'] ?? null),
                            'data' => $category,
                        ]
                    );
                }

                $page++;
            }

            $this->resolveHierarchy($connection->id);

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

    private function resolveHierarchy(int $connectionId): void
    {
        WooCategory::query()
            ->where('connection_id', $connectionId)
            ->whereNotNull('parent_woo_id')
            ->where('parent_woo_id', '>', 0)
            ->chunkById(200, function ($categories) use ($connectionId): void {
                $parentWooIds = $categories->pluck('parent_woo_id')
                    ->filter()
                    ->unique()
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                if ($parentWooIds === []) {
                    return;
                }

                $parentIdByWooId = WooCategory::query()
                    ->where('connection_id', $connectionId)
                    ->whereIn('woo_id', $parentWooIds)
                    ->pluck('id', 'woo_id');

                foreach ($categories as $category) {
                    $parentId = $parentIdByWooId->get((int) $category->parent_woo_id);

                    if ($parentId) {
                        $category->update(['parent_id' => (int) $parentId]);
                    }
                }
            });
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function extractCategoryImageUrl(array $category): ?string
    {
        $src = data_get($category, 'image.src');

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
}
