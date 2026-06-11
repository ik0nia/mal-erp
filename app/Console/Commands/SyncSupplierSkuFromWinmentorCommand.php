<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSupplierSkuFromWinmentorCommand extends Command
{
    protected $signature = 'winmentor:sync-supplier-sku
                            {--supplier= : ID furnizor specific (opțional)}
                            {--only-empty : (deprecated, acum implicit) Actualizează doar cele fără supplier_sku}
                            {--force : Suprascrie și codurile existente (implicit NU suprascrie)}';

    protected $description = 'Sincronizează supplier_sku din codExternAlt WinMentor Bridge pentru toți furnizorii';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $supplierId = $this->option('supplier') ? (int) $this->option('supplier') : null;
        $onlyEmpty  = ! (bool) $this->option('force'); // implicit protejăm codurile existente

        // ── 1. Descarcă toți articolii din WinMentor (paginat) ──────────────────
        $this->info('Descarcă articoli din WinMentor Bridge...');
        $index = $this->fetchAllArticles($bridge);
        $this->info('  ' . count($index) . ' articoli indexați (codExtern → codExternAlt)');

        // ── 2. Încarcă product_suppliers din DB ─────────────────────────────────
        $query = DB::table('product_suppliers as ps')
            ->join('woo_products as w', 'w.id', '=', 'ps.woo_product_id')
            ->whereNotNull('w.sku')
            ->where('w.sku', '!=', '');

        if ($supplierId) {
            $query->where('ps.supplier_id', $supplierId);
        }

        if ($onlyEmpty) {
            $query->where(function ($q) {
                $q->whereNull('ps.supplier_sku')
                  ->orWhere('ps.supplier_sku', '')
                  ->orWhere('ps.supplier_sku', '-');
            });
        }

        $products = $query->get(['ps.id as ps_id', 'w.sku', 'ps.supplier_sku'])->toArray();
        $total    = count($products);

        $this->info("Produse de procesat: {$total}");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated  = 0;
        $skipped  = 0;
        $notFound = 0;

        // ── 3. Matching local — fără apeluri suplimentare la Bridge ─────────────
        foreach ($products as $p) {
            $alt = trim($index[$p->sku] ?? '');

            if ($alt === '') {
                $notFound++;
            } elseif ($alt === $p->supplier_sku) {
                $skipped++;
            } else {
                DB::table('product_suppliers')
                    ->where('id', $p->ps_id)
                    ->update(['supplier_sku' => $alt, 'updated_at' => now()]);
                $updated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Actualizate: {$updated} | Deja corecte: {$skipped} | Fără cod în WinMentor: {$notFound}");

        return self::SUCCESS;
    }

    private function fetchAllArticles(WinmentorBridgeClient $bridge): array
    {
        $index    = [];
        $page     = 1;
        $pageSize = 500;

        do {
            $result     = $bridge->getArticolePaginated($page, $pageSize);
            $items      = $result['items'] ?? [];
            $totalPages = (int) ($result['totalPages'] ?? 1);

            foreach ($items as $item) {
                $codExtern = trim($item['codExtern'] ?? '');
                $codAlt    = trim($item['codExternAlt'] ?? '');
                if ($codExtern !== '' && $codAlt !== '') {
                    $index[$codExtern] = $codAlt;
                }
            }

            $page++;
        } while ($page <= $totalPages);

        return $index;
    }
}
