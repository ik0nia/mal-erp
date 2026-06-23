<?php

namespace App\Console\Commands;

use App\Models\WooOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Asociază comenzile WooCommerce cu facturile din WinMentor.
 *
 * READ-ONLY față de WinMentor: citește doar tabela locală winmentor_vanzari_raw
 * (deja sincronizată) și salvează asocierea pe woo_orders. Zero apeluri COM.
 *
 * Pass 1 — DETERMINIST: factura are ștampila „Comandă online #X (WooCommerce)".
 * Pass 2 — EURISTIC CONSERVATOR: pentru comenzi finalizate fără ștampilă,
 *          asociez DOAR dacă există un singur candidat cu același total în ±2 zile
 *          (marcat estimat=true).
 *
 * Implicit rulează DRY-RUN (doar raport). Cu --apply salvează efectiv.
 */
class MatchWooFacturiCommand extends Command
{
    protected $signature = 'winmentor:match-woo-facturi {--apply : Salvează efectiv (altfel doar raport dry-run)} {--fuzzy : Include și match-ul euristic conservator pentru comenzi istorice}';

    protected $description = 'Asociază comenzile Woo cu facturile WinMentor (determinist + euristic conservator) — local, read-only';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? '== MOD: APLICARE (salvez) ==' : '== MOD: DRY-RUN (doar raport, nu salvez) ==');

        // ───────── PASS 1: DETERMINIST (ștampilă WooCommerce) ─────────
        $stamped = DB::table('winmentor_vanzari_raw')
            ->where('observatii_factura', 'like', '%(WooCommerce)%')
            ->selectRaw("nr_factura, an, luna, MIN(serie_document) serie, MIN(data_emitere) data,
                MIN(observatii_factura) obs, MIN(part_id) pid, ROUND(SUM(cantitate * pret * 1.21), 2) total")
            ->groupBy('nr_factura', 'an', 'luna')
            ->get();

        $det = 0;
        foreach ($stamped as $inv) {
            if (! preg_match('/#(\d+)/', (string) $inv->obs, $m)) {
                continue;
            }
            $order = WooOrder::where('number', (string) $m[1])->first();
            if (! $order) {
                continue;
            }
            if ($apply) {
                $data = [
                    'winmentor_invoice_nr'         => $inv->nr_factura,
                    'winmentor_invoice_serie'      => $inv->serie,
                    'winmentor_invoice_an'         => $inv->an,
                    'winmentor_invoice_luna'       => $inv->luna,
                    'winmentor_invoice_data'       => $inv->data,
                    'winmentor_invoice_total'      => $inv->total,
                    'winmentor_invoice_estimat'    => false,
                    'winmentor_invoice_matched_at' => now(),
                ];
                if (! $order->winmentor_client_id && $inv->pid) {
                    $data['winmentor_client_id'] = $inv->pid;
                }
                $order->update($data);
            }
            $det++;
        }
        $this->info("Pass 1 determinist (ștampilă): {$det}");

        // ───────── PASS 2: EURISTIC CONSERVATOR (doar cu --fuzzy) ─────────
        if (! $this->option('fuzzy')) {
            $this->info('Pass 2 euristic: sărit (folosește --fuzzy pentru comenzi istorice).');
            return self::SUCCESS;
        }

        // Normalizare + potrivire nume (prenume + nume), cu diacritice românești.
        $norm = function ($s) {
            $s = mb_strtolower(trim((string) $s));
            $s = strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
            $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
            return trim(preg_replace('/\s+/', ' ', $s));
        };
        $words = fn ($s) => array_values(array_filter(explode(' ', $norm($s)), fn ($w) => strlen($w) >= 3));
        $nameMatch = function ($a, $b) use ($words) {
            $wa = $words($a);
            $wb = $words($b);
            if (! $wa || ! $wb) {
                return false;
            }
            return count(array_intersect($wa, $wb)) >= 2; // minim prenume + nume comune
        };

        // Harta nume partener (ID intern → denumire) pentru verificarea clientului.
        $pname = DB::table('winmentor_parteneri')->pluck('denumire', 'wm_id');

        // DOAR FACTURI (serie F.MAL...), exclud avizele (AE.*), cu client identificabil prin nume.
        $invoices = DB::table('winmentor_vanzari_raw')
            ->whereIn('an', [2024, 2025, 2026])
            ->where('serie_document', 'like', 'F%')
            ->selectRaw("nr_factura, an, luna, MIN(serie_document) serie, MIN(data_emitere) data,
                MAX(valoare_factura) val, MIN(part_id) pid")
            ->groupBy('nr_factura', 'an', 'luna')
            ->get();

        $inv = [];
        foreach ($invoices as $f) {
            try {
                $f->_d = Carbon::createFromFormat('d.m.Y', (string) $f->data);
            } catch (\Throwable $e) {
                $f->_d = null;
            }
            $f->_name = (string) ($pname[$f->pid] ?? '');
            if ($f->_d && $f->_name !== '') {
                $inv[] = $f;
            }
        }

        $orders = WooOrder::where('status', 'completed')->whereNull('winmentor_invoice_nr')->get();
        $exact = 0;
        $valDiff = 0;
        $none = 0;
        $rows = [];

        foreach ($orders as $o) {
            if ((float) $o->total <= 0 || ! $o->order_date) {
                $none++;
                continue;
            }
            $od = Carbon::parse($o->order_date);
            $oname = (string) $o->customer_name;
            $targets = array_values(array_unique(array_filter([
                (float) $o->total,
                (float) $o->subtotal,
                (float) $o->total - (float) $o->shipping_total,
            ])));

            $best = null;
            $bestScore = null;
            $bestT = null;
            foreach ($inv as $f) {
                $delta = $od->diffInDays($f->_d, false);
                if ($delta < -2 || $delta > 75) {
                    continue;
                }
                if (! $nameMatch($oname, $f->_name)) { // VERIFICARE NUME obligatorie
                    continue;
                }
                $tDiff = min(array_map(fn ($t) => abs($t - (float) $f->val), $targets));
                $score = [$tDiff <= 0.20 ? 0 : 1, round($tDiff, 2), abs($delta)];
                if ($bestScore === null || $score < $bestScore) {
                    $bestScore = $score;
                    $best = $f;
                    $bestT = $tDiff;
                }
            }

            if (! $best) {
                $none++;
                continue;
            }

            $valOk = $bestT <= 0.20;
            $valOk ? $exact++ : $valDiff++;
            $rows[] = [$o->number, mb_substr($oname, 0, 16), number_format((float) $o->total, 2), $best->serie, mb_substr($best->_name, 0, 16), $best->data, $valOk ? '' : '⚠'];

            if ($apply) {
                $data = [
                    'winmentor_invoice_nr'         => $best->nr_factura,
                    'winmentor_invoice_serie'      => $best->serie,
                    'winmentor_invoice_an'         => $best->an,
                    'winmentor_invoice_luna'       => $best->luna,
                    'winmentor_invoice_data'       => $best->data,
                    'winmentor_invoice_total'      => $best->val,
                    'winmentor_invoice_estimat'    => true,
                    'winmentor_invoice_matched_at' => now(),
                ];
                if (! $o->winmentor_client_id && $best->pid) {
                    $data['winmentor_client_id'] = $best->pid;
                }
                $o->update($data);
            }
        }

        $this->info("Pass 2 (DOAR facturi + nume client): potrivit={$exact} | nume ok dar val≠ (⚠)={$valDiff} | nerezolvate={$none}");

        if (! $apply && $rows) {
            $this->newLine();
            $this->table(['Comandă', 'Client comandă', 'Total', 'Factură', 'Client factură', 'Data', '⚠'], array_slice($rows, 0, 25));
            if (count($rows) > 25) {
                $this->info('... + ' . (count($rows) - 25) . ' altele');
            }
            $this->newLine();
            $this->warn('DRY-RUN: nimic salvat. Rulează cu --apply după verificare.');
        }

        return self::SUCCESS;
    }
}
