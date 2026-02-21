<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\WooProductResource\Pages;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\WooCategory;
use App\Models\WooProduct;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WooProductResource extends Resource
{
    protected static ?string $model = WooProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Magazin Online';

    protected static ?string $navigationLabel = 'Produse';

    protected static ?string $modelLabel = 'Produs';

    protected static ?string $pluralModelLabel = 'Produse';

    protected static ?int $navigationSort = 20;

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
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
                Tables\Columns\TextColumn::make('name')
                    ->label('Produs')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('Preț'),
                Tables\Columns\TextColumn::make('stock_status')
                    ->label('Stoc')
                    ->badge(),
                Tables\Columns\TextColumn::make('connection.name')
                    ->label('Conexiune')
                    ->sortable(),
                Tables\Columns\TextColumn::make('categories_count')
                    ->label('Categorii')
                    ->counts('categories'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizat')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('connection_id')
                    ->label('Conexiune')
                    ->options(fn (): array => IntegrationConnection::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tip')
                    ->options(fn (): array => WooProduct::query()
                        ->whereNotNull('type')
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->all()),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Categorie')
                    ->options(function (): array {
                        $query = WooCategory::query()->orderBy('name');
                        $user = static::currentUser();

                        if ($user && ! $user->isSuperAdmin()) {
                            $query->whereHas('connection', function (Builder $connectionQuery) use ($user): void {
                                $connectionQuery->whereIn('location_id', $user->operationalLocationIds());
                            });
                        }

                        return $query->pluck('name', 'id')->all();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $categoryId = (int) ($data['value'] ?? 0);

                        if ($categoryId <= 0) {
                            return $query;
                        }

                        return $query->whereHas('categories', function (Builder $categoryQuery) use ($categoryId): void {
                            $categoryQuery->where('woo_categories.id', $categoryId);
                        });
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWooProducts::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'connection',
            'categories',
        ]);

        $user = static::currentUser();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereHas('connection', function (Builder $connectionQuery) use ($user): void {
            $connectionQuery->whereIn('location_id', $user->operationalLocationIds());
        });
    }
}
