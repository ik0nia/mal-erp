<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Auto-reconciliere: pentru facturile DESCHISE din scadențarul unui furnizor, verifică
 * plățile bancare (GetPlatiFactura, read-only) și marchează ca „stinse" facturile deja
 * plătite integral. Reduce munca manuală; restul (stinse prin compensare) rămân de marcat manual.
 */
class ReconcileSupplierAutoCommand extends Command
{
    protected $signature = 'winmentor:reconcile-supplier-auto
                            {wm? : wm_id-ul furnizorului (gol = TOȚI furnizorii cu facturi deschise)}
                            {--dry-run : Doar raportează}';

    protected $description = 'Marchează automat facturile furnizor plătite (din plăți bancare) ca stinse';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        if (! $bridge->isReachable()) {
            $this->error('WinMentor Bridge indisponibil.');
            return self::FAILURE;
        }

        $arg = (string) ($this->argument('wm') ?? '');
        if ($arg !== '') {
            $m = $this->processSupplier($bridge, $arg, true);
            $this->info("Gata: {$m['verificate']} verificate, {$m['marcate']} marcate automat.");
            return self::SUCCESS;
        }

        // TOȚI furnizorii cu facturi pozitive deschise în scadențarul furnizor
        $wmIds = DB::table('winmentor_solduri_raw')->where('directie', 'furnizor')
            ->where('rest_de_plata', '>', 0.01)->distinct()->pluck('part_id')->all();
        $wmIds = Supplier::whereIn('winmentor_id', $wmIds)->pluck('winmentor_id')->all();

        $this->info(count($wmIds) . ' furnizori de verificat...');
        $totalMarcate = 0; $totalVerif = 0;
        foreach ($wmIds as $wm) {
            $m = $this->processSupplier($bridge, (string) $wm, false);
            $totalMarcate += $m['marcate'];
            $totalVerif += $m['verificate'];
        }
        $this->info("Gata: {$totalVerif} facturi verificate, {$totalMarcate} marcate automat ca stinse (plată bancară integrală).");
        return self::SUCCESS;
    }

    /** @return array{verificate:int,marcate:int} */
    private function processSupplier(WinmentorBridgeClient $bridge, string $wm, bool $verbose): array
    {
        $supplier = Supplier::where('winmentor_id', $wm)->first();
        $codEx = (string) (DB::table('winmentor_parteneri')->where('wm_id', $wm)->value('cod_extern') ?? '');
        $pids = array_values(array_filter(array_unique([$wm, $codEx])));

        $overSet = array_flip(DB::table('winmentor_factura_overrides')->whereIn('part_id', $pids)->where('directie', 'furnizor')->pluck('row_key')->all());
        $rows = DB::table('winmentor_solduri_raw')
            ->where('directie', 'furnizor')->whereIn('part_id', $pids)
            ->where('rest_de_plata', '>', 0.01)
            ->get()
            ->reject(fn ($r) => isset($overSet[\App\Models\WinmentorFacturaOverride::rowKey($r->nr_factura, $r->data_factura, $r->rest_de_plata)]))
            ->values();

        if ($verbose) {
            $this->info("Verific {$rows->count()} facturi deschise pentru furnizorul {$wm}...");
        }
        $marcate = 0; $verificate = 0;
        foreach ($rows as $r) {
            $nrInt = (int) preg_replace('/\D/', '', (string) $r->nr_factura);
            if ($nrInt <= 0 || ! $r->data_factura) {
                continue;
            }
            $an = (int) date('Y', strtotime($r->data_factura));
            $luna = (int) date('n', strtotime($r->data_factura));
            $verificate++;
            try {
                $plati = $bridge->getPlatiFactura($an, $luna, $nrInt, '', $wm);
            } catch (\Throwable $e) {
                continue;
            }
            $platit = array_sum(array_map(fn ($p) => (float) $p['suma'], $plati));
            $valoare = (float) $r->valoare_factura;
            // Plătită integral: ≥99% din valoare ȘI în ±1 leu (conservator — să nu ascundem datorii reale)
            if ($platit > 0 && $platit >= $valoare * 0.99 && $platit >= $valoare - 1.0) {
                if ($verbose) {
                    $this->line("  ✓ {$r->nr_factura}: plătit " . number_format($platit, 2) . " / " . number_format($valoare, 2) . " → stinsă");
                }
                if (! $this->option('dry-run')) {
                    DB::table('winmentor_factura_overrides')->updateOrInsert(
                        ['part_id' => $wm, 'row_key' => \App\Models\WinmentorFacturaOverride::rowKey($r->nr_factura, $r->data_factura, $r->rest_de_plata), 'directie' => 'furnizor'],
                        ['nr_factura' => $r->nr_factura, 'data_factura' => $r->data_factura, 'action' => 'settled', 'source' => 'auto_bank', 'settled_amount' => $platit, 'updated_at' => now(), 'created_at' => now()]
                    );
                }
                $marcate++;
            }
        }

        if (! $this->option('dry-run') && $supplier && $marcate > 0) {
            Cache::forget("supp_wm_fin_{$supplier->id}");
        }
        return ['verificate' => $verificate, 'marcate' => $marcate];
    }
}
