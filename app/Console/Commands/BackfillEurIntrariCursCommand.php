<?php

namespace App\Console\Commands;

use App\Services\BnrExchangeRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill moneda='EUR' și curs_bnr în winmentor_intrari_raw pentru furnizorii EUR,
 * șterge intrările greșite din product_purchase_price_logs și resetează processed_at
 * astfel încât process-intrari le poate repoceса cu prețuri corecte.
 */
class BackfillEurIntrariCursCommand extends Command
{
    protected $signature = 'winmentor:backfill-eur-curs
                            {--dry-run : Afișează ce ar face fără să modifice nimic}';

    protected $description = 'Backfill moneda/curs_bnr pentru furnizorii EUR din winmentor_intrari_raw și resetează price logs greșite';

    public function handle(BnrExchangeRateService $bnr): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Citim part_id-urile furnizorilor non-RON din DB (nu hardcodat)
        $eurPartIds = DB::table('suppliers')
            ->where('default_currency', '!=', 'RON')
            ->whereNotNull('winmentor_id')
            ->pluck('winmentor_id')
            ->all();

        if ($dryRun) {
            $this->warn('--- DRY RUN --- Nicio modificare nu va fi salvată.');
        }

        // ── 1. Obține toate datele unice pentru furnizorii EUR ──────────────────
        $dates = DB::table('winmentor_intrari_raw')
            ->whereIn('part_id', $eurPartIds)
            ->whereNotNull('data_intrare')
            ->distinct()
            ->pluck('data_intrare')
            ->sort()
            ->values();

        $this->info("Date unice de procesat: {$dates->count()}");

        // ── 2. Fetch cursuri BNR pentru fiecare dată ────────────────────────────
        $rateCache = [];
        $bar = $this->output->createProgressBar($dates->count());
        $bar->start();

        foreach ($dates as $dateStr) {
            $rate = $bnr->getEurRate($dateStr);
            $rateCache[$dateStr] = $rate;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $noRate = collect($rateCache)->filter(fn ($r) => $r === null)->count();
        if ($noRate > 0) {
            $this->warn("  {$noRate} date fără curs BNR disponibil — rândurile respective vor fi sărite la reprocessing.");
        }

        // ── 3. Update winmentor_intrari_raw: moneda='EUR' + curs_bnr ───────────
        $updatedRaw = 0;
        foreach ($rateCache as $dateStr => $rate) {
            if ($rate === null) continue;

            if (! $dryRun) {
                $affected = DB::table('winmentor_intrari_raw')
                    ->whereIn('part_id', $eurPartIds)
                    ->where('data_intrare', $dateStr)
                    ->update([
                        'moneda'     => 'EUR',
                        'curs_bnr'   => $rate,
                        'updated_at' => now(),
                    ]);
                $updatedRaw += $affected;
            } else {
                $cnt = DB::table('winmentor_intrari_raw')
                    ->whereIn('part_id', $eurPartIds)
                    ->where('data_intrare', $dateStr)
                    ->count();
                $updatedRaw += $cnt;
            }
        }

        $this->info("winmentor_intrari_raw: {$updatedRaw} rânduri actualizate cu moneda/curs_bnr.");

        // Rânduri fără dată (data_intrare NULL) — marchează cu EUR dar fără curs
        $nullDateCount = DB::table('winmentor_intrari_raw')
            ->whereIn('part_id', $eurPartIds)
            ->whereNull('data_intrare')
            ->count();

        if ($nullDateCount > 0) {
            $this->warn("  {$nullDateCount} rânduri fără data_intrare — se marchează EUR fără curs (vor fi sărite la reprocessing).");
            if (! $dryRun) {
                DB::table('winmentor_intrari_raw')
                    ->whereIn('part_id', $eurPartIds)
                    ->whereNull('data_intrare')
                    ->update(['moneda' => 'EUR', 'updated_at' => now()]);
            }
        }

        // ── 4. Șterge price logs greșite (source=winmentor_import pentru EUR suppliers) ──
        $supplierIds = DB::table('suppliers')
            ->whereIn('winmentor_id', $eurPartIds)
            ->pluck('id');

        $toDelete = DB::table('product_purchase_price_logs')
            ->whereIn('supplier_id', $supplierIds)
            ->where('source', 'winmentor_import')
            ->count();

        $this->info("product_purchase_price_logs: {$toDelete} intrări de șters (EUR stocate greșit ca RON).");

        if (! $dryRun && $toDelete > 0) {
            DB::table('product_purchase_price_logs')
                ->whereIn('supplier_id', $supplierIds)
                ->where('source', 'winmentor_import')
                ->delete();
            $this->info("  ✓ Șterse.");
        }

        // ── 5. Resetează processed_at pentru rândurile cu curs valid ───────────
        $toReset = DB::table('winmentor_intrari_raw')
            ->whereIn('part_id', $eurPartIds)
            ->whereNotNull('curs_bnr')
            ->whereNotNull('processed_at')
            ->count();

        $this->info("winmentor_intrari_raw: {$toReset} rânduri de resetat (processed_at → NULL).");

        if (! $dryRun && $toReset > 0) {
            DB::table('winmentor_intrari_raw')
                ->whereIn('part_id', $eurPartIds)
                ->whereNotNull('curs_bnr')
                ->update(['processed_at' => null, 'updated_at' => now()]);
            $this->info("  ✓ Resetate.");
        }

        // ── Summary ────────────────────────────────────────────────────────────
        $this->newLine();
        $this->info('─── Gata' . ($dryRun ? ' (DRY RUN — nicio modificare salvată)' : '') . ' ───');
        if (! $dryRun) {
            $this->info('Rulează acum: php artisan winmentor:process-intrari');
        }

        Log::channel('winmentor_sync')->info('[WinMentor BackfillEurCurs] Finalizat', [
            'dry_run'      => $dryRun,
            'dates'        => $dates->count(),
            'updated_raw'  => $updatedRaw,
            'deleted_logs' => $dryRun ? 0 : $toDelete,
            'reset_rows'   => $dryRun ? 0 : $toReset,
        ]);

        return self::SUCCESS;
    }
}
