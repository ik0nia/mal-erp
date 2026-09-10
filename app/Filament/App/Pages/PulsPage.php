<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\HasDynamicNavSort;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard „Pulsul afacerii" — cifre live din tot sistemul.
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

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->email, self::PILOT_EMAILS, true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getViewData(): array
    {
        $today = now()->toDateString();

        // vânzări WinMentor pe zile (linii de vânzare reale, preț de linie)
        $salesDay = fn (string $date) => DB::table('winmentor_vanzari_raw')
            ->whereRaw("STR_TO_DATE(CONCAT(an,'-',luna,'-',zi), '%Y-%m-%d') = ?", [$date])
            ->where('cantitate', '>', 0)
            ->selectRaw('ROUND(SUM(cantitate * pret)) lei, COUNT(DISTINCT nr_factura) documente')
            ->first();
        $azi = $salesDay($today);
        $ieri = $salesDay(now()->subDay()->toDateString());

        $salesRange = fn ($from, $to) => (float) DB::table('winmentor_vanzari_raw')
            ->whereRaw("STR_TO_DATE(CONCAT(an,'-',luna,'-',zi), '%Y-%m-%d') BETWEEN ? AND ?", [$from, $to])
            ->where('cantitate', '>', 0)
            ->selectRaw('COALESCE(SUM(cantitate * pret),0) lei')->value('lei');
        $sapt = $salesRange(now()->startOfWeek()->toDateString(), $today);
        $saptTrecuta = $salesRange(now()->subWeek()->startOfWeek()->toDateString(), now()->subWeek()->endOfWeek()->toDateString());

        // comenzi online
        $procesare = DB::table('woo_orders')->where('status', 'processing')->count();
        $onlineAzi = DB::table('woo_orders')->whereDate('order_date', $today)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) lei')->first();

        // AWB / livrări (aceleași criterii ca widgetul de pe comenzi)
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

        // top produse 7 zile (marfă reală)
        $topProduse = DB::table('winmentor_vanzari_raw')
            ->whereRaw("STR_TO_DATE(CONCAT(an,'-',luna,'-',zi), '%Y-%m-%d') >= ?", [now()->subDays(7)->toDateString()])
            ->where('cantitate', '>', 0)->whereNotNull('den_articol')->where('den_articol', '!=', '')
            ->whereRaw("den_articol NOT REGEXP 'SERVICII|TRANSPORT|AVANS|TAXA|ACHITAT|TRANSFER|REFACTURAT|Utilitati|Deseu'")
            ->groupBy('den_articol')
            ->selectRaw('den_articol, ROUND(SUM(cantitate)) buc, ROUND(SUM(cantitate*pret)) lei')
            ->orderByDesc(DB::raw('SUM(cantitate*pret)'))->limit(6)->get();

        // alerte: produse cu vânzări în 14 zile dar stoc total 0 în ERP
        $alerteStoc = DB::table('winmentor_vanzari_raw as v')
            ->join('woo_products as p', fn ($j) => $j->on('p.sku', '=', 'v.sku'))
            ->leftJoin('product_stocks as s', 's.woo_product_id', '=', 'p.id')
            ->whereRaw("STR_TO_DATE(CONCAT(v.an,'-',v.luna,'-',v.zi), '%Y-%m-%d') >= ?", [now()->subDays(14)->toDateString()])
            ->where('v.cantitate', '>', 0)
            ->groupBy('p.id', 'p.name')
            ->havingRaw('COALESCE(SUM(DISTINCT s.quantity), 0) <= 0')
            ->selectRaw('p.name, ROUND(SUM(v.cantitate)) buc_14z')
            ->orderByDesc(DB::raw('SUM(v.cantitate)'))->limit(6)->get();

        // activitate sistem
        $emailsAi = DB::table('email_messages')->whereNotNull('agent_processed_at')->count();
        $ultimSync = DB::table('sync_runs')->orderByDesc('id')->value('created_at');

        return [
            'aziLei' => (float) ($azi->lei ?? 0), 'aziDoc' => (int) ($azi->documente ?? 0),
            'ieriLei' => (float) ($ieri->lei ?? 0),
            'saptLei' => $sapt, 'saptTrecutaLei' => $saptTrecuta,
            'procesare' => $procesare,
            'onlineAziC' => (int) ($onlineAzi->c ?? 0), 'onlineAziLei' => (float) ($onlineAzi->lei ?? 0),
            'inLivrare' => $inLivrare,
            'avgLivrare' => $avgLivrare === null ? null : ($avgLivrare < 48 ? round($avgLivrare) . 'h' : number_format($avgLivrare / 24, 1, ',', '') . ' zile'),
            'codPendingC' => (int) ($codPending->c ?? 0), 'codPendingS' => (float) ($codPending->s ?? 0),
            'topProduse' => $topProduse,
            'alerteStoc' => $alerteStoc,
            'emailsAi' => $emailsAi,
            'ultimSync' => $ultimSync,
        ];
    }
}
