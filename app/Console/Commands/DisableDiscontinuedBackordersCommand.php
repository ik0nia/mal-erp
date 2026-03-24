<?php

namespace App\Console\Commands;

use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;

class DisableDiscontinuedBackordersCommand extends Command
{
    protected $signature = 'woo:disable-discontinued-backorders {--dry-run : Afișează fără a face modificări}';

    protected $description = 'Dezactivează backorders în WooCommerce pentru toate produsele marcate ca discontinued';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $products = WooProduct::where('is_discontinued', true)
            ->whereNotNull('woo_id')
            ->whereNotNull('connection_id')
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, '$.backorders')), 'no') IN ('yes', 'notify')")
            ->with('connection')
            ->get();

        if ($products->isEmpty()) {
            $this->info('Niciun produs discontinued cu backorders activ găsit.');
            return self::SUCCESS;
        }

        $this->info("Găsite {$products->count()} produse discontinued cu backorders activ.");

        if ($dryRun) {
            $this->warn('Mod --dry-run: nicio modificare nu se face.');
            $this->table(['SKU', 'Nume', 'WooID', 'Backorders'], $products->map(fn ($p) => [
                $p->sku,
                \Illuminate\Support\Str::limit($p->name, 50),
                $p->woo_id,
                data_get($p->data, 'backorders', '?'),
            ])->toArray());
            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;

        foreach ($products as $product) {
            if (! $product->connection) {
                $this->warn("  [{$product->sku}] fără conexiune — sărit");
                $fail++;
                continue;
            }

            try {
                $client = new WooClient($product->connection);
                $client->updateProduct((int) $product->woo_id, ['backorders' => 'no']);

                // Actualizează și local câmpul data
                $data = $product->data ?? [];
                $data['backorders'] = 'no';
                $product->update(['data' => $data]);

                $this->line("  ✓ [{$product->sku}] {$product->name}");
                $ok++;
            } catch (\Throwable $e) {
                $this->error("  ✗ [{$product->sku}] {$e->getMessage()}");
                $fail++;
            }
        }

        $this->newLine();
        $this->info("Finalizat: {$ok} actualizate, {$fail} eșuate.");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
