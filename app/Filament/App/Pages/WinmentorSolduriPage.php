<?php

namespace App\Filament\App\Pages;

use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Scadențar derivat local din datele sincronizate WinMentor:
 * - Clienți: facturi emise (tip F) − încasări legate prin serie (F.MALxxxxx)
 * - Furnizori: facturi de intrare − plăți legate prin nr. factură (F.xxxxx)
 * Fără apeluri COM — totul din winmentor_vanzari_raw / intrari / incasari / plati.
 */
class WinmentorSolduriPage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-solduri';

    protected static ?string $navigationLabel = 'Scadențar (beta)';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int    $navigationSort  = 93;
    protected static ?string $title = 'Scadențar clienți & furnizori';

    public string $tab = 'clienti';
    public int    $luniIstoric = 12;

    protected $queryString = ['tab'];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Facturi clienți cu sold (valoare − încasat), ultimele N luni.
     */
    public function getFacturiClienti(): array
    {
        $start = now()->subMonths($this->luniIstoric)->startOfMonth();

        $facturi = DB::table('winmentor_vanzari_raw')
            ->where('firma', 'MAL2019')
            ->where('tip_document', 'F')
            ->where('serie_document', 'LIKE', 'F.MAL%')
            ->whereRaw("STR_TO_DATE(CONCAT(an,'-',luna,'-',COALESCE(zi,1)),'%Y-%m-%d') >= ?", [$start->toDateString()])
            ->groupBy('nr_factura', 'serie_document', 'part_id')
            ->selectRaw("
                nr_factura, serie_document, part_id,
                MAX(cod_fiscal_client) as cui,
                MAX(valoare_factura) as valoare,
                MIN(STR_TO_DATE(data_emitere, '%d.%m.%Y'))  as emisa,
                MIN(STR_TO_DATE(data_scadenta, '%d.%m.%Y')) as scadenta
            ")
            ->havingRaw('valoare > 0')
            ->get();

        $incasat = DB::table('winmentor_incasari_raw')
            ->select('document_ref', DB::raw('SUM(suma) as suma'))
            ->groupBy('document_ref')
            ->pluck('suma', 'document_ref');

        // Facturi încasate CASH la casă: apar ca rânduri S (bon) pe numărul facturii
        // în vânzări — NU trec prin modulul de încasări bancare. Fără verificarea asta,
        // orice factură achitată pe loc ar rămâne veșnic „restantă".
        $nrFacturi = $facturi->pluck('nr_factura')->filter()->unique()->values()->all();
        $cashPaid  = DB::table('winmentor_vanzari_raw')
            ->where('firma', 'MAL2019')
            ->where('tip_document', 'S')
            ->whereIn('nr_factura', $nrFacturi)
            ->whereRaw("nr_factura NOT REGEXP '^2[0-9]{5}$'") // excludem seriile native de bon
            ->select('nr_factura', DB::raw('MAX(CONCAT(an, LPAD(luna,2,"0"))) as perioada'))
            ->groupBy('nr_factura')
            ->pluck('perioada', 'nr_factura');

        // Nume partener: Customer (winmentor_id = CUI) sau winmentor_parteneri
        $partIds  = $facturi->pluck('part_id')->filter()->unique()->values()->all();
        $wmNames  = DB::table('winmentor_parteneri')->whereIn('wm_id', $partIds)->pluck('denumire', 'wm_id');
        $cuis     = $facturi->pluck('cui')->filter()->unique()->values()->all();
        $custByCui = \App\Models\Customer::whereIn('winmentor_id', $cuis)->get(['id', 'name', 'winmentor_id'])->keyBy('winmentor_id');

        $azi  = now()->startOfDay();
        $rows = [];

        foreach ($facturi as $f) {
            $inc  = (float) ($incasat[$f->serie_document] ?? 0);
            $sold = round((float) $f->valoare - $inc, 2);
            if ($sold < 1) continue;

            // Încasată cash prin bon (în aceeași lună sau ulterior emiterii) → nu e restantă
            $cashPeriod = $cashPaid[$f->nr_factura] ?? null;
            if ($cashPeriod !== null) {
                $emisaPeriod = $f->emisa ? substr(str_replace('-', '', (string) $f->emisa), 0, 6) : null;
                if ($emisaPeriod === null || $cashPeriod >= $emisaPeriod) {
                    continue;
                }
            }

            $customer = $f->cui ? ($custByCui[$f->cui] ?? null) : null;
            $scadenta = $f->scadenta ? Carbon::parse($f->scadenta) : null;

            $rows[] = (object) [
                'nr_factura'   => $f->nr_factura,
                'serie'        => $f->serie_document,
                'partner_name' => $customer?->name ?? $wmNames[$f->part_id] ?? ($f->cui ?: $f->part_id ?: '—'),
                'partner_url'  => $customer
                    ? \App\Filament\App\Resources\CustomerResource::getUrl('view', ['record' => $customer->id])
                    : null,
                'emisa'        => $f->emisa,
                'scadenta'     => $f->scadenta,
                'zile'         => $scadenta ? (int) $scadenta->diffInDays($azi, false) : null, // pozitiv = depășită
                'valoare'      => (float) $f->valoare,
                'incasat'      => $inc,
                'sold'         => $sold,
            ];
        }

        usort($rows, fn ($a, $b) => ($b->zile ?? -9999) <=> ($a->zile ?? -9999));

        return $rows;
    }

    /**
     * Facturi furnizori cu sold estimat (valoare ex-TVA × 1.21 − plătit), ultimele N luni.
     */
    public function getFacturiFurnizori(): array
    {
        $start = now()->subMonths($this->luniIstoric)->startOfMonth();

        $facturi = DB::table('winmentor_intrari_raw')
            ->where('firma', 'MAL2019')
            ->whereNotNull('nr_doc')
            ->where('data_intrare', '>=', $start->toDateString())
            ->groupBy('nr_doc', 'an', 'luna')
            ->selectRaw("
                nr_doc, an, luna,
                GROUP_CONCAT(DISTINCT part_id) as part_ids,
                MAX(den_furnizor) as den_furnizor,
                MIN(data_intrare) as data_intrare,
                ROUND(SUM(cantitate * pret * COALESCE(curs_bnr, 1)), 2) as valoare_ex_tva
            ")
            ->havingRaw('valoare_ex_tva > 0')
            ->get();

        $platit = DB::table('winmentor_plati_raw')
            ->select('document_ref', DB::raw('SUM(suma) as suma'))
            ->groupBy('document_ref')
            ->pluck('suma', 'document_ref');

        $allPartIds  = $facturi->flatMap(fn ($f) => explode(',', $f->part_ids ?? ''))->filter()->unique()->values()->all();
        $suppliers   = \App\Models\Supplier::whereIn('winmentor_id', $allPartIds)->get(['id', 'name', 'winmentor_id'])->keyBy('winmentor_id');
        $wmNames     = DB::table('winmentor_parteneri')->whereIn('wm_id', $allPartIds)->pluck('denumire', 'wm_id');

        $rows = [];

        foreach ($facturi as $f) {
            $valCuTva = round((float) $f->valoare_ex_tva * 1.21, 2);
            $pl       = (float) ($platit['F.' . $f->nr_doc] ?? 0);
            $sold     = round($valCuTva - $pl, 2);
            if ($sold < 1) continue;

            $partIds  = array_filter(explode(',', $f->part_ids ?? ''));
            $supplier = collect($partIds)->map(fn ($id) => $suppliers[$id] ?? null)->filter()->first();

            $rows[] = (object) [
                'nr_doc'       => $f->nr_doc,
                'partner_name' => $supplier?->name
                    ?? ($f->den_furnizor ?: (collect($partIds)->map(fn ($id) => $wmNames[$id] ?? null)->filter()->first() ?: implode(',', $partIds))),
                'partner_url'  => $supplier
                    ? \App\Filament\App\Resources\SupplierResource::getUrl('view', ['record' => $supplier->id])
                    : null,
                'data'         => $f->data_intrare,
                'valoare'      => $valCuTva,
                'platit'       => $pl,
                'sold'         => $sold,
            ];
        }

        usort($rows, fn ($a, $b) => $b->sold <=> $a->sold);

        return $rows;
    }

    /**
     * Sumar aging pe facturile de clienți cu sold.
     */
    public function getAgingClienti(array $rows): array
    {
        $buckets = ['neajunse' => 0.0, '1-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];

        foreach ($rows as $r) {
            $z = $r->zile;
            if ($z === null || $z <= 0)  $buckets['neajunse'] += $r->sold;
            elseif ($z <= 30)            $buckets['1-30']     += $r->sold;
            elseif ($z <= 60)            $buckets['31-60']    += $r->sold;
            elseif ($z <= 90)            $buckets['61-90']    += $r->sold;
            else                         $buckets['90+']      += $r->sold;
        }

        return $buckets;
    }
}
