<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliere EURISTICĂ (FIFO) a scadențarului furnizor la soldul autoritar.
 * WinMentor nu ne spune care facturi sunt stinse prin compensare. Presupunem că cele
 * stinse sunt cele mai VECHI (datoriile vechi se plătesc primele): păstrăm rândurile
 * cele mai noi (net, cu semn — invoice+avans) până când suma lor = soldul autoritar,
 * și marcăm restul (mai vechi) ca stinse (source='auto_fifo', reversibil).
 *
 * ⚠️ Soldul rămâne 100% corect; atribuirea per-factură e o ESTIMARE plauzibilă, nu verificată.
 */
class ReconcileSupplierFifoCommand extends Command
{
    protected $signature = 'winmentor:reconcile-supplier-fifo
                            {wm? : wm_id furnizor (gol = toți divergenții)}
                            {--dry-run : Doar raportează}';

    protected $description = 'Reconciliere euristică FIFO: marchează cele mai vechi facturi ca stinse până se potrivește soldul';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        if (! $bridge->isReachable()) {
            $this->error('WinMentor Bridge indisponibil.');
            return self::FAILURE;
        }

        $arg = (string) ($this->argument('wm') ?? '');
        if ($arg !== '') {
            $r = $this->processSupplier($bridge, $arg, true);
            $this->info("Rezultat: " . json_encode($r, JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $wmIds = DB::table('winmentor_solduri_raw')->where('directie', 'furnizor')
            ->where('rest_de_plata', '>', 0.01)->distinct()->pluck('part_id')->all();
        $wmIds = Supplier::whereIn('winmentor_id', $wmIds)->pluck('winmentor_id')->all();

        $this->info(count($wmIds) . ' furnizori de verificat...');
        $rec = 0; $skip = 0; $totalMarcate = 0; $skipList = [];
        foreach ($wmIds as $wm) {
            $r = $this->processSupplier($bridge, (string) $wm, false);
            if ($r['status'] === 'reconciled') { $rec++; $totalMarcate += $r['marcate']; }
            elseif ($r['status'] === 'skip') { $skip++; $skipList[] = $r['name'] . ' (gap rămas ' . number_format($r['gap'], 0) . ')'; }
        }
        $this->newLine();
        $this->info("FIFO: {$rec} furnizori reconciliați ({$totalMarcate} facturi marcate auto_fifo), {$skip} săriți (nu s-a găsit potrivire curată).");
        if ($skipList) {
            $this->warn('Săriți (rămân manual):');
            foreach (array_slice($skipList, 0, 30) as $s) {
                $this->line('  · ' . $s);
            }
        }
        return self::SUCCESS;
    }

    private function processSupplier(WinmentorBridgeClient $bridge, string $wm, bool $verbose): array
    {
        $supplier = Supplier::where('winmentor_id', $wm)->first();
        $name = $supplier->name ?? $wm;
        $codEx = (string) (DB::table('winmentor_parteneri')->where('wm_id', $wm)->value('cod_extern') ?? '');
        $pids = array_values(array_filter(array_unique([$wm, $codEx])));

        // Soldul autoritar (țintă)
        $target = null;
        try {
            $s = $bridge->getSoldPartener($wm);
            $target = $this->parseSold($s['sold'] ?? null) ?? 0.0;
        } catch (\Throwable $e) {
            return ['status' => 'error', 'name' => $name, 'marcate' => 0, 'gap' => 0];
        }
        $target = abs($target);

        // Rânduri deschise (nemarcate deja, filtru pe row_key), cele mai NOI primele
        $overSet = array_flip(DB::table('winmentor_factura_overrides')->whereIn('part_id', $pids)->where('directie', 'furnizor')->pluck('row_key')->all());
        $rows = DB::table('winmentor_solduri_raw')
            ->where('directie', 'furnizor')->whereIn('part_id', $pids)
            ->whereRaw('ABS(rest_de_plata) >= 0.01')
            ->orderByDesc('data_factura')->orderByDesc('id')
            ->get()
            ->reject(fn ($r) => isset($overSet[\App\Models\WinmentorFacturaOverride::rowKey($r->nr_factura, $r->data_factura, $r->rest_de_plata)]))
            ->values();

        $totalOpen = (float) $rows->sum('rest_de_plata');
        $tol = max(50.0, $target * 0.05);

        // Deja reconciliat?
        if (abs(abs($totalOpen) - $target) <= $tol) {
            return ['status' => 'reconciled', 'name' => $name, 'marcate' => 0, 'gap' => abs(abs($totalOpen) - $target)];
        }

        // Caut prefixul de rânduri NOI al căror net (abs) ≈ țintă; restul (vechi) = stinse.
        $running = 0.0; $bestIdx = -1; $bestDiff = PHP_FLOAT_MAX;
        foreach ($rows as $i => $r) {
            $running += (float) $r->rest_de_plata;
            $diff = abs(abs($running) - $target);
            if ($diff < $bestDiff) { $bestDiff = $diff; $bestIdx = $i; }
        }

        if ($bestDiff > $tol) {
            // Nicio potrivire curată — lăsăm manual
            return ['status' => 'skip', 'name' => $name, 'marcate' => 0, 'gap' => $bestDiff];
        }

        // Marchez ca stinse toate rândurile DE DUPĂ prefixul păstrat (cele mai vechi)
        $marcate = 0;
        foreach ($rows as $i => $r) {
            if ($i <= $bestIdx) {
                continue; // păstrate deschise (cele mai noi)
            }
            if (! $this->option('dry-run')) {
                DB::table('winmentor_factura_overrides')->updateOrInsert(
                    ['part_id' => $wm, 'row_key' => \App\Models\WinmentorFacturaOverride::rowKey($r->nr_factura, $r->data_factura, $r->rest_de_plata), 'directie' => 'furnizor'],
                    ['nr_factura' => $r->nr_factura, 'data_factura' => $r->data_factura, 'action' => 'settled', 'source' => 'auto_fifo', 'updated_at' => now(), 'created_at' => now()]
                );
            }
            $marcate++;
        }
        if (! $this->option('dry-run') && $supplier) {
            Cache::forget("supp_wm_fin_{$supplier->id}");
        }
        if ($verbose) {
            $this->line("  {$name}: țintă " . number_format($target, 2) . ", păstrat " . ($bestIdx + 1) . " rânduri noi, marcate {$marcate} vechi (diff " . number_format($bestDiff, 2) . ")");
        }
        return ['status' => 'reconciled', 'name' => $name, 'marcate' => $marcate, 'gap' => $bestDiff];
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
