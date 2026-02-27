<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PushWinmentorImagesToWooCommand extends Command
{
    protected $signature = 'woo:push-winmentor-images
                            {--connection=1 : IntegrationConnection ID}
                            {--limit=       : Max produse de procesat}
                            {--last=        : Procesează ultimele N produse importate (după updated_at DESC)}
                            {--from-id=     : ID minim produs local (inclusiv)}
                            {--to-id=       : ID maxim produs local (inclusiv)}
                            {--from-woo-id= : woo_id minim (inclusiv)}
                            {--to-woo-id=   : woo_id maxim (inclusiv)}
                            {--dry-run      : Afișează fără a actualiza}
                            {--all          : Actualizează și produsele care au deja imagine pe WooCommerce}';

    protected $description = 'Actualizează imaginile produselor WinMentor în WooCommerce folosind candidatele aprobate (jpg/png)';

    public function handle(): int
    {
        $connectionId = (int) $this->option('connection');
        $limit        = $this->option('limit') ? (int) $this->option('limit') : null;
        $last         = $this->option('last') ? (int) $this->option('last') : null;
        $fromId       = $this->option('from-id') ? (int) $this->option('from-id') : null;
        $toId         = $this->option('to-id') ? (int) $this->option('to-id') : null;
        $fromWooId    = $this->option('from-woo-id') ? (int) $this->option('from-woo-id') : null;
        $toWooId      = $this->option('to-woo-id') ? (int) $this->option('to-woo-id') : null;
        $dryRun       = (bool) $this->option('dry-run');
        $all          = (bool) $this->option('all');

        $connection = IntegrationConnection::find($connectionId);

        if (! $connection || ! $connection->is_active) {
            $this->error("IntegrationConnection #{$connectionId} negăsită sau inactivă.");
            return self::FAILURE;
        }

        $wooHost = parse_url((string) $connection->base_url, PHP_URL_HOST); // malinco.ro

        $this->info('Push imagini WinMentor → WooCommerce' . ($dryRun ? ' [DRY RUN]' : ''));

        // Produse WM create în WooCommerce (is_placeholder=false) cu cel puțin o candidată aprobată
        $query = DB::table('woo_products as wp')
            ->join(
                DB::raw('(SELECT woo_product_id, MIN(id) as min_id FROM product_image_candidates WHERE status = \'approved\' GROUP BY woo_product_id) as best'),
                'best.woo_product_id', '=', 'wp.id'
            )
            ->join('product_image_candidates as pic', 'pic.id', '=', 'best.min_id')
            ->where('wp.source', 'winmentor_csv')
            ->where('wp.is_placeholder', false)
            ->where('wp.woo_id', '>', 0)
            ->select('wp.id', 'wp.woo_id', 'wp.name', 'wp.main_image_url', 'pic.image_url as candidate_url');

        // Implicit: procesăm doar produsele cu imagine pe erp (webp - nu sunt pe WooCommerce)
        // Cu --all procesăm și cele cu imagine deja pe WooCommerce
        if (! $all) {
            $query->where('wp.main_image_url', 'LIKE', '%erp.malinco.ro%');
        }

        // --last=N: limitează la ultimele N produse (după updated_at DESC)
        if ($last !== null) {
            $lastIds = DB::table('woo_products')
                ->where('source', 'winmentor_csv')
                ->where('is_placeholder', false)
                ->orderByDesc('updated_at')
                ->limit($last)
                ->pluck('id');
            $query->whereIn('wp.id', $lastIds);
        }

        // --from-id / --to-id: interval după id local
        if ($fromId !== null) {
            $query->where('wp.id', '>=', $fromId);
        }
        if ($toId !== null) {
            $query->where('wp.id', '<=', $toId);
        }

        // --from-woo-id / --to-woo-id: interval după woo_id
        if ($fromWooId !== null) {
            $query->where('wp.woo_id', '>=', $fromWooId);
        }
        if ($toWooId !== null) {
            $query->where('wp.woo_id', '<=', $toWooId);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->orderBy('wp.id')->get();
        $total    = $products->count();

        if ($total === 0) {
            $this->info('Niciun produs de procesat.');
            return self::SUCCESS;
        }

        $this->info("Produse de actualizat: {$total}");

        if ($dryRun) {
            foreach ($products->take(5) as $p) {
                $this->line("  woo#{$p->woo_id} [{$p->name}]");
                $this->line("    Candidat: {$p->candidate_url}");
            }
            return self::SUCCESS;
        }

        $wooClient = new WooClient($connection);
        $ok        = 0;
        $failed    = 0;
        $done      = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($products as $product) {
            $done++;

            try {
                $newUrl = $wooClient->sideloadProductImage((int) $product->woo_id, $product->candidate_url);

                if (empty($newUrl)) {
                    $this->newLine();
                    $this->warn("  #{$product->id} woo#{$product->woo_id}: WooCommerce returned empty URL");
                    $failed++;
                } else {
                    DB::table('woo_products')
                        ->where('id', $product->id)
                        ->update(['main_image_url' => $newUrl, 'updated_at' => now()]);

                    $ok++;
                }
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("  #{$product->id} woo#{$product->woo_id} [{$product->name}]: " . $e->getMessage());
                Log::warning("PushWinmentorImages #{$product->id}: " . $e->getMessage());
                $failed++;

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                    $this->newLine();
                    $this->warn('  Rate limit — aștept 10s...');
                    sleep(10);
                }
            }

            $bar->advance();

            if ($done < $total) {
                usleep(200_000); // 0.2s între cereri
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Gata. Total: {$total} | OK: {$ok} | Eșuate: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
