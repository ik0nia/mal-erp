<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\WooProductResource\Pages;
use App\Models\DailyStockMetric;
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
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
                    ->formatStateUsing(fn (WooProduct $record): string => $record->decoded_name)
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
                Tables\Filters\SelectFilter::make('source')
                    ->label('Sursă')
                    ->options([
                        WooProduct::SOURCE_WOOCOMMERCE => 'WooCommerce',
                        WooProduct::SOURCE_WINMENTOR_CSV => 'ERP (contabilitate)',
                    ]),
                Tables\Filters\SelectFilter::make('stock_status')
                    ->label('Stoc')
                    ->options([
                        'instock' => 'În stoc',
                        'outofstock' => 'Fără stoc',
                        'onbackorder' => 'Precomandă',
                    ]),
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
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->persistFiltersInSession()
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
                        TextEntry::make('name')
                            ->label('Nume')
                            ->state(fn (WooProduct $record): string => $record->decoded_name),
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
                Section::make('Istoric variație stoc')
                    ->description('Afișează 10 poziții vizibile și până la 30 poziții cu scroll.')
                    ->schema([
                        TextEntry::make('daily_variation_history')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => static::renderDailyVariationHistory($record))
                            ->html(),
                    ]),
                Section::make('Rezumat variații')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('daily_quantity_variation_card')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => static::renderQuantityVariationCard($record))
                            ->html(),
                        TextEntry::make('daily_value_variation_card')
                            ->label('')
                            ->state(fn (WooProduct $record): HtmlString => static::renderValueVariationCard($record))
                            ->html(),
                    ]),
                Section::make('Payload brut (Woo)')
                    ->collapsible()
                    ->collapsed()
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

    /**
     * @return Collection<int, DailyStockMetric>
     */
    private static function recentDailyMetrics(WooProduct $record, int $limit = 30): Collection
    {
        static $cache = [];
        $cacheKey = $record->getKey().':'.(string) $record->sku.':'.$limit;

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $cache[$cacheKey] = $record->dailyStockMetrics()
            ->orderByDesc('day')
            ->limit(max(1, $limit))
            ->get([
                'day',
                'daily_total_variation',
                'daily_sales_value_variation',
                'closing_total_qty',
                'closing_sales_value',
                'snapshots_count',
            ]);

        return $cache[$cacheKey];
    }

    private static function renderDailyVariationHistory(WooProduct $record): HtmlString
    {
        $metrics = static::recentDailyMetrics($record, 30);

        if ($metrics->isEmpty()) {
            return new HtmlString(
                '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.85rem;color:#6b7280;font-size:0.875rem;">'
                .'Nu există încă date de variație pentru acest produs.'
                .'</div>'
            );
        }

        $rows = [];
        foreach ($metrics as $index => $metric) {
            $qtyVariation = (float) $metric->daily_total_variation;
            $valueVariation = (float) $metric->daily_sales_value_variation;
            $qtyColor = static::variationInlineColor($qtyVariation);
            $valueColor = static::variationInlineColor($valueVariation);
            $position = $index + 1;
            $dayLabel = static::formatMetricDay($metric->day);
            $closingQty = number_format((float) $metric->closing_total_qty, 3, '.', ',').' buc';
            $qtyText = static::formatSignedMetric($qtyVariation, 3, ' buc');
            $valueText = static::formatSignedCurrency($valueVariation);

            $rows[] = '<tr>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;">'.$position.'</td>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;">'.$dayLabel.'</td>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;color:'.$qtyColor.';font-weight:600;text-align:right;">'.$qtyText.'</td>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;color:'.$valueColor.';font-weight:600;text-align:right;">'.$valueText.'</td>'
                .'<td style="padding:0.5rem 0.55rem;border-bottom:1px solid #f3f4f6;text-align:right;">'.$closingQty.'</td>'
                .'</tr>';
        }

        $table = '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;overflow:hidden;background:#fff;">'
            .'<div style="max-height:26.5rem;overflow-y:auto;">'
            .'<table style="width:100%;border-collapse:collapse;font-size:0.82rem;">'
            .'<thead style="position:sticky;top:0;background:#f9fafb;z-index:1;">'
            .'<tr>'
            .'<th style="padding:0.55rem;text-align:left;border-bottom:1px solid #e5e7eb;width:64px;">#</th>'
            .'<th style="padding:0.55rem;text-align:left;border-bottom:1px solid #e5e7eb;">Zi</th>'
            .'<th style="padding:0.55rem;text-align:right;border-bottom:1px solid #e5e7eb;">Variație cant.</th>'
            .'<th style="padding:0.55rem;text-align:right;border-bottom:1px solid #e5e7eb;">Variație valoare</th>'
            .'<th style="padding:0.55rem;text-align:right;border-bottom:1px solid #e5e7eb;">Stoc final</th>'
            .'</tr>'
            .'</thead>'
            .'<tbody>'.implode('', $rows).'</tbody>'
            .'</table>'
            .'</div>'
            .'</div>';

        return new HtmlString($table);
    }

    private static function renderQuantityVariationCard(WooProduct $record): HtmlString
    {
        $metrics = static::recentDailyMetrics($record, 30);

        if ($metrics->isEmpty()) {
            return new HtmlString(
                '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.9rem;background:#fff;">'
                .'<div style="font-size:0.8rem;color:#6b7280;">Variație cantitate</div>'
                .'<div style="margin-top:0.45rem;font-size:1.2rem;font-weight:700;color:#6b7280;">-</div>'
                .'<div style="margin-top:0.25rem;font-size:0.8rem;color:#9ca3af;">Fără date disponibile.</div>'
                .'</div>'
            );
        }

        /** @var DailyStockMetric $latest */
        $latest = $metrics->first();
        $latestVariation = (float) $latest->daily_total_variation;
        $latestDay = static::formatMetricDay($latest->day);
        $sum7 = $metrics->take(7)->sum(fn (DailyStockMetric $metric): float => (float) $metric->daily_total_variation);
        $sum30 = $metrics->sum(fn (DailyStockMetric $metric): float => (float) $metric->daily_total_variation);
        $closingQty = number_format((float) $latest->closing_total_qty, 3, '.', ',').' buc';
        $color = static::variationInlineColor($latestVariation);

        return new HtmlString(
            '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.9rem;background:#fff;">'
            .'<div style="font-size:0.8rem;color:#6b7280;">Variație cantitate</div>'
            .'<div style="margin-top:0.35rem;font-size:1.35rem;font-weight:700;color:'.$color.';">'
            .static::formatSignedMetric($latestVariation, 3, ' buc')
            .'</div>'
            .'<div style="margin-top:0.2rem;font-size:0.8rem;color:#6b7280;">Ultima zi: '.$latestDay.'</div>'
            .'<div style="margin-top:0.55rem;display:flex;gap:0.7rem;flex-wrap:wrap;font-size:0.8rem;color:#374151;">'
            .'<span>7 poziții: <strong>'.static::formatSignedMetric($sum7, 3, ' buc').'</strong></span>'
            .'<span>30 poziții: <strong>'.static::formatSignedMetric($sum30, 3, ' buc').'</strong></span>'
            .'<span>Stoc final: <strong>'.$closingQty.'</strong></span>'
            .'</div>'
            .'</div>'
        );
    }

    private static function renderValueVariationCard(WooProduct $record): HtmlString
    {
        $metrics = static::recentDailyMetrics($record, 30);

        if ($metrics->isEmpty()) {
            return new HtmlString(
                '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.9rem;background:#fff;">'
                .'<div style="font-size:0.8rem;color:#6b7280;">Variație valoare</div>'
                .'<div style="margin-top:0.45rem;font-size:1.2rem;font-weight:700;color:#6b7280;">-</div>'
                .'<div style="margin-top:0.25rem;font-size:0.8rem;color:#9ca3af;">Fără date disponibile.</div>'
                .'</div>'
            );
        }

        /** @var DailyStockMetric $latest */
        $latest = $metrics->first();
        $latestVariation = (float) $latest->daily_sales_value_variation;
        $latestDay = static::formatMetricDay($latest->day);
        $sum7 = $metrics->take(7)->sum(fn (DailyStockMetric $metric): float => (float) $metric->daily_sales_value_variation);
        $sum30 = $metrics->sum(fn (DailyStockMetric $metric): float => (float) $metric->daily_sales_value_variation);
        $closingValue = number_format((float) $latest->closing_sales_value, 2, '.', ',').' RON';
        $color = static::variationInlineColor($latestVariation);

        return new HtmlString(
            '<div style="border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.9rem;background:#fff;">'
            .'<div style="font-size:0.8rem;color:#6b7280;">Variație valoare</div>'
            .'<div style="margin-top:0.35rem;font-size:1.35rem;font-weight:700;color:'.$color.';">'
            .static::formatSignedCurrency($latestVariation)
            .'</div>'
            .'<div style="margin-top:0.2rem;font-size:0.8rem;color:#6b7280;">Ultima zi: '.$latestDay.'</div>'
            .'<div style="margin-top:0.55rem;display:flex;gap:0.7rem;flex-wrap:wrap;font-size:0.8rem;color:#374151;">'
            .'<span>7 poziții: <strong>'.static::formatSignedCurrency($sum7).'</strong></span>'
            .'<span>30 poziții: <strong>'.static::formatSignedCurrency($sum30).'</strong></span>'
            .'<span>Valoare stoc final: <strong>'.$closingValue.'</strong></span>'
            .'</div>'
            .'</div>'
        );
    }

    private static function formatSignedMetric(?float $value, int $decimals, string $suffix = ''): string
    {
        if ($value === null) {
            return '-';
        }

        $formatted = number_format(abs($value), $decimals, '.', ',');
        $sign = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return trim("{$sign}{$formatted}{$suffix}");
    }

    private static function formatSignedCurrency(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        $formatted = number_format(abs($value), 2, '.', ',');
        $sign = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return trim("{$sign}{$formatted} RON");
    }

    private static function variationInlineColor(?float $value): string
    {
        if ($value === null || abs($value) < 0.00001) {
            return '#6b7280';
        }

        return $value > 0 ? '#16a34a' : '#dc2626';
    }

    private static function formatMetricDay(mixed $day): string
    {
        if ($day instanceof Carbon) {
            return $day->format('d.m.Y');
        }

        $timestamp = strtotime((string) $day);

        return $timestamp !== false ? date('d.m.Y', $timestamp) : '-';
    }
}
