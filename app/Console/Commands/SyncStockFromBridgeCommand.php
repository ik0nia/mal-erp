<?php

namespace App\Console\Commands;

use App\Jobs\PushWinmentorPricesToWooJob;
use App\Models\IntegrationConnection;
use App\Models\ProductPriceLog;
use App\Models\ProductStock;
use App\Models\SyncRun;
use App\Models\WooProduct;
use App\Services\Winmentor\DailyStockMetricAggregator;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincronizează stocul și prețul de vânzare din WinMentor Bridge.
 *
 * Filtrare:
 *   - Doar articolele din clasa configurată (default: 1)
 *   - Doar gestiunea configurată în settings conexiunii (default: MP)
 *
 * Update:
 *   - product_stocks.quantity + synced_at — dacă stocul s-a modificat
 *   - woo_products.regular_price + ProductPriceLog + push WooCommerce — dacă prețul s-a modificat
 *
 * Rulat la 5 minute, luni–sâmbătă 08:00–17:30 (Europe/Bucharest).
 */
class SyncStockFromBridgeCommand extends Command
{
    protected $signature = 'winmentor:sync-stock-bridge
                            {--dry-run : Afișează modificările fără a le salva}
                            {--force-refresh : Forțează refresh din COM ignorând cache-ul Bridge}';

    protected $description = 'Sincronizează stoc și preț de vânzare din WinMentor Bridge (clasa 1, gestiunea configurată)';

    /** SKU-uri redenumite pe site în această rulare (schimbare EAN) — cer reindex FiboSearch */
    private int $eanRenames = 0;

