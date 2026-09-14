<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\ProductPriceLogResource\Pages;
use App\Models\ProductPriceLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductPriceLogResource extends Resource
{
    use HasDynamicNavSort, ChecksRolePermissions;

    protected static ?string $model = ProductPriceLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Administrare magazin';

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
                    ->copyable()->copyMessage('Copiat!')
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
                        default            => 'primary',
                    })
                    ->icon(fn (?string $state): string => match ($state) {
                        'toya_api'         => 'heroicon-m-rss',
                        'winmentor_bridge' => 'heroicon-m-signal',
                        'winmentor_csv'    => 'heroicon-m-document-text',
                        default            => 'heroicon-m-pencil-square',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Magazin')
                    ->relationship('location', 'name'),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Sursă')
                    ->options([
                        'toya_api'         => 'Feed Toya',
                        'winmentor_bridge' => 'WinMentor API',
                        'winmentor_csv'    => 'WinMentor CSV',
                    ]),
            ])
            ->deferFilters(false)
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
