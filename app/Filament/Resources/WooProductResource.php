<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WooProductResource\Pages;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\WooProduct;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class WooProductResource extends Resource
{
    protected static ?string $model = WooProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Integrări';

    protected static ?string $navigationLabel = 'Woo Produse';

    protected static ?string $modelLabel = 'Produs Woo';

    protected static ?string $pluralModelLabel = 'Produse Woo';

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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('connection.name')
                    ->label('Conexiune')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('connection.location.name')
                    ->label('Magazin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('woo_id')
                    ->label('Woo ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nume')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('Preț')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('stock_status')
                    ->label('Stoc')
                    ->badge()
                    ->toggleable(),
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
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => WooProduct::query()
                        ->whereNotNull('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('details')
                    ->label('Detalii')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalHeading(fn (WooProduct $record): string => "Produs Woo #{$record->woo_id}")
                    ->modalContent(fn (WooProduct $record): HtmlString => new HtmlString(
                        '<pre style="white-space: pre-wrap;">'.e(json_encode($record->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>'
                    )),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWooProducts::route('/'),
        ];
    }
}
