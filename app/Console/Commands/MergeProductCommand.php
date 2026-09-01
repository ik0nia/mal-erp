<?php

namespace App\Console\Commands;

use App\Models\WooProduct;
use App\Services\ProductMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Merge manual de fișe duplicat: mută istoricul de pe fișa sursă pe cea păstrată
 * și parchează sursa. Implicit rulează dry-run — folosiți --apply pentru execuție.
 *
 *   php artisan erp:merge-product 33751 652 --apply
 */
class MergeProductCommand extends Command
{
    protected $signature = 'erp:merge-product
                            {from : ID fișă duplicat (sursa istoricului)}
                            {to : ID fișă păstrată (destinația istoricului)}
                            {--apply : Execută modificările (implicit doar afișează)}';

    protected $description = 'Mută istoricul unei fișe de produs duplicat pe fișa păstrată și parchează duplicatul';

    public function handle(ProductMergeService $merge): int
    {
        $from = WooProduct::find($this->argument('from'));
        $to   = WooProduct::find($this->argument('to'));

        if (! $from || ! $to || $from->id === $to->id) {
            $this->error('Produse invalide: sursa și destinația trebuie să existe și să fie diferite.');
            return self::FAILURE;
        }

        $dryRun = ! $this->option('apply');

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Merge #{$from->id} ({$from->name}, sku={$from->sku}) → #{$to->id} ({$to->name}, sku={$to->sku})");

        if ($from->status === 'publish' && ! $dryRun) {
            $this->error("Fișa sursă #{$from->id} este PUBLICATĂ — nu se face merge automat dintr-o fișă live. Retrageți-o întâi de pe site.");
            return self::FAILURE;
        }

        $stats = $merge->mergeHistory($from, $to, $dryRun);

        if (empty($stats)) {
            $this->info('Nicio referință de istoric pe fișa sursă.');
        } else {
            $this->table(
                ['Tabel.coloană', 'Total', 'Mutate', 'Rămase'],
                collect($stats)->map(fn ($s, $k) => [$k, $s['total'], $s['mutate'], $s['ramase']])->values()
            );
        }

        $parkedSku = $merge->parkDuplicate($from, $dryRun);
        $this->info("Fișa #{$from->id} parcată: sku={$parkedSku}, winmentor_name=NULL" . ($dryRun ? ' (dry-run, nimic salvat)' : ''));

        if (! $dryRun) {
            Log::channel('winmentor_sync')->info('[MergeProduct] Istoric migrat', [
                'from' => $from->id, 'to' => $to->id, 'stats' => $stats, 'parked_sku' => $parkedSku,
            ]);
        }

        return self::SUCCESS;
    }
}
