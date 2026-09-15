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
                            {wm : wm_id-ul furnizorului}
                            {--dry-run : Doar raportează}';

    protected $description = 'Marchează automat facturile furnizor plătite (din plăți bancare) ca stinse';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        if (! $bridge->isReachable()) {
            $this->error('WinMentor Bridge indisponibil.');
            return self::FAILURE;
        }

        $wm = (string) $this->argument('wm');
        $supplier = Supplier::where('winmentor_id', $wm)->first();
        $codEx = (string) (DB::table('winmentor_parteneri')->where('wm_id', $wm)->value('cod_extern') ?? '');
        $pids = array_values(array_filter(array_unique([$wm, $codEx])));

        // Facturi pozitive deschise (nemarcate deja)
        $over = DB::table('winmentor_factura_overrides')->whereIn('part_id', $pids)->where('directie', 'furnizor')->pluck('nr_factura')->all();
        $rows = DB::table('winmentor_solduri_raw')
            ->where('directie', 'furnizor')->whereIn('part_id', $pids)
            ->where('rest_de_plata', '>', 0.01)
            ->whereNotIn('nr_factura', $over ?: ['__none__'])
            ->get();

        $this->info("Verific {$rows->count()} facturi deschise pentru furnizorul {$wm}...");
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
                $this->line("  ✓ {$r->nr_factura}: plătit " . number_format($platit, 2) . " / " . number_format($r->valoare_factura, 2) . " → stinsă");
                if (! $this->option('dry-run')) {
                    DB::table('winmentor_factura_overrides')->updateOrInsert(
                        ['part_id' => $wm, 'nr_factura' => $r->nr_factura, 'directie' => 'furnizor'],
                        ['action' => 'settled', 'source' => 'auto_bank', 'settled_amount' => $platit, 'updated_at' => now(), 'created_at' => now()]
                    );
                }
                $marcate++;
            }
        }

        if (! $this->option('dry-run') && $supplier) {
            Cache::forget("supp_wm_fin_{$supplier->id}");
        }
        $this->info("Gata: {$verificate} verificate, {$marcate} marcate automat ca stinse (plată bancară integrală).");
        return self::SUCCESS;
    }
}
