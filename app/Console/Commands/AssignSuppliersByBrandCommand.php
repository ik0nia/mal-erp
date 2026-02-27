<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Asociază furnizori la produsele care nu au furnizor setat,
 * pe baza brandului produsului → tabelul brand_supplier.
 *
 * Dacă brandul produsului este deja legat de un furnizor în brand_supplier,
 * produsul va fi asociat automat acelui furnizor.
 *
 * Dacă brandul are mai mulți furnizori, sunt asociați toți —
 * primul (după id) este marcat is_preferred=true.
 */
class AssignSuppliersByBrandCommand extends Command
{
    protected $signature = 'products:assign-suppliers-by-brand
                            {--dry-run : Afișează potrivirile fără să le salveze}
                            {--force   : Re-procesează și produsele care au deja un furnizor}';

    protected $description = 'Asociază furnizori la produse pe baza brandului (via brand_supplier)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        $this->info('Asociere furnizori via brand' . ($dryRun ? ' [DRY RUN]' : ''));

        // Construim un index: brand_id → [supplier_id, ...]
        $brandSuppliers = DB::table('brand_supplier')
            ->orderBy('brand_id')
            ->orderBy('supplier_id')
            ->get(['brand_id', 'supplier_id'])
            ->groupBy('brand_id')
            ->map(fn ($rows) => $rows->pluck('supplier_id')->map(fn ($id) => (int) $id)->all());

        // Construim un index: lower(brand.name) → brand_id
        $brandIndex = DB::table('brands')
            ->whereNotNull('name')
            ->get(['id', 'name'])
            ->keyBy(fn ($b) => mb_strtolower(trim($b->name)));

        // Produse fără furnizor, cu brand setat
        $query = DB::table('woo_products')
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->select('id', 'name', 'brand', 'sku', 'is_placeholder');

        if (! $force) {
            $query->whereNotIn('id', DB::table('product_suppliers')->select('woo_product_id'));
        }

        $products = $query->orderBy('brand')->orderBy('name')->get();

        $this->line("Produse de procesat: {$products->count()}");
        $this->newLine();

        $assigned   = 0;
        $skipped    = 0;
        $inserts    = [];
        $nowStr     = now()->toDateTimeString();

        foreach ($products as $product) {
            $brandKey = mb_strtolower(trim($product->brand));
            $brand    = $brandIndex->get($brandKey);

            if (! $brand) {
                $this->line("  <fg=gray>SKIP brand necunoscut:</> {$product->brand} — {$product->name}");
                $skipped++;
                continue;
            }

            $supplierIds = $brandSuppliers->get($brand->id, []);

            if (empty($supplierIds)) {
                $this->line("  <fg=gray>SKIP brand fara furnizor:</> {$product->brand} — {$product->name}");
                $skipped++;
                continue;
            }

            $supplierNames = DB::table('suppliers')
                ->whereIn('id', $supplierIds)
                ->pluck('name', 'id');

            $preferredId = $supplierIds[0];

            foreach ($supplierIds as $supplierId) {
                $isPreferred = ($supplierId === $preferredId && count($supplierIds) === 1);
                $label = $isPreferred ? '<fg=green>preferred</>' : '<fg=cyan>secundar</>';

                $this->line(sprintf(
                    '  #%d <fg=yellow>%s</> [%s] → %s %s',
                    $product->id,
                    mb_strimwidth($product->name, 0, 50, '…'),
                    $product->brand,
                    $supplierNames[$supplierId] ?? "#{$supplierId}",
                    $label
                ));

                if (! $dryRun) {
                    $inserts[] = [
                        'woo_product_id' => (int) $product->id,
                        'supplier_id'    => (int) $supplierId,
                        'is_preferred'   => $isPreferred ? 1 : 0,
                        'currency'       => 'RON',
                        'created_at'     => $nowStr,
                        'updated_at'     => $nowStr,
                    ];
                }
            }

            $assigned++;
        }

        if (! $dryRun && $inserts !== []) {
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('product_suppliers')->upsert(
                    $chunk,
                    ['woo_product_id', 'supplier_id'],
                    ['is_preferred', 'currency', 'updated_at']
                );
            }
            $this->newLine();
            $this->info("Rânduri inserate/actualizate în product_suppliers: " . count($inserts));
        }

        $this->newLine();
        $this->info(
            "Asociate: {$assigned} produse | Sărite: {$skipped}" .
            ($dryRun ? ' [DRY RUN — nimic salvat]' : '')
        );

        return self::SUCCESS;
    }
}
