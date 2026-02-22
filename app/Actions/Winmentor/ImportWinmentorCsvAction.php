<?php

namespace App\Actions\Winmentor;

use App\Models\IntegrationConnection;
use App\Models\ProductPriceLog;
use App\Models\ProductStock;
use App\Models\SyncRun;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ImportWinmentorCsvAction
{
    public function execute(IntegrationConnection $connection): SyncRun
    {
        if (! $connection->isWinmentorCsv()) {
            throw new RuntimeException('Connection provider is not winmentor_csv.');
        }

        DB::connection()->disableQueryLog();

        $run = SyncRun::query()->create([
            'provider' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
            'location_id' => $connection->location_id,
            'connection_id' => $connection->id,
            'type' => SyncRun::TYPE_WINMENTOR_STOCK,
            'status' => SyncRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
            'stats' => [
                'pages' => 1,
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'processed' => 0,
                'matched_products' => 0,
                'missing_products' => 0,
                'price_changes' => 0,
                'name_mismatches' => 0,
                'site_price_updates' => 0,
                'site_price_update_failures' => 0,
                'created_placeholders' => 0,
                'missing_skus_sample' => [],
                'name_mismatch_sample' => [],
            ],
            'errors' => [],
        ]);

        $stats = $run->stats ?? [];
        $errors = [];

        try {
            $csvUrl = $connection->csvUrl();

            if ($csvUrl === '') {
                throw new RuntimeException('CSV URL is missing in connection settings.');
            }

            $response = Http::timeout($connection->resolveTimeoutSeconds())
                ->withOptions(['verify' => $connection->verify_ssl])
                ->get($csvUrl);

            $response->throw();

            $rows = $this->parseRows($response->body(), $connection);

            $skuValues = collect($rows)
                ->pluck('sku')
                ->map(fn (string $sku): string => trim($sku))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $wooConnectionIds = IntegrationConnection::query()
                ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
                ->where('location_id', $connection->location_id)
                ->pluck('id')
                ->all();

            if ($wooConnectionIds === []) {
                throw new RuntimeException('No WooCommerce connection found for this location.');
            }

            $wooConnectionsById = IntegrationConnection::query()
                ->whereIn('id', $wooConnectionIds)
                ->get()
                ->keyBy('id');
            $defaultWooConnection = IntegrationConnection::query()
                ->whereIn('id', $wooConnectionIds)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->first();

            $pushPriceToSite = $connection->shouldPushPriceToSite();
            $wooClients = [];

            $products = WooProduct::query()
                ->whereIn('connection_id', $wooConnectionIds)
                ->whereIn('sku', $skuValues)
                ->get();

            $productsBySku = [];
            foreach ($products as $product) {
                $skuKey = $this->normalizeKey((string) $product->sku);

                if (
                    $skuKey !== ''
                    && (
                        ! isset($productsBySku[$skuKey])
                        || ($productsBySku[$skuKey]->is_placeholder && ! $product->is_placeholder)
                    )
                ) {
                    $productsBySku[$skuKey] = $product;
                }
            }

            $stocksByProductId = ProductStock::query()
                ->where('location_id', $connection->location_id)
                ->whereIn('woo_product_id', collect($productsBySku)->pluck('id')->all())
                ->get()
                ->keyBy('woo_product_id');

            foreach ($rows as $rowNumber => $row) {
                $lineNumber = $rowNumber + 2; // +1 header and 1-indexed rows
                $stats['processed']++;

                $sku = trim($row['sku']);
                $skuKey = $this->normalizeKey($sku);
                $quantity = $row['quantity'];
                $price = $row['price'];

                if ($skuKey === '') {
                    $this->appendError($errors, [
                        'row' => $lineNumber,
                        'message' => 'SKU missing.',
                    ]);

                    continue;
                }

                $product = $productsBySku[$skuKey] ?? null;

                if (! $product) {
                    $stats['missing_products']++;

                    if (
                        count($stats['missing_skus_sample']) < 50
                        && ! in_array($sku, $stats['missing_skus_sample'], true)
                    ) {
                        $stats['missing_skus_sample'][] = $sku;
                    }

                    if ($defaultWooConnection instanceof IntegrationConnection) {
                        $product = $this->createPlaceholderProduct(
                            wooConnection: $defaultWooConnection,
                            sku: $sku,
                            csvName: $row['name'] ?? null,
                            price: $price,
                            quantity: $quantity
                        );
                        $productsBySku[$skuKey] = $product;
                        $stats['created_placeholders']++;
                    } else {
                        $this->appendError($errors, [
                            'row' => $lineNumber,
                            'sku' => $sku,
                            'message' => 'SKU not found and no Woo connection available for ERP placeholder.',
                        ]);

                        continue;
                    }
                }

                $stats['matched_products']++;

                if ($product->is_placeholder && $product->source === WooProduct::SOURCE_WINMENTOR_CSV) {
                    $normalizedMentorName = $this->resolvePlaceholderName($sku, $row['name'] ?? null);

                    if ($normalizedMentorName !== '' && $product->name !== $normalizedMentorName) {
                        $data = is_array($product->data) ? $product->data : [];
                        $data['csv_name'] = $normalizedMentorName;

                        $product->update([
                            'name' => $normalizedMentorName,
                            'data' => $data,
                        ]);
                    }
                }

                if ($this->isNameMismatch($row['name'] ?? null, (string) $product->name)) {
                    $stats['name_mismatches']++;

                    if (count($stats['name_mismatch_sample']) < 50) {
                        $stats['name_mismatch_sample'][] = [
                            'sku' => $sku,
                            'site_name' => $product->name,
                            'csv_name' => $row['name'],
                        ];
                    }
                }

                /** @var ProductStock|null $stock */
                $stock = $stocksByProductId->get($product->id);

                $isNew = ! $stock;
                if (! $stock) {
                    $stock = new ProductStock([
                        'woo_product_id' => $product->id,
                        'location_id' => $connection->location_id,
                    ]);
                }

                $oldQuantity = $stock->quantity !== null ? (float) $stock->quantity : null;
                $oldPrice = $stock->price !== null ? (float) $stock->price : null;
                $newQuantity = (float) ($quantity ?? 0);
                $priceDifferent = $this->numbersDiffer($oldPrice, $price);
                $priceUpdated = $this->isPriceUpdated($oldPrice, $price);
                $quantityChanged = $this->numbersDiffer($oldQuantity, $newQuantity);

                if ($isNew) {
                    $stock->fill([
                        'quantity' => $newQuantity,
                        'price' => $price,
                        'source' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                        'sync_run_id' => $run->id,
                        'synced_at' => Carbon::now(),
                    ]);
                    $stock->save();

                    $stocksByProductId->put($product->id, $stock);
                    $stats['created']++;
                } elseif ($quantityChanged || $priceDifferent) {
                    $stock->fill([
                        'quantity' => $newQuantity,
                        'price' => $price,
                        'source' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                        'sync_run_id' => $run->id,
                        'synced_at' => Carbon::now(),
                    ]);
                    $stock->save();

                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }

                if ($priceUpdated) {
                    ProductPriceLog::query()->create([
                        'woo_product_id' => $product->id,
                        'location_id' => $connection->location_id,
                        'old_price' => $oldPrice,
                        'new_price' => $price,
                        'source' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                        'sync_run_id' => $run->id,
                        'payload' => [
                            'sku' => $sku,
                            'name' => $row['name'],
                        ],
                        'changed_at' => Carbon::now(),
                    ]);

                    $stats['price_changes']++;

                    if ($pushPriceToSite && ! $product->is_placeholder) {
                        $wooConnection = $wooConnectionsById->get($product->connection_id);

                        if (! $wooConnection instanceof IntegrationConnection) {
                            $stats['site_price_update_failures']++;
                            $this->appendError($errors, [
                                'row' => $lineNumber,
                                'sku' => $sku,
                                'message' => 'No WooCommerce connection available for product price push.',
                            ]);
                        } elseif (! $wooConnection->is_active) {
                            $stats['site_price_update_failures']++;
                            $this->appendError($errors, [
                                'row' => $lineNumber,
                                'sku' => $sku,
                                'message' => 'WooCommerce connection is inactive; price push skipped.',
                            ]);
                        } else {
                            try {
                                if (! isset($wooClients[$wooConnection->id])) {
                                    $wooClients[$wooConnection->id] = new WooClient($wooConnection);
                                }

                                $wooClients[$wooConnection->id]->updateProductPrice(
                                    (int) $product->woo_id,
                                    $this->formatPrice($price),
                                );
                                $stats['site_price_updates']++;
                            } catch (Throwable $exception) {
                                $stats['site_price_update_failures']++;
                                $this->appendError($errors, [
                                    'row' => $lineNumber,
                                    'sku' => $sku,
                                    'message' => 'Failed to push price to WooCommerce: '.$exception->getMessage(),
                                ]);
                            }
                        }
                    }
                }

                $productUpdates = [];

                if ($price !== null && $priceDifferent) {
                    $formattedPrice = $this->formatPrice($price);
                    $productUpdates['regular_price'] = $formattedPrice;
                    $productUpdates['price'] = $formattedPrice;
                }

                if ($quantity !== null) {
                    $targetStockStatus = $newQuantity > 0 ? 'instock' : 'outofstock';

                    if ((string) ($product->stock_status ?? '') !== $targetStockStatus) {
                        $productUpdates['stock_status'] = $targetStockStatus;
                    }

                    if ($product->manage_stock !== true) {
                        $productUpdates['manage_stock'] = true;
                    }
                }

                if ($productUpdates !== []) {
                    // Accounting CSV updates only stock/price fields, never catalog naming fields.
                    $product->update($productUpdates);
                }
            }

            $run->update([
                'status' => SyncRun::STATUS_SUCCESS,
                'finished_at' => Carbon::now(),
                'stats' => $stats,
                'errors' => $errors,
            ]);
        } catch (Throwable $exception) {
            $this->appendError($errors, [
                'message' => $exception->getMessage(),
            ]);

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
     * @return array<int, array{sku:string, name:?string, quantity:?float, price:?float}>
     */
    private function parseRows(string $csv, IntegrationConnection $connection): array
    {
        $delimiter = (string) data_get($connection->settings, 'delimiter', ',');
        if ($delimiter === '') {
            $delimiter = ',';
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));

        if (! is_array($lines) || $lines === []) {
            throw new RuntimeException('CSV is empty.');
        }

        $header = str_getcsv((string) array_shift($lines), $delimiter);
        $headerMap = [];

        foreach ($header as $index => $columnName) {
            $headerMap[$this->normalizeKey((string) $columnName)] = $index;
        }

        $skuColumn = $this->normalizeKey((string) data_get($connection->settings, 'sku_column', 'codextern'));
        $nameColumn = $this->normalizeKey((string) data_get($connection->settings, 'name_column', 'denumire'));
        $qtyColumn = $this->normalizeKey((string) data_get($connection->settings, 'quantity_column', 'cantitate'));
        $priceColumn = $this->normalizeKey((string) data_get($connection->settings, 'price_column', 'pret'));

        if (! isset($headerMap[$qtyColumn]) && isset($headerMap['cantiate'])) {
            $qtyColumn = 'cantiate';
        }

        foreach ([$skuColumn, $qtyColumn, $priceColumn] as $requiredColumn) {
            if (! isset($headerMap[$requiredColumn])) {
                throw new RuntimeException("CSV column [{$requiredColumn}] not found.");
            }
        }

        $rows = [];

        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }

            $columns = str_getcsv((string) $line, $delimiter);

            $rows[] = [
                'sku' => trim((string) ($columns[$headerMap[$skuColumn]] ?? '')),
                'name' => isset($headerMap[$nameColumn]) ? $this->sanitizeMentorName((string) ($columns[$headerMap[$nameColumn]] ?? '')) : null,
                'quantity' => $this->toFloat($columns[$headerMap[$qtyColumn]] ?? null),
                'price' => $this->toFloat($columns[$headerMap[$priceColumn]] ?? null),
            ];
        }

        return $rows;
    }

    private function normalizeKey(string $key): string
    {
        $key = ltrim($key, "\xEF\xBB\xBF");
        $key = mb_strtolower(trim($key));

        return preg_replace('/\s+/', '', $key) ?? '';
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $raw = str_replace(["\u{00A0}", ' '], '', $raw);

        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function numbersDiffer(?float $oldValue, ?float $newValue): bool
    {
        if ($oldValue === null && $newValue === null) {
            return false;
        }

        if ($oldValue === null || $newValue === null) {
            return true;
        }

        return abs($oldValue - $newValue) > 0.00001;
    }

    private function isPriceUpdated(?float $oldPrice, ?float $newPrice): bool
    {
        if ($oldPrice === null || $newPrice === null) {
            return false;
        }

        return $this->numbersDiffer($oldPrice, $newPrice);
    }

    private function formatPrice(float $price): string
    {
        $value = number_format($price, 4, '.', '');

        return rtrim(rtrim($value, '0'), '.');
    }

    private function isNameMismatch(?string $csvName, string $siteName): bool
    {
        $csvNormalized = $this->normalizeName((string) ($csvName ?? ''));
        $siteNormalized = $this->normalizeName($siteName);

        if ($csvNormalized === '' || $siteNormalized === '') {
            return false;
        }

        return $csvNormalized !== $siteNormalized;
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value);

        return $value ?? '';
    }

    private function createPlaceholderProduct(
        IntegrationConnection $wooConnection,
        string $sku,
        ?string $csvName,
        ?float $price,
        ?float $quantity
    ): WooProduct {
        $existing = WooProduct::query()
            ->where('connection_id', $wooConnection->id)
            ->where('sku', $sku)
            ->first();

        if ($existing instanceof WooProduct) {
            return $existing;
        }

        $wooId = $this->generatePlaceholderWooId($wooConnection->id, $sku);

        while (
            WooProduct::query()
                ->where('connection_id', $wooConnection->id)
                ->where('woo_id', $wooId)
                ->exists()
        ) {
            $wooId++;
        }

        $formattedPrice = $price !== null ? $this->formatPrice($price) : null;
        $safeName = $this->resolvePlaceholderName($sku, $csvName);
        $safeQuantity = (float) ($quantity ?? 0);

        return WooProduct::query()->create([
            'connection_id' => $wooConnection->id,
            'woo_id' => $wooId,
            'type' => 'external',
            'status' => 'draft',
            'sku' => $sku,
            'name' => $safeName,
            'slug' => null,
            'short_description' => null,
            'description' => null,
            'regular_price' => $formattedPrice,
            'sale_price' => null,
            'price' => $formattedPrice,
            'stock_status' => $safeQuantity > 0 ? 'instock' : 'outofstock',
            'manage_stock' => true,
            'woo_parent_id' => null,
            'main_image_url' => null,
            'data' => [
                'source' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                'placeholder' => true,
                'csv_name' => $csvName,
                'placeholder_reason' => 'SKU exists in accounting feed but is missing in Woo import.',
            ],
            'source' => WooProduct::SOURCE_WINMENTOR_CSV,
            'is_placeholder' => true,
        ]);
    }

    private function generatePlaceholderWooId(int $connectionId, string $sku): int
    {
        $hash = (int) sprintf('%u', crc32($connectionId.'|'.$sku));

        return 8_000_000_000_000_000_000 + $hash;
    }

    private function resolvePlaceholderName(string $sku, ?string $csvName): string
    {
        $name = $this->sanitizeMentorName((string) ($csvName ?? ''));

        if ($name !== '') {
            return mb_substr($name, 0, 255);
        }

        return mb_substr('Produs contabilitate '.$sku, 0, 255);
    }

    private function sanitizeMentorName(string $value): string
    {
        $name = trim($value);

        if ($name === '') {
            return '';
        }

        if (! preg_match('/^\p{L}/u', $name)) {
            $name = ltrim((string) mb_substr($name, 1));
        }

        return trim($name);
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<string, mixed>  $error
     */
    private function appendError(array &$errors, array $error): void
    {
        if (count($errors) < 200) {
            $errors[] = $error;
        }
    }
}
