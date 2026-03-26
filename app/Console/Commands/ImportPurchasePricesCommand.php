<?php

namespace App\Console\Commands;

use App\Models\ProductPurchasePriceLog;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\WooProduct;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportPurchasePricesCommand extends Command
{
    protected $signature = 'import:purchase-prices
                            {--file= : Calea către fișierul XLSX (default: storage/app/LISTA PRODUSE PRET ACHIZITIE.xlsx)}
                            {--dry-run : Simulează fără a salva în DB}';

    protected $description = 'Import one-time istoric prețuri achiziție din WinMentor XLSX';

    // Normalizare nume furnizor pentru fuzzy match
    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        // elimină tab-uri, spații multiple
        $name = preg_replace('/\s+/', ' ', $name);
        // elimină sufixe juridice
        $name = preg_replace('/\b(srl|sa|snc|ra|sn|s\.r\.l\.|s\.a\.|s\.n\.c\.)\b\.?/u', '', $name);
        // elimină punctuatie
        $name = preg_replace('/[.\-,\'`\'"]/', ' ', $name);
        $name = preg_replace('/&/', 'and', $name);
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function isNonSupplier(string $name): bool
    {
        $keywords = ['inventar', 'reglari', 'reglare', 'marfa desfacuta', 'depozit', 'ajustare', 'casare', 'diferente', 'test'];
        $lower = mb_strtolower($name);
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return false;
    }

    public function handle(): int
    {
        $file = $this->option('file') ?? storage_path('app/LISTA PRODUSE PRET ACHIZITIE.xlsx');
        $dryRun = $this->option('dry-run');

        if (!file_exists($file)) {
            $this->error("Fișierul nu există: {$file}");
            return 1;
        }

        $this->info("Citesc fișierul...");
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        // Build supplier lookup map (normalized name → supplier id)
        $this->info("Construiesc map furnizori...");
        $supplierMap = [];
        Supplier::all()->each(function (Supplier $s) use (&$supplierMap) {
            $key = $this->normalizeName($s->name);
            $supplierMap[$key] = $s->id;
        });

        // Build product lookup map (ean → woo_product_id)
        $this->info("Construiesc map produse...");
        $productMap = WooProduct::whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('id', 'sku')
            ->toArray();

        $stats = [
            'total'          => 0,
            'skipped'        => 0,
            'imported'       => 0,
            'product_miss'   => 0,
            'supplier_match' => 0,
            'supplier_miss'  => 0,
            'uom_mismatch'   => [],
            'price_errors'   => [],
        ];

        $rows = [];

        $this->info("Parsez rânduri...");
        for ($row = 3; $row <= $highestRow; $row++) {
            $nrCrt   = trim((string) $sheet->getCell('A' . $row)->getValue());
            $articol = trim((string) $sheet->getCell('B' . $row)->getValue());
            $ean     = trim((string) $sheet->getCell('C' . $row)->getValue());
            $codInt  = trim((string) $sheet->getCell('D' . $row)->getValue());
            $um      = trim((string) $sheet->getCell('E' . $row)->getValue());
            $stoc    = $sheet->getCell('F' . $row)->getValue();
            $pretG   = $sheet->getCell('G' . $row)->getValue();
            $pretH   = $sheet->getCell('H' . $row)->getValue();
            $furnizor = trim((string) $sheet->getCell('I' . $row)->getValue());
            $dataRaw  = $sheet->getCell('J' . $row)->getFormattedValue();

            // Skip header row 2
            if ($nrCrt === 'crt.') {
                continue;
            }

            // Skip "Total PRODUS" rows
            if (str_starts_with($nrCrt, 'Total') || str_starts_with($articol, 'Total ')) {
                $stats['skipped']++;
                continue;
            }

            // Skip rows fără articol sau preț
            if ($articol === '' || $pretG === null || $pretG === '') {
                $stats['skipped']++;
                continue;
            }

            $stats['total']++;

            // Parse preț
            $unitPrice = (float) str_replace(',', '.', (string) $pretG);
            $salePrice = (float) str_replace(',', '.', (string) $pretH);

            if ($unitPrice <= 0) {
                $stats['price_errors'][] = $articol;
                $stats['skipped']++;
                continue;
            }

            // Parse dată
            $acquiredAt = null;
            if ($dataRaw && $dataRaw !== '30.12.1899') {
                try {
                    $acquiredAt = Carbon::createFromFormat('d.m.Y', $dataRaw)->toDateString();
                } catch (\Exception) {
                    $acquiredAt = null;
                }
            }

            // Match produs
            $productId = null;
            if ($ean !== '') {
                $productId = $productMap[$ean] ?? null;
            }

            if (!$productId) {
                $stats['product_miss']++;
                continue;
            }

            // Match furnizor
            $supplierId = null;
            $isNonSupplier = $this->isNonSupplier($furnizor);
            if ($furnizor !== '' && !$isNonSupplier) {
                $normKey = $this->normalizeName($furnizor);
                // exact match
                if (isset($supplierMap[$normKey])) {
                    $supplierId = $supplierMap[$normKey];
                    $stats['supplier_match']++;
                } else {
                    // fuzzy: caută cel mai bun similar_text
                    $bestScore = 0;
                    $bestId = null;
                    foreach ($supplierMap as $key => $id) {
                        similar_text($normKey, $key, $pct);
                        if ($pct > $bestScore) {
                            $bestScore = $pct;
                            $bestId = $id;
                        }
                    }
                    if ($bestScore >= 70) {
                        $supplierId = $bestId;
                        $stats['supplier_match']++;
                    } else {
                        $stats['supplier_miss']++;
                    }
                }
            }

            // Verificare UM vs DB
            $product = WooProduct::find($productId);
            if ($product && $um !== '' && $product->unit !== null) {
                $umNorm = mb_strtolower(trim($um));
                $dbUm = mb_strtolower(trim($product->unit));
                if ($umNorm !== $dbUm) {
                    $stats['uom_mismatch'][$articol] = "{$um} (Excel) vs {$product->unit} (DB)";
                }
            }

            $rows[] = [
                'woo_product_id'    => $productId,
                'supplier_id'       => $supplierId,
                'supplier_name_raw' => $furnizor !== '' ? $furnizor : null,
                'unit_price'        => $unitPrice,
                'currency'          => 'RON',
                'acquired_at'       => $acquiredAt,
                'source'            => 'winmentor_import',
                'uom'               => $um ?: null,
                'notes'             => null,
            ];
        }

        // Dedup: eliminăm rândurile care există deja în DB (match pe product+supplier+price+date)
        $this->info("Verific duplicate...");
        $existingKeys = DB::table('product_purchase_price_logs')
            ->select('woo_product_id', 'supplier_id', 'unit_price', 'acquired_at')
            ->get()
            ->map(fn ($r) => $r->woo_product_id . '|' . ($r->supplier_id ?? 0) . '|' . round((float) $r->unit_price, 4) . '|' . ($r->acquired_at ?? ''))
            ->flip()
            ->toArray();

        $uniqueRows = [];
        $duplicateCount = 0;
        foreach ($rows as $row) {
            $key = $row['woo_product_id'] . '|' . ($row['supplier_id'] ?? 0) . '|' . round($row['unit_price'], 4) . '|' . ($row['acquired_at'] ?? '');
            if (isset($existingKeys[$key])) {
                $duplicateCount++;
            } else {
                $uniqueRows[] = $row;
                $existingKeys[$key] = true; // prevent duplicates within same file
            }
        }

        $this->info("Rânduri valide pentru import: " . count($rows) . " (din care {$duplicateCount} duplicate eliminate, " . count($uniqueRows) . " noi)");

        if ($dryRun) {
            $this->warn("[DRY RUN] Nu s-a salvat nimic.");
            $this->printStats($stats, count($uniqueRows));
            return 0;
        }

        if (empty($uniqueRows)) {
            $this->info("Nimic nou de importat — toate rândurile există deja.");
            $this->printStats($stats, 0);
            return 0;
        }

        // Insert în batch
        $this->info("Salvez în DB...");
        $chunks = array_chunk($uniqueRows, 500);
        $now = now()->toDateTimeString();
        foreach ($chunks as $chunk) {
            $insert = array_map(fn($r) => array_merge($r, ['created_at' => $now, 'updated_at' => $now]), $chunk);
            DB::table('product_purchase_price_logs')->insert($insert);
            $stats['imported'] += count($chunk);
            $this->output->write('.');
        }
        $this->newLine();

        // Actualizează last_purchase_price + last_purchase_date pe product_suppliers
        $this->info("Actualizez last_purchase_price pe product_suppliers...");
        $this->updateLastPurchasePrices();

        $this->printStats($stats, count($rows));
        return 0;
    }

    private function updateLastPurchasePrices(): void
    {
        // Pentru fiecare combinație produs+furnizor, ia ultimul preț
        $latest = DB::table('product_purchase_price_logs')
            ->whereNotNull('supplier_id')
            ->whereNotNull('acquired_at')
            ->select('woo_product_id', 'supplier_id', 'unit_price', 'acquired_at')
            ->orderBy('acquired_at', 'desc')
            ->get()
            ->groupBy(fn($r) => $r->woo_product_id . '_' . $r->supplier_id)
            ->map(fn($g) => $g->first());

        $updated = 0;
        foreach ($latest as $item) {
            $affected = DB::table('product_suppliers')
                ->where('woo_product_id', $item->woo_product_id)
                ->where('supplier_id', $item->supplier_id)
                ->update([
                    'last_purchase_price' => $item->unit_price,
                    'last_purchase_date'  => $item->acquired_at,
                    // Setează purchase_price dacă e gol — devine prețul de referință pentru PO
                    'purchase_price' => DB::raw('COALESCE(NULLIF(purchase_price, 0), ' . (float)$item->unit_price . ')'),
                ]);
            $updated += $affected;
        }

        $this->info("Actualizate {$updated} înregistrări product_suppliers.");
    }

    private function printStats(array $stats, int $importCount): void
    {
        $this->newLine();
        $this->table(['Indicator', 'Valoare'], [
            ['Total rânduri procesate', $stats['total']],
            ['Skipped (Total/header/fără preț)', $stats['skipped']],
            ['Produs negăsit în DB (fără EAN)', $stats['product_miss']],
            ['Importate', $importCount],
            ['Furnizor identificat', $stats['supplier_match']],
            ['Furnizor neidentificat (stocat raw)', $stats['supplier_miss']],
        ]);

        if (!empty($stats['uom_mismatch'])) {
            $this->newLine();
            $this->warn("Discrepanțe unitate de măsură (" . count($stats['uom_mismatch']) . "):");
            foreach (array_slice($stats['uom_mismatch'], 0, 30, true) as $prod => $diff) {
                $this->line("  {$prod}: {$diff}");
            }
            if (count($stats['uom_mismatch']) > 30) {
                $this->line("  ... și " . (count($stats['uom_mismatch']) - 30) . " altele");
            }
        }

        if (!empty($stats['price_errors'])) {
            $this->warn(count($stats['price_errors']) . " rânduri cu preț invalid (0 sau null) — skipped.");
        }
    }
}
