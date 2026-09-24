<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trece în DRAFT produsele Toya al căror cod a dispărut din catalogul Toya
 * (getPricesRo) — adică Toya nu le mai are, deci nu mai pot fi aprovizionate.
 *
 * Protecții:
 *  - abandonează dacă feed-ul întoarce suspect de puține coduri (fetch stricat);
 *  - atinge DOAR produse gestionate exclusiv de Toya (winmentor_name gol) — cele
 *    din WinMentor rămân neatinse;
 *  - refuză să trateze un lot mare fără --force (evită draftări în masă din greșeală).
 *
 * Schimbarea de status e prinsă automat de WooProductObserver → jurnal de audit.
 */
class DraftDiscontinuedToyaCommand extends Command
{
    protected $signature = 'toya:draft-discontinued {--dry-run : Doar raportează} {--force : Permite un lot mare}';

    protected $description = 'Trece în draft produsele Toya scoase din catalogul Toya (cod dispărut din feed)';

    private const SUPPLIER_ID   = 112;
    private const MIN_FEED_CODES = 5000; // sub atât, feed-ul e considerat stricat → abort
    private const MAX_BATCH      = 100;  // peste atât fără --force → abort (siguranță)

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_TOYA_API_KEY) ?? env('TOYA_API_KEY', 'D83FD59A4902793862EB8304');
        if (! $apiKey) {
            $this->error('API key Toya lipsește.');
            return self::FAILURE;
        }

        // Codurile curente din catalogul Toya.
        $resp = Http::withoutVerifying()->timeout(60)->get('https://pim.toya.pl/dataapi', ['key' => $apiKey, 'action' => 'getPricesRo']);
        $codes = $resp->successful() ? array_keys($resp->json() ?? []) : [];

        if (count($codes) < self::MIN_FEED_CODES) {
            $this->error('Feed Toya suspect de mic ('.count($codes).' coduri) — abandonez ca să nu draftez în masă din greșeală.');
            Log::warning('[ToyaDraftDiscontinued] feed prea mic: '.count($codes));
            return self::FAILURE;
        }
        $feed = array_fill_keys(array_map('strval', $codes), true);
        $this->info('Coduri în catalogul Toya: '.count($feed));

        // Marchează „delistat de furnizor" pe TOATE legăturile Toya al căror cod a dispărut
        // (și golește marcajul la re-listare). Astfel ERP arată delistarea inclusiv la
        // produsele gestionate de WinMentor (care nu se draftează, dar e util de știut).
        $marked = 0;
        $cleared = 0;
        foreach (DB::table('product_suppliers')->where('supplier_id', self::SUPPLIER_ID)
            ->whereNotNull('supplier_sku')->where('supplier_sku', '!=', '')
            ->select('id', 'supplier_sku', 'delisted_at')->cursor() as $l) {
            $inFeed = isset($feed[(string) $l->supplier_sku]);
            if (! $inFeed && $l->delisted_at === null) {
                if (! $dryRun) {
                    DB::table('product_suppliers')->where('id', $l->id)->update(['delisted_at' => now()]);
                }
                $marked++;
            } elseif ($inFeed && $l->delisted_at !== null) {
                if (! $dryRun) {
                    DB::table('product_suppliers')->where('id', $l->id)->update(['delisted_at' => null]);
                }
                $cleared++;
            }
        }
        $this->info("Marcate delistate: {$marked} | re-listate (golite): {$cleared}");

        // Produse Toya publicate, cu cod (supplier_sku) care NU mai e în feed, gestionate DOAR de Toya.
        $candidates = DB::table('product_suppliers as ps')
            ->join('woo_products as wp', 'wp.id', '=', 'ps.woo_product_id')
            ->where('ps.supplier_id', self::SUPPLIER_ID)
            ->whereNotNull('ps.supplier_sku')->where('ps.supplier_sku', '!=', '')
            ->where('wp.status', 'publish')
            ->whereNotNull('wp.woo_id')->where('wp.woo_id', '<', 1_000_000_000_000_000)
            ->whereNull('wp.winmentor_name') // exclusiv Toya (nu WinMentor)
            ->select('wp.id', 'wp.woo_id', 'wp.name', 'ps.supplier_sku')
            ->get()
            ->filter(fn ($r) => ! isset($feed[(string) $r->supplier_sku]));

        $this->info('Produse Toya publicate cu cod dispărut din catalog: '.$candidates->count());

        if ($candidates->isEmpty()) {
            $this->info('Nimic de făcut.');
            return self::SUCCESS;
        }

        foreach ($candidates->take(15) as $c) {
            $this->line("  • #{$c->woo_id} {$c->name} (cod Toya {$c->supplier_sku})");
        }
        if ($candidates->count() > 15) {
            $this->line('  ... și încă '.($candidates->count() - 15));
        }

        if ($dryRun) {
            $this->warn('DRY-RUN — nimic modificat.');
            return self::SUCCESS;
        }

        if ($candidates->count() > self::MAX_BATCH && ! $this->option('force')) {
            $this->error('Lot mare ('.$candidates->count().' > '.self::MAX_BATCH.') — rulează cu --force dacă e intenționat.');
            return self::FAILURE;
        }

        $connection = IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)->where('is_active', true)->first();
        $client = new WooClient($connection);

        $drafted = 0;
        foreach ($candidates as $c) {
            try {
                $client->updateProduct((int) $c->woo_id, ['status' => 'draft']);
                WooProduct::where('id', $c->id)->update(['status' => 'draft']); // observer → audit
                $drafted++;
            } catch (\Throwable $e) {
                $this->error("Eroare la #{$c->woo_id}: ".$e->getMessage());
            }
        }

        $this->info("Trecute în draft: {$drafted}");
        Log::info("[ToyaDraftDiscontinued] draftate {$drafted} produse scoase din catalogul Toya");

        return self::SUCCESS;
    }
}
