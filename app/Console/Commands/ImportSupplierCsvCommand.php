<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Procesează fișierele CSV din storage/furnizori/ și asociază produsele
 * cu furnizorii corespunzători, pe baza EAN-ului (coloana "Cod extern").
 *
 * Format CSV așteptat (export WinMentor):
 *   - Rândul 1 + 2: header-e (se sar automat)
 *   - Coloane: Nr., Articol, Clasa, Cod extern (EAN), Cod, Pret, UM, Stoc, PRODUCATOR, Data
 *
 * Fișierele procesate cu succes sunt mutate în storage/furnizori/processed/
 *
 * Utilizare:
 *   php artisan suppliers:import-csv               # procesează toate fișierele noi
 *   php artisan suppliers:import-csv --dry-run     # simulare, fără salvare
 *   php artisan suppliers:import-csv --force       # re-procesează chiar dacă asocierile există deja
 *   php artisan suppliers:import-csv --file=LUMYTOOLS.csv  # un singur fișier
 */
class ImportSupplierCsvCommand extends Command
{
    protected $signature = 'suppliers:import-csv
                            {--dry-run  : Simulare — afișează ce s-ar face fără să salveze}
                            {--force    : Re-procesează și dacă asocierile există deja}
                            {--file=    : Procesează un singur fișier (ex: LUMYTOOLS.csv)}
                            {--no-move  : Nu muta fișierele în /processed după import}';

    protected $description = 'Importă asocieri furnizor-produs din fișiere CSV plasate în storage/furnizori/';

    // Coloana (0-indexed) din CSV care conține EAN-ul
    private const COL_EAN  = 3; // "Cod extern"
    private const COL_NAME = 1; // "Articol" (pentru debug)

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');
        $noMove = (bool) $this->option('no-move');
        $singleFile = $this->option('file');

        $dir       = storage_path('furnizori');
        $processedDir = $dir . '/processed';

        if (! is_dir($dir)) {
            $this->error("Directorul {$dir} nu există.");
            return self::FAILURE;
        }

        // Colectează fișierele de procesat
        if ($singleFile) {
            $files = [rtrim($dir, '/') . '/' . $singleFile];
            $files = array_filter($files, fn($f) => file_exists($f));
            if (empty($files)) {
                $this->error("Fișierul '{$singleFile}' nu a fost găsit în {$dir}");
                return self::FAILURE;
            }
        } else {
            $files = glob($dir . '/*.csv') ?: [];
            // Ignoră subdirectoare (de ex. /processed/)
            $files = array_filter($files, fn($f) => is_file($f));
        }

        if (empty($files)) {
            $this->info('Nu există fișiere CSV noi în ' . $dir);
            return self::SUCCESS;
        }

        $this->info('Import asocieri furnizori din CSV' . ($dryRun ? ' [DRY RUN]' : ''));
        $this->newLine();

        // Creează directorul /processed dacă e nevoie
        if (! $dryRun && ! $noMove && ! is_dir($processedDir)) {
            mkdir($processedDir, 0755, true);
        }

        // Preîncarcă SKU-urile produselor WooCommerce (EAN = SKU pentru placeholder)
        // Index: sku → woo_product_id
        $this->line('Încarc index SKU produse...');
        $skuIndex = DB::table('woo_products')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('id', 'sku');

        $this->line("  Produse indexate: {$skuIndex->count()}");
        $this->newLine();

        $summary = [];

