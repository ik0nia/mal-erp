<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Resources\WooProductResource;
use App\Models\DailyStockMetric;
use App\Models\Supplier;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockMovementsReport extends Page implements HasTable
{
    use InteractsWithTable;
    use EnforcesLocationScope;

    public static function canAccess(): bool
    {
        $user = static::currentUser();

        return $user !== null && (
            $user->isSuperAdmin() || $user->role === User::ROLE_MANAGER
        );
    }

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationGroup = 'Rapoarte';

    protected static ?string $navigationLabel = 'Mișcări stocuri';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.app.pages.stock-movements-report';

    public int $days = 7;

    public ?int $supplierId = null;

    public ?int $categoryId = null;

    public ?string $categoryName = null;

    /** @var array<int, array{id: int, name: string, products: int, in_qty: float, out_qty: float, value: float}> */
    public array $categoryStats = [];

    public int $statTotalInQty = 0;

    public int $statTotalOutQty = 0;

    public float $statTotalInValue = 0.0;

    public float $statTotalOutValue = 0.0;

    public int $statProductsWithMovement = 0;

    public int $statProductsWithPriceChange = 0;

    /** @var array<int, array{id: int, name: string, products: int, in_qty: float, out_qty: float, value: float}> */
    public array $supplierStats = [];

    /** @var array<int, string> */
    public array $supplierOptions = [];

    public function mount(): void
    {
        $this->supplierOptions = Supplier::where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();

        $this->computeStats();
        $this->computeCategoryStats();
    }

    public function getTableRecordKey(Model $record): string
    {
        return (string) $record->woo_product_id;
    }

    public function setDays(int $days): void
    {
        $this->days = $days;
        $this->dispatch('stockMovementsSetDays', days: $days);
        $this->resetTable();
        $this->computeStats();
        $this->computeCategoryStats();
    }

    public function setCategory(?int $categoryId): void
    {
        $this->categoryId   = $categoryId;
        $this->categoryName = $categoryId
            ? DB::table('woo_categories')->where('id', $categoryId)->value('name')
            : null;
        $this->dispatch('stockMovementsSetCategory', categoryId: $categoryId);
        $this->resetTable();
        $this->computeStats();
    }

    public function setSupplier(?int $supplierId): void
    {
        $this->supplierId = $supplierId;
        $this->dispatch('stockMovementsSetSupplier', supplierId: $supplierId);
        $this->resetTable();
        $this->computeStats();
        $this->computeCategoryStats();
    }

    private function computeStats(): void
    {
        $from = now()->subDays($this->days - 1)->toDateString();

        $baseQuery = DailyStockMetric::query()
            ->where('day', '>=', $from)
            ->whereNotNull('woo_product_id')
            ->where('daily_total_variation', '!=', 0);

        if ($this->supplierId) {
            $baseQuery->whereIn('woo_product_id', function ($q) {
                $q->select('woo_product_id')
                  ->from('product_suppliers')
                  ->where('supplier_id', $this->supplierId);
            });
        }

        if ($this->categoryId) {
            $categoryProductIds = $this->categoryProductIds($this->categoryId);
            $baseQuery->whereIn('woo_product_id', $categoryProductIds);
        }

        $row = (clone $baseQuery)
            ->selectRaw('
                SUM(CASE WHEN daily_total_variation > 0 THEN daily_total_variation ELSE 0 END) as total_in_qty,
                SUM(CASE WHEN daily_total_variation < 0 THEN ABS(daily_total_variation) ELSE 0 END) as total_out_qty,
                SUM(CASE WHEN daily_total_variation > 0 THEN daily_total_variation * COALESCE(closing_sell_price, 0) ELSE 0 END) as total_in_value,
                SUM(CASE WHEN daily_total_variation < 0 THEN ABS(daily_total_variation) * COALESCE(closing_sell_price, 0) ELSE 0 END) as total_out_value,
                COUNT(DISTINCT CASE WHEN daily_total_variation != 0 THEN woo_product_id END) as products_with_movement,
                COUNT(DISTINCT CASE WHEN closing_sell_price != opening_sell_price AND opening_sell_price IS NOT NULL AND closing_sell_price IS NOT NULL THEN woo_product_id END) as products_with_price_change
            ')
            ->first();

        $this->statTotalInQty              = (int) ($row->total_in_qty ?? 0);
        $this->statTotalOutQty             = (int) ($row->total_out_qty ?? 0);
        $this->statTotalInValue            = round((float) ($row->total_in_value ?? 0), 2);
        $this->statTotalOutValue           = round((float) ($row->total_out_value ?? 0), 2);
        $this->statProductsWithMovement    = (int) ($row->products_with_movement ?? 0);
        $this->statProductsWithPriceChange = (int) ($row->products_with_price_change ?? 0);

        // Statistici per furnizor
        $this->supplierStats = DB::table('daily_stock_metrics as dsm')
            ->join('product_suppliers as ps', 'ps.woo_product_id', '=', 'dsm.woo_product_id')
            ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->where('dsm.day', '>=', $from)
            ->where('dsm.daily_total_variation', '!=', 0)
            ->when($this->supplierId, fn ($q) => $q->where('s.id', $this->supplierId))
            ->when($this->categoryId, fn ($q) => $q->whereIn('dsm.woo_product_id', $this->categoryProductIds($this->categoryId)))
            ->select([
                's.id',
                's.name',
                DB::raw('COUNT(DISTINCT dsm.woo_product_id) as products'),
                DB::raw('SUM(CASE WHEN dsm.daily_total_variation > 0 THEN dsm.daily_total_variation ELSE 0 END) as in_qty'),
                DB::raw('SUM(CASE WHEN dsm.daily_total_variation < 0 THEN ABS(dsm.daily_total_variation) ELSE 0 END) as out_qty'),
                DB::raw('SUM(ABS(dsm.daily_total_variation) * COALESCE(dsm.closing_sell_price, 0)) as value'),
            ])
            ->groupBy('s.id', 's.name')
            ->orderByDesc('value')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'       => $r->id,
                'name'     => $r->name,
                'products' => (int) $r->products,
                'in_qty'   => (float) $r->in_qty,
                'out_qty'  => (float) $r->out_qty,
                'value'    => round((float) $r->value, 2),
            ])
            ->toArray();
    }

    public function table(Table $table): Table
    {
        $days = $this->days;
        $from = now()->subDays($days - 1)->toDateString();

        $supplierId = $this->supplierId;
        $categoryId = $this->categoryId;

        return $table
            ->query(
                DailyStockMetric::query()
                    ->with('product')
                    ->select([
                        'woo_product_id',
                        DB::raw('SUM(ABS(daily_total_variation)) as total_movement'),
                        DB::raw('SUM(daily_total_variation) as net_change'),
                        DB::raw('MAX(closing_total_qty) as last_qty'),
                        DB::raw("(SELECT closing_sell_price FROM daily_stock_metrics dsm2 WHERE dsm2.woo_product_id = daily_stock_metrics.woo_product_id AND dsm2.closing_sell_price IS NOT NULL AND dsm2.day >= '{$from}' ORDER BY dsm2.day DESC LIMIT 1) as last_price"),
                        DB::raw("(SELECT opening_sell_price FROM daily_stock_metrics dsm2 WHERE dsm2.woo_product_id = daily_stock_metrics.woo_product_id AND dsm2.opening_sell_price IS NOT NULL AND dsm2.day >= '{$from}' ORDER BY dsm2.day ASC LIMIT 1) as first_price"),
                    ])
                    ->where('day', '>=', $from)
                    ->whereNotNull('woo_product_id')
                    ->where('daily_total_variation', '!=', 0)
                    ->when($supplierId, fn ($q) => $q->whereIn('woo_product_id', function ($sub) use ($supplierId) {
                        $sub->select('woo_product_id')->from('product_suppliers')->where('supplier_id', $supplierId);
                    }))
                    ->when($categoryId, fn ($q) => $q->whereIn('woo_product_id', $this->categoryProductIds($categoryId)))
                    ->groupBy('woo_product_id')
                    ->orderByDesc(DB::raw('SUM(ABS(daily_total_variation))'))
                    ->limit(100)
            )
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produs')
                    ->formatStateUsing(fn (DailyStockMetric $record): string => $record->product?->decoded_name ?? '-')
                    ->wrap(),
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->placeholder('-'),
                TextColumn::make('total_movement')
                    ->label('Mișcare totală')
                    ->formatStateUsing(fn (DailyStockMetric $record): string => number_format((float) $record->total_movement, 0, '.', '').' buc')
                    ->description('intrări + ieșiri'),
                TextColumn::make('net_change')
                    ->label('Variație netă')
                    ->formatStateUsing(fn (DailyStockMetric $record): string => (
                        (float) $record->net_change >= 0 ? '+' : ''
                    ).number_format((float) $record->net_change, 0, '.', '').' buc')
                    ->color(fn (DailyStockMetric $record): string => (float) $record->net_change >= 0 ? 'success' : 'danger'),
                TextColumn::make('last_qty')
                    ->label('Stoc curent')
                    ->formatStateUsing(fn (DailyStockMetric $record): string => number_format((float) $record->last_qty, 0, '.', '').' buc'),
                TextColumn::make('last_price')
                    ->label('Preț actual')
                    ->formatStateUsing(fn (DailyStockMetric $record): string => $record->last_price
                        ? number_format((float) $record->last_price, 2, ',', '.').' lei'
                        : '-')
                    ->placeholder('-'),
                TextColumn::make('price_change')
                    ->label('Δ Preț')
                    ->state(fn (DailyStockMetric $record): ?float => ($record->last_price && $record->first_price)
                        ? round((float) $record->last_price - (float) $record->first_price, 2)
                        : null
                    )
                    ->formatStateUsing(fn (?float $state): string => $state === null
                        ? '-'
                        : (($state >= 0 ? '+' : '').number_format($state, 2, ',', '.').' lei')
                    )
                    ->color(fn (?float $state): string => match (true) {
                        $state === null || $state == 0.0 => 'gray',
                        $state > 0                      => 'success',
                        default                         => 'danger',
                    }),
            ])
            ->recordUrl(fn (DailyStockMetric $record): ?string => $record->woo_product_id
                ? WooProductResource::getUrl('view', ['record' => $record->woo_product_id])
                : null)
            ->paginated(false)
            ->striped();
    }

    private function computeCategoryStats(): void
    {
        $from = now()->subDays($this->days - 1)->toDateString();

        $this->categoryStats = DB::table('daily_stock_metrics as dsm')
            ->join('woo_product_category as wpc', 'wpc.woo_product_id', '=', 'dsm.woo_product_id')
            ->join('woo_categories as wc', 'wc.id', '=', 'wpc.woo_category_id')
            ->leftJoin('woo_categories as parent', 'parent.id', '=', 'wc.parent_id')
            ->leftJoin('woo_categories as gp', 'gp.id', '=', 'parent.parent_id')
            ->where('dsm.day', '>=', $from)
            ->where('dsm.daily_total_variation', '!=', 0)
            ->whereNotNull('dsm.woo_product_id')
            ->when($this->supplierId, fn ($q) => $q->whereIn('dsm.woo_product_id', function ($sub) {
                $sub->select('woo_product_id')->from('product_suppliers')->where('supplier_id', $this->supplierId);
            }))
            ->select([
                DB::raw('COALESCE(gp.id, parent.id, wc.id) as top_id'),
                DB::raw('COALESCE(gp.name, parent.name, wc.name) as top_name'),
                DB::raw('COUNT(DISTINCT dsm.woo_product_id) as products'),
                DB::raw('SUM(CASE WHEN dsm.daily_total_variation > 0 THEN dsm.daily_total_variation ELSE 0 END) as in_qty'),
                DB::raw('SUM(CASE WHEN dsm.daily_total_variation < 0 THEN ABS(dsm.daily_total_variation) ELSE 0 END) as out_qty'),
                DB::raw('SUM(ABS(dsm.daily_total_variation) * COALESCE(dsm.closing_sell_price, 0)) as value'),
            ])
            ->groupBy('top_id', 'top_name')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($r) => [
                'id'       => (int) $r->top_id,
                'name'     => $r->top_name,
                'products' => (int) $r->products,
                'in_qty'   => (float) $r->in_qty,
                'out_qty'  => (float) $r->out_qty,
                'value'    => round((float) $r->value, 2),
            ])
            ->toArray();
    }

    private function categoryProductIds(int $categoryId): array
    {
        return DB::table('woo_product_category as wpc')
            ->join('woo_categories as wc', 'wc.id', '=', 'wpc.woo_category_id')
            ->leftJoin('woo_categories as p', 'p.id', '=', 'wc.parent_id')
            ->leftJoin('woo_categories as gp', 'gp.id', '=', 'p.parent_id')
            ->whereRaw('COALESCE(gp.id, p.id, wc.id) = ?', [$categoryId])
            ->pluck('wpc.woo_product_id')
            ->toArray();
    }
}
