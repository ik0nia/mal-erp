<?php

namespace App\Console\Commands;

use App\Models\ProductSupplier;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Asociază automat furnizorul la produsele ORFANE (cu rulaj dar fără niciun
 * furnizor în ERP → invizibile în orice PO), pe baza istoricului real de
 * recepții: cine a livrat efectiv produsul în ultimele 24 de luni.
 * Se asociază doar la dominanță clară (≥70% din liniile de recepție).
 */
class AssociateSuppliersFromReceptionsCommand extends Command
{
    protected $signature = 'erp:associate-suppliers-from-receptions {--dry-run : Doar raportează}';

    protected $description = 'Asociază furnizori la produsele cu rulaj rămase fără furnizor, din istoricul de recepții';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $orfane = DB::select("
            SELECT wp.id, wp.sku, wp.name
            FROM woo_products wp
            JOIN bi_product_velocity_current b ON b.reference_product_id = wp.sku
            WHERE GREATEST(b.avg_out_qty_7d, b.avg_out_qty_30d, b.avg_out_qty_90d) > 0
              AND wp.is_discontinued = 0
              AND NOT EXISTS (SELECT 1 FROM product_suppliers ps WHERE ps.woo_product_id = wp.id)
        ");

        $this->info('Produse cu rulaj fără furnizor: '.count($orfane));

        // Mapă partener WinMentor (wm_id + cod_extern + denumire) → supplier ERP
        $suppliers = Supplier::whereNotNull('winmentor_id')->get(['id', 'name', 'winmentor_id']);
        $byWmId    = $suppliers->keyBy('winmentor_id');
        $extMap    = DB::table('winmentor_parteneri')->whereNotNull('cod_extern')->where('cod_extern', '!=', '')
            ->pluck('wm_id', 'cod_extern');
        $byName = Supplier::pluck('id', DB::raw('UPPER(TRIM(name))'));

        $create = $skip = 0;

        foreach ($orfane as $prod) {
            $receptii = DB::table('winmentor_intrari_raw')
                ->where('sku', $prod->sku)
                ->where('data_intrare', '>=', now()->subMonths(24)->toDateString())
                ->whereNotNull('part_id')
                ->get(['part_id', 'den_furnizor', 'pret']);

            if ($receptii->isEmpty()) {
                $skip++;
                continue;
            }

            // Rezolvă fiecare recepție la un supplier ERP
            $votes = [];
            $preturi = [];
            foreach ($receptii as $r) {
                $wmId = $extMap[$r->part_id] ?? $r->part_id;
                $sid  = $byWmId[$wmId]->id
                    ?? ($r->den_furnizor ? $byName->get(mb_strtoupper(trim($r->den_furnizor))) : null);
                if ($sid) {
                    $votes[$sid] = ($votes[$sid] ?? 0) + 1;
                    if ((float) $r->pret > 0) {
                        $preturi[$sid][] = (float) $r->pret;
                    }
                }
            }

            if (empty($votes)) {
                $skip++;
                continue;
            }

            arsort($votes);
            $dominantId  = array_key_first($votes);
            $dominantPct = $votes[$dominantId] / array_sum($votes);

            if ($dominantPct < 0.7) {
                $this->warn("  Ambiguu (fără dominanță): {$prod->name} — ".json_encode($votes));
                $skip++;
                continue;
            }

            $pret = ! empty($preturi[$dominantId]) ? end($preturi[$dominantId]) : null;

            $this->line(sprintf('  %s → %s (%d recepții%s)',
                mb_substr($prod->name, 0, 45),
                Supplier::find($dominantId)?->name,
                $votes[$dominantId],
                $pret ? ', preț '.$pret : ''
            ));

            if (! $dry) {
                ProductSupplier::create([
                    'woo_product_id' => $prod->id,
                    'supplier_id'    => $dominantId,
                    'is_preferred'   => true, // singurul furnizor cunoscut al produsului
                    'purchase_price' => $pret,
                ]);
            }
            $create++;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '')."Asociate: {$create} | Sărite (fără recepții/ambigue): {$skip}");
        return self::SUCCESS;
    }
}
