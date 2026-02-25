<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Resources\WooProductResource;
use App\Models\DailyStockMetric;
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

    public function getTableRecordKey(Model $record): string
    {
        return (string) $record->woo_product_id;
    }

    public function setDays(int $days): void
    {
        $this->days = $days;
        $this->dispatch('stockMovementsSetDays', days: $days);
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $days = $this->days;

        return $table
            ->query(
                DailyStockMetric::query()
                    ->with('product')
                    ->select([
                        'woo_product_id',
                        DB::raw('SUM(ABS(daily_total_variation)) as total_movement'),
                        DB::raw('SUM(daily_total_variation) as net_change'),
                        DB::raw('MAX(closing_total_qty) as last_qty'),
                        DB::raw('MAX(closing_sell_price) as last_price'),
                    ])
                    ->where('day', '>=', now()->subDays($days - 1)->toDateString())
                    ->whereNotNull('woo_product_id')
                    ->where('daily_total_variation', '!=', 0)
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
            ])
            ->recordUrl(fn (DailyStockMetric $record): ?string => $record->woo_product_id
                ? WooProductResource::getUrl('view', ['record' => $record->woo_product_id])
                : null)
            ->paginated(false)
            ->striped();
    }
}
