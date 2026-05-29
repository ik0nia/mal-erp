<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\WooCommerce\WooClient;
use App\Models\IntegrationConnection;

class SyncTemadPricesCommand extends Command
{
    protected $signature = 'temad:sync
        {--dry-run : Nu face modificări, afișează doar ce s-ar face}
        {--skip-woo : Sari peste update-urile WooCommerce, actualizează doar ERP}
        {--skip-erp : Sari peste update-urile ERP, actualizează doar WooCommerce}
        {--prices-only : Actualizează DOAR prețurile (fără status/stoc) — mult mai rapid}';

    protected $description = 'Sincronizează prețuri și coduri Temad: actualizează product_suppliers + WooCommerce';

    // Produse sub cost (coloranti) — se lasă draft, nu se ating
    const SUB_COST_CODES = ['430000', '430001', '430002', '430003'];

    public function handle(): int
    {
        $dryRun     = $this->option('dry-run');
        $skipWoo    = $this->option('skip-woo');
        $skipErp    = $this->option('skip-erp');
        $pricesOnly = $this->option('prices-only');

        if ($dryRun) {
            $this->warn('[DRY RUN] Nicio modificare nu va fi salvată.');
        }

        // ── 1. Citire fișiere Excel ──────────────────────────────────────────

        $listaPath = storage_path('furnizori/temad/malinco lista 04.03.26.xlsx');
        $bazaPath  = storage_path('furnizori/temad/Baza 06.04.26 (1).xlsx');

        $this->info('Citesc malinco lista (prețuri achiziție)...');
        $listaData = $this->readListaFile($listaPath);
        $this->info('  → ' . count($listaData) . ' produse citite din lista');

        $this->info('Citesc Baza (prețuri vânzare)...');
        $bazaData = $this->readBazaFile($bazaPath);
        $this->info('  → ' . count($bazaData) . ' produse citite din Baza');

        // ── 2. Merge date: EAN + Cod Articol ca chei ────────────────────────

        $merged = $this->mergeData($listaData, $bazaData);
        $this->info('  → ' . count($merged) . ' produse după merge (chei unice EAN+cod)');

        // ── 3. Match cu produse WooCommerce ──────────────────────────────────

        $this->info('Caut produse în baza de date...');
        [$matched, $notFound] = $this->matchProducts($merged);
        $this->info('  → ' . count($matched) . ' produse găsite, ' . count($notFound) . ' negăsite');

        // ── 4. Update ERP (product_suppliers) ───────────────────────────────

        if (!$skipErp) {
            $this->info('Actualizez product_suppliers în ERP...');
            $erpStats = $this->updateErp($matched, $dryRun);
            $this->info("  → {$erpStats['updated']} actualizate, {$erpStats['created']} create, {$erpStats['skipped']} sărite");
        }

        // ── 5. Update WooCommerce ────────────────────────────────────────────

        if (!$skipWoo) {
            $this->info('Actualizez produse WooCommerce...');
            $wooStats = $this->updateWooCommerce($matched, $dryRun, $pricesOnly);
            $this->info("  → {$wooStats['published']} actualizate, {$wooStats['price_set']} prețuri setate, {$wooStats['skipped']} sărite (sub cost), {$wooStats['no_change']} neschimbate, {$wooStats['errors']} erori");
        }

        // ── 6. Raport final ──────────────────────────────────────────────────

        if (!empty($notFound)) {
            $this->warn('Produse negăsite (' . count($notFound) . ' total):');
            foreach (array_slice($notFound, 0, 20) as $item) {
                $this->line("  [{$item['stare']}] Cod:{$item['cod_articol']} EAN:{$item['ean']} - {$item['descr']}");
            }
            if (count($notFound) > 20) {
                $this->line('  ... și ' . (count($notFound) - 20) . ' altele');
            }
        }

        $this->info('Sincronizare Temad finalizată!');
        return self::SUCCESS;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Citire fișiere
    // ────────────────────────────────────────────────────────────────────────

    private function readListaFile(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $sheet  = $reader->load($path)->getActiveSheet();
        $maxRow = $sheet->getHighestRow();

        $data = [];
        for ($row = 2; $row <= $maxRow; $row++) {
            $codArticol = trim((string) $sheet->getCell('A' . $row)->getValue());
            $descr      = trim((string) $sheet->getCell('B' . $row)->getValue());
            $descr2     = trim((string) $sheet->getCell('C' . $row)->getValue());
            $pret       = $sheet->getCell('F' . $row)->getCalculatedValue();
            $stare      = trim((string) $sheet->getCell('G' . $row)->getValue());
            $ean        = trim((string) $sheet->getCell('J' . $row)->getValue());

            if (empty($codArticol)) continue;

            // Extrage cod alternativ din descr2: "pe baza de apa c.1001305" → "1001305"
            $altCod = null;
            if (preg_match('/c\.(\d+)/', $descr2, $m)) {
                $altCod = $m[1];
            }

            $data[$codArticol] = [
                'cod_articol'      => $codArticol,
                'alt_cod'          => $altCod,
                'descr'            => $descr,
                'purchase_price'   => $pret > 0 ? (float) $pret : null,
                'stare'            => $this->normalizeStare($stare),
                'ean'              => $this->normalizeEan($ean),
                'source'           => 'lista',
            ];
        }

        return $data;
    }

    private function readBazaFile(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $sheet  = $reader->load($path)->getActiveSheet();
        $maxRow = $sheet->getHighestRow();

        $data = [];
        for ($row = 2; $row <= $maxRow; $row++) {
            $codArticol = trim((string) $sheet->getCell('A' . $row)->getValue());
            $descr      = trim((string) $sheet->getCell('B' . $row)->getValue());
            $bazaPret   = $sheet->getCell('E' . $row)->getCalculatedValue();
            $stare      = trim((string) $sheet->getCell('H' . $row)->getValue());
            $ean        = trim((string) $sheet->getCell('K' . $row)->getValue());

            if (empty($codArticol)) continue;

            $data[$codArticol] = [
                'cod_articol' => $codArticol,
                'descr'       => $descr,
                'baza_pret'   => $bazaPret > 0 ? (float) $bazaPret : null,
                'stare'       => $this->normalizeStare($stare),
                'ean'         => $this->normalizeEan($ean),
                'source'      => 'baza',
            ];
        }

        return $data;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Merge date din ambele fișiere
    // ────────────────────────────────────────────────────────────────────────

    private function mergeData(array $listaData, array $bazaData): array
    {
        // Build index EAN → date din Baza
        $bazaByEan = [];
        $bazaByCod = [];
        foreach ($bazaData as $cod => $item) {
            if (!empty($item['ean'])) {
                $bazaByEan[$item['ean']] = $item;
            }
            $bazaByCod[$cod] = $item;
        }

        $merged = [];

        // Procesăm fiecare produs din lista (purchase prices)
        foreach ($listaData as $cod => $listaItem) {
            $ean = $listaItem['ean'];

            // Găsim în Baza după EAN sau cod
            $bazaItem = null;
            if ($ean && isset($bazaByEan[$ean])) {
                $bazaItem = $bazaByEan[$ean];
            } elseif (isset($bazaByCod[$cod])) {
                $bazaItem = $bazaByCod[$cod];
            } elseif ($listaItem['alt_cod'] && isset($bazaByCod[$listaItem['alt_cod']])) {
                $bazaItem = $bazaByCod[$listaItem['alt_cod']];
            }

            $mergedItem = [
                'cod_articol'    => $cod,
                'alt_cod'        => $listaItem['alt_cod'],
                'descr'          => $listaItem['descr'],
                'purchase_price' => $listaItem['purchase_price'],
                'baza_pret'      => $bazaItem['baza_pret'] ?? null,
                'stare'          => $listaItem['stare'], // stare din lista are prioritate
                'ean'            => $ean ?: ($bazaItem['ean'] ?? null),
            ];

            $key = $ean ?: 'cod_' . $cod;
            $merged[$key] = $mergedItem;
        }

        // Adăugăm și produsele din Baza care NU sunt în lista (fără purchase_price)
        foreach ($bazaData as $cod => $bazaItem) {
            $ean = $bazaItem['ean'];
            $key = $ean ?: 'cod_' . $cod;

            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'cod_articol'    => $cod,
                    'alt_cod'        => null,
                    'descr'          => $bazaItem['descr'],
                    'purchase_price' => null,
                    'baza_pret'      => $bazaItem['baza_pret'],
                    'stare'          => $bazaItem['stare'],
                    'ean'            => $ean,
                ];
            }
        }

        return $merged;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Match cu produse WooCommerce din DB
    // ────────────────────────────────────────────────────────────────────────

    private function matchProducts(array $merged): array
    {
        // Preload: SKU → woo_product
        $productsBySku = DB::table('woo_products')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->select('id', 'woo_id', 'sku', 'status', 'data')
            ->get()
            ->keyBy('sku');

        // Preload: supplier_sku → woo_product_id pentru Temad
        $temadBySku = DB::table('product_suppliers')
            ->where('supplier_id', 5)
            ->whereNotNull('supplier_sku')
            ->select('woo_product_id', 'supplier_sku', 'purchase_price', 'id as ps_id')
            ->get()
            ->keyBy('supplier_sku');

        // Preload: stocuri WinMentor (location_id=1 = Malinco Sântandrei / depozit central)
        $winmentorStock = DB::table('product_stocks')
            ->where('location_id', 1)
            ->where('quantity', '>', 0)
            ->pluck('quantity', 'woo_product_id');

        $matched  = [];
        $notFound = [];

        foreach ($merged as $key => $item) {
            $ean = $item['ean'];
            $cod = $item['cod_articol'];
            $alt = $item['alt_cod'];

            $product = null;

            // 1. Match prin EAN (= SKU în WooCommerce)
            if ($ean) {
                $product = $productsBySku[$ean] ?? null;
            }

            // 2. Match prin cod Temad în product_suppliers
            if (!$product && $temadBySku->has($cod)) {
                $ps      = $temadBySku[$cod];
                $product = DB::table('woo_products')->where('id', $ps->woo_product_id)->select('id', 'woo_id', 'sku', 'status', 'data')->first();
            }

            // 3. Match prin cod alternativ
            if (!$product && $alt && $temadBySku->has($alt)) {
                $ps      = $temadBySku[$alt];
                $product = DB::table('woo_products')->where('id', $ps->woo_product_id)->select('id', 'woo_id', 'sku', 'status', 'data')->first();
            }

            if ($product) {
                $wooData      = is_string($product->data) ? json_decode($product->data, true) : (array)$product->data;
                $currentPrice = $wooData['regular_price'] ?? $wooData['price'] ?? null;
                $hasPrice     = !empty($currentPrice) && (float) $currentPrice > 0;
                $ps           = $temadBySku[$cod] ?? $temadBySku[$alt ?? ''] ?? null;

                // Stoc WinMentor
                $wmQty = (float) ($winmentorStock[$product->id] ?? 0);

                $matched[] = array_merge($item, [
                    'woo_product_id'    => $product->id,
                    'woo_id'            => $product->woo_id,
                    'current_status'    => $product->status,
                    'current_price'     => $currentPrice,
                    'has_price'         => $hasPrice,
                    'ps_id'             => $ps?->ps_id ?? null,
                    'ps_purchase_price' => $ps?->purchase_price ?? null,
                    'winmentor_qty'     => $wmQty,
                ]);
            } else {
                $notFound[] = $item;
            }
        }

        return [$matched, $notFound];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Update ERP: product_suppliers
    // ────────────────────────────────────────────────────────────────────────

    private function updateErp(array $matched, bool $dryRun): array
    {
        $stats = ['updated' => 0, 'created' => 0, 'skipped' => 0];
        $now   = now();

        foreach ($matched as $item) {
            $wooProductId = $item['woo_product_id'];
            $cod          = $item['cod_articol'];

            // Verifică dacă există deja o intrare Temad pentru produsul acesta
            $existing = DB::table('product_suppliers')
                ->where('supplier_id', 5)
                ->where('woo_product_id', $wooProductId)
                ->first();

            $updates = [];

            // Setăm supplier_sku dacă nu este setat sau diferit
            if (!$existing || $existing->supplier_sku !== $cod) {
                $updates['supplier_sku'] = $cod;
            }

            // Setăm purchase_price DOAR dacă avem valoare din lista și este diferită
            if ($item['purchase_price'] !== null) {
                $newPrice = round($item['purchase_price'], 4);
                if (!$existing || (float)($existing->purchase_price ?? 0) !== $newPrice) {
                    $updates['purchase_price'] = $newPrice;
                    $updates['currency']       = 'RON';
                }
            }

            if (empty($updates)) {
                $stats['skipped']++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [ERP] woo_product_id={$wooProductId} cod={$cod} updates=" . json_encode($updates));
                $stats['updated']++;
                continue;
            }

            if ($existing) {
                DB::table('product_suppliers')
                    ->where('id', $existing->id)
                    ->update(array_merge($updates, ['updated_at' => $now]));
                $stats['updated']++;
            } else {
                DB::table('product_suppliers')->insert(array_merge([
                    'woo_product_id' => $wooProductId,
                    'supplier_id'    => 5,
                    'is_preferred'   => 0,
                    'currency'       => 'RON',
                    'conversion_factor' => 1.0,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ], $updates));
                $stats['created']++;
            }
        }

        return $stats;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Update WooCommerce
    // ────────────────────────────────────────────────────────────────────────

    private function updateWooCommerce(array $matched, bool $dryRun, bool $pricesOnly = false): array
    {
        $stats = ['published' => 0, 'price_set' => 0, 'skipped' => 0, 'no_change' => 0, 'errors' => 0];

        // Obținem WooClient
        $connection = IntegrationConnection::where('provider', 'woocommerce')->first();
        if (!$connection) {
            $this->error('Nu am găsit conexiune WooCommerce!');
            return $stats;
        }
        $woo = new WooClient($connection);

        // Preload starea curentă din woo_products.data
        $wooProductIds = array_column($matched, 'woo_product_id');
        $currentData   = DB::table('woo_products')
            ->whereIn('id', $wooProductIds)
            ->pluck('data', 'id')
            ->map(fn($d) => is_string($d) ? json_decode($d, true) : (array)$d);

        // Construim lista de produse care chiar au nevoie de update
        $toUpdate = [];
        foreach ($matched as $item) {
            $wooId = $item['woo_id'];
            if (!$wooId) continue;

            // Sub-cost → skip
            if (in_array($item['cod_articol'], self::SUB_COST_CODES)) {
                $stats['skipped']++;
                continue;
            }

            // ── Logică stoc ────────────────────────────────────────────────
            // 1. Are stoc WinMentor → manage_stock=true, qty=X, backorders=no
            //    → plugin afișează "În stoc" normal
            // 2. Stocabil Temad, fără WinMentor → manage_stock=true, qty=0, backorders=yes
            //    → plugin afișează "În stoc furnizor", poate comanda
            // 3. C.speciala → manage_stock=true, qty=0, backorders=no
            //    → plugin afișează "Stoc epuizat", buton coș dezactivat
            // ────────────────────────────────────────────────────────────────

            $wmQty = $item['winmentor_qty'] ?? 0;

            if ($wmQty > 0) {
                // Are stoc fizic în depozit
                $desiredBackorders    = 'no';
                $desiredStockQty      = (int) $wmQty;
                $desiredManageStock   = true;
            } elseif ($item['stare'] === 'stocabil') {
                // Stocabil la Temad, fără stoc propriu → "În stoc furnizor"
                $desiredBackorders    = 'yes';
                $desiredStockQty      = 0;
                $desiredManageStock   = true;
            } else {
                // C.speciala → stoc epuizat, nu poate comanda
                $desiredBackorders    = 'no';
                $desiredStockQty      = 0;
                $desiredManageStock   = true;
            }

            $current        = $currentData[$item['woo_product_id']] ?? [];
            $currentStatus  = $current['status'] ?? '';
            $currentBack    = $current['backorders'] ?? '';
            $currentManage  = $current['manage_stock'] ?? false;
            $currentQty     = (int) ($current['stock_quantity'] ?? 0);

            $update      = ['id' => $wooId];
            $needsUpdate = false;

            if (!$pricesOnly) {
                if ($currentStatus !== 'publish') {
                    $update['status'] = 'publish';
                    $needsUpdate = true;
                }
                if ($currentBack !== $desiredBackorders) {
                    $update['backorders'] = $desiredBackorders;
                    $needsUpdate = true;
                }
                if ((bool)$currentManage !== $desiredManageStock) {
                    $update['manage_stock'] = $desiredManageStock;
                    $needsUpdate = true;
                }
                if ($currentQty !== $desiredStockQty) {
                    $update['stock_quantity'] = $desiredStockQty;
                    $needsUpdate = true;
                }
            }

            // Preț: setăm DOAR dacă produsul nu are prețul curent și avem baza_pret
            if (!$item['has_price'] && $item['baza_pret'] !== null) {
                $pretVanzare             = number_format($item['baza_pret'] * 1.21, 2, '.', '');
                $update['regular_price'] = $pretVanzare;
                $update['price']         = $pretVanzare;
                $needsUpdate             = true;
                $stats['price_set']++;
            }

            if (!$needsUpdate) {
                $stats['no_change']++;
                continue;
            }

            $stats['published']++;
            $toUpdate[] = $update;
        }

        $this->info("  → {$stats['published']} produse necesită update WooCommerce, {$stats['no_change']} deja ok, {$stats['skipped']} sărite sub-cost");

        if ($dryRun) {
            foreach ($toUpdate as $u) {
                $this->line("  [WOO] " . json_encode($u));
            }
            return $stats;
        }

        // Trimitem în batch-uri de 10
        $batches = array_chunk($toUpdate, 10);
        $bar     = $this->output->createProgressBar(count($toUpdate));
        $bar->start();

        foreach ($batches as $batch) {
            try {
                $woo->updateProductsBatch($batch);
            } catch (\Exception $e) {
                $this->error("\nEroare batch WooCommerce: " . $e->getMessage());
                $stats['errors']++;
            }
            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->newLine();

        return $stats;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function normalizeStare(string $stare): string
    {
        $stare = mb_strtolower(trim($stare));
        if (str_contains($stare, 'special') || str_contains($stare, 'speciala') || str_contains($stare, 'c.special')) {
            return 'speciala';
        }
        return 'stocabil';
    }

    private function normalizeEan(string $ean): ?string
    {
        $ean = trim($ean);
        // Curăță formaturi Excel numeric (poate fi float)
        $ean = rtrim($ean, '.0');
        // EAN valid = 8-14 cifre
        if (preg_match('/^\d{8,14}$/', $ean)) {
            return $ean;
        }
        return null;
    }
}
