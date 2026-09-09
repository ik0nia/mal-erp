<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooDirectSqlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliere unică stoc WooCommerce ↔ ERP.
 *
 * Aliniază _stock / _stock_status / _manage_stock / _backorders de pe site la
 * stocul REAL din ERP (product_stocks), pentru toate produsele sincronizate.
 *
 * Scop:
 *   - repară produsele „fantomă" (instock + _stock=0 + manage_stock=yes → coș blocat);
 *   - garantează enforcement corect: nu se comandă peste stoc;
 *   - produse cu feed furnizor activ → backorders='notify' (diferența = precomandă).
 *
 * Codul de sync (winmentor:sync-stock-bridge) menține valorile corecte de aici încolo;
 * această comandă stabilește baseline-ul corect o singură dată.
 */
class ReconcileWooStockCommand extends Command
{
    protected $signature = 'winmentor:reconcile-woo-stock
                            {--dry-run : Afișează ce s-ar schimba, fără a scrie pe site}
                            {--only= : Listă woo_id separate prin virgulă (reconciliere țintită)}';

    protected $description = 'Aliniază stocul WooCommerce la stocul real ERP (fix produse fantomă + backorders precomandă)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $connection = IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_WINMENTOR_BRIDGE)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            $this->error('Conexiune WinMentor Bridge inactivă / inexistentă.');
            return self::FAILURE;
        }

        // Produse sincronizate: publicate, cu woo_id real, care au stoc cunoscut în ERP.
        $query = WooProduct::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_id')
            ->where('woo_id', '<', 1_000_000_000_000_000); // exclude placeholder-e

        if ($only = $this->option('only')) {
            $ids = array_filter(array_map('intval', explode(',', $only)));
            $query->whereIn('woo_id', $ids);
        }

        $products = $query->get(['id', 'woo_id', 'name', 'stock_status']);
        $this->info('Produse candidate: ' . $products->count());

        // Stoc real ERP (sumă pe toate locațiile) + flag feed furnizor activ.
        $stockByProduct = DB::table('product_stocks')
            ->whereIn('woo_product_id', $products->pluck('id'))
            ->groupBy('woo_product_id')
            ->select('woo_product_id', DB::raw('SUM(quantity) as qty'))
            ->pluck('qty', 'woo_product_id');

        $feedProductIds = array_fill_keys(
            DB::table('product_suppliers as ps')
                ->join('supplier_feeds as sf', 'sf.supplier_id', '=', 'ps.supplier_id')
                ->where('sf.is_active', true)
                ->whereIn('ps.woo_product_id', $products->pluck('id')->all())
                ->distinct()
                ->pluck('ps.woo_product_id')
                ->all(),
            true
        );

        $batch = [];
        $stats = ['instock' => 0, 'outofstock' => 0, 'onbackorder' => 0, 'no_stock_record' => 0];

        foreach ($products as $p) {
            if (! $stockByProduct->has($p->id)) {
                $stats['no_stock_record']++;
                continue; // ERP nu cunoaște stocul → nu atingem produsul
            }

            $qty = (float) $stockByProduct->get($p->id);
            $hasFeed = isset($feedProductIds[$p->id]);

            // WooCommerce stochează stoc întreg → statusul urmează cantitatea întreagă,
            // ca un stoc sub-unitar (0.46) să nu rămână instock + 0 buc = coș blocat.
            $intQty = max(0, (int) $qty);
            if ($intQty > 0) {
                $status = 'instock';
            } elseif ($hasFeed) {
                $status = 'onbackorder';
            } else {
                $status = 'outofstock';
            }
            $stats[$status]++;

            $batch[] = [
                'id'             => (int) $p->woo_id,
                'stock_quantity' => $intQty,
                'stock_status'   => $status,
                'manage_stock'   => true,
                'backorders'     => $hasFeed ? 'notify' : 'no',
            ];
        }

        $this->table(
            ['instock', 'outofstock', 'onbackorder', 'fără stoc ERP (ignorate)', 'de scris'],
            [[$stats['instock'], $stats['outofstock'], $stats['onbackorder'], $stats['no_stock_record'], count($batch)]]
        );

        if ($dryRun) {
            $this->warn('DRY-RUN — nimic scris pe site.');
            return self::SUCCESS;
        }

        if (empty($batch)) {
            $this->info('Nimic de reconciliat.');
            return self::SUCCESS;
        }

        $service = new WooDirectSqlService;
        $totalUpdated = 0;
        $totalFailed = 0;

        foreach (array_chunk($batch, 500) as $i => $chunk) {
            $res = $service->updateStock($chunk);
            $totalUpdated += $res['updated'];
            $totalFailed += $res['failed'];
            $this->line('  Batch ' . ($i + 1) . ': updated=' . $res['updated'] . ' failed=' . $res['failed']);
        }

        $this->info("Scris: {$totalUpdated} | eșuat: {$totalFailed}");

        // Golim cache-ul DOAR dacă am scris ceva — rulează orar, iar un flush
        // total pe fiecare rulare ar goli permanent cache-ul nginx degeaba
        // (primele vizite după flush primesc HTML pre-optimizare LiteSpeed).
        if ($totalUpdated > 0) {
            $this->info('Golesc cache site...');
            $service->flushCache();
        } else {
            $this->info('Nimic scris — cache-ul rămâne cald.');
        }

        return self::SUCCESS;
    }
}
