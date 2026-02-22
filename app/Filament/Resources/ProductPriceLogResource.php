<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductPriceLogResource\Pages;
use App\Models\ProductPriceLog;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class ProductPriceLogResource extends Resource
{
    protected static ?string $model = ProductPriceLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Integrări';

    protected static ?string $navigationLabel = 'Price logs';

    protected static ?string $modelLabel = 'Price log';

    protected static ?string $pluralModelLabel = 'Price logs';

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Produs')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('old_price')
                    ->label('Preț vechi')
                    ->money('RON')
                    ->sortable(),
                Tables\Columns\TextColumn::make('new_price')
                    ->label('Preț nou')
                    ->money('RON')
                    ->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Sursă')
                    ->badge(),
                Tables\Columns\TextColumn::make('changed_at')
                    ->label('Schimbat la')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_run_id')
                    ->label('Sync run')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Magazin')
                    ->relationship('location', 'name'),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Sursă')
                    ->options([
                        'winmentor_csv' => 'WinMentor CSV',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('details')
                    ->label('Detalii')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalHeading(fn (ProductPriceLog $record): string => "Price log #{$record->id}")
                    ->modalContent(function (ProductPriceLog $record): HtmlString {
                        $payload = [
                            'sku' => $record->product?->sku,
                            'product' => $record->product?->name,
                            'old_price' => $record->old_price,
                            'new_price' => $record->new_price,
                            'source' => $record->source,
                            'changed_at' => $record->changed_at?->toDateTimeString(),
                            'sync_run_id' => $record->sync_run_id,
                            'payload' => $record->payload,
                        ];

                        return new HtmlString('<pre style="white-space: pre-wrap;">'.e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>');
                    }),
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
