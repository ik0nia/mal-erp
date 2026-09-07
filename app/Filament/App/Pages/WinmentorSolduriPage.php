<?php

namespace App\Filament\App\Pages;

use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Scadențar pe SURSA OFICIALĂ WinMentor: winmentor_solduri_raw = toate
 * documentele cu rest din contabilitatea Mentor (GetSolduri / GetSolduriFurn),
 * sincronizate nocturn. Multe încasări/plăți NU sunt legate per factură în
 * Mentor (apar ca +factură și −încasare separate), de aceea cifra corectă
 * e SOLDUL NET PER PARTENER — exact cum îl vede contabilitatea.
 */
class WinmentorSolduriPage extends Page
{
    protected string $view = 'filament.app.pages.winmentor-solduri';

    protected static ?string $navigationLabel = 'Scadențar';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int    $navigationSort  = 93;
    protected static ?string $title = 'Scadențar clienți & furnizori';

    public string $tab = 'clienti';

    protected $queryString = ['tab'];

    public static function canAccess(): bool
    {
        return auth()->user()?->email === 'codrut@ikonia.ro';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function lastSync(): ?string
    {
        $ts = DB::table('winmentor_solduri_raw')->max('fetched_at');
        return $ts ? Carbon::parse($ts)->format('d.m.Y H:i') : null;
    }

    /**
     * Solduri NETE per partener din sursa oficială + detaliu documente restante.
     *
     * @return array{rows: array, total_net: float, total_avans: float}
     */
    public function getSolduri(string $directie): array
    {
        $parteneri = DB::table('winmentor_solduri_raw as s')
            ->leftJoin('winmentor_parteneri as p', 'p.wm_id', '=', 's.part_id')
            ->where('s.directie', $directie)
            ->groupBy('s.part_id', 'p.denumire', 'p.cod_fiscal')
            ->selectRaw('
                s.part_id,
                p.denumire as partener,
                p.cod_fiscal,
                ROUND(SUM(s.rest_de_plata), 2) as net,
                COUNT(*) as docs,
                MIN(CASE WHEN s.rest_de_plata > 0 THEN s.data_factura END) as cel_mai_vechi,
                MAX(CASE WHEN s.rest_de_plata > 0 THEN s.data_factura END) as cel_mai_nou
            ')
            ->havingRaw('ABS(net) >= 1')
            ->orderByDesc('net')
            ->get();

        $partIds   = $parteneri->pluck('part_id')->filter()->unique()->values()->all();
        $suppliers = $directie === 'furnizor'
            ? \App\Models\Supplier::whereIn('winmentor_id', $partIds)->pluck('id', 'winmentor_id')
            : collect();

        $cuis = $parteneri->pluck('cod_fiscal')->filter()
            ->map(fn ($c) => preg_replace('/[^0-9]/', '', (string) $c))
            ->filter()->unique()->values()->all();
        $customers = $directie === 'client'
            ? \App\Models\Customer::whereIn('winmentor_id', $cuis)->pluck('id', 'winmentor_id')
            : collect();

        $azi        = now()->startOfDay();
        $totalNet   = 0.0;
        $totalAvans = 0.0;
        $rows       = [];

        foreach ($parteneri as $p) {
            $net = (float) $p->net;

            if ($net < 0) {
                $totalAvans += abs($net); // partener cu sold în favoarea lui (avans/necorelat)
                continue;
            }

            $totalNet += $net;

            $url = null;
            if ($directie === 'furnizor' && ($sid = $suppliers->get($p->part_id))) {
                $url = \App\Filament\App\Resources\SupplierResource::getUrl('view', ['record' => $sid]);
            } elseif ($directie === 'client' && $p->cod_fiscal) {
                $cui = preg_replace('/[^0-9]/', '', (string) $p->cod_fiscal);
                if ($cid = $customers->get($cui)) {
                    $url = \App\Filament\App\Resources\CustomerResource::getUrl('view', ['record' => $cid]);
                }
            }

            $rows[] = (object) [
                'partner_name' => $p->partener ?: $p->part_id ?: '—',
                'partner_url'  => $url,
                'cui'          => $p->cod_fiscal,
                'net'          => $net,
                'docs'         => (int) $p->docs,
                'vechi'        => $p->cel_mai_vechi,
                'nou'          => $p->cel_mai_nou,
                'zile_vechime' => $p->cel_mai_vechi ? (int) Carbon::parse($p->cel_mai_vechi)->diffInDays($azi) : null,
            ];
        }

        return ['rows' => $rows, 'total_net' => $totalNet, 'total_avans' => $totalAvans];
    }

    /** Documentele deschise ale unui partener (pentru expandare în UI). */
    public function getDocumentePartener(string $partId, string $directie): array
    {
        return DB::table('winmentor_solduri_raw')
            ->where('directie', $directie)
            ->where('part_id', $partId)
            ->whereRaw('ABS(rest_de_plata) >= 0.01')
            ->orderByDesc('data_factura')
            ->limit(100)
            ->get(['tip_document', 'nr_factura', 'data_factura', 'termen_plata', 'valoare_factura', 'rest_de_plata'])
            ->all();
    }
}
