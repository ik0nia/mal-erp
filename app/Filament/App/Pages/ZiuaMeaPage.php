<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Models\ErrorEvent;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SamedayAwb;
use App\Models\WooOrder;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard „Ziua mea" — centru de comandă personal: la intrare vezi exact
 * ce-ți cere atenția azi (aprobări, necesare, expedieri, stoc critic), cu
 * butonul de acțiune lângă. Cifrele sunt trase din date reale.
 * Momentan vizibil DOAR pentru codrut@ikonia.ro (pilot, ca Puls).
 */
class ZiuaMeaPage extends Page
{
    use HasDynamicNavSort;

    protected string $view = 'filament.app.pages.ziua-mea';

    protected static ?string $navigationLabel = 'Ziua mea';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';
    protected static ?int $navigationSort = -1;
    protected static ?string $title = 'Ziua mea';
    protected static ?string $slug = 'ziua-mea';

    private const PILOT_EMAILS = ['codrut@ikonia.ro'];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->email, self::PILOT_EMAILS, true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Numele mic pentru salut. */
    public function getPrenume(): string
    {
        $name = (string) (auth()->user()?->name ?? '');
        return trim(explode(' ', $name)[0] ?? '') ?: 'șefu';
    }

    /** Salut în funcție de oră. */
    public function getSalut(): string
    {
        $h = (int) now()->format('H');
        return $h < 12 ? 'Bună dimineața' : ($h < 18 ? 'Bună ziua' : 'Bună seara');
    }

