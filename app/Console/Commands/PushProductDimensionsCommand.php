<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Împinge greutatea + dimensiunile spre WooCommerce (batch REST, 100/request)
 * pentru produsele completate de products:fill-dimensions (dims_source setat).
 * Woo folosește aceste câmpuri la calculul transportului în coș.
 */
class PushProductDimensionsCommand extends Command
{
    protected $signature = 'products:push-dimensions
        {--all : Împinge toate produsele cu greutate/dimensiuni, nu doar cele completate automat}
        {--dry-run : Doar raport}';

    protected $description = 'Push greutate/dimensiuni către WooCommerce pentru produsele completate';

    public function handle(): int
    {
        $q = DB::table('woo_products')
            ->where('status', 'publish')
            ->whereNotNull('woo_id')
            ->where('woo_id', '<', 8000000000000000000) // exclude woo_id sintetice (placeholder)
            ->where('weight', '>', 0);

        if (! $this->option('all')) {
            $q->whereNotNull('dims_source');
        }

        $products = $q->get(['id', 'woo_id', 'connection_id', 'weight', 'dim_length', 'dim_width', 'dim_height']);
        $connId = (int) $products->pluck('connection_id')->filter()->mode()[0];
        $woo = new WooClient(IntegrationConnection::findOrFail($connId));
        $this->info('De împins: ' . $products->count() . ' produse');

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;
        $bar = $this->output->createProgressBar((int) ceil($products->count() / 20));

        foreach ($products->chunk(20) as $chunk) {
            $items = $chunk->map(fn ($p) => [
                'id'         => (int) $p->woo_id,
                'weight'     => (string) $p->weight,
                'dimensions' => [
                    'length' => (string) ($p->dim_length ?: ''),
                    'width'  => (string) ($p->dim_width ?: ''),
                    'height' => (string) ($p->dim_height ?: ''),
                ],
            ])->values()->all();

            try {
                $res = $woo->updateProductsBatch($items);
                $updated = $res['update'] ?? [];
                foreach ($updated as $u) {
                    isset($u['error']) ? $fail++ : $ok++;
                }
            } catch (\Throwable $e) {
                $fail += count($items);
                $this->warn(' batch eșuat: ' . substr($e->getMessage(), 0, 120));
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("Push gata: {$ok} actualizate, {$fail} eșuate.");

        return self::SUCCESS;
    }
}
