<?php

namespace App\Console\Commands;

use App\Services\Winmentor\VanzariAziService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class WarmVanzariAziCommand extends Command
{
    protected $signature = 'dispecer:warm';
    protected $description = 'Pre-încălzește cache-ul vânzărilor zilei (bonuri reale via GetInfoBonExt) pentru dispecerizare';

    public function handle(VanzariAziService $service): int
    {
        $zi = Carbon::now('Europe/Bucharest')->toDateString();

        // Forțăm reconstrucția listei (prinde bonuri/vânzări noi); conținutul bonurilor
        // rămâne cache-uit (imutabil), deci doar bonurile noi lovesc API-ul.
        Cache::forget("vanzari_azi_{$zi}");
        Cache::forget("bonuri_lista_{$zi}");

        $t = microtime(true);
        $linii = $service->liniiAzi($zi);
        $dt = round(microtime(true) - $t, 1);

        $bonuri = collect($linii)->where('tip_doc', 'BON')->pluck('nr_doc')->unique()->count();
        $this->info("Warm vânzări {$zi}: " . count($linii) . " linii, {$bonuri} bonuri, în {$dt}s.");

        return self::SUCCESS;
    }
}