    /** Toate cifrele dashboardului, cache 2 min. */
    public function getData(): array
    {
        return Cache::remember('ziua_mea_' . auth()->id(), 120, function () {
            $num = fn (callable $cb) => (int) rescue($cb, 0, false);

            // ---- KPI-uri de sus ----
            $vanzariAzi = rescue(fn () => (float) DB::table('winmentor_vanzari_raw')
                ->whereDate('data_emitere', now()->toDateString())
                ->sum('lei_cu_tva'), 0.0, false);

            $comenziProcesare = $num(fn () => WooOrder::where('status', 'processing')->count());

            $poInLucru = $num(fn () => PurchaseOrder::whereIn('status', [
                PurchaseOrder::STATUS_PENDING_APPROVAL,
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])->count());

            $necesareDeProcesat = $num(fn () => PurchaseRequest::whereIn('status', [
                PurchaseRequest::STATUS_SUBMITTED,
                PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ])->count());

            // ---- Cozile de acțiune ----
            $poDeAprobat      = $num(fn () => PurchaseOrder::where('status', PurchaseOrder::STATUS_PENDING_APPROVAL)->count());
            $poDeAprobatValoare = rescue(fn () => (float) PurchaseOrder::where('status', PurchaseOrder::STATUS_PENDING_APPROVAL)->sum('total'), 0.0, false);

            $poDeReceptionat  = $num(fn () => PurchaseOrder::whereIn('status', [
                PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])->count());

            $comenziDeExpediat = $num(fn () => WooOrder::where('status', 'processing')
                ->whereDoesntHave('samedayAwbs', fn ($q) => $q->where('status', SamedayAwb::STATUS_CREATED))
                ->count());

            $awbInTranzit = $num(fn () => SamedayAwb::where('status', SamedayAwb::STATUS_CREATED)->count());

            // Produse critice: stoc zero DAR care chiar se vând (avg_out_30d > 0) — nu tot catalogul.
            [$criticeVand, $criticeTotal] = rescue(function () {
                $day = DB::table('bi_inventory_alert_candidates_daily')->max('day');
                if (! $day) return [0, 0];
                $vand  = (int) DB::table('bi_inventory_alert_candidates_daily')
                    ->where('day', $day)->where('risk_level', 'P0')->where('avg_out_30d', '>', 0)->count();
                $total = (int) DB::table('bi_inventory_alert_candidates_daily')
                    ->where('day', $day)->where('risk_level', 'P0')->count();
                return [$vand, $total];
            }, [0, 0], false);

            $eroriDeschise = $num(fn () => ErrorEvent::where('status', 'open')->count());

            // ---- URL-uri (rezolvate o dată) ----
            $url = fn (string $cls, string $page = 'index', array $p = []) => rescue(fn () => $cls::getUrl($page, $p), null, false);
            $poUrl       = $url(\App\Filament\App\Resources\PurchaseOrderResource::class);
            $prUrl       = $url(\App\Filament\App\Resources\PurchaseRequestResource::class);
            $wooUrl      = $url(\App\Filament\App\Resources\WooOrderResource::class);
            $awbUrl      = $url(\App\Filament\App\Resources\SamedayAwbResource::class);
            $necesarUrl  = rescue(fn () => NecesarMarfa::getUrl(), null, false);

            $actiuni = [
                [
                    'key' => 'aprobare', 'icon' => '✅', 'color' => '#dc2626',
                    'count' => $poDeAprobat, 'label' => 'PO de aprobat',
                    'sub' => $poDeAprobat ? 'valoare totală ' . number_format($poDeAprobatValoare, 0, ',', '.') . ' lei · așteaptă decizia ta' : 'nimic de aprobat',
                    'url' => $poUrl,
                ],
                [
                    'key' => 'necesar', 'icon' => '📝', 'color' => '#b45309',
                    'count' => $necesareDeProcesat, 'label' => 'Necesare de transformat în PO',
                    'sub' => $necesareDeProcesat ? 'cereri trimise, gata de comandat' : 'coada e goală',
                    'url' => $prUrl,
                ],
                [
                    'key' => 'expediat', 'icon' => '📦', 'color' => '#1d4ed8',
                    'count' => $comenziDeExpediat, 'label' => 'Comenzi de expediat',
                    'sub' => $comenziDeExpediat ? 'în procesare, fără AWB emis' : 'toate au AWB',
                    'url' => $wooUrl,
                ],
                [
                    'key' => 'critic', 'icon' => '⛔', 'color' => '#be123c',
                    'count' => $criticeVand, 'label' => 'Produse care se vând, pe stoc zero',
                    'sub' => $criticeTotal ? 'din ' . number_format($criticeTotal, 0, ',', '.') . ' pe stoc zero, doar astea au vânzări reale' : 'niciunul',
                    'url' => $necesarUrl,
                ],
                [
                    'key' => 'receptie', 'icon' => '🚛', 'color' => '#0f766e',
                    'count' => $poDeReceptionat, 'label' => 'PO trimise, în așteptare recepție',
                    'sub' => $poDeReceptionat ? 'marfă comandată care urmează să vină' : 'nimic pe drum',
                    'url' => $poUrl,
                ],
                [
                    'key' => 'awb', 'icon' => '🚚', 'color' => '#7c3aed',
                    'count' => $awbInTranzit, 'label' => 'AWB-uri active',
                    'sub' => $awbInTranzit ? 'colete emise, în livrare' : 'niciun colet activ',
                    'url' => $awbUrl,
                ],
            ];

            // păstrează doar cele cu ceva de făcut, sortate desc după count; restul „liniște"
            $active = array_values(array_filter($actiuni, fn ($a) => $a['count'] > 0));
            usort($active, fn ($a, $b) => $b['count'] <=> $a['count']);
            $goale = array_values(array_filter($actiuni, fn ($a) => $a['count'] === 0));

            return [
                'kpi' => [
                    'vanzari_azi'  => $vanzariAzi,
                    'comenzi'      => $comenziProcesare,
                    'po_in_lucru'  => $poInLucru,
                    'necesare'     => $necesareDeProcesat,
                ],
                'actiuni_active' => $active,
                'actiuni_goale'  => $goale,
                'erori'          => $eroriDeschise,
                'generat_la'     => now()->format('H:i'),
            ];
        });
    }
}
