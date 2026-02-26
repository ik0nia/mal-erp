<?php

namespace App\Actions\Winmentor;

use App\Jobs\PushWinmentorPricesToWooJob;
use App\Models\IntegrationConnection;
use App\Models\ProductPriceLog;
use App\Models\ProductStock;
use App\Models\SyncRun;
use App\Models\WooProduct;
use App\Services\Winmentor\DailyStockMetricAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ImportWinmentorCsvAction
{
    public function execute(IntegrationConnection $connection, ?SyncRun $run = null): SyncRun
    {
        if (! $connection->isWinmentorCsv()) {
            throw new RuntimeException('Connection provider is not winmentor_csv.');
        }

        DB::connection()->disableQueryLog();
        $initialStats = [
            'phase' => 'queued',
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
            'site_price_push_jobs' => 0,
            'site_price_push_queued' => 0,
            'site_price_push_processed' => 0,
            'created_placeholders' => 0,
            'daily_metrics_products' => 0,
            'daily_metrics_failed' => false,
            'local_started_at' => null,
            'local_finished_at' => null,
            'push_started_at' => null,
            'push_finished_at' => null,
            'last_heartbeat_at' => null,
            'missing_skus_sample' => [],
            'name_mismatch_sample' => [],
        ];

        if ($run instanceof SyncRun) {
            if ((int) $run->connection_id !== (int) $connection->id) {
                throw new RuntimeException('Sync run does not belong to selected connection.');
            }

            $run->refresh();

            if ($run->status === SyncRun::STATUS_CANCELLED) {
                return $run;
            }

            $run->update([
                'provider' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                'location_id' => $connection->location_id,
                'connection_id' => $connection->id,
                'type' => SyncRun::TYPE_WINMENTOR_STOCK,
                'status' => SyncRun::STATUS_RUNNING,
                'started_at' => Carbon::now(),
                'finished_at' => null,
                'stats' => array_merge(
                    $initialStats,
                    is_array($run->stats) ? $run->stats : [],
                    [
                        'phase' => 'local_import',
                        'local_started_at' => now()->toIso8601String(),
                        'local_finished_at' => null,
                        'push_started_at' => null,
                        'push_finished_at' => null,
                        'site_price_push_jobs' => 0,
                        'site_price_push_queued' => 0,
                        'site_price_push_processed' => 0,
                        'site_price_updates' => 0,
                        'site_price_update_failures' => 0,
                        'last_heartbeat_at' => now()->toIso8601String(),
                    ]
                ),
                'errors' => [],
            ]);
        } else {
            $run = SyncRun::query()->create([
                'provider' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                'location_id' => $connection->location_id,
                'connection_id' => $connection->id,
                'type' => SyncRun::TYPE_WINMENTOR_STOCK,
                'status' => SyncRun::STATUS_RUNNING,
                'started_at' => Carbon::now(),
                'stats' => array_merge($initialStats, [
                    'phase' => 'local_import',
                    'local_started_at' => now()->toIso8601String(),
                    'last_heartbeat_at' => now()->toIso8601String(),
                ]),
                'errors' => [],
            ]);
        }

        $stats = array_merge($initialStats, is_array($run->stats) ? $run->stats : []);
        $errors = [];
        $startedAt = microtime(true);

        Log::info('Winmentor import started', [
            'sync_run_id' => $run->id,
            'connection_id' => $connection->id,
            'location_id' => $connection->location_id,
            'csv_url' => $connection->csvUrl(),
        ]);

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
            $stats['total_rows'] = count($rows);
            $stats['last_heartbeat_at'] = now()->toIso8601String();

            $this->persistProgress($run, $stats, $errors);

            Log::info('Winmentor CSV parsed', [
                'sync_run_id' => $run->id,
                'rows' => count($rows),
                'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
            ]);

            $skuValues = collect($rows)
                ->pluck('sku')
                ->map(fn (string $sku): string => trim($sku))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $wooConnectionIds = IntegrationConnection::query()
                ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
                ->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($connection): void {
                    $q->where('location_id', $connection->location_id)
                      ->orWhereNull('location_id');
                })
                ->pluck('id')
                ->all();

            if ($wooConnectionIds === []) {
                throw new RuntimeException('No WooCommerce connection found for this location or globally.');
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
            $batchTimestamp = Carbon::now();

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

            $productIds = collect($productsBySku)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $stocksByProductId = ProductStock::query()
                ->where('location_id', $connection->location_id)
                ->whereIn('woo_product_id', $productIds)
                ->get()
                ->keyBy('woo_product_id');

            $stockUpserts = [];
            $priceLogsToInsert = [];
            $productUpdatesById = [];
            $sitePricePushesByConnection = [];
            $dailySnapshots = [];
            $cancelled = false;

            foreach ($rows as $rowNumber => $row) {
                if (($rowNumber % 100) === 0 && $this->isRunCancellationRequested((int) $run->id)) {
                    $cancelled = true;
                    $this->appendError($errors, [
                        'message' => 'Import oprit manual din platformă.',
                    ]);

                    break;
                }

                $lineNumber = $rowNumber + 2; // +1 header and 1-indexed rows
                $stats['processed']++;

                if (($stats['processed'] % 1000) === 0) {
                    $stats['last_heartbeat_at'] = now()->toIso8601String();
                    $this->persistProgress($run, $stats, $errors);

                    Log::info('Winmentor import progress checkpoint', [
                        'sync_run_id' => $run->id,
                        'processed' => $stats['processed'],
                        'created' => $stats['created'] ?? 0,
                        'updated' => $stats['updated'] ?? 0,
                        'price_changes' => $stats['price_changes'] ?? 0,
                        'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
                    ]);
                }

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
                    $notes              = (string) ($product->erp_notes ?? '');
                    $alreadyReformatted = str_contains($notes, '[titlu-reformat]');
                    $alreadyNormalized  = str_contains($notes, 'Denumire originală WinMentor:');

                    if (! $alreadyReformatted && ! $alreadyNormalized && $normalizedMentorName !== '' && $product->name !== $normalizedMentorName) {
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

                $dailySnapshots[$sku] = [
                    'reference_product_id' => $sku,
                    'woo_product_id' => (int) $product->id,
                    'quantity' => $newQuantity,
                    'sell_price' => $price,
                ];

                if ($isNew || $quantityChanged || $priceDifferent) {
                    if ($isNew) {
                        $stats['created']++;
                    } else {
                        $stats['updated']++;
                    }

                    $createdAt = $stock->created_at instanceof Carbon ? $stock->created_at : $batchTimestamp;
                    $stockUpserts[$product->id] = [
                        'woo_product_id' => $product->id,
                        'location_id' => $connection->location_id,
                        'quantity' => $newQuantity,
                        'price' => $price,
                        'source' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                        'sync_run_id' => $run->id,
                        'synced_at' => $batchTimestamp,
                        'created_at' => $createdAt,
                        'updated_at' => $batchTimestamp,
                    ];

                    $stock->quantity = $newQuantity;
                    $stock->price = $price;
                    $stock->source = IntegrationConnection::PROVIDER_WINMENTOR_CSV;
                    $stock->sync_run_id = $run->id;
                    $stock->synced_at = $batchTimestamp;
                    $stock->created_at = $createdAt;
                    $stocksByProductId->put($product->id, $stock);
                } else {
                    $stats['unchanged']++;
                }

                if ($priceUpdated) {
                    $priceLogsToInsert[] = [
                        'woo_product_id' => $product->id,
                        'location_id' => $connection->location_id,
                        'old_price' => $oldPrice,
                        'new_price' => $price,
                        'source' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                        'sync_run_id' => $run->id,
                        'payload' => json_encode([
                            'sku' => $sku,
                            'name' => $row['name'],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                        'changed_at' => $batchTimestamp,
                        'created_at' => $batchTimestamp,
                        'updated_at' => $batchTimestamp,
                    ];

                    $stats['price_changes']++;

                    if ($pushPriceToSite && ! $product->is_placeholder && $newQuantity > 0) {
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
                            $sitePricePushesByConnection[$wooConnection->id][$product->woo_id] = [
                                'row' => $lineNumber,
                                'sku' => $sku,
                                'woo_id' => (int) $product->woo_id,
                                'regular_price' => $this->formatPrice($price),
                            ];
                        }
                    }
                }

                // Push price when first seen OR stock just became positive — even if price didn't change.
                // This covers: new products on first import, and products restocked from zero.
                if (
                    $pushPriceToSite
                    && ! $product->is_placeholder
                    && ! $priceUpdated
                    && $price !== null
                    && $price > 0
                    && $newQuantity > 0
                    && ($isNew || ($quantityChanged && ($oldQuantity === null || $oldQuantity <= 0)))
                ) {
                    $wooConnection = $wooConnectionsById->get($product->connection_id);

                    if ($wooConnection instanceof IntegrationConnection && $wooConnection->is_active) {
                        $sitePricePushesByConnection[$wooConnection->id][$product->woo_id] = [
                            'row' => $lineNumber,
                            'sku' => $sku,
                            'woo_id' => (int) $product->woo_id,
                            'regular_price' => $this->formatPrice($price),
                        ];
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
                    $productUpdatesById[$product->id] = array_merge(
                        $productUpdatesById[$product->id] ?? [],
                        $productUpdates,
                        ['updated_at' => $batchTimestamp],
                    );

                    foreach ($productUpdates as $field => $value) {
                        $product->{$field} = $value;
                    }
                }
            }

            if ($stockUpserts !== []) {
                foreach (array_chunk(array_values($stockUpserts), 1000) as $stockChunk) {
                    ProductStock::query()->upsert(
                        $stockChunk,
                        ['woo_product_id', 'location_id'],
                        ['quantity', 'price', 'source', 'sync_run_id', 'synced_at', 'updated_at']
                    );
                }
            }

            if ($dailySnapshots !== []) {
                try {
                    $stats['daily_metrics_products'] = app(DailyStockMetricAggregator::class)->recordSnapshots(
                        $batchTimestamp,
                        array_values($dailySnapshots)
                    );
                    $stats['daily_metrics_failed'] = false;
                    $stats['last_heartbeat_at'] = now()->toIso8601String();
                } catch (Throwable $exception) {
                    $stats['daily_metrics_failed'] = true;
                    $this->appendError($errors, [
                        'message' => 'Daily stock metrics update failed: '.$exception->getMessage(),
                    ]);

                    Log::warning('Daily stock metrics update failed during Winmentor import', [
                        'sync_run_id' => $run->id,
                        'processed_products' => count($dailySnapshots),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            if ($priceLogsToInsert !== []) {
                foreach (array_chunk($priceLogsToInsert, 1000) as $logChunk) {
                    ProductPriceLog::query()->insert($logChunk);
                }
            }

            if ($productUpdatesById !== []) {
                foreach ($productUpdatesById as $productId => $updatePayload) {
                    WooProduct::query()
                        ->whereKey((int) $productId)
                        ->update($updatePayload);
                }
            }

            if (! $cancelled) {
                $stats['local_finished_at'] = now()->toIso8601String();
                $stats['phase'] = 'queueing_price_push';
                $stats['last_heartbeat_at'] = now()->toIso8601String();
                $this->persistProgress($run, $stats, $errors);
            }

            if (! $cancelled && $sitePricePushesByConnection !== []) {
                $queuedJobs = 0;
                $queuedUpdates = 0;

                foreach ($sitePricePushesByConnection as $wooConnectionId => $updatesByWooId) {
                    $updates = array_values($updatesByWooId);

                    foreach (array_chunk($updates, 100) as $updateChunk) {
                        PushWinmentorPricesToWooJob::dispatch((int) $run->id, (int) $wooConnectionId, $updateChunk);
                        $queuedJobs++;
                        $queuedUpdates += count($updateChunk);
                    }
                }

                $stats['site_price_push_jobs'] = $queuedJobs;
                $stats['site_price_push_queued'] = $queuedUpdates;
                $stats['phase'] = 'pushing_prices';
                $stats['push_started_at'] = $stats['push_started_at'] ?: now()->toIso8601String();
                $stats['last_heartbeat_at'] = now()->toIso8601String();

                $run->update([
                    'status' => SyncRun::STATUS_RUNNING,
                    'finished_at' => null,
                    'stats' => $stats,
                    'errors' => $errors,
                ]);

                Log::info('Winmentor local import done, queued Woo push jobs', [
                    'sync_run_id' => $run->id,
                    'queued_jobs' => $queuedJobs,
                    'queued_updates' => $queuedUpdates,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
                ]);

                return $run;
            }

            if ($cancelled) {
                $stats['phase'] = 'cancelled';
                $stats['last_heartbeat_at'] = now()->toIso8601String();

                $run->update([
                    'status' => SyncRun::STATUS_CANCELLED,
                    'finished_at' => Carbon::now(),
                    'stats' => $stats,
                    'errors' => $errors,
                ]);

                Log::warning('Winmentor import cancelled', [
                    'sync_run_id' => $run->id,
                    'processed' => $stats['processed'] ?? 0,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
                ]);

                return $run;
            }

            $stats['phase'] = 'completed';
            $stats['push_finished_at'] = now()->toIso8601String();
            $stats['last_heartbeat_at'] = now()->toIso8601String();

            $run->update([
                'status' => SyncRun::STATUS_SUCCESS,
                'finished_at' => Carbon::now(),
                'stats' => $stats,
                'errors' => $errors,
            ]);

            Log::info('Winmentor import completed without deferred pushes', [
                'sync_run_id' => $run->id,
                'processed' => $stats['processed'] ?? 0,
                'created' => $stats['created'] ?? 0,
                'updated' => $stats['updated'] ?? 0,
                'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
            ]);
        } catch (Throwable $exception) {
            $this->appendError($errors, [
                'message' => $exception->getMessage(),
            ]);

            $stats['phase'] = 'failed';
            $stats['last_heartbeat_at'] = now()->toIso8601String();

            $run->update([
                'status' => SyncRun::STATUS_FAILED,
                'finished_at' => Carbon::now(),
                'stats' => $stats,
                'errors' => $errors,
            ]);

            Log::error('Winmentor import failed', [
                'sync_run_id' => $run->id,
                'processed' => $stats['processed'] ?? 0,
                'error' => $exception->getMessage(),
                'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
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
    private function persistProgress(SyncRun $run, array $stats, array $errors): void
    {
        $run->update([
            'stats' => $stats,
            'errors' => $errors,
        ]);
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

    private function isRunCancellationRequested(int $runId): bool
    {
        return SyncRun::query()
            ->whereKey($runId)
            ->where('status', SyncRun::STATUS_CANCELLED)
            ->exists();
    }
}
