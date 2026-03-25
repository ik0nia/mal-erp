<?php

namespace App\Console\Commands;

use App\Models\ProductPriceLog;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\WooProduct;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importă lista de prețuri de achiziție din exportul WinMentor (Excel).
 *
 * Fișierul conține produse cu EAN (CodExtern), cod intern WinMentor,
 * prețul de achiziție, furnizorul și data ultimei achiziții.
 *
 * Logică:
 *  1. Citește Excel-ul (Sheet1, rânduri de la 3 în jos, skip "Total")
 *  2. Grupează rândurile pe CodExtern (EAN) → ia cel cu DataAchiz cea mai recentă
 *  3. Match produs: woo_products.sku = CodExtern
 *  4. Match/creează furnizor: suppliers.name LIKE %Furnizor%
 *  5. Upsert product_suppliers (supplier_sku, purchase_price, last_purchase_date, last_purchase_price)
 *  6. Log diferențe de preț în product_price_logs (source='winmentor_lista')
 */
class ImportPurchasePriceListCommand extends Command
{
    protected $signature = 'import:purchase-price-list
                            {--file= : Calea către fișierul Excel}
                            {--dry-run : Afișează ce s-ar face, fără a salva}';

    protected $description = 'Importă prețuri de achiziție din lista WinMentor (Excel)';

    private const DEFAULT_FILE = '/Users/codrutmaritanu/Downloads/LISTA PRODUSE PRET ACHIZITIE.xlsx';

    /**
     * Supplier names that look like inventory adjustments — skip these.
     */
    private const INVENTORY_KEYWORDS = [
        'INVENTAR',
        'REGLARI',
        'INCARCAT',
        'MARFA DESFACUTA',
        'DEPOZIT',
        'CONSUM',
        'PRODUCTIE',
        'RENOVARE',
    ];

    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $filePath = $this->option('file') ?: self::DEFAULT_FILE;

        if ($this->dryRun) {
            $this->warn('--- MOD DRY-RUN: nu se salvează nimic ---');
        }

        // ----------------------------------------------------------------
        // 1. Citește Excel-ul
        // ----------------------------------------------------------------
        $this->info('Citesc fișierul Excel...');

        if (! file_exists($filePath)) {
            $this->error("Fișier negăsit: {$filePath}");
            return self::FAILURE;
        }

        $rows = $this->readExcel($filePath);

        if (empty($rows)) {
            $this->error('Nu s-au găsit rânduri de date în fișier.');
            return self::FAILURE;
        }

        $this->line("  {$this->count($rows)} rânduri de date citite din Excel");

        // ----------------------------------------------------------------
        // 2. Filtrează furnizori de tip inventar
        // ----------------------------------------------------------------
        $inventorySkipped = 0;
        $validRows = [];

        foreach ($rows as $row) {
            if ($this->isInventoryAdjustment($row['furnizor'])) {
                $inventorySkipped++;
                continue;
            }
            $validRows[] = $row;
        }

        $this->line("  {$inventorySkipped} rânduri ignorate (ajustări inventar)");
        $this->line("  " . count($validRows) . " rânduri valide rămase");

        // ----------------------------------------------------------------
        // 3. Grupează pe CodExtern → per furnizor ia cel mai recent rând
        //    (un produs poate avea mai mulți furnizori)
        // ----------------------------------------------------------------
        $grouped = $this->groupByProductAndSupplier($validRows);
        $productCount = count($grouped);
        $totalAssoc = array_sum(array_map('count', $grouped));
        $this->line("  {$productCount} produse unice, {$totalAssoc} asocieri produs-furnizor");

