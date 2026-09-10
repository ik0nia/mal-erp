<?php

namespace App\Console\Commands;

use App\Services\Winmentor\VanzariNetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecomputeVanzariNetCommand extends Command
{
    protected $signature = 'winmentor:recompute-vanzari-net
                            {--an= : Doar anul specificat (implicit toți anii cu date)}
                            {--firma=MAL2019 : Firma WinMentor}';

    protected $description = 'Recalculează lei_cu_tva + motiv_exclus pe winmentor_vanzari_raw (vezi VanzariNetService)';

    public function handle(VanzariNetService $service): int
    {
        $firma = $this->option('firma');

        $ani = $this->option('an')
            ? [(int) $this->option('an')]
            : DB::table('winmentor_vanzari_raw')->where('firma', $firma)
                ->distinct()->orderBy('an')->pluck('an')->all();

        foreach ($ani as $an) {
            $n = $service->recomputeAn($an, $firma);
            $total = DB::table('winmentor_vanzari_raw')
                ->where('firma', $firma)->where('an', $an)->sum('lei_cu_tva');
            $this->info("  {$an}: {$n} rânduri, total cu TVA = " . number_format($total, 0, ',', '.') . ' lei');
        }

        return self::SUCCESS;
    }
}
