<?php

namespace App\Filament\App\Widgets;

use App\Models\ProductPriceLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentPriceChangesWidget extends BaseWidget
{
    protected static ?string $heading = 'Ultimele modificări de prețuri';

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    protected static string $view = 'filament.widgets.recent-price-changes-widget';

    protected function getTableHeading(): ?string
    {
        return null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductPriceLog::query()
                    ->with(['product', 'location'])
                    ->latest('changed_at')
                    ->limit(50)
            )
            ->columns([
                Tables\Columns\TextColumn::make('changed_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.sku')
                    ->label('SKU')
                    ->copyable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Produs')
                    ->wrap()
                    ->formatStateUsing(fn (ProductPriceLog $record): string => $record->product?->decoded_name ?? '-'),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('old_price')
                    ->label('Preț vechi')
                    ->money('RON'),
                Tables\Columns\TextColumn::make('new_price')
                    ->label('Preț nou')
                    ->money('RON'),
                Tables\Columns\TextColumn::make('delta')
                    ->label('Δ')
                    ->state(fn (ProductPriceLog $record): float => round((float) $record->new_price - (float) $record->old_price, 4))
                    ->formatStateUsing(fn (float $state): string => ($state >= 0 ? '+' : '') . number_format($state, 2, ',', '.') . ' lei')
                    ->color(fn (ProductPriceLog $record): string => (float) $record->new_price >= (float) $record->old_price ? 'success' : 'danger'),
            ])
            ->paginated(false);
    }
}