        foreach ($files as $filePath) {
            $fileName     = basename($filePath);
            $supplierName = pathinfo($fileName, PATHINFO_FILENAME);

            $this->line("<fg=cyan>━━━ {$fileName} ━━━</>");

            // Găsește sau creează furnizorul
            [$supplierId, $supplierStatus] = $this->findOrCreateSupplier($supplierName, $dryRun);

            if ($supplierId === null) {
                $this->warn("  Nu s-a putut crea furnizorul. Fișier sărit.");
                $summary[] = [
                    'file'     => $fileName,
                    'supplier' => $supplierName,
                    'status'   => 'EROARE',
                    'id'       => '-',
                    'asociate' => 0,
                    'negasite' => 0,
                ];
                continue;
            }

            $this->line("  Furnizor: <fg=yellow>{$supplierName}</> [{$supplierStatus}] (ID: {$supplierId})");

            // Procesează rândurile CSV
            [$associated, $notFound, $inserts] = $this->processCsv(
                $filePath,
                $supplierId,
                $skuIndex,
                $force,
                $dryRun,
            );

            // Salvează asocierile
            if (! $dryRun && ! empty($inserts)) {
                foreach (array_chunk($inserts, 500) as $chunk) {
                    DB::table('product_suppliers')->upsert(
                        $chunk,
                        ['woo_product_id', 'supplier_id'],
                        ['updated_at']
                    );
                }
            }

            $this->line("  Asociate: <fg=green>{$associated}</> | Negăsite: <fg=red>{$notFound}</>" . ($dryRun ? ' [DRY RUN]' : ''));

            // Mută fișierul în /processed
            if (! $dryRun && ! $noMove) {
                $timestamp   = now()->format('Ymd_His');
                $destination = $processedDir . '/' . $timestamp . '_' . $fileName;
                rename($filePath, $destination);
                $this->line("  <fg=gray>→ Mutat în processed/{$timestamp}_{$fileName}</>");
            }

            $summary[] = [
                'file'     => $fileName,
                'supplier' => $supplierName,
                'status'   => $supplierStatus,
                'id'       => $supplierId,
                'asociate' => $associated,
                'negasite' => $notFound,
            ];

            $this->newLine();
        }

        // Tabel sumar final
        $this->info('Gata. Rezumat:');
        $this->table(
            ['Fișier', 'Furnizor', 'Status', 'ID', 'Asociate', 'Negăsite'],
            array_map(fn($r) => [
                $r['file'],
                $r['supplier'],
                $r['status'],
                $r['id'],
                $r['asociate'],
                $r['negasite'],
            ], $summary)
        );

        if ($dryRun) {
            $this->warn('DRY RUN — nimic nu a fost salvat.');
        }

        return self::SUCCESS;
    }

    /**
     * Găsește furnizorul după nume sau îl creează.
     * Returnează [id, status_label] sau [null, 'EROARE'].
     */
    private function findOrCreateSupplier(string $name, bool $dryRun): array
    {
        // Căutare case-insensitive
        $supplier = DB::table('suppliers')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($supplier) {
            return [(int) $supplier->id, 'Existent'];
        }

        if ($dryRun) {
            return [0, 'Ar fi creat (dry-run)'];
        }

        $id = DB::table('suppliers')->insertGetId([
            'name'       => $name,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$id, 'Creat nou'];
    }

    /**
     * Parsează CSV-ul și construiește lista de asocieri.
     * Returnează [associated_count, not_found_count, inserts_array].
     */
    private function processCsv(
        string $filePath,
        int $supplierId,
        \Illuminate\Support\Collection $skuIndex,
        bool $force,
        bool $dryRun,
    ): array {
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $this->warn("  Nu pot deschide fișierul.");
            return [0, 0, []];
        }

        // Detectează separatorul (virgulă sau punct-virgulă)
        $firstLine = fgets($handle);
        rewind($handle);
        $separator = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        // Existingele asocieri pentru acest furnizor (pentru --force=false)
        $existingProductIds = $force ? collect() : DB::table('product_suppliers')
            ->where('supplier_id', $supplierId)
            ->pluck('woo_product_id')
            ->flip();

        $associated = 0;
        $notFound   = 0;
        $inserts    = [];
        $rowIndex   = 0;
        $nowStr     = now()->toDateTimeString();

        while (($row = fgetcsv($handle, 0, $separator)) !== false) {
            $rowIndex++;

            // Sare primele 2 rânduri (header-e WinMentor)
            if ($rowIndex <= 2) {
                continue;
            }

            $ean  = trim($row[self::COL_EAN] ?? '');
            $name = trim($row[self::COL_NAME] ?? '');

            // EAN valid = 8-14 cifre
            if (! preg_match('/^\d{8,14}$/', $ean)) {
                continue;
            }

            $productId = $skuIndex->get($ean);

            if ($productId === null) {
                $notFound++;
                continue;
            }

            // Dacă există deja asocierea și nu e --force, sare
            if (! $force && $existingProductIds->has($productId)) {
                $associated++; // numărăm ca asociate (deja existente)
                continue;
            }

            $inserts[] = [
                'woo_product_id' => (int) $productId,
                'supplier_id'    => $supplierId,
                'is_preferred'   => false,
                'created_at'     => $nowStr,
                'updated_at'     => $nowStr,
            ];

            $associated++;
        }

        fclose($handle);

        return [$associated, $notFound, $inserts];
    }
}
