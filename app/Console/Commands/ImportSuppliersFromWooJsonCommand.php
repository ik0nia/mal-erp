<?php

namespace App\Console\Commands;

use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportSuppliersFromWooJsonCommand extends Command
{
    protected $signature = 'suppliers:import-from-woo-json';

    protected $description = 'Importă furnizorii din meta_data JSON al produselor WooCommerce și creează legăturile în product_suppliers';

    public function handle(): int
    {
        $this->info('Pornire import furnizori din WooCommerce JSON meta_data...');

        $suppliersCreated  = 0;
        $suppliersSkipped  = 0;
        $productsLinked    = 0;
        $productsSkipped   = 0;
        $errors            = 0;

        // ── 1. Extrage toate produsele cu meta_data ──────────────────────────
        $this->info('Citire produse WooCommerce...');
        $products = WooProduct::whereNotNull('data')->get();
        $this->line("  Total produse cu câmp data: {$products->count()}");

        // ── 2. Colectează perechi (productId => furnizorNume) ────────────────
        $this->info('Extragere meta_data furnizori...');

        /** @var array<int, string> $productSupplierMap  woo_product_id => normalized name */
        $productSupplierMap = [];

        foreach ($products as $product) {
            $data = $product->data;

            if (! is_array($data) || ! isset($data['meta_data'])) {
                continue;
            }

            $furnizorNume = null;

            foreach ($data['meta_data'] as $meta) {
                if (($meta['key'] ?? '') === '_furnizor_nume' && ! empty($meta['value'])) {
                    $furnizorNume = $meta['value'];
                    break;
                }
            }

            if ($furnizorNume === null) {
                continue;
            }

            // Decodifică HTML entities (e.g. "Schuller Eh&#8217;klar" → "Schuller Eh'klar")
            $furnizorNume = html_entity_decode((string) $furnizorNume, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Normalizează variantele Unicode de apostrofe/ghilimele la echivalentul ASCII
            // U+2019 RIGHT SINGLE QUOTATION MARK → ASCII apostrophe
            // U+2018 LEFT SINGLE QUOTATION MARK  → ASCII apostrophe
            // U+201C LEFT DOUBLE QUOTATION MARK  → ASCII double quote
            // U+201D RIGHT DOUBLE QUOTATION MARK → ASCII double quote
            $furnizorNume = str_replace(
                ["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2032}", "\u{00B4}", "\u{0060}"],
                ["'",        "'",        '"',        '"',        "'",        "'",        "'"],
                $furnizorNume
            );
            $furnizorNume = trim($furnizorNume);

            if ($furnizorNume === '') {
                continue;
            }

            $productSupplierMap[$product->id] = $furnizorNume;
        }

        $this->line('  Produse cu furnizor valid: ' . count($productSupplierMap));

        // ── 3. Determină furnizorii unici (case-insensitive) ─────────────────
        /** @var array<string, string>  lowercase_key => normalized display name */
        $uniqueSuppliers = [];

        foreach ($productSupplierMap as $name) {
            $key = mb_strtolower($name);
            // Păstrează prima variantă întâlnită ca display name
            if (! isset($uniqueSuppliers[$key])) {
                $uniqueSuppliers[$key] = $name;
            }
        }

        $this->line('  Furnizori unici (după deduplicare): ' . count($uniqueSuppliers));

        // ── 4. Creează furnizorii care nu există deja ────────────────────────
        $this->info('Creare furnizori noi în baza de date...');

        /** @var array<string, int>  lowercase_key => supplier_id */
        $supplierIdMap = [];

        // Pre-încarcă furnizorii existenți pentru a evita N+1 queries
        $existingSuppliers = Supplier::all();
        foreach ($existingSuppliers as $existing) {
            $supplierIdMap[mb_strtolower($existing->name)] = $existing->id;
        }

        foreach ($uniqueSuppliers as $key => $displayName) {
            if (isset($supplierIdMap[$key])) {
                $suppliersSkipped++;
                $this->line("  [skip] Furnizorul există deja: {$displayName}");
                continue;
            }

            try {
                $supplier = Supplier::create([
                    'name'      => $displayName,
                    'is_active' => true,
                ]);

                $supplierIdMap[$key] = $supplier->id;
                $suppliersCreated++;
                $this->line("  [nou]  Creat furnizor: {$displayName} (ID={$supplier->id})");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  [eroare] Nu s-a putut crea furnizorul '{$displayName}': {$e->getMessage()}");
            }
        }

        // ── 5. Leagă produsele de furnizori prin product_suppliers ───────────
        $this->info('Creare legături product_suppliers...');

        // Pre-încarcă legăturile existente pentru a evita duplicate
        $existingLinks = DB::table('product_suppliers')
            ->select('woo_product_id', 'supplier_id')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->woo_product_id}_{$row->supplier_id}" => true])
            ->toArray();

        $insertBatch = [];

        foreach ($productSupplierMap as $wooProductId => $supplierName) {
            $key        = mb_strtolower($supplierName);
            $supplierId = $supplierIdMap[$key] ?? null;

            if ($supplierId === null) {
                $errors++;
                $this->error("  [eroare] Nu s-a găsit supplier_id pentru '{$supplierName}' (product_id={$wooProductId})");
                continue;
            }

            $linkKey = "{$wooProductId}_{$supplierId}";

            if (isset($existingLinks[$linkKey])) {
                $productsSkipped++;
                continue;
            }

            $now           = now()->toDateTimeString();
            $insertBatch[] = [
                'woo_product_id' => $wooProductId,
                'supplier_id'    => $supplierId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            // Marchează ca procesat pentru a preveni duplicatele din batch
            $existingLinks[$linkKey] = true;
        }

        // Insert în chunk-uri de 500 pentru a nu depăși limitele SQL
        if (! empty($insertBatch)) {
            $chunks = array_chunk($insertBatch, 500);
            foreach ($chunks as $chunk) {
                try {
                    DB::table('product_suppliers')->insert($chunk);
                    $productsLinked += count($chunk);
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("  [eroare] Insert batch eșuat: {$e->getMessage()}");
                }
            }
        }

        // ── 6. Raport final ──────────────────────────────────────────────────
        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info('  RAPORT IMPORT FURNIZORI');
        $this->info('═══════════════════════════════════════════════');
        $this->line("  Furnizori creați:           {$suppliersCreated}");
        $this->line("  Furnizori existenți (skip): {$suppliersSkipped}");
        $this->line("  Produse legate:             {$productsLinked}");
        $this->line("  Produse deja legate (skip): {$productsSkipped}");
        $this->line("  Erori:                      {$errors}");
        $this->info('═══════════════════════════════════════════════');

        // Verificare finală din DB
        $totalSuppliers       = Supplier::count();
        $totalProductSuppliers = DB::table('product_suppliers')->count();
        $this->newLine();
        $this->info("  Verificare DB:");
        $this->line("  SELECT COUNT(*) FROM suppliers       = {$totalSuppliers}");
        $this->line("  SELECT COUNT(*) FROM product_suppliers = {$totalProductSuppliers}");
        $this->info('═══════════════════════════════════════════════');

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
