<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\WooProductResource\Pages;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\WooCategory;
use App\Models\WooProduct;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

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

    public static function canView(Model $record): bool
    {
        return auth()->check();
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
                ImageColumn::make('main_image_url')
                    ->label('Imagine')
                    ->size(96)
                    ->square()
                    ->defaultImageUrl('https://placehold.co/96x96?text=No+Img'),
                TextColumn::make('name')
                    ->label('Produs')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return static::applyOptimizedSearch($query, $search);
                    })
                    ->sortable()
                    ->wrap(),
                TextColumn::make('source')
                    ->label('Sursă')
                    ->badge()
                    ->formatStateUsing(fn (WooProduct $record): string => $record->is_placeholder ? 'ERP (contabilitate)' : 'WooCommerce')
                    ->color(fn (WooProduct $record): string => $record->is_placeholder ? 'warning' : 'success')
                    ->toggleable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Preț')
                    ->placeholder('-'),
                TextColumn::make('stock_status')
                    ->label('Stoc')
                    ->badge(),
                TextColumn::make('categories_list')
                    ->label('Categorii')
                    ->state(fn (WooProduct $record): string => $record->categories->pluck('name')->implode(', '))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('updated_at')
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
            ->recordUrl(fn (WooProduct $record): string => static::getUrl('view', ['record' => $record]))
            ->searchPlaceholder('Caută după nume, SKU, slug sau categorie...')
            ->searchDebounce('800ms')
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWooProducts::route('/'),
            'view' => Pages\ViewWooProduct::route('/{record}'),
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

    private static function applyOptimizedSearch(Builder $query, string $search): Builder
    {
        $terms = collect(preg_split('/\s+/', trim($search)))
            ->filter(fn ($term): bool => is_string($term) && $term !== '')
            ->map(fn (string $term): string => str_replace(['%', '_'], ['\\%', '\\_'], $term))
            ->values();

        if ($terms->isEmpty()) {
            return $query;
        }

        return $query->where(function (Builder $searchQuery) use ($terms): void {
            foreach ($terms as $term) {
                $like = "%{$term}%";

                $searchQuery->where(function (Builder $termQuery) use ($like): void {
                    $termQuery
                        ->where('woo_products.name', 'like', $like)
                        ->orWhere('woo_products.sku', 'like', $like)
                        ->orWhere('woo_products.slug', 'like', $like)
                        ->orWhereHas('categories', function (Builder $categoryQuery) use ($like): void {
                            $categoryQuery->where('woo_categories.name', 'like', $like);
                        });
                });
            }
        });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Produs')
                    ->columns(2)
                    ->schema([
                        ImageEntry::make('main_image_url')
                            ->label('Imagine')
                            ->height(180)
                            ->defaultImageUrl('https://placehold.co/300x180?text=No+Image')
                            ->columnSpanFull(),
                        TextEntry::make('name')->label('Nume'),
                        TextEntry::make('woo_id')->label('Woo ID'),
                        TextEntry::make('sku')->label('SKU'),
                        TextEntry::make('type')->label('Tip'),
                        TextEntry::make('status')->label('Status'),
                        TextEntry::make('price')->label('Preț'),
                        TextEntry::make('regular_price')->label('Preț regulat'),
                        TextEntry::make('sale_price')->label('Preț promo'),
                        TextEntry::make('stock_status')->label('Stoc'),
                        TextEntry::make('manage_stock')
                            ->label('Manage stock')
                            ->formatStateUsing(fn (?bool $state): string => $state === null ? '-' : ($state ? 'Da' : 'Nu')),
                        TextEntry::make('woo_parent_id')->label('Woo parent ID'),
                        TextEntry::make('slug')->label('Slug'),
                        TextEntry::make('connection.name')->label('Conexiune'),
                        TextEntry::make('connection.location.name')->label('Magazin'),
                        TextEntry::make('source')
                            ->label('Sursă')
                            ->formatStateUsing(fn (WooProduct $record): string => $record->is_placeholder ? 'ERP (contabilitate)' : 'WooCommerce'),
                        TextEntry::make('categories_list')
                            ->label('Categorii')
                            ->state(fn (WooProduct $record): string => $record->categories->pluck('name')->implode(', '))
                            ->columnSpanFull(),
                        TextEntry::make('short_description')
                            ->label('Short description')
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->label('Description')
                            ->html()
                            ->columnSpanFull(),
                    ]),
                Section::make('Payload brut (Woo)')
                    ->schema([
                        TextEntry::make('data_pretty')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => new HtmlString(
                                '<pre style="white-space: pre-wrap;">'.e(json_encode($record->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>'
                            ))
                            ->html(),
                    ]),
            ]);
    }
}
