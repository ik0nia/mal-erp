<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\WooCategoryResource\Pages;
use App\Models\User;
use App\Models\WooCategory;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

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
                Stack::make([
                    TextColumn::make('name')
                        ->label('')
                        ->formatStateUsing(function (WooCategory $record): HtmlString {
                            $depth = (int) $record->getAttribute('_tree_depth');
                            $paddingLeft = max(0, $depth) * 24;
                            $prefix = $depth > 0 ? '↳ ' : '';

                            return new HtmlString(
                                '<span style="display:inline-block;padding-left:'.$paddingLeft.'px;">'.$prefix.e($record->name).'</span>'
                            );
                        })
                        ->html()
                        ->weight('bold')
                        ->searchable(),
                    TextColumn::make('count')
                        ->label('')
                        ->formatStateUsing(fn (WooCategory $record): string => ((int) ($record->count ?? 0)).' produse'),
                ])
                    ->space(1)
                    ->extraAttributes([
                        'class' => 'w-full rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-white/5',
                    ]),
            ])
            ->contentGrid([
                'md' => 1,
                'xl' => 1,
            ])
            ->recordUrl(fn (WooCategory $record): string => WooProductResource::getUrl('index', [
                'tableFilters' => [
                    'connection_id' => ['value' => (string) $record->connection_id],
                    'category_id' => ['value' => (string) $record->id],
                ],
            ]))
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
