<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliază soldul furnizor LOCAL (scadențarul global /api/solduri/furnizori,
 * stocat în winmentor_solduri_raw) cu soldul AUTORITAR getSoldPartener (per-partener).
 * Scopul: identifică furnizorii cu date corupte în WinMentor (facturi închise/duplicate
 * care apar ca deschise) — read-only, nu scrie nimic în Mentor.
 *
 * Rezultat: listă sortată după divergență + total, pt prioritizarea curățării în WinMentor.
 */
class ReconcileSupplierSolduriCommand extends Command
{
    protected $signature = 'winmentor:reconcile-solduri
                            {--limit=0 : Limitează numărul de furnizori (0 = toți)}
                            {--tol=10 : Prag divergență procentual (%)}';

    protected $description = 'Compară soldul furnizor local vs getSoldPartener; raportează divergențele (read-only)';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        if (! $bridge->isReachable()) {
            $this->error('WinMentor Bridge indisponibil.');
            return self::FAILURE;
        }

        $tol = max(0.0, (float) $this->option('tol')) / 100;

        // Furnizori cu winmentor_id care au rânduri în scadențarul local
        $wmIds = DB::table('winmentor_solduri_raw')->where('directie', 'furnizor')
            ->distinct()->pluck('part_id')->all();
        $q = Supplier::whereIn('winmentor_id', $wmIds)->orderBy('name');
        if (($lim = (int) $this->option('limit')) > 0) {
            $q->limit($lim);
        }
        $suppliers = $q->get(['id', 'name', 'winmentor_id']);

        $this->info("Reconciliere {$suppliers->count()} furnizori (prag {$this->option('tol')}%)...");
        $bar = $this->output->createProgressBar($suppliers->count());
        $bar->start();

        $divergent = [];
        $okCount = 0; $errCount = 0;
        foreach ($suppliers as $s) {
            $bar->advance();
            $wm = (string) $s->winmentor_id;
            $local = (float) DB::table('winmentor_solduri_raw')->where('directie', 'furnizor')->where('part_id', $wm)->sum('rest_de_plata');

            $live = null;
            try {
                $r = $bridge->getSoldPartener($wm);
                $live = $this->parseSold($r['sold'] ?? null);
            } catch (\Throwable $e) {
                $errCount++;
                continue;
            }
            if ($live === null) {
                $errCount++;
                continue;
            }

            $diff = abs(abs($local) - abs($live));
            $reliable = $diff <= max(50.0, abs($live) * $tol);
            if ($reliable) {
                $okCount++;
            } else {
                $divergent[] = [
                    'name'  => $s->name,
                    'wm'    => $wm,
                    'local' => $local,
                    'live'  => $live,
                    'diff'  => $diff,
                    'linii' => DB::table('winmentor_solduri_raw')->where('directie', 'furnizor')->where('part_id', $wm)->count(),
                ];
            }
        }
        $bar->finish();
        $this->newLine(2);

        usort($divergent, fn ($a, $b) => $b['diff'] <=> $a['diff']);
        $this->warn(count($divergent) . " furnizori DIVERGENȚI (date locale greșite), {$okCount} OK, {$errCount} erori/fără sold:");
        $this->newLine();
        $this->table(
            ['Furnizor', 'wm_id', 'Local', 'Live (autoritar)', 'Diferență', 'Linii'],
            array_map(fn ($d) => [
                mb_substr($d['name'], 0, 32),
                $d['wm'],
                number_format($d['local'], 2, ',', '.'),
                number_format($d['live'], 2, ',', '.'),
                number_format($d['diff'], 2, ',', '.'),
                $d['linii'],
            ], $divergent)
        );

        $totalFantoma = array_sum(array_column($divergent, 'diff'));
        $this->newLine();
        $this->error('Total „fantomă" (diferență local vs real): ' . number_format($totalFantoma, 2, ',', '.') . ' lei pe ' . count($divergent) . ' furnizori — de curățat în WinMentor.');

        return self::SUCCESS;
    }

    private function parseSold(mixed $s): ?float
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);
        }
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : null;
    }
}
