<?php

namespace App\Filament\App\Pages;
use App\Models\RolePermission;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class NecesarMarfa extends Page
{
    use HasDynamicNavSort;

    use EnforcesLocationScope;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-shopping-cart';
    protected static string|\UnitEnum|null $navigationGroup = 'Rapoarte';
    protected static ?string $navigationLabel = 'Necesar de marfă';
    protected static ?int    $navigationSort  = 0;

    protected string $view = 'filament.app.pages.necesar-marfa';

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return RolePermission::check(static::class, 'can_access');
    }

    #[Url]
    public int $coverDays = 14;

    #[Url]
    public ?int $selectedSupplierId = null;

    /** @var Collection<int, object> Produse cu stoc < 7 zile (toate furnizoarele) */
    public Collection $urgentProducts;

    /** @var Collection<int, object> Produse cu stoc 7–14 zile (toate furnizoarele) */
    public Collection $soonProducts;

    /** @var Collection<int, object> Furnizori grupați cu produsele lor */
    public Collection $suppliers;

    public int $statSuppliers = 0;
    public int $statProducts  = 0;
    public int $statZeroStock = 0;
    public int $statUrgent    = 0;
    public int $statSoon      = 0;

    public function mount(): void
    {
        $this->urgentProducts = collect();
        $this->soonProducts   = collect();
        $this->suppliers      = collect();
        $this->load();
    }

    public function updatedCoverDays(): void
    {
        $this->coverDays = max(7, min(60, (int) $this->coverDays));
        $this->invalidateCache();
        $this->load();
    }

    public function invalidateCache(): void
    {
        Cache::forget('necesar_marfa_' . auth()->id() . '_' . $this->coverDays);
    }

    private function load(): void
    {
        $coverDays = max(7, min(60, $this->coverDays));
        // Fetch always at least 14 days so "Epuizare 7-14 zile" tab is populated
        $fetchDays = max($coverDays, 14);

        $cacheKey = 'necesar_marfa_' . auth()->id() . '_' . $coverDays;

        $rows = Cache::remember($cacheKey, 300, function () use ($coverDays, $fetchDays) {
            $stkSub = DB::raw('(SELECT woo_product_id, COALESCE(SUM(quantity),0) as total_qty
                                 FROM product_stocks GROUP BY woo_product_id) stk');

            $selectFields = [
                'wp.id      as product_id',
                'wp.sku',
                'wp.name',
                'wp.brand',
                'wp.unit',
                DB::raw('COALESCE(stk.total_qty, 0) as stock'),
                DB::raw('EXISTS(SELECT 1 FROM daily_stock_metrics dsm WHERE dsm.woo_product_id = wp.id) as has_history'),
                DB::raw('COALESCE(bpv.out_qty_7d, 0)     as consumed_7d'),
                DB::raw('COALESCE(bpv.avg_out_qty_7d, 0)  as avg_daily_7d'),
                DB::raw('COALESCE(bpv.avg_out_qty_30d, 0) as avg_daily_30d'),
                DB::raw('COALESCE(bpv.avg_out_qty_90d, 0) as avg_daily_90d'),
            ];

            // -------- Produse cu furnizor activ --------
            $rowsWithSupplier = DB::table('product_suppliers as ps')
                ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
                ->join('woo_products as wp', 'wp.id', '=', 'ps.woo_product_id')
                ->leftJoin($stkSub, 'stk.woo_product_id', '=', 'wp.id')
                ->leftJoin('bi_product_velocity_current as bpv', 'bpv.reference_product_id', '=', 'wp.sku')
                ->where('s.is_active', true)
                ->where('wp.is_discontinued', false)
                ->whereRaw("COALESCE(wp.procurement_type, 'stock') != 'on_demand'")
                ->whereRaw("COALESCE(stk.total_qty, 0) < GREATEST(
                    COALESCE(bpv.avg_out_qty_7d, 0),
                    COALESCE(bpv.avg_out_qty_30d, 0)
                ) * ?", [$fetchDays])
                ->select(array_merge([
                    's.id       as supplier_id',
                    's.name     as supplier_name',
                    's.logo_url as supplier_logo',
                    'ps.is_preferred',
                ], $selectFields))
                ->orderByDesc('ps.is_preferred')
                ->orderBy('s.name')
                ->orderBy(DB::raw('COALESCE(stk.total_qty, 0)'))
                ->orderBy('wp.name')
                ->get();

            // -------- Produse fără furnizor activ (cu mișcare reală) --------
            $rowsNoSupplier = DB::table('woo_products as wp')
                ->leftJoin($stkSub, 'stk.woo_product_id', '=', 'wp.id')
                ->leftJoin('bi_product_velocity_current as bpv', 'bpv.reference_product_id', '=', 'wp.sku')
                ->where('wp.is_discontinued', false)
                ->whereRaw("COALESCE(wp.procurement_type, 'stock') != 'on_demand'")
                ->whereRaw("COALESCE(stk.total_qty, 0) < GREATEST(
                    COALESCE(bpv.avg_out_qty_7d, 0),
                    COALESCE(bpv.avg_out_qty_30d, 0)
                ) * ?", [$fetchDays])
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('product_suppliers as ps2')
                      ->join('suppliers as s2', 's2.id', '=', 'ps2.supplier_id')
                      ->whereColumn('ps2.woo_product_id', 'wp.id')
                      ->where('s2.is_active', true);
                })
                ->select(array_merge([
                    DB::raw('0 as supplier_id'),
                    DB::raw("'Fără furnizor' as supplier_name"),
                    DB::raw('NULL as supplier_logo'),
                    DB::raw('0 as is_preferred'),
                ], $selectFields))
                ->orderBy(DB::raw('COALESCE(stk.total_qty, 0)'))
                ->orderBy('wp.name')
                ->get();

            return $rowsWithSupplier->merge($rowsNoSupplier);
        });

        // Calcul dinamic: trend 7d/30d aplicat pe baseline 90d + safety stock + acoperire configurabilă
        $rows = $rows->map(function ($p) use ($coverDays) {
            $avg7  = (float) $p->avg_daily_7d;
            $avg30 = (float) $p->avg_daily_30d;
            $avg90 = (float) $p->avg_daily_90d;
            $stock = (float) $p->stock;

            // Baseline: MAX dintre cele trei medii.
            // avg_30d și avg_90d sunt diluate când avem <30/<90 zile de date reale
            // (ex: 10 vândute în 7 zile → avg_7d=1.43, avg_30d=0.33, avg_90d=0.11)
            // MAX asigură că nu subestimăm din cauza perioadei incomplete.
            $base = max($avg7, $avg30, $avg90);

            // Trend: comparăm 7d cu 30d doar pentru atenuare (cerere în scădere clară).
            // Nu amplificăm avg_7d — ar dubla un semnal deja corect.
            // Odată cu acumularea datelor, avg_30d va deveni stabil și trendul va fi semnificativ.
            $trend = 1.0;
            if ($avg30 > 0 && $avg7 > 0 && $avg7 < ($avg30 * 0.85)) {
                // Cerere în scădere >15% față de media 30d → atenuăm conservator
                $trend = max(0.5, $avg7 / $avg30);
            }

            // Direcție trend pentru afișare (informativă, independentă de calcul)
            $trendDirection = 0; // neutru
            if ($avg30 > 0) {
                if ($avg7 > $avg30 * 1.15) $trendDirection = 1;  // ↑ accelerare >15%
                elseif ($avg7 < $avg30 * 0.85) $trendDirection = -1; // ↓ scădere >15%
            }

            $adjustedDaily = $base * $trend;
            $safetyStock   = $adjustedDaily * 3; // buffer 3 zile

            $p->trend_factor        = $trend;
            $p->trend_direction     = $trendDirection;
            $p->adjusted_daily      = $adjustedDaily;
            $p->days_until_stockout = $avg7 > 0 ? $stock / $avg7 : null;
            $p->recommended_qty     = $adjustedDaily > 0
                ? max(0, (int) ceil($adjustedDaily * $coverDays + $safetyStock - $stock))
                : null;

            return $p;
        });

        // Urgențe: < 7 zile stoc (inclusiv fără furnizor), un rând per produs
        $this->urgentProducts = $rows
            ->filter(fn ($p) => $p->days_until_stockout !== null && $p->days_until_stockout < 7)
            ->unique('product_id')
            ->sortBy('days_until_stockout')
            ->values();

        // Curând: 7-14 zile stoc, un rând per produs
        $this->soonProducts = $rows
            ->filter(fn ($p) => $p->days_until_stockout !== null && $p->days_until_stockout >= 7 && $p->days_until_stockout < 14)
            ->unique('product_id')
            ->sortBy('days_until_stockout')
            ->values();

        // Furnizori grupați (inclusiv "Fără furnizor" cu id=0)
        $this->suppliers = $rows
            ->groupBy('supplier_id')
            ->map(function (Collection $items) {
                $first    = $items->first();
                $products = $items->unique('product_id')->values();
                $withHistory = $products->filter(fn ($p) => (bool) $p->has_history)->values();
                return (object) [
                    'id'            => $first->supplier_id,
                    'name'          => $first->supplier_name,
                    'logo'          => $first->supplier_logo,
                    'products'      => $withHistory,
                    'extraProducts' => $products->filter(fn ($p) => !(bool) $p->has_history)->values(),
                    'urgent_count'  => $withHistory->filter(fn ($p) => $p->days_until_stockout !== null && $p->days_until_stockout < 7)->count(),
                    'soon_count'    => $withHistory->filter(fn ($p) => $p->days_until_stockout !== null && $p->days_until_stockout >= 7 && $p->days_until_stockout < 14)->count(),
                    'zero_count'    => $products->where('stock', 0)->count(),
                ];
            })
            ->values()
            ->sortBy(fn ($s) => (int) $s->id === 0 ? 'ZZZZZ' : $s->name);

        $allProducts = $rows->unique('product_id');

        $this->statSuppliers = $this->suppliers->filter(fn ($s) => (int) $s->id > 0)->count();
        $this->statProducts  = $allProducts->count();
        $this->statZeroStock = $allProducts->where('stock', 0)->count();
        $this->statUrgent    = $this->urgentProducts->count();
        $this->statSoon      = $this->soonProducts->count();
    }

    /**
     * Creează câte un PurchaseRequest per furnizor din selecția utilizatorului.
     * $items = [{product_id, supplier_id, qty}, ...]
     */
    public function createNecesarFromSelection(array $items): void
    {
        $items = collect($items)->filter(fn ($i) => !empty($i['product_id']));

        if ($items->isEmpty()) {
            return;
        }

        $withSupplier    = $items->filter(fn ($i) => (int) ($i['supplier_id'] ?? 0) > 0);
        $withoutSupplier = $items->count() - $withSupplier->count();

        if ($withSupplier->isEmpty()) {
            Notification::make()
                ->title('Niciun produs cu furnizor selectat')
                ->warning()->send();
            return;
        }

        $bySupplier      = $withSupplier->groupBy('supplier_id');
        $requestNumbers  = [];

        DB::transaction(function () use ($bySupplier, &$requestNumbers): void {
            foreach ($bySupplier as $supplierId => $supplierItems) {
                $request = PurchaseRequest::create([
                    'status' => PurchaseRequest::STATUS_SUBMITTED,
                    'notes'  => 'Generat din Necesar Marfă — ' . now()->format('d.m.Y H:i'),
                ]);

                foreach ($supplierItems as $item) {
                    $qty = max(1, (int) round((float) ($item['qty'] ?? 1)));

                    PurchaseRequestItem::create([
                        'purchase_request_id' => $request->id,
                        'woo_product_id'      => (int) $item['product_id'],
                        'supplier_id'         => (int) $item['supplier_id'],
                        'quantity'            => $qty,
                        'status'              => PurchaseRequestItem::STATUS_PENDING,
                    ]);
                }

                $requestNumbers[] = $request->number;
            }
        });

        $supplierCount = count($requestNumbers);
        $productCount  = $withSupplier->count();

        Notification::make()
            ->title($supplierCount . ' ' . ($supplierCount === 1 ? 'necesar creat' : 'necesare create') . " — {$productCount} produse")
            ->body(implode(', ', $requestNumbers))
            ->success()->send();

        if ($withoutSupplier > 0) {
            Notification::make()
                ->title("{$withoutSupplier} " . ($withoutSupplier === 1 ? 'produs omis' : 'produse omise') . ' (fără furnizor)')
                ->warning()->send();
        }

        $this->redirect(BuyerDashboardPage::getUrl());
    }

    public function bootGuardAccess(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }
}
