<?php

namespace App\Filament\App\Pages;
use App\Models\RolePermission;
use App\Filament\App\Concerns\HasDynamicNavSort;

use App\Models\WooProduct;
use App\Services\Winmentor\WinmentorBridgeClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class NewWinmentorProducts extends Page implements HasTable
{
    use HasDynamicNavSort, InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-sparkles';
    protected static string|\UnitEnum|null $navigationGroup = 'Produse';
    protected static ?string $navigationLabel = 'Produse noi WinMentor';
    protected static ?int    $navigationSort  = 25;
    protected string  $view            = 'filament.app.pages.new-winmentor-products';

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return RolePermission::check(static::class, 'can_access');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = WooProduct::query()
            ->where('is_placeholder', true)
            ->whereIn('source', [WooProduct::SOURCE_WINMENTOR_CSV, WooProduct::SOURCE_WINMENTOR_BRIDGE])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sterge_non_clasa1')
                ->label('Șterge non-clasa 1')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Șterge produse care nu sunt în clasa 1 WinMentor')
                ->modalDescription('Va fi interogat WinMentor Bridge pentru a obține lista articolelor din clasa 1. Produsele placeholder din ERP care NU se regăsesc în clasa 1 vor fi șterse definitiv. Continuați?')
                ->modalSubmitActionLabel('Da, șterge')
                ->action(function (): void {
                    try {
                        $client = new WinmentorBridgeClient();
                        $skusClasa1 = $client->getSkusInClasa('1');
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Eroare WinMentor Bridge')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        return;
                    }

                    if (empty($skusClasa1)) {
                        Notification::make()
                            ->title('Niciun articol găsit în clasa 1')
                            ->body('WinMentor Bridge nu a returnat articole pentru clasa 1. Operațiunea a fost anulată pentru siguranță.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $placeholders = WooProduct::query()
                        ->where('is_placeholder', true)
                        ->whereIn('source', [WooProduct::SOURCE_WINMENTOR_CSV, WooProduct::SOURCE_WINMENTOR_BRIDGE])
                        ->get(['id', 'sku', 'name']);

                    $deleted = 0;
                    $skipped = 0;

                    foreach ($placeholders as $product) {
                        $skuNorm = strtolower(trim($product->sku ?? ''));

                        if ($skuNorm === '' || isset($skusClasa1[$skuNorm])) {
                            $skipped++;
                            continue;
                        }

                        $product->delete();
                        $deleted++;
                    }

                    Notification::make()
                        ->title('Verificare completă')
                        ->body("Șterse: {$deleted} produse non-clasa 1. Rămase: {$skipped} produse în clasa 1.")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                WooProduct::query()
                    ->where('is_placeholder', true)
                    ->whereIn('source', [WooProduct::SOURCE_WINMENTOR_CSV, WooProduct::SOURCE_WINMENTOR_BRIDGE])
                    ->with(['categories', 'suppliers', 'stocks'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->copyable()->copyMessage('Copiat!')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Denumire WinMentor')
                    ->formatStateUsing(fn (WooProduct $record): string => $record->decoded_name ?? $record->name)
                    ->searchable()
                    ->wrap(),

                TextColumn::make('price')
                    ->label('Preț')
                    ->money('RON')
                    ->sortable(),

                TextColumn::make('stoc')
                    ->label('Stoc')
                    ->state(fn (WooProduct $record): string => number_format((float) $record->stocks->sum('quantity'), 0, ',', '.'))
                    ->alignCenter(),

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

                IconColumn::make('has_brand')
                    ->label('Brand')
                    ->state(fn (WooProduct $record): bool => filled($record->brand))
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('has_supplier')
                    ->label('Furnizor')
                    ->state(fn (WooProduct $record): bool => $record->suppliers->isNotEmpty())
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('Adăugat')
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

                TernaryFilter::make('has_supplier')
                    ->label('Furnizor')
                    ->placeholder('Toate')
                    ->trueLabel('Cu furnizor')
                    ->falseLabel('Fără furnizor')
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('suppliers'),
                        false: fn (Builder $q) => $q->whereDoesntHave('suppliers'),
                    ),
            ])
            ->actions([
                Action::make('adauga_la_produse')
                    ->label('Adaugă la produse')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Adaugă la produse')
                    ->modalDescription('Produsul va apărea în lista de produse ERP și nu va mai fi în lista "Produse noi". Nu va fi publicat pe WooCommerce.')
                    ->modalSubmitActionLabel('Confirmă')
                    ->action(function (WooProduct $record): void {
                        $record->updateQuietly(['is_placeholder' => false]);
                    })
                    ->successNotificationTitle('Produs adăugat la produse ERP'),

                Action::make('view')
                    ->label('Vezi')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (WooProduct $record): string => \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('adauga_la_produse_bulk')
                        ->label('Adaugă la produse')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Adaugă produsele selectate')
                        ->modalDescription('Produsele selectate vor apărea în lista de produse ERP și nu vor mai fi în această listă.')
                        ->modalSubmitActionLabel('Confirmă')
                        ->action(function (Collection $records): void {
                            $records->each(fn (WooProduct $r) => $r->updateQuietly(['is_placeholder' => false]));
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->deferFilters(false)
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([25, 50, 100]);
    }

    public function bootGuardAccess(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }
}
