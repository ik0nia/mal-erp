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

    protected string $view = 'filament.widgets.recent-price-changes-widget';

    public function table(Table $table): Table
    {
        return $table
            ->heading(false)
            ->query(
                ProductPriceLog::query()
                    ->with(['product', 'location'])
                    ->latest('changed_at')
                    ->limit(15)
            )
            ->recordUrl(fn (ProductPriceLog $record): ?string => $record->woo_product_id
                ? \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $record->woo_product_id])
                : null
            )
            ->columns([
                Tables\Columns\TextColumn::make('changed_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.sku')
                    ->label('SKU')
                    ->copyable()->copyMessage('Copiat!')
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
                Tables\Columns\TextColumn::make('source')
                    ->label('Sursă')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'toya_api'         => 'Feed Toya',
                        'winmentor_bridge' => 'WinMentor API',
                        'winmentor_csv'    => 'WinMentor CSV',
                        'manual', 'erp', null, '' => 'Manual ERP',
                        default            => $state,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'toya_api'         => 'warning',
                        'winmentor_bridge' => 'info',
                        'winmentor_csv'    => 'gray',
                        default            => 'primary', // manual/ERP sau necunoscut
                    })
                    ->icon(fn (?string $state): string => match ($state) {
                        'toya_api'         => 'heroicon-m-rss',
                        'winmentor_bridge' => 'heroicon-m-signal',
                        'winmentor_csv'    => 'heroicon-m-document-text',
                        default            => 'heroicon-m-pencil-square',
                    }),
            ])
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

}