    public function handle(WinmentorBridgeClient $bridge, DailyStockMetricAggregator $aggregator): int
    {
        $dryRun      = $this->option('dry-run');
        $forceRefresh = $this->option('force-refresh');

        // ── 1. Citim configurația din conexiunea Bridge ──────────────────────────
        $connection = IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_WINMENTOR_BRIDGE)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            $this->error('Conexiune WinMentor Bridge nu a fost găsită sau e inactivă.');
            return self::FAILURE;
        }

        $settings = is_array($connection->settings) ? $connection->settings : json_decode($connection->settings, true);
        $firma    = $settings['firma']    ?? 'MAL2019';
        $luna     = (int) ($settings['luna']     ?? now()->month);
        $an       = (int) ($settings['an']       ?? now()->year);
        $gestiune = $settings['gestiune'] ?? 'MP';
        $clasa    = $settings['clasa']    ?? '1';

        $this->info("Firmă: {$firma} | {$luna}/{$an} | Gestiune: {$gestiune} | Clasă: {$clasa}");

        // ── 2. Selectare firmă + lună ────────────────────────────────────────────
        $bridge->selectFirmaForMonth($an, $luna, $firma);

        // ── 3. SyncRun ───────────────────────────────────────────────────────────
        $run = SyncRun::create([
            'provider'      => IntegrationConnection::PROVIDER_WINMENTOR_BRIDGE,
            'location_id'   => $connection->location_id,
            'connection_id' => $connection->id,
            'type'          => 'winmentor_bridge_stock',
            'status'        => SyncRun::STATUS_RUNNING,
            'started_at'    => now(),
            'stats'         => json_encode(['phase' => 'fetching']),
        ]);

        try {
            // ── 4. Fetch toate articolele din Bridge ─────────────────────────────
            $items = $this->fetchAllItems($bridge, $gestiune, $clasa, $forceRefresh);
            $this->info("Articole primite din Bridge (clasa {$clasa}, gestiune {$gestiune}): " . count($items));

            if (empty($items)) {
                $this->warn('Niciun articol primit. Verifică gestiunea și clasa configurată.');
                $run->update(['status' => SyncRun::STATUS_SUCCESS, 'finished_at' => now(),
                    'stats' => json_encode(['total_bridge' => 0])]);
                return self::SUCCESS;
            }

            // ── 5. Index produse ERP după SKU ────────────────────────────────────
            $skus       = array_column($items, 'codExtern');
            $bridgeSkus = array_fill_keys(array_filter($skus), true);
            $products = WooProduct::whereIn('sku', $skus)
                ->where(function ($q) {
                    $q->where('type', '!=', 'external')->orWhere('is_placeholder', true);
                })
                // La SKU duplicat (produs real + placeholder), keyBy păstrează
                // ultimul rând — ordonăm ca produsul real să câștige.
                ->orderByDesc('is_placeholder')
                ->get()
                ->keyBy('sku');

            // Index stocuri existente
            $stocks = ProductStock::whereIn('woo_product_id', $products->pluck('id'))
                ->where('location_id', $connection->location_id)
                ->get()
                ->keyBy('woo_product_id');

            // Index conexiuni WooCommerce pentru push preț
            $wooConnections = IntegrationConnection::where('location_id', $connection->location_id)
                ->where('provider', 'woocommerce')
                ->where('is_active', true)
                ->get();

            // Produse cu feed furnizor activ (stoc 0 + feed → onbackorder, nu outofstock)
            $productIdsWithActiveFeed = array_fill_keys(
                DB::table('product_suppliers as ps')
                    ->join('supplier_feeds as sf', 'sf.supplier_id', '=', 'ps.supplier_id')
                    ->where('sf.is_active', true)
                    ->whereIn('ps.woo_product_id', $products->pluck('id')->all())
                    ->distinct()
                    ->pluck('ps.woo_product_id')
                    ->all(),
                true
            );

            // ── 6. Procesare ─────────────────────────────────────────────────────
            $now              = now();
            $stockUpserts     = [];
            $stockStatusUpdates = []; // productId → 'instock'|'onbackorder'|'outofstock'
            $priceUpdates     = [];
            $priceLogs        = [];
            $sitePrices       = []; // woo_connection_id → [woo_id => [regular_price, sale_price]]
            $siteStocks       = []; // woo_connection_id → [woo_id => [manage_stock, stock_quantity, backorders]]

            // Index conexiune WooCommerce default pentru placeholder-e
            $defaultWooConnection = IntegrationConnection::where('location_id', $connection->location_id)
                ->where('provider', 'woocommerce')
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            $stats = [
                'total_bridge'         => count($items),
                'matched'              => 0,
                'stock_updated'        => 0,
                'price_updated'        => 0,
                'unchanged'            => 0,
                'unmatched'            => 0,
                'created_placeholders' => 0,
                'daily_metrics_products' => 0,
            ];

            // Snapshot zilnic — colectat din datele Bridge pentru daily_stock_metrics
            $dailySnapshots = [];

            // Cereri de asociere EAN deschise (pending) — se auto-aprobă când sync-ul
            // detectează că EAN-ul cerut a fost deja schimbat în WinMentor. Cheie: "productId:ean".
            $pendingEanRequests = \App\Models\EanAssociationRequest::where('status', \App\Models\EanAssociationRequest::STATUS_PENDING)
                ->get()
                ->keyBy(fn ($r) => $r->woo_product_id . ':' . $r->ean);

            foreach ($items as $item) {
                $sku     = $item['codExtern'] ?? null;
                $product = $products->get($sku);

                if (! $product) {
                    // Înainte de a crea placeholder, verificăm dacă e o schimbare de EAN:
                    // căutăm un produs existent cu aceeași denumire WinMentor (winmentor_name)
                    $bridgeName = trim($item['denumire'] ?? '');
                    if (! $dryRun && $bridgeName && $sku) {
                        $existingByName = WooProduct::where('winmentor_name', $bridgeName)->first();
                        if ($existingByName && $existingByName->sku !== $sku) {
                            $oldSku = $existingByName->sku;
                            // E schimbare de EAN doar dacă vechiul EAN a dispărut din WinMentor.
                            // Dacă ambele EAN-uri există în Bridge = două articole distincte cu
                            // același nume → NU redenumim (altfel SKU-ul face ping-pong la fiecare sync).
                            if (isset($bridgeSkus[$oldSku])) {
                                Log::channel('winmentor_sync')->warning(
                                    "[BridgeStockSync] Două articole WinMentor cu același nume \"{$bridgeName}\": [{$oldSku}] și [{$sku}] — de clarificat în WinMentor"
                                );
                                $stats['unmatched']++;
                                continue;
                            }
                            // Verifică dacă noul EAN nu e deja pe alt produs (conflict real)
                            $conflict = WooProduct::where('sku', $sku)->where('id', '!=', $existingByName->id)->first();
                            if (! $conflict) {
                                // Schimbare de EAN — actualizăm SKU-ul pe produsul existent
                                $existingByName->updateQuietly(['sku' => $sku]);
                                $products->put($sku, $existingByName);
                                $product = $existingByName;

                                // Actualizăm și pe WooCommerce
                                if ($existingByName->woo_id && $defaultWooConnection) {
                                    try {
                                        $wooClient = new \App\Services\WooCommerce\WooClient($defaultWooConnection);
                                        $wooClient->updateProduct($existingByName->woo_id, ['sku' => $sku]);
                                    } catch (\Throwable $e) {
                                        $this->warn("  WooCommerce SKU update failed for {$existingByName->name}: {$e->getMessage()}");
                                    }
                                }

                                // Evidența rămâne doar în logul de sync — NU mai creăm
                                // EanAssociationRequest (aglomera tabelul de cereri cu mii de
                                // log-uri auto; tabelul e doar pentru fluxul manual de scanare).
                                Log::channel('winmentor_sync')->info(
                                    "[BridgeStockSync] EAN schimbat automat: \"{$existingByName->name}\" {$oldSku} → {$sku}"
                                );

                                $this->eanRenames++;
                                $this->info("  EAN schimbat automat: {$existingByName->name} ({$oldSku} → {$sku})");
                                $stats['matched']++;
                                goto afterMatch;
                            }
                        }
                    }

                    if (! $dryRun && $defaultWooConnection && $sku) {
                        $product = $this->createPlaceholderProduct(
                            wooConnection: $defaultWooConnection,
                            sku: $sku,
                            name: $item['denumire'] ?? null,
                            price: (float) str_replace(',', '.', $item['pretCuTVA'] ?? '0'),
                            quantity: (float) str_replace(',', '.', $item['stoc'] ?? '0'),
                            activeSkus: $bridgeSkus,
                        );
                        $products->put($sku, $product);
                        if ($product->wasRecentlyCreated) {
                            $stats['created_placeholders']++;
                        }
                    } else {
                        $stats['unmatched']++;
                        continue;
                    }
                }

                $stats['matched']++;

                afterMatch:

                // Auto-aprobare cerere asociere EAN: dacă există o cerere pending pentru acest
                // produs cu EAN-ul cerut și EAN-ul a fost deja schimbat în WinMentor (= a venit
                // pe item-ul curent), o marcăm aprobată automat. Nu mai e nevoie de aprobare manuală.
                if (! $dryRun && $sku) {
                    $reqKey = $product->id . ':' . $sku;
                    if ($pendingReq = $pendingEanRequests->get($reqKey)) {
                        $pendingReq->update([
                            'status'       => \App\Models\EanAssociationRequest::STATUS_APPROVED,
                            'processed_by' => 1,
                            'processed_at' => now(),
                            'notes'        => trim(($pendingReq->notes ? $pendingReq->notes . "\n" : '')
                                . 'Auto-aprobat: schimbarea EAN detectată în WinMentor la sync stoc.'),
                        ]);
                        $pendingEanRequests->forget($reqKey);
                        Log::channel('winmentor_sync')->info(
                            "[BridgeStockSync] Cerere asociere EAN auto-aprobată: \"{$product->name}\" → {$sku}"
                        );
                    }
                }

                // Populăm winmentor_name dacă lipsește (pentru match viitor la schimbare EAN)
                $bridgeName = trim($item['denumire'] ?? '');
                if (! $dryRun && $bridgeName && ! $product->winmentor_name) {
                    $product->updateQuietly(['winmentor_name' => $bridgeName]);
                }

                $newQty   = (float) str_replace(',', '.', $item['stoc'] ?? '0');
                $newPrice = (float) str_replace(',', '.', $item['pretCuTVA'] ?? '0'); // preț cu TVA = regular_price

                // Colectăm snapshot pentru daily_stock_metrics
                if ($sku) {
                    $dailySnapshots[] = [
                        'reference_product_id' => $sku,
                        'woo_product_id'       => $product->id,
                        'quantity'             => $newQty,
                        'sell_price'           => $newPrice > 0 ? $newPrice : null,
                    ];
                }
                $oldQty   = (float) ($stocks->get($product->id)?->quantity ?? 0);
                $oldPrice = (float) ($product->regular_price ?? 0);

                $noExistingRecord = $stocks->get($product->id) === null;
                $stockChanged = $noExistingRecord || abs($newQty - $oldQty) > 0.001;
                $priceChanged = $newPrice > 0 && abs($newPrice - $oldPrice) > 0.001;

                if (! $stockChanged && ! $priceChanged) {
                    $stats['unchanged']++;
                    // Actualizăm synced_at chiar dacă stocul e neschimbat
                    $stockUpserts[$product->id] = [
                        'woo_product_id' => $product->id,
                        'location_id'    => $connection->location_id,
                        'quantity'       => $newQty,
                        'price'          => $newPrice > 0 ? $newPrice : ($stocks->get($product->id)?->price ?? null),
                        'source'         => IntegrationConnection::PROVIDER_WINMENTOR_BRIDGE,
                        'sync_run_id'    => $run->id,
                        'synced_at'      => $now,
                        'created_at'     => $stocks->get($product->id)?->created_at ?? $now,
                        'updated_at'     => $now,
                    ];
                    continue;
                }

                if ($stockChanged) {
                    $stats['stock_updated']++;
                    $stockUpserts[$product->id] = [
                        'woo_product_id' => $product->id,
                        'location_id'    => $connection->location_id,
                        'quantity'       => $newQty,
                        'price'          => $newPrice > 0 ? $newPrice : ($stocks->get($product->id)?->price ?? null),
                        'source'         => IntegrationConnection::PROVIDER_WINMENTOR_BRIDGE,
                        'sync_run_id'    => $run->id,
                        'synced_at'      => $now,
                        'created_at'     => $stocks->get($product->id)?->created_at ?? $now,
                        'updated_at'     => $now,
                    ];

                    if ($newQty > 0) {
                        $newStatus = 'instock';
                    } elseif (isset($productIdsWithActiveFeed[$product->id])) {
                        $newStatus = 'onbackorder'; // stoc 0, dar disponibil prin feed furnizor
                    } else {
                        $newStatus = 'outofstock';
                    }

                    $statusChanged = (string) ($product->stock_status ?? '') !== $newStatus;
                    if ($statusChanged) {
                        $stockStatusUpdates[$product->id] = $newStatus;
                    }

                    // Cantitatea pe care WooCommerce o stochează e ÎNTREAGĂ; un stoc
                    // sub-unitar (ex. 0.46 kg) ar deveni 0 buc pe site. De aceea statusul
                    // de pe SITE urmează cantitatea întreagă — altfel ar fi instock + 0 buc
                    // = coș blocat (fantomă). ERP păstrează statusul fin (din WinMentor).
                    $siteQty = max(0, (int) $newQty);
                    $siteStatus = $siteQty > 0
                        ? 'instock'
                        : (isset($productIdsWithActiveFeed[$product->id]) ? 'onbackorder' : 'outofstock');
                    $oldSiteQty = max(0, (int) $oldQty);
                    $siteStatusChanged = ($oldSiteQty > 0) !== ($siteQty > 0);

                    // Push stoc la WooCommerce ori de câte ori se schimbă cantitatea (nu doar
                    // statusul). Site-ul gestionează stocul (manage_stock=yes) cu cantitatea
                    // REALĂ din ERP → WooCommerce blochează comenzile peste stoc la coș/checkout.
                    //
                    // backorders:
                    //   - produse cu feed furnizor activ → 'notify' = clientul poate comanda peste
                    //     stoc, iar diferența apare clar ca „precomandă / pe bază de comandă";
                    //   - produse fără feed → 'no' = nu se poate comanda mai mult decât avem.
                    //
                    // Fix bug-uri istorice:
                    //   1) push-ul nu trimitea 'stock_status' → job-ul default-a pe 'instock',
                    //      deci produse epuizate ajungeau instock+0buc = coș blocat (fantomă);
                    //   2) cantitatea se trimitea doar la schimbare de status → _stock pe site
                    //      driftează la 0 prin comenzi online și produsul rămânea blocat.
                    //
                    // Flush cache nginx DOAR la schimbare de disponibilitate (status), nu la
                    // fiecare modificare de cantitate (altfel cache-ul s-ar goli mereu, iar
                    // enforcement-ul la coș citește oricum _stock live din DB).
                    foreach ($wooConnections as $wooConn) {
                        if ($product->woo_id && ($product->status ?? '') === 'publish') {
                            $siteStocks[$wooConn->id][$product->woo_id] = [
                                'manage_stock'   => true,
                                'stock_quantity' => $siteQty,
                                'stock_status'   => $siteStatus,
                                'backorders'     => isset($productIdsWithActiveFeed[$product->id]) ? 'notify' : 'no',
                                'flush'          => $statusChanged || $siteStatusChanged,
                            ];
                        }
                    }
                }

                // Produse cu feed furnizor activ + stoc WM 0 → nu suprascrie prețul (vine din feed)
                // + dacă stocul tocmai a scăzut la 0, activăm prețul furnizor imediat
                $hasFeed = isset($productIdsWithActiveFeed[$product->id]);
                $skipPrice = $priceChanged && $newQty <= 0 && $hasFeed;

                if ($skipPrice && $stockChanged && $oldQty > 0 && $newQty <= 0) {
                    // Stoc tocmai ajuns la 0 — calculăm prețul furnizor (purchase_price * markup * TVA)
                    $feedRow = DB::table('product_suppliers as ps')
                        ->join('supplier_feeds as sf', 'sf.supplier_id', '=', 'ps.supplier_id')
                        ->where('sf.is_active', true)
                        ->where('ps.woo_product_id', $product->id)
                        ->whereNotNull('ps.purchase_price')
                        ->where('ps.purchase_price', '>', 0)
                        ->select('ps.purchase_price', 'sf.settings')
                        ->first();

                    $feedPrice = null;
                    if ($feedRow) {
                        $settings = json_decode($feedRow->settings ?? '{}', true);
                        $markup = (float) ($settings['markup'] ?? 0);
                        $vat = (float) ($settings['vat'] ?? 21);
                        $feedPrice = round((float) $feedRow->purchase_price * (1 + $markup / 100) * (1 + $vat / 100), 2);
                    }

                    if ($feedPrice && $feedPrice > 0 && abs($feedPrice - $oldPrice) >= 0.01) {
                        $stats['price_updated']++;
                        $priceUpdates[$product->id] = (float) $feedPrice;

                        $priceLogs[] = [
                            'woo_product_id' => $product->id,
                            'location_id'    => $connection->location_id,
                            'old_price'      => $oldPrice,
                            'new_price'      => (float) $feedPrice,
                            'source'         => 'supplier_feed_fallback',
                            'sync_run_id'    => $run->id,
                            'payload'        => json_encode(['reason' => 'stoc_zero_feed_price', 'sku' => $sku], JSON_UNESCAPED_UNICODE),
                            'changed_at'     => $now,
                            'created_at'     => $now,
                            'updated_at'     => $now,
                        ];

                        foreach ($wooConnections as $wooConn) {
                            if ($product->woo_id && $product->woo_id < 1_000_000_000_000_000) {
                                $sitePrices[$wooConn->id][$product->woo_id] = [
                                    'woo_id'        => $product->woo_id,
                                    'sku'           => $product->sku,
                                    'regular_price' => (string) $feedPrice,
                                    'sale_price'    => $product->sale_price ?? '',
                                ];
                            }
                        }
                    }
                }

                if ($priceChanged && ! $skipPrice) {
                    $stats['price_updated']++;
                    $priceUpdates[$product->id] = $newPrice;

                    $priceLogs[] = [
                        'woo_product_id' => $product->id,
                        'location_id'    => $connection->location_id,
                        'old_price'      => $oldPrice,
                        'new_price'      => $newPrice,
                        'source'         => IntegrationConnection::PROVIDER_WINMENTOR_BRIDGE,
                        'sync_run_id'    => $run->id,
                        'payload'        => json_encode(['sku' => $sku, 'name' => $item['denumire'] ?? ''], JSON_UNESCAPED_UNICODE),
                        'changed_at'     => $now,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];

                    // Pregătim push spre WooCommerce
                    foreach ($wooConnections as $wooConn) {
                        if ($product->woo_id && $product->woo_id < 1_000_000_000_000_000) {
                            $sitePrices[$wooConn->id][$product->woo_id] = [
                                'woo_id'        => $product->woo_id,
                                'sku'           => $product->sku,
                                'regular_price' => (string) $newPrice,
                                'sale_price'    => $product->sale_price ?? '',
                            ];
                        }
                    }

                    if ($dryRun) {
                        $this->line("  PREȚ {$product->sku} | {$product->name} | {$oldPrice} → {$newPrice} RON");
                    }
                }

                if ($stockChanged && $dryRun) {
                    $this->line("  STOC {$product->sku} | {$product->name} | {$oldQty} → {$newQty} buc");
                }
            }

            // ── 7. Salvare ───────────────────────────────────────────────────────
            if (! $dryRun) {
                // Stocuri
                if (! empty($stockUpserts)) {
                    foreach (array_chunk(array_values($stockUpserts), 500) as $chunk) {
                        ProductStock::query()->upsert(
                            $chunk,
                            ['woo_product_id', 'location_id'],
                            ['quantity', 'price', 'source', 'sync_run_id', 'synced_at', 'updated_at']
                        );
                    }
                }

                // stock_status pe woo_products (actualizat de Bridge)
                if (! empty($stockStatusUpdates)) {
                    foreach (array_chunk($stockStatusUpdates, 500, true) as $chunk) {
                        foreach ($chunk as $productId => $status) {
                            WooProduct::whereKey($productId)->update([
                                'stock_status' => $status,
                                'updated_at'   => $now,
                            ]);
                        }
                    }
                }

                // Prețuri woo_products
                if (! empty($priceUpdates)) {
                    foreach (array_chunk($priceUpdates, 500, true) as $chunk) {
                        foreach ($chunk as $productId => $price) {
                            WooProduct::whereKey($productId)->update([
                                'regular_price' => $price,
                                'price'         => $price,
                                'updated_at'    => $now,
                            ]);
                        }
                    }
                }

                // Price logs
                if (! empty($priceLogs)) {
                    foreach (array_chunk($priceLogs, 500) as $chunk) {
                        ProductPriceLog::insert($chunk);
                    }
                }

                // Push prețuri spre WooCommerce (direct SQL, batch 100)
                foreach ($sitePrices as $wooConnId => $priceMap) {
                    foreach (array_chunk($priceMap, 100, true) as $chunk) {
                        PushWinmentorPricesToWooJob::dispatch((int) $run->id, $wooConnId, $chunk);
                    }
                }

                // Push stoc/backorders spre WooCommerce (direct SQL, batch 100)
                foreach ($siteStocks as $wooConnId => $stockMap) {
                    foreach (array_chunk($stockMap, 100, true) as $chunk) {
                        \App\Jobs\PushWinmentorStockToWooJob::dispatch((int) $run->id, $wooConnId, $chunk);
                    }
                }

                // ── Snapshot daily_stock_metrics ─────────────────────────────────
                if (! empty($dailySnapshots)) {
                    try {
                        $snapshotCount = $aggregator->recordSnapshots($now, $dailySnapshots);
                        $stats['daily_metrics_products'] = $snapshotCount;
                    } catch (\Throwable $e) {
                        Log::channel('winmentor_sync')->warning('[BridgeStockSync] daily_stock_metrics snapshot failed: ' . $e->getMessage());
                    }
                }
            }

            // ── 7b. Placeholder pentru articole cu stoc 0 (lipsă din /api/stocuri) ─
            // /api/stocuri omite articolele cu stoc=0; le detectăm din /api/articole
            if (! $dryRun && $defaultWooConnection) {
                $allArticole = $bridge->fetchAllArticoleSkuMap();

                // SKU-uri active = stocuri + toate articolele (pentru garda anti ping-pong EAN)
                $articoleSkus = $bridgeSkus;
                foreach ($allArticole as $article) {
                    if (! empty($article['codExtern'])) {
                        $articoleSkus[$article['codExtern']] = true;
                    }
                }

                foreach ($allArticole as $skuLower => $article) {
                    if (($article['simbolClasa']   ?? '') !== $clasa)    continue;
                    if (($article['gestImplicita'] ?? '') !== $gestiune) continue;

                    $sku = $article['codExtern'] ?? null;
                    if (! $sku) continue;

                    // Deja procesat prin stocuri sau existent în ERP
                    if ($products->has($sku)) continue;

                    $price = (float) str_replace(',', '.', $article['pretVCuTVA'] ?? $article['pretVanzare'] ?? '0');
                    $placeholder = $this->createPlaceholderProduct(
                        wooConnection: $defaultWooConnection,
                        sku: $sku,
                        name: $article['denumire'] ?? null,
                        price: $price,
                        quantity: 0,
                        activeSkus: $articoleSkus,
                    );

                    if ($placeholder->wasRecentlyCreated) {
                        $stats['created_placeholders']++;
                        $products->put($sku, $placeholder);
                    }
                }
            }

            // ── 8. Finalizare ────────────────────────────────────────────────────
            // Notificare admini pentru placeholder-e noi
            if (! $dryRun && $stats['created_placeholders'] > 0) {
                $this->notifyNewPlaceholders($stats['created_placeholders']);
            }

            $this->line(str_repeat('─', 60));
            $this->info("Bridge: {$stats['total_bridge']} | matched: {$stats['matched']} | stoc: {$stats['stock_updated']} | preț: {$stats['price_updated']} | neschimbat: {$stats['unchanged']} | neidentificat: {$stats['unmatched']} | placeholder nou: {$stats['created_placeholders']}");

            if (! $dryRun) {
                $run->update([
                    'status'      => SyncRun::STATUS_SUCCESS,
                    'finished_at' => now(),
                    'stats'       => json_encode($stats),
                ]);

                // Cache-ul site-ului se golește din job-urile de push (FlushWooCacheJob),
                // DUPĂ ce SQL-ul a fost scris efectiv — nu de aici (race condition).
                // FiboSearch reindex doar când s-a schimbat un SKU pe site (rename EAN),
                // nu la fiecare rulare (consuma 1-3 min CPU pe server degeaba).
                if ($this->eanRenames > 0) {
                    try {
                        (new \App\Services\WooCommerce\WooDirectSqlService)->afterSync();
                    } catch (\Throwable $e) {
                        Log::channel('winmentor_sync')->warning('[BridgeStockSync] afterSync failed: '.$e->getMessage());
                    }
                }
            }

            Log::channel('winmentor_sync')->info('[BridgeStockSync] Finalizat', array_merge($stats, compact('firma', 'gestiune')));

        } catch (\Throwable $e) {
            $run->update(['status' => SyncRun::STATUS_FAILED, 'finished_at' => now(),
                'errors' => json_encode(['message' => $e->getMessage()])]);
            Log::channel('winmentor_sync')->error('[BridgeStockSync] Eroare: ' . $e->getMessage());
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ─── Creare produs placeholder pentru SKU nou din Bridge ─────────────────────

    private function createPlaceholderProduct(
        IntegrationConnection $wooConnection,
        string $sku,
        ?string $name,
        float $price,
        float $quantity,
        array $activeSkus = [],
    ): WooProduct {
        $existing = WooProduct::where('connection_id', $wooConnection->id)
            ->where('sku', $sku)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Verifică dacă există un produs cu exact aceeași denumire WinMentor — posibil schimbare de EAN
        // Comparăm DOAR cu winmentor_name (denumirea exactă din WinMentor), nu cu name (editabilă pe site)
        if ($name) {
            $trimmedName = trim($name);
            $byName = WooProduct::where('connection_id', $wooConnection->id)
                ->where('winmentor_name', $trimmedName)
                ->first();

            if ($byName) {
                $oldSku = $byName->sku;

                // Redenumim doar dacă vechiul EAN a dispărut din WinMentor;
                // altfel sunt două articole distincte cu același nume.
                if (! isset($activeSkus[$oldSku])) {
                    $byName->update(['sku' => $sku]);
                    $this->eanRenames++;
                    Log::channel('winmentor_sync')->info("[BridgeStockSync] SKU actualizat (schimbare EAN): [{$oldSku}] → [{$sku}] — \"{$trimmedName}\"");
                    $this->line("  [SKU UPDATE] \"{$trimmedName}\": {$oldSku} → {$sku}");
                    return $byName;
                }

                Log::channel('winmentor_sync')->warning(
                    "[BridgeStockSync] Două articole WinMentor cu același nume \"{$trimmedName}\": [{$oldSku}] și [{$sku}] — creez placeholder separat"
                );
            }
        }

        $wooId = $this->generatePlaceholderWooId($wooConnection->id, $sku);
        while (WooProduct::where('connection_id', $wooConnection->id)->where('woo_id', $wooId)->exists()) {
            $wooId++;
        }

        $safeName      = $name ? mb_substr(trim($name), 0, 255) : mb_substr('Produs contabilitate ' . $sku, 0, 255);
        $formattedPrice = $price > 0 ? number_format($price, 4, '.', '') : null;
        if ($formattedPrice !== null) {
            $formattedPrice = rtrim(rtrim($formattedPrice, '0'), '.');
        }

        return WooProduct::create([
            'connection_id'  => $wooConnection->id,
            'woo_id'         => $wooId,
            'type'           => 'simple',
            'status'         => 'draft',
            'sku'            => $sku,
            'name'           => $safeName,
            'winmentor_name' => $name ? mb_substr(trim($name), 0, 255) : null,
            'slug'           => null,
            'regular_price'  => $formattedPrice,
            'price'          => $formattedPrice,
            'stock_status'   => $quantity > 0 ? 'instock' : 'outofstock',
            'manage_stock'   => true,
            'source'         => WooProduct::SOURCE_WINMENTOR_BRIDGE,
            'is_placeholder' => true,
            'data'           => [
                'source'             => \App\Models\IntegrationConnection::PROVIDER_WINMENTOR_BRIDGE,
                'placeholder'        => true,
                'placeholder_reason' => 'SKU exists in WinMentor Bridge but is missing in Woo import.',
            ],
        ]);
    }

    private function generatePlaceholderWooId(int $connectionId, string $sku): int
    {
        $hash = (int) sprintf('%u', crc32($connectionId . '|' . $sku));
        return 8_000_000_000_000_000_000 + $hash;
    }

    private function notifyNewPlaceholders(int $count): void
    {
        $recipients = \App\Models\User::where('is_admin', true)->get();
        if ($recipients->isEmpty()) {
            return;
        }

        \Filament\Notifications\Notification::make()
            ->title('Produse noi din WinMentor')
            ->body("{$count} " . ($count === 1 ? 'produs nou necesită' : 'produse noi necesită') . ' pregătire pentru site.')
            ->icon('heroicon-o-sparkles')
            ->warning()
            ->actions([
                \Filament\Actions\Action::make('vezi')
                    ->label('Vezi produsele')
                    ->url(route('filament.app.pages.new-winmentor-products'))
                    ->button(),
            ])
            ->sendToDatabase($recipients);
    }

    // ─── Fetch toate articolele din Bridge, paginat ───────────────────────────────

    private function fetchAllItems(WinmentorBridgeClient $bridge, string $gestiune, string $clasa, bool $refresh): array
    {
        $ref    = new \ReflectionClass($bridge);
        $method = $ref->getMethod('get');
        $method->setAccessible(true);

        $params = ['pageSize' => 5000, 'page' => 1];
        if ($refresh) {
            $params['refresh'] = 'true';
        }

        $all  = [];
        $page = 1;

        do {
            $params['page'] = $page;
            $r    = $method->invoke($bridge, '/api/stocuri', $params);
            $data = $r['data'] ?? [];

            foreach ($data['items'] ?? [] as $item) {
                // Filtrăm strict după clasă și gestiune
                if (($item['simbolClasa'] ?? '') !== $clasa) continue;
                if (($item['simbolGestiune'] ?? '') !== $gestiune) continue;

                $all[] = $item;
            }

            $hasNext    = $data['hasNextPage'] ?? false;
            $totalPages = (int) ($data['totalPages'] ?? 1);
            $page++;
        } while ($hasNext && $page <= $totalPages);

        // Dacă același SKU apare de mai multe ori (sub-locații în aceeași gestiune),
        // sumăm cantitățile și păstrăm prețul primei intrări.
        $merged = [];
        foreach ($all as $item) {
            $sku = $item['codExtern'];
            if (! isset($merged[$sku])) {
                $merged[$sku] = $item;
            } else {
                $existing  = (float) str_replace(',', '.', $merged[$sku]['stoc'] ?? '0');
                $extra     = (float) str_replace(',', '.', $item['stoc'] ?? '0');
                $merged[$sku]['stoc'] = str_replace('.', ',', (string) ($existing + $extra));
                // Rezervat: sumăm și el
                $existingRez = (float) str_replace(',', '.', $merged[$sku]['stocRezervat'] ?? '0');
                $extraRez    = (float) str_replace(',', '.', $item['stocRezervat'] ?? '0');
                $merged[$sku]['stocRezervat'] = str_replace('.', ',', (string) ($existingRez + $extraRez));
            }
        }

        return array_values($merged);
    }
}
