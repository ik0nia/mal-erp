<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\HasDynamicNavSort;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard „Pulsul afacerii" — interactiv: perioadă comutabilă, drill-down
 * pe zi din grafic, detaliu client, sortare topuri, auto-refresh.
 * Momentan vizibil DOAR pentru codrut@ikonia.ro (pilot înainte de lansare).
 */
class PulsPage extends Page
{
    use HasDynamicNavSort;

    protected string $view = 'filament.app.pages.puls';

    protected static ?string $navigationLabel = 'Puls';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';
    protected static ?int $navigationSort = 0;
    protected static ?string $title = 'Pulsul afacerii';
    protected static ?string $slug = 'puls';

    private const PILOT_EMAILS = ['codrut@ikonia.ro'];

    /** Perioada topurilor: 7 / 30 / 90 zile. */
    public int $perioada = 7;

    /** Sortarea topului de produse: lei | buc. */
    public string $sortTop = 'lei';

    /** Ziua selectată din grafic (drill-down) — Y-m-d sau null. */
    public ?string $ziSelectata = null;

    /** Clientul selectat pentru detaliu (denumire) — sau null. */
    public ?string $clientSelectat = null;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->email, self::PILOT_EMAILS, true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function setPerioada(int $zile): void
    {
        $this->perioada = in_array($zile, [7, 30, 90], true) ? $zile : 7;
        $this->clientSelectat = null;
    }

    public function setSortTop(string $sort): void
    {
        $this->sortTop = $sort === 'buc' ? 'buc' : 'lei';
    }

    public function selecteazaZi(?string $zi): void
    {
        $this->ziSelectata = ($zi && $zi === $this->ziSelectata) ? null : $zi;
    }

    public function selecteazaClient(?string $denumire): void
    {
        $this->clientSelectat = ($denumire && $denumire === $this->clientSelectat) ? null : $denumire;
    }

    /** WHERE sargabil pe indexul (an, luna, zi) — prefiltru pe an + expresie exactă. */
    private function whereZile($q, string $from, ?string $to = null)
    {
        $to ??= now()->toDateString();
        [$fa, $fl, $fz] = explode('-', $from);
        [$ta, $tl, $tz] = explode('-', $to);

        return $q->whereBetween('an', [(int) $fa, (int) $ta])
            ->whereRaw('(an*10000 + luna*100 + zi) BETWEEN ? AND ?', [
                (int) $fa * 10000 + (int) $fl * 100 + (int) $fz,
                (int) $ta * 10000 + (int) $tl * 100 + (int) $tz,
            ]);
    }

    protected function getViewData(): array
    {
        $today = now()->toDateString();

        $salesDay = fn (string $date) => $this->whereZile(DB::table('winmentor_vanzari_raw'), $date, $date)
            ->where('cantitate', '>', 0)
            ->selectRaw('ROUND(SUM(cantitate * pret)) lei, COUNT(DISTINCT nr_factura) documente')->first();
        $azi = $salesDay($today);
        $ieri = $salesDay(now()->subDay()->toDateString());

        $salesRange = fn ($from, $to) => (float) $this->whereZile(DB::table('winmentor_vanzari_raw'), $from, $to)
            ->where('cantitate', '>', 0)->selectRaw('COALESCE(SUM(cantitate * pret),0) lei')->value('lei');
        $sapt = $salesRange(now()->startOfWeek()->toDateString(), $today);
        $saptTrecuta = $salesRange(now()->subWeek()->startOfWeek()->toDateString(), now()->subWeek()->endOfWeek()->toDateString());
        $luna = $salesRange(now()->startOfMonth()->toDateString(), $today);
        $lunaTrecutaLaZi = $salesRange(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->toDateString());

        // grafic: vânzări pe zi, ultimele 30 zile
        $grafic = $this->whereZile(DB::table('winmentor_vanzari_raw'), now()->subDays(29)->toDateString())
            ->where('cantitate', '>', 0)
            ->groupBy('an', 'luna', 'zi')
            ->selectRaw("CONCAT(an,'-',LPAD(luna,2,'0'),'-',LPAD(zi,2,'0')) ziua, ROUND(SUM(cantitate*pret)) lei")
            ->pluck('lei', 'ziua')->all();
        $zileGrafic = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $zileGrafic[$d] = (float) ($grafic[$d] ?? 0);
        }

