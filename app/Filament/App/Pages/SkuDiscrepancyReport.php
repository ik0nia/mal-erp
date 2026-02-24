<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SkuDiscrepancyReport extends Page implements HasTable
{
    use InteractsWithTable;
    use EnforcesLocationScope;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Magazin Online';

    protected static ?string $navigationLabel = 'Discrepanțe SKU';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.app.pages.sku-discrepancy-report';

    public string $activeTab = 'placeholder';

    public int $statPlaceholder = 0;

    public int $statPlaceholderWithStock = 0;

    public int $statNoSku = 0;

    public int $statOnSiteNoMentor = 0;

    public function mount(): void
    {
        $this->computeStats();
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $connectionIds = $this->getConnectionIds();
        $activeTab = $this->activeTab;

        return $table
            ->query(
                WooProduct::query()
                    ->with(['stocks'])
                    ->whereIn('connection_id', $connectionIds ?: [0])
                    ->when(
                        $activeTab === 'placeholder',
                        fn (Builder $q) => $q->where('is_placeholder', true)
                    )
                    ->when(
                        $activeTab === 'no_sku',
                        fn (Builder $q) => $q
                            ->where('is_placeholder', false)
                            ->where(fn (Builder $inner) => $inner->whereNull('sku')->orWhere('sku', ''))
                    )
                    ->when(
                        $activeTab === 'no_mentor',
                        fn (Builder $q) => $q
                            ->where('is_placeholder', false)
                            ->whereNotNull('sku')
                            ->where('sku', '!=', '')
                            ->doesntHave('stocks')
                    )
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->placeholder('-')
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Denumire')
                    ->formatStateUsing(fn (WooProduct $record): string => $record->decoded_name)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('price')
                    ->label('Preț WinMentor')
                    ->placeholder('-'),
                TextColumn::make('stock_status')
                    ->label('Stoc site')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'instock' ? 'success' : 'danger'),
                TextColumn::make('source')
                    ->label('Sursă')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        WooProduct::SOURCE_WOOCOMMERCE => 'WooCommerce',
                        WooProduct::SOURCE_WINMENTOR_CSV => 'WinMentor',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        WooProduct::SOURCE_WOOCOMMERCE => 'success',
                        WooProduct::SOURCE_WINMENTOR_CSV => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }

    private function computeStats(): void
    {
        $connectionIds = $this->getConnectionIds();

        if ($connectionIds === []) {
            return;
        }

        $this->statPlaceholder = WooProduct::query()
            ->whereIn('connection_id', $connectionIds)
            ->where('is_placeholder', true)
            ->count();

        $this->statPlaceholderWithStock = WooProduct::query()
            ->whereIn('connection_id', $connectionIds)
            ->where('is_placeholder', true)
            ->whereHas('stocks', fn (Builder $q) => $q->where('quantity', '>', 0))
            ->count();

        $this->statNoSku = WooProduct::query()
            ->whereIn('connection_id', $connectionIds)
            ->where('is_placeholder', false)
            ->where(fn (Builder $q) => $q->whereNull('sku')->orWhere('sku', ''))
            ->count();

        $this->statOnSiteNoMentor = WooProduct::query()
            ->whereIn('connection_id', $connectionIds)
            ->where('is_placeholder', false)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->doesntHave('stocks')
            ->count();
    }

    /** @return array<int, int> */
    private function getConnectionIds(): array
    {
        $user = static::currentUser();

        if (! $user) {
            return [];
        }

        $query = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE);

        if (! $user->isSuperAdmin()) {
            $query->whereIn('location_id', $user->operationalLocationIds());
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
