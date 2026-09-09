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

    /** Peste atâtea corecții într-o rulare orară = drift anormal → alertă admini. */
    private const DRIFT_ALERT_THRESHOLD = 300;

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

        // Scriem DOAR diferențele față de site — rulează orar; o suprascriere
        // oarbă a 3.500+ produse ar însemna flush de cache la fiecare oră și
        // ar face inutilizabilă alerta de drift.
        $service = new WooDirectSqlService;
        $batch = $this->filterChangedOnly($service, $batch);

        $this->table(
            ['instock', 'outofstock', 'onbackorder', 'fără stoc ERP (ignorate)', 'diferite → de scris'],
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

        $totalUpdated = 0;
        $totalFailed = 0;

        foreach (array_chunk($batch, 500) as $i => $chunk) {
            $res = $service->updateStock($chunk);
            $totalUpdated += $res['updated'];
            $totalFailed += $res['failed'];
            $this->line('  Batch ' . ($i + 1) . ': updated=' . $res['updated'] . ' failed=' . $res['failed']);
        }

        $this->info("Scris: {$totalUpdated} | eșuat: {$totalFailed}");

        // În regim normal reconcilierea orară corectează câteva produse (comenzile
        // online dintre facturările WinMentor). Sute de scrieri pe oră = push-ul
        // delta nu mai ajunge pe site (vezi incidentul din 2026-09-09: drift
        // acumulat până la -65 pe site vs 35 real) — anunțăm adminii.
        if (! $dryRun && $totalUpdated >= self::DRIFT_ALERT_THRESHOLD) {
            $admins = \App\Models\User::where('is_super_admin', true)->get();
            \Filament\Notifications\Notification::make()
                ->title('⚠ Drift mare de stoc site ↔ ERP')
                ->body("Reconcilierea orară a corectat {$totalUpdated} produse pe site (prag: " . self::DRIFT_ALERT_THRESHOLD . '). Verifică sync-ul de stoc (winmentor:sync-stock-bridge) și push-ul spre WooCommerce.')
                ->warning()
                ->sendToDatabase($admins);
            \Illuminate\Support\Facades\Log::warning("[ReconcileWooStock] drift mare: {$totalUpdated} produse corectate într-o rulare");
        }

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

    /**
     * Păstrează doar produsele a căror stare pe site diferă de ținta ERP
     * (_stock, _stock_status, _backorders — citite bulk de pe site).
     * La eroare de citire întoarce batch-ul întreg (mai bine scriem în plus
     * decât să ratăm corecții).
     *
     * @param  array<int, array{id: int, stock_quantity: int, stock_status: string, manage_stock: bool, backorders: string}>  $batch
     */
    private function filterChangedOnly(WooDirectSqlService $service, array $batch): array
    {
        if (empty($batch)) {
            return $batch;
        }

        try {
            $site = [];
            foreach (array_chunk(array_column($batch, 'id'), 5000) as $ids) {
                $rows = $service->querySite(
                    'SELECT post_id, meta_key, meta_value FROM wp_postmeta'
                    . ' WHERE meta_key IN ("_stock", "_stock_status", "_backorders")'
                    . ' AND post_id IN (' . implode(',', array_map('intval', $ids)) . ')',
                    120
                );
                foreach ($rows as $r) {
                    $site[(int) $r['post_id']][$r['meta_key']] = $r['meta_value'];
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Citirea stării site a eșuat (' . $e->getMessage() . ') — scriu tot batch-ul.');

            return $batch;
        }

        return array_values(array_filter($batch, function (array $item) use ($site) {
            $s = $site[$item['id']] ?? null;
            if ($s === null) {
                return true; // necunoscut pe site → scriem
            }

            return (int) ($s['_stock'] ?? PHP_INT_MIN) !== $item['stock_quantity']
                || ($s['_stock_status'] ?? '') !== $item['stock_status']
                || ($s['_backorders'] ?? '') !== $item['backorders'];
        }));
    }
}
