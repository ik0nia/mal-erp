<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;
use SimpleXMLElement;

/**
 * Importă asocierile produs-furnizor din exportul WinMentor.
 *
 * Surse:
 *  - storage/furnizori/lista producatori (1).csv  — produse cu cod producator
 *  - storage/furnizori/Explicatii producatori (1).xlsx — cod → denumire furnizor real
 *
 * Logică:
 *  1. Citește Excel: {cod_wm => denumire_furnizor}
 *  2. Pentru fiecare furnizor unic: găsește în ERP sau îl creează
 *  3. Citește CSV: pentru fiecare produs cu cod producator definit:
 *     - Găsește WooProduct după SKU (Cod extern = EAN)
 *     - Creează asocierea product_suppliers (dacă nu există deja)
 */
class ImportSupplierProductsFromWinmentorCommand extends Command
{
    protected $signature = 'supplier:import-winmentor
                            {--dry-run : Afișează ce s-ar face, fără a salva}
                            {--force  : Suprascrie asocierile existente}';

    protected $description = 'Importă furnizori și asocieri produs-furnizor din exportul WinMentor';

    private const XLSX_PATH = 'furnizori/Explicatii producatori (1).xlsx';
    private const CSV_PATH  = 'furnizori/lista producatori (1).csv';

    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $force        = (bool) $this->option('force');

        if ($this->dryRun) {
            $this->warn('--- MOD DRY-RUN: nu se salvează nimic ---');
        }

        // ----------------------------------------------------------------
        // 1. Citește Excel → {cod_wm => denumire_furnizor}
        // ----------------------------------------------------------------
        $this->info('Citesc fișierul Excel...');
        $mapping = $this->readExcelMapping();

        if (empty($mapping)) {
            $this->error('Nu s-a putut citi fișierul Excel.');
            return self::FAILURE;
        }

        $this->line('  ' . count($mapping) . ' producători cu furnizor definit');

        // ----------------------------------------------------------------
        // 2. Găsește sau creează furnizorii în ERP
        // ----------------------------------------------------------------
        $this->info('Procesez furnizorii...');
        $supplierIds  = $this->resolveSuppliers($mapping);  // {denumire => supplier_id}

        $created  = collect($supplierIds)->filter(fn ($v) => $v['created'])->count();
        $existing = collect($supplierIds)->filter(fn ($v) => ! $v['created'])->count();
        $this->line("  Existenți: {$existing} | Creați: {$created}");

        // ----------------------------------------------------------------
        // 3. Citește CSV și asociază produsele
        // ----------------------------------------------------------------
        $this->info('Citesc CSV-ul de produse...');
        $csvRows = $this->readCsv();
        $this->line('  ' . count($csvRows) . ' produse în CSV');

        // Inversăm mapping: {cod_wm => supplier_id}
        $codToSupplierId = [];
        foreach ($mapping as $cod => $denumire) {
            if (isset($supplierIds[$denumire])) {
                $codToSupplierId[$cod] = $supplierIds[$denumire]['id'];
            }
        }

        // Preîncărcăm toate SKU-urile din WooProduct
        $this->info('Preîncărc produsele din ERP...');
        $skuToProductId = WooProduct::query()
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->where('is_placeholder', false)
            ->pluck('id', 'sku')
            ->all();

        // Asocieri existente
        $existingAssoc = DB::table('product_suppliers')
            ->select('woo_product_id', 'supplier_id')
            ->get()
            ->groupBy('woo_product_id')
            ->map(fn ($g) => $g->pluck('supplier_id')->all());

        $stats = [
            'processed'        => 0,
            'skipped_no_match' => 0,  // SKU negăsit în ERP
            'skipped_no_code'  => 0,  // fără cod producator în mapping
            'already_exists'   => 0,
            'inserted'         => 0,
            'supplier_missing' => 0,
        ];

        $toInsert = [];

