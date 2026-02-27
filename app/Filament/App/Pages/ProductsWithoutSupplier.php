<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsWithoutSupplier extends Page implements HasTable
{
    use InteractsWithTable;
    use EnforcesLocationScope;

    protected static ?string $navigationIcon  = 'heroicon-o-link-slash';
    protected static ?string $navigationGroup = 'Achiziții';
    protected static ?string $navigationLabel = 'Fără furnizor';
    protected static ?int    $navigationSort  = 3;

    protected static string $view = 'filament.app.pages.products-without-supplier';

    public int $statTotal       = 0;
    public int $statPlaceholder = 0;
    public int $statWithStock   = 0;
    public int $statWithBrand   = 0;

    public function mount(): void
    {
        $this->computeStats();
    }

    private function computeStats(): void
    {
        $base = WooProduct::whereDoesntHave('suppliers')
            ->whereIn('connection_id', $this->getConnectionIds() ?: [0]);

        $this->statTotal       = $base->count();
        $this->statPlaceholder = (clone $base)->where('is_placeholder', true)->count();
        $this->statWithStock   = (clone $base)
            ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))
            ->count();
        $this->statWithBrand   = (clone $base)->whereNotNull('brand')->where('brand', '!=', '')->count();
    }

    public function table(Table $table): Table
    {
        $connectionIds = $this->getConnectionIds();

        return $table
            ->query(
                WooProduct::query()
                    ->whereDoesntHave('suppliers')
                    ->whereIn('connection_id', $connectionIds ?: [0])
                    ->with(['stocks'])
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->width('120px'),

                TextColumn::make('name')
                    ->label('Produs')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->name),

                TextColumn::make('brand')
                    ->label('Brand')
                    ->placeholder('-')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                TextColumn::make('stocks_sum_quantity')
                    ->label('Stoc')
                    ->getStateUsing(fn ($record) => $record->stocks->sum('quantity'))
                    ->numeric(decimalPlaces: 0)
                    ->sortable(false)
                    ->alignRight()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('source')
                    ->label('Sursă')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'winmentor_csv' => 'warning',
                        'woocommerce'   => 'success',
                        default         => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'winmentor_csv' => 'WinMentor',
                        'woocommerce'   => 'WooCommerce',
                        default         => $state,
                    }),

                IconColumn::make('is_placeholder')
                    ->label('Placeholder')
                    ->boolean()
                    ->trueIcon('heroicon-o-clock')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success'),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Sursă')
                    ->options([
                        'winmentor_csv' => 'WinMentor',
                        'woocommerce'   => 'WooCommerce',
                    ]),

                SelectFilter::make('is_placeholder')
                    ->label('Tip')
                    ->options([
                        '1' => 'Placeholder',
                        '0' => 'Produs real',
                    ]),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([25, 50, 100]);
    }

    private function getConnectionIds(): array
    {
        $user = static::currentUser();

        if (! $user) {
            return [];
        }

        $query = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE);

        if (! $user->isSuperAdmin()) {
            $query->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($user): void {
                $q->whereIn('location_id', $user->operationalLocationIds())
                  ->orWhereNull('location_id');
            });
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
