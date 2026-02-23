<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'daily_stock_metrics';

    private const OLD_UNIQUE = 'daily_stock_metrics_day_woo_product_id_unique';

    private const OLD_INDEX = 'daily_stock_metrics_product_day_idx';

    private const NEW_UNIQUE = 'daily_stock_metrics_day_reference_product_id_unique';

    private const NEW_INDEX = 'daily_stock_metrics_reference_product_day_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'reference_product_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('reference_product_id', 191)->nullable()->after('day');
            });
        }

        $this->backfillReferenceProductIds();
        $this->consolidateDuplicateReferenceRows();
        $this->fillMissingReferenceProductIds();

        $this->dropUniqueIfExists(self::TABLE, self::OLD_UNIQUE);
        $this->dropIndexIfExists(self::TABLE, self::OLD_INDEX);

        $this->addUniqueIfMissing(
            self::TABLE,
            self::NEW_UNIQUE,
            ['day', 'reference_product_id']
        );
        $this->addIndexIfMissing(
            self::TABLE,
            self::NEW_INDEX,
            ['reference_product_id', 'day']
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->dropUniqueIfExists(self::TABLE, self::NEW_UNIQUE);
        $this->dropIndexIfExists(self::TABLE, self::NEW_INDEX);

        $this->addUniqueIfMissing(
            self::TABLE,
            self::OLD_UNIQUE,
            ['day', 'woo_product_id']
        );
        $this->addIndexIfMissing(
            self::TABLE,
            self::OLD_INDEX,
            ['woo_product_id', 'day']
        );

        if (Schema::hasColumn(self::TABLE, 'reference_product_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('reference_product_id');
            });
        }
    }

    private function backfillReferenceProductIds(): void
    {
        DB::table(self::TABLE)
            ->select(['id', 'woo_product_id'])
            ->orderBy('id')
            ->chunkById(1000, function ($rows): void {
                $productIds = collect($rows)
                    ->pluck('woo_product_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $skuByProductId = $productIds === []
                    ? []
                    : DB::table('woo_products')
                        ->whereIn('id', $productIds)
                        ->pluck('sku', 'id')
                        ->map(fn ($sku): string => trim((string) $sku))
                        ->all();

                foreach ($rows as $row) {
                    $wooProductId = (int) ($row->woo_product_id ?? 0);
                    $resolvedReferenceId = trim((string) ($skuByProductId[$wooProductId] ?? ''));

                    if ($resolvedReferenceId === '') {
                        $resolvedReferenceId = 'woo:'.$wooProductId;
                    }

                    DB::table(self::TABLE)
                        ->where('id', (int) $row->id)
                        ->update([
                            'reference_product_id' => $resolvedReferenceId,
                        ]);
                }
            });
    }

    private function consolidateDuplicateReferenceRows(): void
    {
        $duplicates = DB::table(self::TABLE)
            ->select('day', 'reference_product_id', DB::raw('COUNT(*) as rows_count'))
            ->whereNotNull('reference_product_id')
            ->where('reference_product_id', '<>', '')
            ->groupBy('day', 'reference_product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table(self::TABLE)
                ->where('day', (string) $duplicate->day)
                ->where('reference_product_id', (string) $duplicate->reference_product_id)
                ->orderBy('id')
                ->get();

            if ($rows->count() <= 1) {
                continue;
            }

            $openingRow = $rows
                ->sortBy(fn ($row): int => $this->timestampScore(
                    $row->first_snapshot_at ?? null,
                    $row->created_at ?? null,
                    (int) $row->id
                ))
                ->first();

            $closingRow = $rows
                ->sortByDesc(fn ($row): int => $this->timestampScore(
                    $row->last_snapshot_at ?? null,
                    $row->updated_at ?? null,
                    (int) $row->id
                ))
                ->first();

            if (! $openingRow || ! $closingRow) {
                continue;
            }

            $openingTotalQty = (float) ($openingRow->opening_total_qty ?? 0);
            $closingTotalQty = (float) ($closingRow->closing_total_qty ?? 0);
            $openingAvailableQty = (float) ($openingRow->opening_available_qty ?? 0);
            $closingAvailableQty = (float) ($closingRow->closing_available_qty ?? 0);
            $openingSellPrice = $this->nullableFloat($openingRow->opening_sell_price ?? null);
            $closingSellPrice = $this->nullableFloat($closingRow->closing_sell_price ?? null);

            $openingSalesValue = round($openingAvailableQty * ($openingSellPrice ?? 0.0), 4);
            $closingSalesValue = round($closingAvailableQty * ($closingSellPrice ?? 0.0), 4);

            $minAvailableQty = $rows->min(fn ($row): float => (float) ($row->min_available_qty ?? 0.0));
            $maxAvailableQty = $rows->max(fn ($row): float => (float) ($row->max_available_qty ?? 0.0));
            $snapshotsCount = $rows->sum(fn ($row): int => max(0, (int) ($row->snapshots_count ?? 0)));

            $firstSnapshotAt = $rows
                ->pluck('first_snapshot_at')
                ->filter(fn ($value): bool => $value !== null && $value !== '')
                ->sort()
                ->first();
            $lastSnapshotAt = $rows
                ->pluck('last_snapshot_at')
                ->filter(fn ($value): bool => $value !== null && $value !== '')
                ->sort()
                ->last();

            $baseId = (int) $openingRow->id;
            $resolvedWooProductId = (int) ($closingRow->woo_product_id ?: $openingRow->woo_product_id);

            DB::table(self::TABLE)
                ->where('id', $baseId)
                ->update([
                    'woo_product_id' => $resolvedWooProductId,
                    'opening_total_qty' => $openingTotalQty,
                    'closing_total_qty' => $closingTotalQty,
                    'opening_available_qty' => $openingAvailableQty,
                    'closing_available_qty' => $closingAvailableQty,
                    'opening_sell_price' => $openingSellPrice,
                    'closing_sell_price' => $closingSellPrice,
                    'daily_total_variation' => $closingTotalQty - $openingTotalQty,
                    'daily_available_variation' => $closingAvailableQty - $openingAvailableQty,
                    'closing_sales_value' => $closingSalesValue,
                    'daily_sales_value_variation' => $closingSalesValue - $openingSalesValue,
                    'min_available_qty' => $minAvailableQty,
                    'max_available_qty' => $maxAvailableQty,
                    'snapshots_count' => $snapshotsCount,
                    'first_snapshot_at' => $firstSnapshotAt,
                    'last_snapshot_at' => $lastSnapshotAt,
                    'updated_at' => now(),
                ]);

            $idsToDelete = $rows
                ->pluck('id')
                ->filter(fn ($id): bool => (int) $id !== $baseId)
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            if ($idsToDelete !== []) {
                DB::table(self::TABLE)
                    ->whereIn('id', $idsToDelete)
                    ->delete();
            }
        }
    }

    private function fillMissingReferenceProductIds(): void
    {
        DB::table(self::TABLE)
            ->select(['id'])
            ->where(function ($query): void {
                $query->whereNull('reference_product_id')
                    ->orWhere('reference_product_id', '');
            })
            ->orderBy('id')
            ->chunkById(1000, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table(self::TABLE)
                        ->where('id', (int) $row->id)
                        ->update([
                            'reference_product_id' => 'legacy:'.$row->id,
                        ]);
                }
            });
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function timestampScore(mixed $primary, mixed $fallback, int $id): int
    {
        $candidates = [$primary, $fallback];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $timestamp = strtotime((string) $candidate);

            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return $id;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addUniqueIfMissing(string $table, string $indexName, array $columns): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->unique($columns, $indexName);
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropUniqueIfExists(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->dropUnique($indexName);
            });
        } catch (\Throwable) {
            // Index is missing or cannot be dropped in current schema state.
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->dropIndex($indexName);
            });
        } catch (\Throwable) {
            // Index is missing or cannot be dropped in current schema state.
        }
    }
};