        // drill-down pe ziua selectată
        $detaliuZi = null;
        if ($this->ziSelectata) {
            $detaliuZi = [
                'total' => $salesDay($this->ziSelectata),
                'topProduse' => $this->whereZile(DB::table('winmentor_vanzari_raw'), $this->ziSelectata, $this->ziSelectata)
                    ->where('cantitate', '>', 0)->whereNotNull('den_articol')->where('den_articol', '!=', '')
                    ->whereRaw("den_articol NOT REGEXP 'SERVICII|TRANSPORT|AVANS|TAXA|ACHITAT|TRANSFER|REFACTURAT|Utilitati|Deseu|PALET'")
                    ->groupBy('den_articol')
                    ->selectRaw('den_articol, ROUND(SUM(cantitate)) buc, ROUND(SUM(cantitate*pret)) lei')
                    ->orderByDesc(DB::raw('SUM(cantitate*pret)'))->limit(8)->get(),
            ];
        }

        // comenzi online
        $procesare = DB::table('woo_orders')->where('status', 'processing')->count();
        $onlineAzi = DB::table('woo_orders')->whereDate('order_date', $today)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) lei')->first();
        $comenziRecente = DB::table('woo_orders')->orderByDesc('order_date')->limit(6)
            ->get(['id', 'number', 'status', 'total', 'billing', 'order_date', 'payment_method_title']);

        // AWB / livrări
        $inLivrare = DB::table('sameday_awbs')
            ->whereNotNull('awb_number')->where('awb_number', '!=', '')
            ->where('status', 'created')->whereNull('delivered_at')
            ->whereNotNull('courier_status')->where('courier_status', '!=', 'indisponibil')
            ->whereRaw("courier_status NOT REGEXP 'retur|anulat|refuz'")->count();
        $avgLivrare = DB::table('sameday_awbs')->whereNotNull('picked_up_at')->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subDays(90))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, picked_up_at, delivered_at)) h')->value('h');
        $codPending = DB::table('sameday_awbs')->where('cod_amount', '>', 0)->whereNotNull('delivered_at')
            ->where(fn ($q) => $q->whereNull('courier_status')->orWhereRaw("courier_status NOT REGEXP 'rambur.*(transferat|compensat)'"))
            ->whereRaw("COALESCE(courier_status,'') NOT REGEXP 'retur|refuz|anulat'")
            ->selectRaw('COUNT(*) c, COALESCE(SUM(cod_amount),0) s')->first();

        $fromPerioada = now()->subDays($this->perioada)->toDateString();

        // top produse (perioadă + sortare comutabile)
        $topProduse = $this->whereZile(DB::table('winmentor_vanzari_raw'), $fromPerioada)
            ->where('cantitate', '>', 0)->whereNotNull('den_articol')->where('den_articol', '!=', '')
            ->whereRaw("den_articol NOT REGEXP 'SERVICII|TRANSPORT|AVANS|TAXA|ACHITAT|TRANSFER|REFACTURAT|Utilitati|Deseu|PALET'")
            ->groupBy('den_articol')
            ->selectRaw('den_articol, MAX(sku) sku, ROUND(SUM(cantitate)) buc, ROUND(SUM(cantitate*pret)) lei')
            ->orderByDesc(DB::raw($this->sortTop === 'buc' ? 'SUM(cantitate)' : 'SUM(cantitate*pret)'))
            ->limit(10)->get();

        // stoc ERP în timp real pentru top — interogare separată, doar 10 SKU-uri
        $stocuri = DB::table('woo_products as p')
            ->leftJoin('product_stocks as s', 's.woo_product_id', '=', 'p.id')
            ->whereIn('p.sku', $topProduse->pluck('sku')->filter()->all())
            ->groupBy('p.sku')
            ->selectRaw('p.sku, SUM(s.quantity) stoc')->pluck('stoc', 'sku');
        $topProduse->each(fn ($p) => $p->stoc = $stocuri->has($p->sku) ? (float) $stocuri[$p->sku] : null);

        // top clienți (aceeași perioadă)
        $topClienti = $this->whereZile(DB::table('winmentor_vanzari_raw as v'), $fromPerioada)
            ->leftJoin('winmentor_parteneri as wp', 'wp.wm_id', '=', 'v.part_id')
            ->where('v.cantitate', '>', 0)
            ->whereNotNull('wp.denumire')
            ->whereRaw("wp.denumire NOT REGEXP 'PERSOANE FIZICE|DIVERSI'")
            ->groupBy('wp.denumire')
            ->selectRaw('wp.denumire, ROUND(SUM(v.cantitate*v.pret)) lei, COUNT(DISTINCT v.nr_factura) facturi')
            ->orderByDesc(DB::raw('SUM(v.cantitate*v.pret)'))->limit(10)->get();

        // detaliu client: ultimele facturi
        $detaliuClient = null;
        if ($this->clientSelectat) {
            $detaliuClient = $this->whereZile(DB::table('winmentor_vanzari_raw as v'), now()->subDays(90)->toDateString())
                ->join('winmentor_parteneri as wp', 'wp.wm_id', '=', 'v.part_id')
                ->where('wp.denumire', $this->clientSelectat)
                ->where('v.cantitate', '>', 0)
                ->groupBy('v.nr_factura', 'v.an', 'v.luna', 'v.zi')
                ->selectRaw("v.nr_factura, CONCAT(LPAD(v.zi,2,'0'),'.',LPAD(v.luna,2,'0'),'.',v.an) data_fact, ROUND(SUM(v.cantitate*v.pret)) lei, COUNT(*) linii")
                ->orderByDesc(DB::raw('v.an*10000 + v.luna*100 + v.zi'))->limit(8)->get();
        }

        // alerte stoc
        $alerteStoc = $this->whereZile(DB::table('winmentor_vanzari_raw as v'), now()->subDays(14)->toDateString())
            ->join('woo_products as p', fn ($j) => $j->on('p.sku', '=', 'v.sku'))
            ->leftJoin('product_stocks as s', 's.woo_product_id', '=', 'p.id')
            ->where('v.cantitate', '>', 0)
            ->groupBy('p.id', 'p.name')
            ->havingRaw('COALESCE(SUM(DISTINCT s.quantity), 0) <= 0')
            ->selectRaw('p.id pid, p.name, ROUND(SUM(v.cantitate)) buc_14z, ROUND(SUM(v.cantitate*v.pret)) lei_14z')
            ->orderByDesc(DB::raw('SUM(v.cantitate*v.pret)'))->limit(10)->get();

        // sistemul
        $produsePublicate = DB::table('woo_products')->where('status', 'publish')->count();
        $preturiAzi = DB::table('product_price_logs')->whereDate('created_at', $today)->count();
        $ultimSync = DB::table('sync_runs')->orderByDesc('id')->value('created_at');

        return [
            'aziLei' => (float) ($azi->lei ?? 0), 'aziDoc' => (int) ($azi->documente ?? 0),
            'ieriLei' => (float) ($ieri->lei ?? 0),
            'saptLei' => $sapt, 'saptTrecutaLei' => $saptTrecuta,
            'lunaLei' => $luna, 'lunaTrecutaLaZiLei' => $lunaTrecutaLaZi,
            'zileGrafic' => $zileGrafic,
            'detaliuZi' => $detaliuZi,
            'procesare' => $procesare,
            'onlineAziC' => (int) ($onlineAzi->c ?? 0), 'onlineAziLei' => (float) ($onlineAzi->lei ?? 0),
            'comenziRecente' => $comenziRecente,
            'inLivrare' => $inLivrare,
            'avgLivrare' => $avgLivrare === null ? null : ($avgLivrare < 48 ? round($avgLivrare) . 'h' : number_format($avgLivrare / 24, 1, ',', '') . ' zile'),
            'codPendingC' => (int) ($codPending->c ?? 0), 'codPendingS' => (float) ($codPending->s ?? 0),
            'topProduse' => $topProduse,
            'topClienti' => $topClienti,
            'detaliuClient' => $detaliuClient,
            'alerteStoc' => $alerteStoc,
            'produsePublicate' => $produsePublicate,
            'preturiAzi' => $preturiAzi,
            'ultimSync' => $ultimSync,
        ];
    }
}
