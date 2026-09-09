<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\SupplierFeed;
use App\Models\User;
use App\Notifications\PriceChangeAlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncToyaPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 600;

    private const API_BASE        = 'https://pim.toya.pl/dataapi';
    private const SPIKE_THRESHOLD = 5;
    private const DROP_THRESHOLD  = 5;

    public function __construct(
        private readonly int $feedId
    ) {}

    public function handle(): void
    {
        $feed = SupplierFeed::with('supplier')->find($this->feedId);

        if (! $feed || ! $feed->is_active) {
            Log::error("[SyncToyaPrices] Feed ID={$this->feedId} nu există sau e inactiv.");
            return;
        }

        $feed->update(['last_sync_status' => 'running', 'last_sync_at' => now()]);

        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_TOYA_API_KEY)
            ?? env('TOYA_API_KEY', 'D83FD59A4902793862EB8304');

        if (! $apiKey) {
            $this->fail($feed, 'API key Toya lipsește (AppSetting toya_api_key sau env TOYA_API_KEY).');
            return;
        }

        $supplierId   = $feed->supplier_id;
        $supplierName = $feed->supplier?->name ?? 'Toya';
        $discount     = $feed->getDiscount();
        $markup       = $feed->getMarkup();
        $vat          = $feed->getVat();

        $discountFactor = round(1 - $discount / 100, 10);
        $sellMultiplier = round((1 + $markup / 100) * (1 + $vat / 100), 10);

        Log::info("[SyncToyaPrices] Feed={$this->feedId} — discount={$discount}%, adaos={$markup}%, TVA={$vat}%");

        // 1. Fetch prețuri + stocuri
        $prices = $this->fetchBulk($apiKey, 'getPricesRo');
        if (empty($prices)) {
            $this->fail($feed, 'Nu s-au putut prelua prețurile din API.');
            return;
        }

        $stocks = $this->fetchBulk($apiKey, 'getStocksRo');
        Log::info('[SyncToyaPrices] Prețuri: ' . count($prices) . ' | Stocuri: ' . count($stocks));

        // 2. Produsele furnizorului din DB
        $rows = DB::table('product_suppliers as ps')
            ->join('woo_products as wp', 'wp.id', '=', 'ps.woo_product_id')
            ->where('ps.supplier_id', $supplierId)
            ->whereNotNull('ps.supplier_sku')
            ->select('ps.id as ps_id', 'ps.supplier_sku', 'ps.purchase_price',
                     'wp.id as product_id', 'wp.name', 'wp.sku',
                     'wp.regular_price', 'wp.stock_status', 'wp.woo_id', 'wp.status')
            ->get()
            ->keyBy('supplier_sku'); // Toya feed folosește cod intern (YT-XXXXX) ca cheie

        $now = now()->toDateTimeString();

        // Produse cu stoc WinMentor — nu le actualizăm preț/stoc (vine din Bridge)
        $wmStockProductIds = DB::table('product_stocks')
            ->where('quantity', '>', 0)
            ->groupBy('woo_product_id')
            ->pluck('woo_product_id')
            ->flip();

        // 3. Calcule în memorie — colectare bulk updates
        $psUpdates        = []; // product_suppliers: purchase_price
        $wpPriceUpdates   = []; // woo_products cu preț nou
        $wpStockUpdates   = []; // woo_products cu stoc/backorder nou
        $priceLogRows     = [];
        $wooPricePushRows = []; // push prețuri la WooCommerce
        $wooStockPushRows = []; // push manage_stock + backorders la WooCommerce
        $spikes           = [];
        $drops            = [];
        $stats            = ['updated' => 0, 'unchanged' => 0, 'missing' => 0, 'price_pushed' => 0];

        $newSkus = [];

        foreach ($prices as $code => $priceData) {
            $netPrice = (float) ($priceData['netPrice'] ?? 0);
            if ($netPrice <= 0) continue;

            $row = $rows->get($code);
            if (! $row) {
                $stats['missing']++;
                // SKU nou doar dacă e cod EAN numeric (≥13 cifre) — codurile gen YT-XXXXXXX sunt coduri interne Toya
                if (ctype_digit((string) $code) && strlen((string) $code) >= 13) {
                    $newSkus[] = [
                        'sku'   => $code,
                        'price' => number_format((float) ($priceData['netPrice'] ?? 0), 2, '.', ''),
                        'stock' => $stocks[$code]['stock'] ?? 'N/A',
                    ];
                }
                continue;
            }

            $purchasePrice    = round($netPrice * $discountFactor, 4);
            $oldPurchasePrice = (float) $row->purchase_price;
            $newSellPrice     = round($purchasePrice * $sellMultiplier, 2);
            $oldSellPrice     = (float) $row->regular_price;

            // purchase_price — mereu actualizat
            $psUpdates[] = [
                'id'             => $row->ps_id,
                'purchase_price' => $purchasePrice,
                'updated_at'     => $now,
            ];

            // Produse cu stoc WinMentor — nu modificăm preț/stoc (gestionat de Bridge sync)
            $hasWmStock = isset($wmStockProductIds[$row->product_id]);

            // stoc
            $stockFlag   = $stocks[$code]['stock'] ?? null;
            $stockStatus = match ($stockFlag) {
                'LARGE QUANTITY', 'MEDIUM QUANTITY', 'SMALL QUANTITY' => 'instock',
                'OUT OF STOCK' => 'outofstock',
                default        => null,
            };

            if ($stockStatus !== null && ! $hasWmStock) {
                $wpStockUpdates[] = [
                    'id'           => $row->product_id,
                    'stock_status' => $stockStatus,
                    'updated_at'   => $now,
                ];

                if ($row->woo_id && $row->status === 'publish' && $stockStatus !== $row->stock_status) {
                    $wooStockPushRows[] = [
                        'id'             => $row->woo_id,
                        'manage_stock'   => true,
                        'stock_quantity' => 0,
                        'backorders'     => $stockStatus === 'outofstock' ? 'no' : 'yes',
                        // doar pentru logging (array_map-ul de batch nu le trimite în SQL)
                        '_sku'           => $row->sku,
                        '_schimbare'     => $row->stock_status . '→' . $stockStatus,
                    ];
                }
            }

            // preț vânzare — skip produse cu stoc WinMentor (prețul vine din Bridge)
            if (abs($newSellPrice - $oldSellPrice) >= 0.01 && ! $hasWmStock) {
                $wpPriceUpdates[] = [
                    'id'            => $row->product_id,
                    'regular_price' => $newSellPrice,
                    'price'         => $newSellPrice,
                    'updated_at'    => $now,
                ];

                $priceLogRows[] = [
                    'woo_product_id' => $row->product_id,
                    'location_id'    => 1,
                    'source'         => 'toya_api',
                    'old_price'      => $oldSellPrice,
                    'new_price'      => $newSellPrice,
                    'changed_at'     => $now,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];

                $stats['updated']++;

                if ($row->woo_id && $row->status === 'publish') {
                    $wooPricePushRows[] = ['id' => $row->woo_id, 'regular_price' => (string) $newSellPrice];
                }
            } else {
                $stats['unchanged']++;
            }

            // Alertă modificare semnificativă preț achiziție
            if ($oldPurchasePrice > 0) {
                $pct = ($purchasePrice - $oldPurchasePrice) / $oldPurchasePrice * 100;
                $margin = $newSellPrice > 0
                    ? round(($newSellPrice - $purchasePrice) / $purchasePrice * 100, 1)
                    : null;

                $alertRow = [
                    'name'       => $row->name ?? $code,
                    'sku'        => $row->sku ?? $code,
                    'pct'        => round(abs($pct), 1),
                    'old_price'  => number_format($oldPurchasePrice, 2, '.', ''),
                    'new_price'  => number_format($purchasePrice, 2, '.', ''),
                    'sell_price' => number_format($newSellPrice, 2, '.', ''),
                    'margin'     => $margin,
                    'supplier'   => $supplierName,
                ];

                if ($pct >= self::SPIKE_THRESHOLD) {
                    $spikes[] = $alertRow;
                } elseif ($pct <= -self::DROP_THRESHOLD) {
                    $drops[] = $alertRow;
                }
            }
        }

        // 4. Bulk DB writes via CASE WHEN UPDATE
        foreach (array_chunk($psUpdates, 500) as $chunk) {
            $this->bulkUpdateById('product_suppliers', $chunk, ['purchase_price', 'updated_at']);
        }

        foreach (array_chunk($wpPriceUpdates, 500) as $chunk) {
            $this->bulkUpdateById('woo_products', $chunk, ['regular_price', 'price', 'updated_at']);
        }

        foreach (array_chunk($wpStockUpdates, 500) as $chunk) {
            $this->bulkUpdateById('woo_products', $chunk, ['stock_status', 'updated_at']);
        }

        foreach (array_chunk($priceLogRows, 500) as $chunk) {
            DB::table('product_price_logs')->insert($chunk);
        }

        Log::info("[SyncToyaPrices] DB scris — ps: " . count($psUpdates) . ", wp_price: " . count($wpPriceUpdates) . ", wp_stock: " . count($wpStockUpdates));

        // 5. Push la WooCommerce via SQL direct (fără API — instant, fără timeout)
        $directSql = new \App\Services\WooCommerce\WooDirectSqlService;

        // Chunk-uri de 2000 — un singur batch mare (>10k) depășește timeout-ul SSH de 120s
        if (! empty($wooPricePushRows)) {
            $updated = 0;
            $failed = 0;
            foreach (array_chunk($wooPricePushRows, 2000) as $chunk) {
                $result = $directSql->updatePrices($chunk);
                $updated += $result['updated'];
                $failed += $result['failed'];
            }
            $stats['price_pushed'] = $updated;
            Log::info('[SyncToyaPrices] Push prețuri direct SQL: ' . $updated . ' updated, ' . $failed . ' failed');
        }

        if (! empty($wooStockPushRows)) {
            $stockBatch = array_map(fn ($row) => [
                'id' => $row['id'],
                'stock_quantity' => $row['stock_quantity'] ?? 0,
                'stock_status' => ($row['backorders'] ?? 'no') === 'yes' ? 'onbackorder' : 'outofstock',
                'manage_stock' => $row['manage_stock'] ?? true,
                'backorders' => $row['backorders'] ?? 'no',
            ], $wooStockPushRows);

            $updated = 0;
            $failed = 0;
            foreach (array_chunk($stockBatch, 2000) as $chunk) {
                $result = $directSql->updateStock($chunk);
                $updated += $result['updated'];
                $failed += $result['failed'];
            }
            Log::info('[SyncToyaPrices] Push stoc direct SQL: ' . $updated . ' updated, ' . $failed . ' failed');
            $detalii = array_map(fn ($r) => ($r['_sku'] ?? $r['id']) . ' ' . ($r['_schimbare'] ?? ''), array_slice($wooStockPushRows, 0, 50));
            Log::info('[SyncToyaPrices] Disponibilitate schimbată: ' . implode(', ', $detalii)
                . (count($wooStockPushRows) > 50 ? ' … (+' . (count($wooStockPushRows) - 50) . ')' : ''));
        }

        if (! empty($wooPricePushRows) || ! empty($wooStockPushRows)) {
            $directSql->afterSync();
        }

        // 6. Notificare produse noi Toya (SKU-uri EAN inexistente în ERP)
        // Dedupe: la rulare orară, nu retrimitem același set de SKU-uri în aceeași zi
        $newSkusHash = md5(implode(',', array_column($newSkus, 'sku')));
        if (! empty($newSkus) && \Illuminate\Support\Facades\Cache::get('toya:new-skus-notified') !== $newSkusHash) {
            \Illuminate\Support\Facades\Cache::put('toya:new-skus-notified', $newSkusHash, now()->addDay());
            \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($newSkus) {
                $message->to('codrut@ikonia.ro')
                    ->subject('[ERP Malinco] ' . count($newSkus) . ' produse noi Toya detectate')
                    ->html($this->buildNewSkusEmailHtml($newSkus));
            });
            Log::info('[SyncToyaPrices] Email produse noi trimis — ' . count($newSkus) . ' SKU-uri noi.');
        }

        // 7. Alertă email la modificări semnificative — momentan doar codrut@ikonia.ro
        if (! empty($spikes) || ! empty($drops)) {
            $recipients = User::where('email', 'codrut@ikonia.ro')->get();

            foreach ($recipients as $user) {
                $user->notify(new PriceChangeAlertNotification($spikes, $drops, []));
            }

            Log::info('[SyncToyaPrices] Alerte trimise — spikes: ' . count($spikes) . ', drops: ' . count($drops));
        }

        $summary = "Actualizate: {$stats['updated']}, neschimbate: {$stats['unchanged']}, lipsă: {$stats['missing']}, push Woo: {$stats['price_pushed']}, alerte: " . (count($spikes) + count($drops));
        $feed->update(['last_sync_status' => 'ok', 'last_sync_summary' => $summary]);
        Log::info("[SyncToyaPrices] Gata. {$summary}");
    }

    /**
     * Bulk UPDATE by primary key using CASE WHEN — evită INSERT cu coloane lipsă.
     * @param array<array<string,mixed>> $rows  fiecare row trebuie să conțină 'id'
     * @param string[] $columns  coloanele de actualizat (inclusiv 'id' nu e necesar în lista asta)
     */
    /**
     * Execută un batch WooCommerce cu 3 retry-uri și pauze exponențiale.
     * Returnează true dacă a reușit, false dacă toate retry-urile au eșuat.
     */
    private function buildNewSkusEmailHtml(array $newSkus): string
    {
        $rows = '';
        foreach ($newSkus as $s) {
            $rows .= "<tr>
                <td style='padding:6px 12px;border-bottom:1px solid #eee;font-family:monospace'>{$s['sku']}</td>
                <td style='padding:6px 12px;border-bottom:1px solid #eee'>{$s['price']} RON</td>
                <td style='padding:6px 12px;border-bottom:1px solid #eee'>{$s['stock']}</td>
            </tr>";
        }

        $count = count($newSkus);
        $date  = now()->format('d.m.Y');

        return "
        <div style='font-family:Arial,sans-serif;max-width:700px;margin:0 auto'>
            <div style='background:#910120;padding:20px 30px'>
                <h2 style='color:#fff;margin:0'>ERP Malinco — Produse noi Toya</h2>
            </div>
            <div style='background:#F5F0E8;padding:20px 30px'>
                <p>Au fost detectate <strong>{$count} SKU-uri noi</strong> în feed-ul Toya la data de <strong>{$date}</strong> care nu există încă în ERP.</p>
                <table style='width:100%;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden'>
                    <thead>
                        <tr style='background:#910120;color:#fff'>
                            <th style='padding:8px 12px;text-align:left'>SKU / EAN</th>
                            <th style='padding:8px 12px;text-align:left'>Preț net Toya</th>
                            <th style='padding:8px 12px;text-align:left'>Stoc</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
                <p style='margin-top:16px;color:#666;font-size:13px'>
                    Rulează <code>php artisan toya:import-products</code> pentru a le importa.
                </p>
            </div>
        </div>";
    }

    private function pushWithRetry(callable $fn, string $label, int $maxAttempts = 3): bool
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $fn();
                return true;
            } catch (\Throwable $e) {
                Log::warning("{$label} batch attempt {$attempt}/{$maxAttempts} eșuat: " . $e->getMessage());
                if ($attempt < $maxAttempts) {
                    sleep($attempt * 2); // 2s, 4s între retry-uri
                }
            }
        }
        Log::error("{$label} batch abandonat după {$maxAttempts} încercări.");
        return false;
    }

    private function bulkUpdateById(string $table, array $rows, array $columns): void
    {
        if (empty($rows)) return;

        $ids      = array_column($rows, 'id');
        $setClauses = [];
        $bindings   = [];

        foreach ($columns as $col) {
            $whens = [];
            foreach ($rows as $row) {
                $whens[]    = 'WHEN ? THEN ?';
                $bindings[] = $row['id'];
                $bindings[] = $row[$col];
            }
            $setClauses[] = "`{$col}` = CASE `id` " . implode(' ', $whens) . ' END';
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE `{$table}` SET " . implode(', ', $setClauses)
             . " WHERE `id` IN ({$placeholders})";

        DB::statement($sql, array_merge($bindings, $ids));
    }

    private function fail(SupplierFeed $feed, string $reason): void
    {
        $feed->update(['last_sync_status' => 'error', 'last_sync_summary' => $reason]);
        Log::error("[SyncToyaPrices] {$reason}");
    }

    private function fetchBulk(string $apiKey, string $action): array
    {
        $response = Http::withoutVerifying()
            ->timeout(60)
            ->get(self::API_BASE, ['key' => $apiKey, 'action' => $action]);

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();
        return is_array($data) ? $data : [];
    }
}
