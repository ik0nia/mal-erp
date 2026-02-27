<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ProductPriceLogResource\Pages;
use App\Models\ProductPriceLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductPriceLogResource extends Resource
{
    protected static ?string $model = ProductPriceLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Administrare magazin';

    protected static ?string $navigationLabel = 'Modificări prețuri';

    protected static ?string $modelLabel = 'Modificare preț';

    protected static ?string $pluralModelLabel = 'Modificări prețuri';

    protected static ?int $navigationSort = 50;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('changed_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->since(),
                Tables\Columns\TextColumn::make('product.sku')
                    ->label('SKU')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Produs')
                    ->searchable()
                    ->wrap()
                    ->formatStateUsing(fn (ProductPriceLog $record): string => $record->product?->decoded_name ?? '-'),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->sortable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('old_price')
                    ->label('Preț vechi')
                    ->money('RON')
                    ->sortable(),
                Tables\Columns\TextColumn::make('new_price')
                    ->label('Preț nou')
                    ->money('RON')
                    ->sortable(),
                Tables\Columns\TextColumn::make('delta')
                    ->label('Diferență')
                    ->state(fn (ProductPriceLog $record): float => round((float) $record->new_price - (float) $record->old_price, 4))
                    ->formatStateUsing(fn (float $state): string => ($state >= 0 ? '+' : '') . number_format($state, 2, ',', '.') . ' lei')
                    ->color(fn (ProductPriceLog $record): string => (float) $record->new_price >= (float) $record->old_price ? 'success' : 'danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Magazin')
                    ->relationship('location', 'name'),
            ])
            ->defaultSort('changed_at', 'desc')
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductPriceLogs::route('/'),
        ];
    }
}