        foreach ($csvRows as $row) {
            $codProducator = $row['producator'];
            $ean           = $row['cod_extern'];
            $codArticol    = $row['cod_articol'];
            $pret          = $row['pret'];

            if ($codProducator === '' || ! isset($codToSupplierId[$codProducator])) {
                $stats['skipped_no_code']++;
                continue;
            }

            $supplierId = $codToSupplierId[$codProducator];

            if ($ean === '' || ! isset($skuToProductId[$ean])) {
                $stats['skipped_no_match']++;
                continue;
            }

            $productId = $skuToProductId[$ean];
            $stats['processed']++;

            $alreadyLinked = isset($existingAssoc[$productId])
                && in_array($supplierId, $existingAssoc[$productId], true);

            if ($alreadyLinked && ! $force) {
                $stats['already_exists']++;
                continue;
            }

            $toInsert[] = [
                'woo_product_id' => $productId,
                'supplier_id'    => $supplierId,
                'supplier_sku'   => $codArticol,
                'is_preferred'   => false, // setăm după
                'created_at'     => now(),
                'updated_at'     => now(),
            ];

            $stats['inserted']++;
        }

        // Setăm is_preferred = true pentru produsele cu un singur furnizor
        $productSupplierCount = [];
        foreach ($toInsert as $row) {
            $productSupplierCount[$row['woo_product_id']] = ($productSupplierCount[$row['woo_product_id']] ?? 0) + 1;
        }

        $toInsert = array_map(function ($row) use ($productSupplierCount, $existingAssoc) {
            $pid = $row['woo_product_id'];
            // is_preferred dacă e singurul furnizor nou ȘI nu are deja altul preferred
            $hasExisting = isset($existingAssoc[$pid]) && ! empty($existingAssoc[$pid]);
            $row['is_preferred'] = (! $hasExisting && ($productSupplierCount[$pid] ?? 0) === 1) ? true : false;
            return $row;
        }, $toInsert);

        // ----------------------------------------------------------------
        // Afișare sumar
        // ----------------------------------------------------------------
        $this->newLine();
        $this->table(
            ['Statistică', 'Valoare'],
            [
                ['Produse cu furnizor în CSV',    $stats['processed'] + $stats['already_exists']],
                ['Asocieri noi de inserat',       $stats['inserted']],
                ['Asocieri deja existente',       $stats['already_exists']],
                ['SKU negăsit în ERP',            $stats['skipped_no_match']],
                ['Fără cod producator în Excel',  $stats['skipped_no_code']],
            ]
        );

        if ($this->dryRun) {
            $this->warn('Dry-run complet. Rulează fără --dry-run pentru a salva.');
            return self::SUCCESS;
        }

        if (empty($toInsert)) {
            $this->info('Nimic de inserat.');
            return self::SUCCESS;
        }

        if (! $this->confirm("Inserez {$stats['inserted']} asocieri noi?", true)) {
            return self::SUCCESS;
        }

        // ----------------------------------------------------------------
        // Inserare în DB (batch, câte 500)
        // ----------------------------------------------------------------
        $this->info('Salvez asocierile...');
        $bar = $this->output->createProgressBar(count($toInsert));
        $bar->start();

        foreach (array_chunk($toInsert, 500) as $chunk) {
            if ($force) {
                // Upsert pe (woo_product_id, supplier_id)
                foreach ($chunk as $row) {
                    DB::table('product_suppliers')->updateOrInsert(
                        ['woo_product_id' => $row['woo_product_id'], 'supplier_id' => $row['supplier_id']],
                        $row
                    );
                }
            } else {
                DB::table('product_suppliers')->insertOrIgnore($chunk);
            }
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();
        $this->info('Import finalizat cu succes!');

        return self::SUCCESS;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Citește Excel-ul și returnează {cod_wm => denumire_furnizor}.
     */
    private function readExcelMapping(): array
    {
        $path = storage_path(self::XLSX_PATH);

        if (! file_exists($path)) {
            $this->error("Fișier negăsit: {$path}");
            return [];
        }

        $strings  = [];
        $rowsData = [];

        try {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                $this->error('Nu se poate deschide Excel-ul.');
                return [];
            }

            // Shared strings
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml) {
                $ss = new SimpleXMLElement($ssXml);
                $ns = $ss->getNamespaces(true);
                $defNs = array_values($ns)[0] ?? '';
                foreach ($ss->si as $si) {
                    $parts = [];
                    foreach ($si->r ?? [$si] as $part) {
                        $t = $part->t ?? null;
                        if ($t !== null) {
                            $parts[] = (string) $t;
                        }
                    }
                    $strings[] = implode('', $parts);
                }
            }

            // Sheet1
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml) {
                $sheet = new SimpleXMLElement($sheetXml);
                foreach ($sheet->sheetData->row ?? [] as $row) {
                    $vals = [];
                    foreach ($row->c as $c) {
                        $t = (string) ($c['t'] ?? '');
                        $v = (string) ($c->v ?? '');
                        $vals[] = ($t === 's' && $v !== '') ? ($strings[(int) $v] ?? '') : $v;
                    }
                    $rowsData[] = $vals;
                }
            }

