<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportToyaProductsCommand extends Command
{
    protected $signature = 'toya:import-products
                            {--limit=0         : Limitează numărul de produse importate (0 = toate)}
                            {--dry-run         : Afișează ce s-ar face, fără a salva}
                            {--force           : Re-importă și produsele deja existente}
                            {--supplier-id=112 : ID-ul furnizorului Toya Romania în ERP}
                            {--skip-disabled   : Sare peste produsele cu Enabled=0 (implicit activ)}
                            {--chunk-total=1   : Numărul total de chunk-uri (pentru rulare paralelă)}
                            {--chunk-index=0   : Index-ul acestui chunk (0-based)}';

    protected $description = 'Importă produse din API-ul Toya Pimcore ca draft (nepublicate pe site)';

    private const API_BASE = 'https://pim.toya.pl/dataapi';

    private string $apiKey;

    public function handle(): int
    {
        $this->apiKey   = AppSetting::getEncrypted(AppSetting::KEY_TOYA_API_KEY)
            ?? env('TOYA_API_KEY', 'D83FD59A4902793862EB8304');

        $supplierId   = (int) $this->option('supplier-id');
        $dryRun       = (bool) $this->option('dry-run');
        $force        = (bool) $this->option('force');
        $limit        = (int) $this->option('limit');
        $skipDisabled = (bool) $this->option('skip-disabled');
        $chunkTotal   = max(1, (int) $this->option('chunk-total'));
        $chunkIndex   = (int) $this->option('chunk-index');

        if ($dryRun) {
            $this->warn('--- MOD DRY-RUN: nu se salvează nimic ---');
        }

        // ----------------------------------------------------------------
        // 1. Prețuri RO (bulk) — filtrăm doar produsele relevante pentru România
        // ----------------------------------------------------------------
        $this->info('1/3 — Preiau prețurile RO (bulk)...');
        $prices = $this->fetchBulk('getPricesRo');
        $this->line('  ' . count($prices) . ' produse cu preț RO');

        if (empty($prices)) {
            $this->error('Nu s-au putut prelua prețurile. Verifică API key-ul.');
            return self::FAILURE;
        }

        // ----------------------------------------------------------------
        // 2. Stocuri RO (bulk)
        // ----------------------------------------------------------------
        $this->info('2/3 — Preiau stocurile RO (bulk)...');
        $stocks = $this->fetchBulk('getStocksRo');
        $this->line('  ' . count($stocks) . ' produse cu stoc RO');

        // ----------------------------------------------------------------
        // 3. Determinăm codurile de procesat
        // ----------------------------------------------------------------
        $codes = array_keys($prices);

        // Împărțire în chunk-uri pentru rulare paralelă
        if ($chunkTotal > 1) {
            $chunks = array_chunk($codes, (int) ceil(count($codes) / $chunkTotal));
            $codes  = $chunks[$chunkIndex] ?? [];
            $this->line("  Chunk {$chunkIndex}/{$chunkTotal}: " . count($codes) . ' coduri');
        }

        if ($limit > 0) {
            $codes = array_slice($codes, 0, $limit);
        }

        if (! $force) {
            $existing = DB::table('product_suppliers')
                ->join('woo_products', 'woo_products.id', '=', 'product_suppliers.woo_product_id')
                ->where('woo_products.source', WooProduct::SOURCE_TOYA_API)
                ->where('product_suppliers.supplier_id', $supplierId)
                ->pluck('product_suppliers.supplier_sku')
                ->flip()
                ->all();

            $before = count($codes);
            $codes  = array_values(array_filter($codes, fn ($c) => ! isset($existing[$c])));
            $this->line('  ' . ($before - count($codes)) . ' deja importate — sărite');
        }

        $this->line('  ' . count($codes) . ' coduri de importat');

        if (empty($codes)) {
            $this->info('Nimic de importat.');
            return self::SUCCESS;
        }

        // ----------------------------------------------------------------
        // 4. Preîncărcăm categoriile existente pentru mapping
        // ----------------------------------------------------------------
        $categoryByName = DB::table('woo_categories')
            ->whereNotNull('name')
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id])
            ->all();

        // ----------------------------------------------------------------
        // 5. Import produs cu produs
        // ----------------------------------------------------------------
        $this->info('3/3 — Import produse...');
        $bar = $this->output->createProgressBar(count($codes));
        $bar->start();

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($codes as $code) {
            try {
                $raw = $this->fetchProductData($code);

                if (! $raw) {
                    $stats['failed']++;
                    $bar->advance();
                    continue;
                }

                // Filtrăm produsele dezactivate dacă --skip-disabled
                if ($skipDisabled && ($raw['Enabled'] ?? '1') !== '1') {
                    $stats['skipped']++;
                    $bar->advance();
                    continue;
                }

                $price = (float) ($prices[$code]['netPrice'] ?? 0);
                $stock = $stocks[$code]['stock'] ?? 'OUT OF STOCK';

                if (! $dryRun) {
                    $created = $this->persistProduct($raw, $price, $stock, $supplierId, $categoryByName, $force);
                    $created ? $stats['created']++ : $stats['updated']++;
                } else {
                    $stats['created']++;
                    // Dry-run: afișăm primul produs ca sample
                    if ($stats['created'] === 1) {
                        $this->newLine();
                        $this->line('--- SAMPLE ---');
                        $this->line('Code: ' . $code);
                        $this->line('Name RO: ' . ($raw['NameInternet']['ro'] ?? $raw['NameSAP']['ro'] ?? '—'));
                        $this->line('Price: ' . $price . ' RON');
                        $this->line('Stock: ' . $stock);
                        $this->line('Image: ' . ($raw['Image']['high'] ?? '—'));
                    }
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->newLine();
                $this->warn("  ERR {$code}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Statistică', 'Valoare'],
            [
                ['Importate (noi)',    $stats['created']],
                ['Actualizate',        $stats['updated']],
                ['Sărite (disabled)',  $stats['skipped']],
                ['Eșuate',            $stats['failed']],
            ]
        );

        return self::SUCCESS;
    }

    // ----------------------------------------------------------------
    // API helpers
    // ----------------------------------------------------------------

    private function fetchBulk(string $action): array
    {
        $response = Http::withoutVerifying()
            ->timeout(60)
            ->get(self::API_BASE, ['key' => $this->apiKey, 'action' => $action]);

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function fetchProductData(string $code): ?array
    {
        $response = Http::withoutVerifying()
            ->timeout(30)
            ->get(self::API_BASE, ['key' => $this->apiKey, 'action' => 'getData', 'code' => $code]);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return ($json['success'] ?? false) ? ($json['data'] ?? null) : null;
    }

    // ----------------------------------------------------------------
    // Persistare
    // ----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $raw         date brute din API getData
     * @param  array<string, int>    $categoryByName  map [lower_name => woo_category_id]
     * @return bool  true = produs nou, false = actualizat
     */
    private function persistProduct(
        array $raw,
        float $price,
        string $stock,
        int $supplierId,
        array $categoryByName,
        bool $force
    ): bool {
        $code = (string) ($raw['Code'] ?? '');

        // Denumire: RO preferată, fallback EN, fallback SKU
        $nameRo = trim($raw['NameInternet']['ro'] ?? '')
            ?: trim($raw['NameSAP']['ro'] ?? '')
            ?: trim($raw['NameInternet']['en'] ?? '')
            ?: trim($raw['NameSAP']['en'] ?? '')
            ?: $code;

        $imageUrl = $raw['Image']['high'] ?? $raw['Image']['low'] ?? null;
        // Forțăm HTTPS pentru a evita mixed content pe ERP (care e HTTPS)
        if ($imageUrl) {
            $imageUrl = str_replace('http://', 'https://', $imageUrl);
        }

        // Greutate brută (cu ambalaj) — mai relevantă pentru shipping
        $weightKg = isset($raw['BruttoWeight']['value']) && (float) $raw['BruttoWeight']['value'] > 0
            ? (float) $raw['BruttoWeight']['value']
            : (isset($raw['NettoWeight']['value']) ? (float) $raw['NettoWeight']['value'] : null);

        // Dimensiuni ambalaj unitar HE (mm → cm), fallback pe MC (cutie master)
        $dimLength = $this->mmToCm($raw['LengthHE'] ?? null) ?? $this->mmToCm($raw['LengthMC'] ?? null);
        $dimWidth  = $this->mmToCm($raw['WidthHE'] ?? null)  ?? $this->mmToCm($raw['WidthMC'] ?? null);
        $dimHeight = $this->mmToCm($raw['HeightHE'] ?? null) ?? $this->mmToCm($raw['HeightMC'] ?? null);

        // Ambalare/comandare
        $qtyPerInnerBox    = $this->toInt($raw['IB'] ?? null);  // Inner Box
        $qtyPerCarton      = $this->toInt($raw['MC'] ?? null);  // Master Carton (cantitate minimă comandă)
        $cartonsPerPallet  = $this->toInt($raw['AC'] ?? null);  // Cartoane per palet
        $eanCarton         = isset($raw['EanMC']) && $raw['EanMC'] !== 'N/A' ? (string) $raw['EanMC'] : null;

        // Unitate de măsură: KPL=set, BUC=buc, etc.
        $unit = match (strtoupper($raw['Unit'] ?? '')) {
            'KPL'  => 'set',
            'BUC'  => 'buc',
            'SZT'  => 'buc',
            'KG'   => 'kg',
            'M'    => 'm',
            'M2'   => 'm²',
            'L'    => 'l',
            default => strtolower($raw['Unit'] ?? ''),
        };

        // Stock status WooCommerce
        $stockStatus = match ($stock) {
            'OUT OF STOCK'    => 'outofstock',
            'LARGE QUANTITY'  => 'instock',
            'MEDIUM QUANTITY' => 'instock',
            'SMALL QUANTITY'  => 'instock',
            default           => 'outofstock',
        };

        $slug = $this->makeSlug($nameRo, $code);

        $existingId = DB::table('product_suppliers')
            ->where('supplier_id', $supplierId)
            ->where('supplier_sku', $code)
            ->value('woo_product_id');
        $existing = $existingId
            ? WooProduct::where('source', WooProduct::SOURCE_TOYA_API)->find($existingId)
            : null;

        // Warnings de siguranță în română
        $safetyWarnings = collect($raw['TextWarningsRel'] ?? [])
            ->map(fn ($w) => $w['localizedfields']['Name']['ro'] ?? null)
            ->filter()
            ->values()
            ->all();

        $productData = [
            'source'         => WooProduct::SOURCE_TOYA_API,
            'status'         => 'draft',
            'is_placeholder' => true,
            'sku'            => $raw['Ean'] ?? null,
            'name'           => $nameRo,
            'slug'           => $slug,
            'regular_price'  => $price > 0 ? $price : null,
            'price'          => $price > 0 ? $price : null,
            'main_image_url' => $imageUrl,
            'weight'         => $weightKg,
            'dim_length'          => $dimLength,
            'dim_width'           => $dimWidth,
            'dim_height'          => $dimHeight,
            'qty_per_inner_box'   => $qtyPerInnerBox,
            'qty_per_carton'      => $qtyPerCarton,
            'cartons_per_pallet'  => $cartonsPerPallet,
            'ean_carton'          => $eanCarton,
            'stock_status'   => $stockStatus,
            'brand'          => $raw['Brand'] ?? null,
            'unit'           => $unit ?: null,
            'type'           => WooProduct::TYPE_SHOP,
            'manage_stock'   => false,
            'data'           => json_encode([
                'toya_id'              => $raw['id'] ?? null,
                'ean'                  => $raw['Ean'] ?? null,
                'ean_mc'               => $raw['EanMC'] ?? null,
                'stock_flag'           => $stock,
                'category_ro'          => $raw['Category']['ro'] ?? null,
                'category_en'          => $raw['Category']['en'] ?? null,
                'name_internet_ro'     => $raw['NameInternet']['ro'] ?? null,
                'name_internet_en'     => $raw['NameInternet']['en'] ?? null,
                'name_sap_ro'          => $raw['NameSAP']['ro'] ?? null,
                'name_package_ro'      => $raw['NamePackage']['ro'] ?? null,
                'discount_group'       => $raw['DiscountGroup'] ?? null,
                'mc'                   => $raw['MC'] ?? null,
                'unit_volume'          => $raw['UnitVolume'] ?? null,
                'manufacturer_address' => $raw['ManufacturerAddress'] ?? null,
                'safety_warnings_ro'   => $safetyWarnings,
                'modification_date'    => $raw['modificationDate'] ?? null,
                'images_additional'    => $raw['ImageAdditional'] ?? [],
                'substitution_ref'     => $raw['SubstytutionRef'] ?? [],
                'complementary_ref'    => $raw['ComplementaryRef'] ?? [],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $isNew = ! $existing;

        if ($existing) {
            if ($force) {
                $existing->update($productData);
                $product = $existing;
            } else {
                return false;
            }
        } else {
            $product = WooProduct::create($productData);
        }

        // ----------------------------------------------------------------
        // Atribute (limba RO)
        // ----------------------------------------------------------------
        if ($isNew || $force) {
            $this->saveAttributes($product->id, $raw['Attributes'] ?? []);
        }

        // ----------------------------------------------------------------
        // Categorii — mapare la categorii existente din WooCommerce
        // ----------------------------------------------------------------
        if ($isNew || $force) {
            $this->saveCategories($product->id, $raw['Category']['ro'] ?? '', $categoryByName);
        }

        // ----------------------------------------------------------------
        // Asociere furnizor
        // ----------------------------------------------------------------
        DB::table('product_suppliers')->updateOrInsert(
            ['woo_product_id' => $product->id, 'supplier_id' => $supplierId],
            [
                'supplier_sku' => $code,
                'is_preferred' => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]
        );

        return $isNew;
    }

    private function saveAttributes(int $productId, array $attributes): void
    {
        DB::table('woo_product_attributes')->where('woo_product_id', $productId)->delete();

        $rows    = [];
        $sorters = array_column($attributes, 'sorter');
        array_multisort($sorters, SORT_ASC, $attributes);

        foreach ($attributes as $position => $attr) {
            $ro = $attr['ro'] ?? null;
            if (! $ro || empty($ro['name']) || empty($ro['value'])) {
                continue;
            }

            $rows[] = [
                'woo_product_id' => $productId,
                'name'           => $ro['name'],
                'value'          => $ro['value'],
                'is_visible'     => true,
                'position'       => $position,
                'source'         => 'toya_api',
                'created_at'     => now(),
                'updated_at'     => now(),
            ];
        }

        if (! empty($rows)) {
            DB::table('woo_product_attributes')->insert($rows);
        }
    }

    private function saveCategories(int $productId, string $categoryPath, array $categoryByName): void
    {
        if (empty($categoryPath)) {
            return;
        }

        // "Produkty/Șurubelnițe.../Biți.../Seturi.../" → luăm segmentele
        $segments = array_filter(array_map('trim', explode('/', $categoryPath)));

        // Ștergem primul segment generic "Produkty" / "Products"
        $segments = array_values(array_filter($segments, fn ($s) => ! in_array(mb_strtolower($s), ['produkty', 'products', 'produse'], true)));

        if (empty($segments)) {
            return;
        }

        $matched = [];
        foreach ($segments as $segment) {
            $key = mb_strtolower($segment);
            if (isset($categoryByName[$key])) {
                $matched[] = $categoryByName[$key];
            }
        }

        if (empty($matched)) {
            return;
        }

        // Inserăm asocierile (ignorăm duplicate)
        foreach (array_unique($matched) as $catId) {
            DB::table('woo_product_category')->updateOrInsert(
                ['woo_product_id' => $productId, 'woo_category_id' => $catId]
            );
        }
    }

    private function mmToCm(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === 'N/A') {
            return null;
        }
        $v = (float) $value;
        return $v > 0 ? round($v / 10, 2) : null;
    }

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'N/A') {
            return null;
        }
        $v = (int) $value;
        return $v > 0 ? $v : null;
    }

    private function makeSlug(string $name, string $code): string
    {
        $base = Str::slug($name);
        $suffix = Str::slug(strtolower($code));

        // Verificăm unicitate
        $slug = $base . '-' . $suffix;

        $count = WooProduct::query()
            ->where('slug', 'like', $slug . '%')
            ->where('source', WooProduct::SOURCE_TOYA_API)
            ->count();

        return $count > 0 ? $slug . '-' . ($count + 1) : $slug;
    }
}
