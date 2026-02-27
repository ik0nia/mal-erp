<?php

namespace App\Filament\App\Pages;

use App\Models\WooProduct;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NewWinmentorProducts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon  = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Produse';
    protected static ?string $navigationLabel = 'Produse noi WinMentor';
    protected static ?int    $navigationSort  = 25;
    protected static string  $view            = 'filament.app.pages.new-winmentor-products';

    public static function getNavigationBadge(): ?string
    {
        $count = WooProduct::query()
            ->where('is_placeholder', true)
            ->where('source', WooProduct::SOURCE_WINMENTOR_CSV)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                WooProduct::query()
                    ->where('is_placeholder', true)
                    ->where('source', WooProduct::SOURCE_WINMENTOR_CSV)
                    ->with(['categories', 'suppliers', 'stocks'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->copyable()
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
            ->recordUrl(fn (WooProduct $record): string => \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([25, 50, 100]);
    }
}