        // ----------------------------------------------------------------
        // 4. Preîncarcă produsele din ERP (SKU → id)
        // ----------------------------------------------------------------
        $this->info('Preîncărc produsele din ERP...');
        $skuToProduct = WooProduct::query()
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['id', 'sku'])
            ->keyBy(fn ($p) => trim($p->sku));

        // ----------------------------------------------------------------
        // 5. Preîncarcă furnizorii existenți
        // ----------------------------------------------------------------
        $this->info('Preîncărc furnizorii...');
        $existingSuppliers = Supplier::query()
            ->get(['id', 'name'])
            ->keyBy(fn ($s) => mb_strtolower(trim($s->name)));

        // ----------------------------------------------------------------
        // 6. Preîncarcă asocierile product_suppliers existente
        // ----------------------------------------------------------------
        $existingPivots = DB::table('product_suppliers')
            ->select('id', 'woo_product_id', 'supplier_id', 'purchase_price', 'last_purchase_date')
            ->get()
            ->groupBy(function ($row) {
                return $row->woo_product_id . '-' . $row->supplier_id;
            });

        // ----------------------------------------------------------------
        // 7. Procesare — match produse, furnizori, upsert
        // ----------------------------------------------------------------
        $this->info('Procesez importul...');

        $stats = [
            'products_found'         => 0,
            'products_not_found'     => 0,
            'suppliers_matched'      => 0,
            'suppliers_created'      => 0,
            'suppliers_not_found'    => 0, // this won't happen as we create them
            'suppliers_skipped_inv'  => $inventorySkipped,
            'pivots_created'         => 0,
            'pivots_updated'         => 0,
            'price_logs_created'     => 0,
        ];

        $notFoundSkus = [];
        $supplierCache = []; // furnizor name → supplier_id (to avoid repeated lookups)

        $bar = $this->output->createProgressBar($productCount);
        $bar->start();

        foreach ($grouped as $ean => $supplierRows) {
            $bar->advance();

            // Match product
            $product = $skuToProduct->get($ean);

            if (! $product) {
                $stats['products_not_found']++;
                $notFoundSkus[] = $ean;
                continue;
            }

            $stats['products_found']++;

            // Găsește cel mai recent furnizor (va fi is_preferred)
            $latestDate = null;
            $latestSupplier = null;
            foreach ($supplierRows as $sName => $sRow) {
                if (! $latestDate || $sRow['data_achiz']->gt($latestDate)) {
                    $latestDate = $sRow['data_achiz'];
                    $latestSupplier = $sName;
                }
            }

            // Procesează TOȚI furnizorii pentru acest produs
            foreach ($supplierRows as $furnizorName => $row) {
                $supplierId = $supplierCache[$furnizorName] ?? null;

                if ($supplierId === null) {
                    $supplierId = $this->resolveSupplier($furnizorName, $existingSuppliers, $stats);
                    $supplierCache[$furnizorName] = $supplierId;
                }

                if (! $supplierId) {
                    continue;
                }

                $isPreferred = ($furnizorName === $latestSupplier);

                // Upsert product_suppliers
                $pivotKey = $product->id . '-' . $supplierId;
                $existingPivot = $existingPivots->get($pivotKey)?->first();

                $purchasePrice = $row['pret_achizitie'];
                $purchaseDate = $row['data_achiz'];
                $supplierSku = $row['cod_intern'];

                if ($existingPivot) {
                    // Update only if this row's date is newer or equal
                    $existingDate = $existingPivot->last_purchase_date
                        ? Carbon::parse($existingPivot->last_purchase_date)
                        : null;

                    $shouldUpdate = ! $existingDate || $purchaseDate->gte($existingDate);

                    if ($shouldUpdate) {
                        $oldPrice = (float) ($existingPivot->purchase_price ?? 0);
                        $newPrice = $purchasePrice;

                        if (! $this->dryRun) {
                            DB::table('product_suppliers')
                                ->where('id', $existingPivot->id)
                                ->update([
                                    'supplier_sku'        => $supplierSku,
                                    'purchase_price'      => $newPrice,
                                    'last_purchase_date'  => $purchaseDate->toDateString(),
                                    'last_purchase_price' => $newPrice,
                                    'is_preferred'        => $isPreferred,
                                    'updated_at'          => now(),
                                ]);
                        }

                        $stats['pivots_updated']++;

                        // Log price change if different
                        if (abs($oldPrice - $newPrice) > 0.0001) {
                            $this->logPriceChange($product->id, $oldPrice, $newPrice, $purchaseDate, $stats);
                        }
                    }
                } else {
                    // Create new association
                    if (! $this->dryRun) {
                        DB::table('product_suppliers')->insert([
                            'woo_product_id'      => $product->id,
                            'supplier_id'         => $supplierId,
                            'supplier_sku'        => $supplierSku,
                            'purchase_price'      => $purchasePrice,
                            'last_purchase_date'  => $purchaseDate->toDateString(),
                            'last_purchase_price' => $purchasePrice,
                            'is_preferred'        => $isPreferred,
                            'created_at'          => now(),
                            'updated_at'          => now(),
                        ]);
                    }

                    $stats['pivots_created']++;

                    // Log initial price
                    $this->logPriceChange($product->id, null, $purchasePrice, $purchaseDate, $stats);
                }
            } // end foreach supplierRows
        } // end foreach grouped

        $bar->finish();
        $this->newLine(2);

        // ----------------------------------------------------------------
        // 8. Afișare sumar
        // ----------------------------------------------------------------
        $this->table(
            ['Statistică', 'Valoare'],
            [
                ['Rânduri citite din Excel',              count($rows)],
                ['Furnizori ignorați (ajustări inventar)', $stats['suppliers_skipped_inv']],
                ['Produse unice (după EAN)',              $productCount],
                ['Asocieri produs-furnizor din Excel',   $totalAssoc],
                ['Produse găsite în ERP',                 $stats['products_found']],
                ['Produse NEGĂSITE în ERP',               $stats['products_not_found']],
                ['Furnizori potriviți (existenți)',       $stats['suppliers_matched']],
                ['Furnizori creați (noi)',                $stats['suppliers_created']],
                ['Asocieri produs-furnizor create',       $stats['pivots_created']],
                ['Asocieri produs-furnizor actualizate',  $stats['pivots_updated']],
                ['Loguri de preț create',                 $stats['price_logs_created']],
            ]
        );

        // Show sample of not-found SKUs
        if (! empty($notFoundSkus)) {
            $sample = array_slice($notFoundSkus, 0, 20);
            $this->warn('Exemple SKU-uri negăsite în ERP (' . count($notFoundSkus) . ' total):');
            foreach ($sample as $sku) {
                $this->line("  - {$sku}");
            }
            if (count($notFoundSkus) > 20) {
                $this->line('  ... și încă ' . (count($notFoundSkus) - 20) . ' altele');
            }
        }

        if ($this->dryRun) {
            $this->newLine();
            $this->warn('Dry-run complet. Rulează fără --dry-run pentru a salva.');
        } else {
            $this->newLine();
            $this->info('Import finalizat cu succes!');
        }

        return self::SUCCESS;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Citește Excel-ul și returnează un array de rânduri normalizate.
     */
    private function readExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];

        foreach ($sheet->getRowIterator(3) as $row) { // start at row 3 (skip 2 header rows)
            $cells = [];
            $cellIterator = $row->getCellIterator('A', 'J');
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }

            // Column A = Nr.crt — skip "Total" rows and empty rows
            $colA = trim((string) ($cells[0] ?? ''));

            if ($colA === '' || stripos($colA, 'Total') === 0) {
                continue;
            }

            // Column A must be numeric (data row)
            if (! is_numeric($colA)) {
                continue;
            }

            $codExtern = trim((string) ($cells[2] ?? '')); // Col C = CodExtern (EAN)
            $codIntern = trim((string) ($cells[3] ?? '')); // Col D = CodIntern (WinMentor)
            $pretAchizitie = $this->parseDecimal($cells[6] ?? 0); // Col G = Pret achizitie
            $furnizor = trim((string) ($cells[8] ?? ''));   // Col I = Furnizor
            $dataAchiz = $this->parseDate($cells[9] ?? ''); // Col J = DataAchiz

            if ($codExtern === '' || $furnizor === '' || ! $dataAchiz) {
                continue;
            }

            $rows[] = [
                'cod_extern'     => $codExtern,
                'cod_intern'     => $codIntern,
                'pret_achizitie' => $pretAchizitie,
                'furnizor'       => $furnizor,
                'data_achiz'     => $dataAchiz,
            ];
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    /**
     * Parsează data din format "dd.mm.yyyy" sau Excel serial date.
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Excel serial date number
        if (is_numeric($value)) {
            try {
                $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
                return Carbon::instance($dateTime);
            } catch (\Throwable) {
                return null;
            }
        }

        $value = trim((string) $value);

        // dd.mm.yyyy
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            try {
                return Carbon::createFromFormat('d.m.Y', $value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Parsează un decimal din formatul care poate fi string cu virgulă.
     */
    private function parseDecimal(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) str_replace(',', '.', trim((string) $value));
    }

    /**
     * Verifică dacă un furnizor e de fapt o ajustare de inventar.
     */
    private function isInventoryAdjustment(string $name): bool
    {
        $upper = mb_strtoupper($name);

        foreach (self::INVENTORY_KEYWORDS as $keyword) {
            if (str_contains($upper, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Grupează rândurile pe CodExtern → per furnizor ia cel mai recent.
     * Returnează: [ean => [furnizorName => row, ...], ...]
     *
     * @return array<string, array<string, array>>
     */
    private function groupByProductAndSupplier(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $ean = $row['cod_extern'];
            $furnizor = $row['furnizor'];

            if (! isset($grouped[$ean])) {
                $grouped[$ean] = [];
            }

            // Per furnizor, păstrează rândul cu data cea mai recentă
            if (! isset($grouped[$ean][$furnizor]) || $row['data_achiz']->gt($grouped[$ean][$furnizor]['data_achiz'])) {
                $grouped[$ean][$furnizor] = $row;
            }
        }

        return $grouped;
    }

    /**
     * Rezolvă un furnizor: match LIKE sau creează.
     */
    private function resolveSupplier(
        string $furnizorName,
        \Illuminate\Support\Collection &$existingSuppliers,
        array &$stats,
    ): ?int {
        $key = mb_strtolower(trim($furnizorName));

        // Exact match (case-insensitive)
        if (isset($existingSuppliers[$key])) {
            $stats['suppliers_matched']++;
            return $existingSuppliers[$key]->id;
        }

        // Partial match (LIKE)
        foreach ($existingSuppliers as $erpKey => $supplier) {
            if (str_contains($erpKey, $key) || str_contains($key, $erpKey)) {
                $this->line("  <info>Match parțial furnizor:</info> \"{$furnizorName}\" → \"{$supplier->name}\"");
                $stats['suppliers_matched']++;
                // Cache this for future lookups
                $existingSuppliers[$key] = $supplier;
                return $supplier->id;
            }
        }

        // Create new supplier
        $this->line("  <comment>Creez furnizor nou:</comment> \"{$furnizorName}\"");

        if (! $this->dryRun) {
            $supplier = Supplier::create([
                'name'      => $furnizorName,
                'is_active' => true,
            ]);
            $existingSuppliers[$key] = $supplier;
            $stats['suppliers_created']++;
            return $supplier->id;
        }

        $stats['suppliers_created']++;
        return 0; // dry-run placeholder
    }

    /**
     * Loghează o schimbare de preț în product_price_logs.
     */
    private function logPriceChange(
        int $productId,
        ?float $oldPrice,
        float $newPrice,
        Carbon $date,
        array &$stats,
    ): void {
        if (! $this->dryRun) {
            ProductPriceLog::create([
                'woo_product_id' => $productId,
                'location_id'    => 1, // default location
                'old_price'      => $oldPrice,
                'new_price'      => $newPrice,
                'source'         => 'winmentor_lista',
                'changed_at'     => $date,
            ]);
        }

        $stats['price_logs_created']++;
    }

    /**
     * Shortcut for count that works on arrays.
     */
    private function count(array $arr): int
    {
        return count($arr);
    }
}
