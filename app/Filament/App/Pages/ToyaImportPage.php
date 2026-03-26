<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Jobs\GenerateToyaDescriptionsJob;
use App\Models\WooProduct;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ToyaImportPage extends Page implements HasTable
{
    use InteractsWithTable, ChecksRolePermissions;

    public static function canAccess(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-arrow-down-tray';
    protected static string|\UnitEnum|null $navigationGroup = 'Produse';
    protected static ?string $navigationLabel = 'Import Toya';
    protected static ?int    $navigationSort  = 26;
    protected string  $view            = 'filament.app.pages.toya-import';

    public static function getNavigationBadge(): ?string
    {
        $count = WooProduct::query()
            ->where('source', WooProduct::SOURCE_TOYA_API)
            ->where('is_placeholder', true)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    // ----------------------------------------------------------------
    // Header actions
    // ----------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateDescriptions')
                ->label('Generează descrieri AI')
                ->icon('heroicon-o-sparkles')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Generează descrieri pentru produsele Toya')
                ->modalDescription('Se vor dispatcha joburi AI (Claude Haiku) pentru toate produsele Toya fără descriere. Procesul rulează în fundal.')
                ->modalSubmitActionLabel('Pornește')
                ->action(function () {
                    $ids = WooProduct::query()
                        ->where('source', WooProduct::SOURCE_TOYA_API)
                        ->whereNotNull('name')
                        ->whereNull('description')
                        ->whereNull('short_description')
                        ->pluck('id')
                        ->all();

                    if (empty($ids)) {
                        Notification::make()
                            ->title('Nicio descriere de generat')
                            ->info()
                            ->send();
                        return;
                    }

                    $chunks = array_chunk($ids, 5);
                    foreach ($chunks as $chunk) {
                        GenerateToyaDescriptionsJob::dispatch($chunk);
                    }

                    Notification::make()
                        ->title(count($chunks) . ' joburi AI pornite pentru ' . count($ids) . ' produse')
                        ->success()
                        ->send();
                }),
        ];
    }

    // ----------------------------------------------------------------
    // Stats pentru header
    // ----------------------------------------------------------------

    public function getStats(): array
    {
        $base = WooProduct::query()->where('source', WooProduct::SOURCE_TOYA_API);

        $total       = (clone $base)->count();
        $withImage   = (clone $base)->whereNotNull('main_image_url')->where('main_image_url', '!=', '')->count();
        $withDesc    = (clone $base)->where(fn ($q) => $q->whereNotNull('description')->orWhereNotNull('short_description'))->count();
        $withCat     = (clone $base)->whereHas('categories')->count();
        $readyToPub  = (clone $base)->whereNotNull('main_image_url')
            ->where('main_image_url', '!=', '')
            ->where(fn ($q) => $q->whereNotNull('description')->orWhereNotNull('short_description'))
            ->whereHas('categories')
            ->count();

        return [
            'total'      => $total,
            'withImage'  => $withImage,
            'withDesc'   => $withDesc,
            'withCat'    => $withCat,
            'readyToPub' => $readyToPub,
        ];
    }

    // ----------------------------------------------------------------
    // Tabel
    // ----------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(
                WooProduct::query()
                    ->where('source', WooProduct::SOURCE_TOYA_API)
                    ->with(['categories', 'suppliers'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('Cod Toya')
                    ->searchable()
                    ->copyable()->copyMessage('Copiat!')
                    ->sortable()
                    ->fontFamily('mono'),

                TextColumn::make('name')
                    ->label('Denumire')
                    ->searchable()
                    ->wrap()
                    ->limit(60),

                TextColumn::make('brand')
                    ->label('Brand')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('regular_price')
                    ->label('Preț net')
                    ->money('RON')
                    ->sortable(),

                TextColumn::make('stock_status')
                    ->label('Stoc Toya')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'instock'    => 'Disponibil',
                        'outofstock' => 'Indisponibil',
                        default      => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'instock'    => 'success',
                        'outofstock' => 'danger',
                        default      => 'gray',
                    }),

                IconColumn::make('has_image')
                    ->label('Poză')
                    ->state(fn (WooProduct $record): bool => filled($record->main_image_url))
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('has_description')
                    ->label('Descriere')
                    ->state(fn (WooProduct $record): bool => filled($record->description) || filled($record->short_description))
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('has_category')
                    ->label('Categorie')
                    ->state(fn (WooProduct $record): bool => $record->categories->isNotEmpty())
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'draft'   => 'Draft',
                        'publish' => 'Publicat',
                        default   => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'draft'   => 'warning',
                        'publish' => 'success',
                        default   => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Importat')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('has_image')
                    ->label('Poză')
                    ->placeholder('Toate')
                    ->trueLabel('Cu poză')
                    ->falseLabel('Fără poză')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('main_image_url')->where('main_image_url', '!=', ''),
                        false: fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('main_image_url')->orWhere('main_image_url', '')),
                    ),

                TernaryFilter::make('has_description')
                    ->label('Descriere')
                    ->placeholder('Toate')
                    ->trueLabel('Cu descriere')
                    ->falseLabel('Fără descriere')
                    ->queries(
                        true: fn (Builder $q) => $q->where(fn ($q) => $q->whereNotNull('description')->orWhereNotNull('short_description')),
                        false: fn (Builder $q) => $q->whereNull('description')->whereNull('short_description'),
                    ),

                TernaryFilter::make('has_category')
                    ->label('Categorie')
                    ->placeholder('Toate')
                    ->trueLabel('Cu categorie')
                    ->falseLabel('Fără categorie')
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('categories'),
                        false: fn (Builder $q) => $q->whereDoesntHave('categories'),
                    ),

                SelectFilter::make('stock_status')
                    ->label('Stoc')
                    ->options([
                        'instock'    => 'Disponibil',
                        'outofstock' => 'Indisponibil',
                    ]),

                SelectFilter::make('status')
                    ->label('Status site')
                    ->options([
                        'draft'   => 'Draft',
                        'publish' => 'Publicat',
                    ]),
            ])
            ->deferFilters(false)
            ->recordUrl(fn (WooProduct $record): string => \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([25, 50, 100, 250]);
    }
}
