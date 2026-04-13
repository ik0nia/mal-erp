<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Completează rândurile existente din winmentor_intrari_raw cu datele
 * suplimentare din /api/receptii: nr_receptie, den_furnizor, den_articol,
 * pret_vanzare, id_comanda_wm.
 *
 * Matching: firma + an + luna + nr_doc (= nr_factura din receptii) + sku
 */
class BackfillIntrariReceptiiCommand extends Command
{
    protected $signature = 'winmentor:backfill-receptii
                            {--firma=MAL2019 : Firma de procesat}
                            {--delay=10 : Secunde pauză între luni}
                            {--an= : Procesează doar anul specificat}
                            {--luna= : Procesează doar luna specificată (necesită --an)}';

    protected $description = 'Backfill câmpuri suplimentare din /api/receptii în winmentor_intrari_raw existent';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma = $this->option('firma');
        $delay = max(5, (int) $this->option('delay'));
        $anFiltru   = $this->option('an')   ? (int) $this->option('an')   : null;
        $lunaFiltru = $this->option('luna') ? (int) $this->option('luna') : null;

        $health = $bridge->health();
        if (! ($health['data']['comConnected'] ?? false)) {
            $this->error('WinMentor Bridge COM nu este conectat.');
            return self::FAILURE;
        }

        // Obținem lunile care au date în intrari_raw
        $query = DB::table('winmentor_intrari_sync')
            ->where('firma', $firma)
            ->orderBy('an')
            ->orderBy('luna');

        if ($anFiltru) $query->where('an', $anFiltru);
        if ($lunaFiltru) $query->where('luna', $lunaFiltru);

        $luni = $query->get();

        if ($luni->isEmpty()) {
            $this->error('Nicio lună găsită în winmentor_intrari_sync.');
            return self::FAILURE;
        }

        $this->info("Firma: {$firma} — {$luni->count()} luni de procesat");
        $this->line(str_repeat('─', 60));

        $totalUpdated = 0;
        $totalNoMatch = 0;

        foreach ($luni as $sync) {
            $an   = (int) $sync->an;
            $luna = (int) $sync->luna;

            // Câte rânduri din luna asta au deja date completate?
            $alreadyFilled = DB::table('winmentor_intrari_raw')
                ->where('firma', $firma)->where('an', $an)->where('luna', $luna)
                ->whereNotNull('nr_receptie')
                ->count();

            $total = DB::table('winmentor_intrari_raw')
                ->where('firma', $firma)->where('an', $an)->where('luna', $luna)
                ->count();

            if ($total === 0) {
                $this->line("  [{$luna}/{$an}] nicio intrare — skip");
                continue;
            }

            if ($alreadyFilled === $total) {
                $this->line("  [{$luna}/{$an}] deja complet ({$total} rânduri) — skip");
                continue;
            }

            $this->line("  [{$luna}/{$an}] selectFirma...");

            try {
                $bridge->selectFirmaForMonth($an, $luna, $firma);
            } catch (\Throwable $e) {
                $this->warn("  [{$luna}/{$an}] Eroare selectFirma: {$e->getMessage()}");
                $this->waitWithCountdown($delay);
                continue;
            }

            try {
                $receptii = $bridge->getReceptii();
            } catch (\Throwable $e) {
                $this->warn("  [{$luna}/{$an}] Eroare getReceptii: {$e->getMessage()}");
                $this->waitWithCountdown($delay);
                continue;
            }

            if (empty($receptii)) {
                $this->line("  [{$luna}/{$an}] 0 recepții returnate — skip");
                $this->waitWithCountdown($delay);
                continue;
            }

            // Indexăm recepțiile după (nr_factura|sku) pentru lookup rapid
            $index = [];
            foreach ($receptii as $row) {
                if (! is_array($row) || count($row) < 14) continue;

                $nrDoc = trim($row[6] ?? '');
                $sku   = trim($row[8] ?? '');
                if (! $nrDoc || ! $sku) continue;

                $index["{$nrDoc}|{$sku}"] = $row;
            }

            // Fetch rândurile existente din luna asta care nu au backfill
            $rows = DB::table('winmentor_intrari_raw')
                ->where('firma', $firma)->where('an', $an)->where('luna', $luna)
                ->whereNull('nr_receptie')
                ->select(['id', 'nr_doc', 'sku'])
                ->get();

            $updated = 0;
            $noMatch = 0;

            foreach ($rows as $raw) {
                $key = "{$raw->nr_doc}|{$raw->sku}";
                $r   = $index[$key] ?? null;

                if (! $r) {
                    $noMatch++;
                    continue;
                }

                $pretVanzStr = trim($r[15] ?? '');

                DB::table('winmentor_intrari_raw')
                    ->where('id', $raw->id)
                    ->update([
                        'nr_receptie'   => trim($r[2]  ?? '') ?: null,
                        'den_furnizor'  => trim($r[4]  ?? '') ?: null,
                        'den_articol'   => trim($r[9]  ?? '') ?: null,
                        'pret_vanzare'  => $pretVanzStr !== '' ? (float) str_replace(',', '.', $pretVanzStr) : null,
                        'id_comanda_wm' => trim($r[17] ?? '') ?: null,
                        'updated_at'    => now(),
                    ]);

                $updated++;
            }

            $totalUpdated += $updated;
            $totalNoMatch += $noMatch;

            $this->info("  [{$luna}/{$an}] ✓ {$updated} actualizate, {$noMatch} fără match (documente fără NIR)");

            Log::channel('winmentor_sync')->info("[WinMentor BackfillReceptii] {$luna}/{$an} firma={$firma}", compact('updated', 'noMatch'));

            $this->waitWithCountdown($delay);
        }

        $this->line(str_repeat('─', 60));
        $this->info("Total: {$totalUpdated} rânduri completate, {$totalNoMatch} fără corespondent în receptii.");

        return self::SUCCESS;
    }

    private function waitWithCountdown(int $seconds): void
    {
        $this->output->write("  Aștept {$seconds}s");
        for ($i = 0; $i < $seconds; $i++) {
            sleep(1);
            $this->output->write('.');
        }
        $this->output->writeln(' gata');
    }
}
