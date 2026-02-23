<?php

namespace App\Services\Winmentor;

use App\Models\DailyStockMetric;
use Illuminate\Support\Carbon;

class DailyStockMetricAggregator
{
    /**
     * @param  array<int, array{woo_product_id:int, quantity:float|int|null, sell_price:float|int|null}>  $snapshots
     */
    public function recordSnapshots(Carbon $snapshotAt, array $snapshots): int
    {
        if ($snapshots === []) {
            return 0;
        }

        $day = $snapshotAt
            ->copy()
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->toDateString();

        $snapshotsByProductId = $this->normalizeSnapshots($snapshots);

        if ($snapshotsByProductId === []) {
            return 0;
        }

        $processedProducts = 0;

        foreach (array_chunk($snapshotsByProductId, 1000, true) as $snapshotChunk) {
            $productIds = array_keys($snapshotChunk);
            $existingRows = DailyStockMetric::query()
                ->where('day', $day)
                ->whereIn('woo_product_id', $productIds)
                ->get()
                ->keyBy('woo_product_id');

            $upsertRows = [];

            foreach ($snapshotChunk as $productId => $snapshot) {
                /** @var DailyStockMetric|null $existing */
                $existing = $existingRows->get($productId);
                $upsertRows[] = $this->buildMetricRow($day, $snapshotAt, $snapshot, $existing);
                $processedProducts++;
            }

            DailyStockMetric::query()->upsert(
                $upsertRows,
                ['day', 'woo_product_id'],
                [
                    'opening_total_qty',
                    'closing_total_qty',
                    'opening_available_qty',
                    'closing_available_qty',
                    'opening_sell_price',
                    'closing_sell_price',
                    'daily_total_variation',
                    'daily_available_variation',
                    'closing_sales_value',
                    'daily_sales_value_variation',
                    'min_available_qty',
                    'max_available_qty',
                    'snapshots_count',
                    'first_snapshot_at',
                    'last_snapshot_at',
                    'updated_at',
                ]
            );
        }

        return $processedProducts;
    }

    /**
     * @param  array<int, array{woo_product_id:int, quantity:float|int|null, sell_price:float|int|null}>  $snapshots
     * @return array<int, array{woo_product_id:int, quantity:float, sell_price:?float}>
     */
    private function normalizeSnapshots(array $snapshots): array
    {
        $normalized = [];

        foreach ($snapshots as $snapshot) {
            $productId = (int) ($snapshot['woo_product_id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $quantity = $this->normalizeQuantity($snapshot['quantity'] ?? 0);
            $sellPriceRaw = $snapshot['sell_price'] ?? null;
            $sellPrice = is_numeric($sellPriceRaw) ? $this->normalizePrice((float) $sellPriceRaw) : null;

            // Last occurrence wins, matching ProductStock upsert behavior.
            $normalized[$productId] = [
                'woo_product_id' => $productId,
                'quantity' => $quantity,
                'sell_price' => $sellPrice,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array{woo_product_id:int, quantity:float, sell_price:?float}  $snapshot
     */
    private function buildMetricRow(string $day, Carbon $snapshotAt, array $snapshot, ?DailyStockMetric $existing): array
    {
        $quantity = $snapshot['quantity'];
        // "available" currently mirrors total quantity until reservation logic exists.
        $availableQty = $quantity;
        $snapshotPrice = $snapshot['sell_price'];

        if (! $existing instanceof DailyStockMetric) {
            $closingSalesValue = $this->calculateSalesValue($availableQty, $snapshotPrice);

            return [
                'day' => $day,
                'woo_product_id' => $snapshot['woo_product_id'],
                'opening_total_qty' => $quantity,
                'closing_total_qty' => $quantity,
                'opening_available_qty' => $availableQty,
                'closing_available_qty' => $availableQty,
                'opening_sell_price' => $snapshotPrice,
                'closing_sell_price' => $snapshotPrice,
                'daily_total_variation' => 0.0,
                'daily_available_variation' => 0.0,
                'closing_sales_value' => $closingSalesValue,
                'daily_sales_value_variation' => 0.0,
                'min_available_qty' => $availableQty,
                'max_available_qty' => $availableQty,
                'snapshots_count' => 1,
                'first_snapshot_at' => $snapshotAt,
                'last_snapshot_at' => $snapshotAt,
                'created_at' => $snapshotAt,
                'updated_at' => $snapshotAt,
            ];
        }

        $firstSnapshotAt = $existing->first_snapshot_at instanceof Carbon
            ? $existing->first_snapshot_at->copy()
            : null;
        $lastSnapshotAt = $existing->last_snapshot_at instanceof Carbon
            ? $existing->last_snapshot_at->copy()
            : null;

        $openingTotalQty = $this->toFloat($existing->opening_total_qty);
        $openingAvailableQty = $this->toFloat($existing->opening_available_qty);
        $openingSellPrice = $this->toNullableFloat($existing->opening_sell_price);

        $closingTotalQty = $this->toFloat($existing->closing_total_qty);
        $closingAvailableQty = $this->toFloat($existing->closing_available_qty);
        $closingSellPrice = $this->toNullableFloat($existing->closing_sell_price);

        if ($firstSnapshotAt === null || $snapshotAt->lt($firstSnapshotAt)) {
            $firstSnapshotAt = $snapshotAt->copy();
            $openingTotalQty = $quantity;
            $openingAvailableQty = $availableQty;
            $openingSellPrice = $snapshotPrice ?? $openingSellPrice;
        }

        if ($lastSnapshotAt === null || $snapshotAt->gte($lastSnapshotAt)) {
            $lastSnapshotAt = $snapshotAt->copy();
            $closingTotalQty = $quantity;
            $closingAvailableQty = $availableQty;
            $closingSellPrice = $snapshotPrice ?? $closingSellPrice;
        }

        $minAvailableQty = min(
            $this->toFloat($existing->min_available_qty, $availableQty),
            $availableQty
        );
        $maxAvailableQty = max(
            $this->toFloat($existing->max_available_qty, $availableQty),
            $availableQty
        );

        $openingSalesValue = $this->calculateSalesValue($openingAvailableQty, $openingSellPrice);
        $closingSalesValue = $this->calculateSalesValue($closingAvailableQty, $closingSellPrice);

        return [
            'day' => $day,
            'woo_product_id' => $snapshot['woo_product_id'],
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
            'snapshots_count' => max(0, (int) $existing->snapshots_count) + 1,
            'first_snapshot_at' => $firstSnapshotAt,
            'last_snapshot_at' => $lastSnapshotAt,
            'created_at' => $existing->created_at ?? $snapshotAt,
            'updated_at' => $snapshotAt,
        ];
    }

    private function normalizeQuantity(mixed $value): float
    {
        return round((float) $value, 3);
    }

    private function normalizePrice(float $value): float
    {
        return round($value, 4);
    }

    private function calculateSalesValue(float $quantity, ?float $price): float
    {
        return round($quantity * ($price ?? 0.0), 4);
    }

    private function toFloat(mixed $value, float $fallback = 0.0): float
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return (float) $value;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
