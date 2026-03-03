<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailAIJob;
use App\Models\SupplierPriceQuote;
use Illuminate\Console\Command;

/**
 * Reface matching-ul produs pentru supplier_price_quotes existente.
 *
 * Util după ce s-a îmbunătățit logica de matching sau după import nou de produse.
 * Opțional poate șterge quote-urile fără nicio șansă de potrivire (rubbish).
 */
class RematchPriceQuotesCommand extends Command
{
    protected $signature = 'email:rematch-quotes
                            {--clear-wrong : Resetează și woo_product_id greșite (re-rulează matching)}
                            {--delete-rubbish : Șterge quote-uri evident non-produs (ex: totaluri facturi)}';

    protected $description = 'Reface asocierea produse WooCommerce pentru supplier_price_quotes existente';

    // Pattern-uri care indică clar că nu e un produs fizic
    private array $rubbishPatterns = [
        '/^factur[aă]/i',
        '/^total/i',
        '/^comand[aă]/i',
        '/^avans/i',
        '/^sold/i',
        '/^rest din/i',
        '/^plat[aă]/i',
        '/^bonus/i',
        '/^transport/i',
        '/^livrare/i',
        '/^excursie/i',
        '/^diferen/iu',
        '/^valoare factur[aă]/i',
        '/turnover/i',
        '/credit note/i',
        '/annual bonus/i',
        '/^other$/i',
        '/^screws$/i',
        '/^accessories$/i',
        '/^services$/i',
        '/^miscellaneous$/i',
    ];

    public function handle(): int
    {
        if ($this->option('delete-rubbish')) {
            $this->deleteRubbish();
        }

        $this->rematch();

        return self::SUCCESS;
    }

    private function deleteRubbish(): void
    {
        $quotes = SupplierPriceQuote::all();
        $deleted = 0;

        foreach ($quotes as $quote) {
            foreach ($this->rubbishPatterns as $pattern) {
                if (preg_match($pattern, $quote->product_name_raw)) {
                    $quote->delete();
                    $deleted++;
                    break;
                }
            }
        }

        $this->info("Șters {$deleted} quote-uri non-produs.");
    }

    private function rematch(): void
    {
        $query = SupplierPriceQuote::query();

        if (! $this->option('clear-wrong')) {
            // Implicit: doar cele fără potrivire
            $query->whereNull('woo_product_id');
        }

        $quotes = $query->get();
        $this->info("Procesez {$quotes->count()} quote-uri...");

        $matched   = 0;
        $unmatched = 0;

        foreach ($quotes as $quote) {
            $productId = ProcessEmailAIJob::matchProduct($quote->product_name_raw);

            if ($productId) {
                $quote->update(['woo_product_id' => $productId]);
                $matched++;
                $this->line("  ✓ [{$quote->id}] {$quote->product_name_raw} → produs #{$productId}");
            } else {
                if ($quote->woo_product_id) {
                    $quote->update(['woo_product_id' => null]);
                }
                $unmatched++;
            }
        }

        $this->info("Rezultat: {$matched} potrivite, {$unmatched} nepotrivite.");
    }
}
