<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reîmprospătează soldul AUTORITAR (getSoldPartener) per partener din scadențar
 * și îl stochează în winmentor_solduri_autoritar. Read-only în WinMentor.
 * Folosit de pagina de scadențar ca să afișeze totaluri corecte (nu suma umflată
 * din facturile care apar deschise dar sunt închise contabil).
 */
class SnapshotSolduriAutoritarCommand extends Command
{
    protected $signature = 'winmentor:snapshot-sold-autoritar
                            {--directie=furnizor : furnizor | client | toate}
                            {--limit=0 : Limitează numărul de parteneri (0 = toți)}';

    protected $description = 'Snapshot sold autoritar getSoldPartener per partener (read-only)';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        if (! $bridge->isReachable()) {
            $this->error('WinMentor Bridge indisponibil.');
            return self::FAILURE;
        }

        $directii = match ($this->option('directie')) {
            'toate' => ['furnizor', 'client'],
            'client' => ['client'],
            default => ['furnizor'],
        };

        $now = now();
        $totalOk = 0;
        $totalErr = 0;

        foreach ($directii as $directie) {
            $partIds = DB::table('winmentor_solduri_raw')
                ->where('directie', $directie)
                ->whereRaw('ABS(rest_de_plata) >= 0.01')
                ->distinct()->pluck('part_id')->filter()->values();

            if (($lim = (int) $this->option('limit')) > 0) {
                $partIds = $partIds->take($lim);
            }

            $this->info("[{$directie}] " . $partIds->count() . ' parteneri de reîmprospătat…');
            $bar = $this->output->createProgressBar($partIds->count());

            foreach ($partIds as $partId) {
                $bar->advance();
                try {
                    $r = $bridge->getSoldPartener((string) $partId);
                    $sold = $this->parseSold($r['sold'] ?? null);
                } catch (\Throwable $e) {
                    $totalErr++;
                    continue;
                }
                if ($sold === null) {
                    $totalErr++;
                    continue;
                }

                DB::table('winmentor_solduri_autoritar')->updateOrInsert(
                    ['part_id' => (string) $partId, 'directie' => $directie],
                    ['sold' => $sold, 'fetched_at' => $now, 'updated_at' => $now, 'created_at' => $now],
                );
                $totalOk++;
            }

            $bar->finish();
            $this->newLine(2);
        }

        $this->info("Gata. Reîmprospătate: {$totalOk} | erori/fără sold: {$totalErr}");
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
