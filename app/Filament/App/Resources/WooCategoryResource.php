<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\WooCategoryResource\Pages;
use App\Models\User;
use App\Models\WooCategory;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WooCategoryResource extends Resource
{
    protected static ?string $model = WooCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Magazin Online';

    protected static ?string $navigationLabel = 'Categorii';

    protected static ?string $modelLabel = 'Categorie';

    protected static ?string $pluralModelLabel = 'Categorii';

    protected static ?int $navigationSort = 10;

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
                    ->label('Categorie')
                    ->formatStateUsing(fn (WooCategory $record): string => str_repeat('— ', (int) $record->getAttribute('_tree_depth')).$record->name)
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('count')
                    ->label('Produse')
                    ->numeric(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizat')
                    ->dateTime('d.m.Y H:i:s'),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWooCategories::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'connection',
            'parent',
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
