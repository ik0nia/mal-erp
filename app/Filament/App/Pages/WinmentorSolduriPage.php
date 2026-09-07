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
    /** Documentele mai vechi de atâtea luni = „istoric necompensat", nu creanță operațională. */
    public int $luniOperational = 24;

    /**
     * Solduri nete per partener cu compensare FIFO: încasările/avansurile/stornourile
     * (minusurile) se aplică peste cele mai vechi facturi (plusurile), pentru că în
     * Mentor multe NU sunt legate per document. Vechimea și împărțirea recent/istoric
     * se calculează DOAR pe documentele rămase efectiv neacoperite — altfel o factură
     * veche apărea „restantă 400 de zile" deși stornoul ei stă alături, nelegat.
     */
    public function getSolduri(string $directie): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "scadentar_{$directie}_{$this->luniOperational}",
            600,
            fn () => $this->computeSolduri($directie)
        );
    }

    private function computeSolduri(string $directie): array
    {
        $cutoff = now()->subMonths($this->luniOperational)->toDateString();

        $docs = DB::table('winmentor_solduri_raw')
            ->where('directie', $directie)
            ->whereRaw('ABS(rest_de_plata) >= 0.01')
            ->orderBy('part_id')
            ->orderBy('data_factura')
            ->get(['part_id', 'data_factura', 'rest_de_plata', 'moneda'])
            ->groupBy('part_id');

        $partIds  = $docs->keys()->filter()->values()->all();
        $wmNames  = DB::table('winmentor_parteneri')->whereIn('wm_id', $partIds)->get(['wm_id', 'denumire', 'cod_fiscal'])->keyBy('wm_id');

        $suppliers = $directie === 'furnizor'
            ? \App\Models\Supplier::whereIn('winmentor_id', $partIds)->pluck('id', 'winmentor_id')
            : collect();
        $cuis = $wmNames->pluck('cod_fiscal')->filter()
            ->map(fn ($c) => preg_replace('/[^0-9]/', '', (string) $c))->filter()->unique()->values()->all();
        $customers = $directie === 'client'
            ? \App\Models\Customer::whereIn('winmentor_id', $cuis)->pluck('id', 'winmentor_id')
            : collect();

        $azi        = now()->startOfDay();
        $totalNet   = 0.0;
        $totalVechi = 0.0;
        $totalAvans = 0.0;
        $rows       = [];

        foreach ($docs as $partId => $partDocs) {
            // FIFO: pool-ul de minusuri acoperă plusurile în ordine cronologică
            $pool    = (float) $partDocs->where('rest_de_plata', '<', 0)->sum(fn ($d) => abs((float) $d->rest_de_plata));
            $deschise = [];
            $areEur  = false;

            foreach ($partDocs as $d) {
                if (($d->moneda ?? null) === 'EUR') $areEur = true;
                $rest = (float) $d->rest_de_plata;
                if ($rest <= 0) continue;

                $acoperit = min($pool, $rest);
                $pool    -= $acoperit;
                $ramas    = round($rest - $acoperit, 2);

                if ($ramas >= 0.01) {
                    $deschise[] = ['data' => $d->data_factura, 'suma' => $ramas];
                }
            }

            $netRecent = round(array_sum(array_map(fn ($x) => ($x['data'] ?? '') >= $cutoff ? $x['suma'] : 0, $deschise)), 2);
            $netVechi  = round(array_sum(array_map(fn ($x) => ($x['data'] ?? '') < $cutoff ? $x['suma'] : 0, $deschise)), 2);
            $avans     = round($pool, 2); // minusuri rămase neaplicate = plăți în plus reale

            if ($netRecent < 1 && $netVechi < 1 && $avans < 1) continue;

            $totalNet   += $netRecent;
            $totalVechi += $netVechi;
            $totalAvans += $avans;

            if ($netRecent < 1 && $netVechi < 1) continue; // doar avans — apare în total, nu în listă

            $p   = $wmNames->get($partId);
            $url = null;
            if ($directie === 'furnizor' && ($sid = $suppliers->get($partId))) {
                $url = \App\Filament\App\Resources\SupplierResource::getUrl('view', ['record' => $sid]);
            } elseif ($directie === 'client' && $p?->cod_fiscal) {
                $cui = preg_replace('/[^0-9]/', '', (string) $p->cod_fiscal);
                if ($cid = $customers->get($cui)) {
                    $url = \App\Filament\App\Resources\CustomerResource::getUrl('view', ['record' => $cid]);
                }
            }

            $primulDeschis = $deschise[0]['data'] ?? null;

            $rows[] = (object) [
                'partner_name' => $p?->denumire ?: $partId ?: '—',
                'partner_url'  => $url,
                'cui'          => $p?->cod_fiscal,
                'net'          => round($netRecent + $netVechi, 2),
                'net_recent'   => $netRecent,
                'net_vechi'    => $netVechi,
                'are_eur'      => $areEur,
                'docs'         => count($deschise),
                'vechi'        => $primulDeschis,
                'nou'          => $deschise ? end($deschise)['data'] : null,
                'zile_vechime' => $primulDeschis ? (int) Carbon::parse($primulDeschis)->diffInDays($azi) : null,
            ];
        }

        usort($rows, fn ($a, $b) => $b->net_recent <=> $a->net_recent);

        return ['rows' => $rows, 'total_net' => $totalNet, 'total_vechi' => $totalVechi, 'total_avans' => $totalAvans];
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