            $zip->close();
        } catch (\Throwable $e) {
            $this->error('Eroare la citirea Excel: ' . $e->getMessage());
            return [];
        }

        $mapping = [];
        foreach (array_slice($rowsData, 1) as $row) { // skip header
            $cod      = trim($row[0] ?? '');
            $furnizor = trim($row[1] ?? '');
            if ($cod !== '' && $furnizor !== '' && $cod !== '---- nedefinit ----' && $cod !== 'ALTELE') {
                $mapping[$cod] = $furnizor;
            }
        }

        return $mapping;
    }

    /**
     * Citește CSV-ul și returnează array de produse.
     */
    private function readCsv(): array
    {
        $path = storage_path(self::CSV_PATH);

        if (! file_exists($path)) {
            $this->error("Fișier negăsit: {$path}");
            return [];
        }

        $rows   = [];
        $handle = fopen($path, 'r');
        $lineNo = 0;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $lineNo++;
            if ($lineNo <= 2) {
                continue; // skip 2 header rows
            }

            $rows[] = [
                'cod_extern'  => trim($row[3] ?? ''),  // EAN — se potrivește cu SKU în WooProduct
                'cod_articol' => trim($row[4] ?? ''),  // Cod intern WinMentor
                'pret'        => (float) str_replace(',', '.', $row[5] ?? '0'),
                'producator'  => trim($row[8] ?? ''),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Găsește sau creează furnizorii în ERP.
     * Returnează {denumire => ['id' => int, 'created' => bool]}.
     */
    private function resolveSuppliers(array $mapping): array
    {
        $uniqueNames = array_unique(array_values($mapping));
        $result      = [];

        // Preîncărcăm toți furnizorii existenți
        $existing = Supplier::query()
            ->get(['id', 'name'])
            ->keyBy(fn ($s) => strtolower(trim($s->name)));

        foreach ($uniqueNames as $denumire) {
            $key = strtolower(trim($denumire));

            // Match exact (case-insensitive)
            if (isset($existing[$key])) {
                $result[$denumire] = ['id' => $existing[$key]->id, 'created' => false];
                continue;
            }

            // Match parțial (denumire din ERP conține sau e conținută în denumire din Excel)
            $partial = null;
            foreach ($existing as $erpKey => $supplier) {
                if (str_contains($erpKey, $key) || str_contains($key, $erpKey)) {
                    $partial = $supplier;
                    break;
                }
            }

            if ($partial) {
                $this->line("  <info>Match parțial:</info> \"{$denumire}\" → \"{$partial->name}\" (id={$partial->id})");
                $result[$denumire] = ['id' => $partial->id, 'created' => false];
                continue;
            }

            // Nu există → creăm
            $this->line("  <comment>Creez furnizor nou:</comment> \"{$denumire}\"");

            if (! $this->dryRun) {
                $supplier = Supplier::create([
                    'name'      => $denumire,
                    'is_active' => true,
                ]);
                $result[$denumire] = ['id' => $supplier->id, 'created' => true];
                // Adăugăm la lista existenților pentru potriviri ulterioare
                $existing[$key] = $supplier;
            } else {
                $result[$denumire] = ['id' => 0, 'created' => true];
            }
        }

        return $result;
    }
}
