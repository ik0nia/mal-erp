<?php

namespace App\Filament\App\Resources\SupplierResource\RelationManagers;

use App\Models\Supplier;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';
    protected static ?string $title       = 'Produse asociate';
    protected static ?string $modelLabel  = 'produs';
    protected static ?string $pluralModelLabel = 'produse';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->fontFamily(\Filament\Support\Enums\FontFamily::Mono)
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Produs')
                    ->searchable()
                    ->limit(60),

                Tables\Columns\TextColumn::make('pivot.supplier_sku')
                    ->label('SKU furnizor')
                    ->fontFamily(\Filament\Support\Enums\FontFamily::Mono)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('pivot.purchase_price')
                    ->label('Preț achiziție (fără TVA)')
                    ->money('RON')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('purchase_price_vat')
                    ->label('Preț achiziție (cu TVA)')
                    ->state(fn ($record) => $record->pivot->purchase_price
                        ? round((float) $record->pivot->purchase_price * 1.21, 2)
                        : null)
                    ->money('RON')
                    ->placeholder('—')
                    ->color('gray'),

                Tables\Columns\IconColumn::make('pivot.is_preferred')
                    ->label('Preferat')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                Tables\Actions\Action::make('move_to_supplier')
                    ->label('Mută la furnizor')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('warning')
                    ->modalHeading(fn ($record) => 'Mută produs: ' . $record->name)
                    ->modalSubmitActionLabel('Mută')
                    ->form([
                        Forms\Components\Select::make('target_supplier_id')
                            ->label('Furnizor destinație')
                            ->options(fn () => Supplier::where('id', '!=', $this->getOwnerRecord()->id)
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->native(false)
                            ->required(),
                    ])
                    ->action(function ($record, array $data): void {
                        $currentSupplierId = $this->getOwnerRecord()->id;
                        $targetSupplierId  = (int) $data['target_supplier_id'];

                        $pivot = DB::table('product_suppliers')
                            ->where('supplier_id', $currentSupplierId)
                            ->where('woo_product_id', $record->id)
                            ->first();

                        if (! $pivot) {
                            Notification::make()->danger()->title('Eroare')->body('Asocierea nu a fost găsită.')->send();
                            return;
                        }

                        $targetSupplier = Supplier::find($targetSupplierId);

                        $existsAtTarget = DB::table('product_suppliers')
                            ->where('supplier_id', $targetSupplierId)
                            ->where('woo_product_id', $record->id)
                            ->exists();

                        if ($existsAtTarget) {
                            // Produsul e deja la furnizorul destinație — doar eliminăm legătura veche
                            DB::table('product_suppliers')
                                ->where('supplier_id', $currentSupplierId)
                                ->where('woo_product_id', $record->id)
                                ->delete();

                            Notification::make()->warning()
                                ->title('Produs deja asociat')
                                ->body("Produsul era deja la {$targetSupplier->name}. Legătura de la furnizorul curent a fost eliminată.")
                                ->send();

                            return;
                        }

                        DB::table('product_suppliers')
                            ->where('supplier_id', $currentSupplierId)
                            ->where('woo_product_id', $record->id)
                            ->update(['supplier_id' => $targetSupplierId]);

                        Notification::make()->success()
                            ->title('Produs mutat')
                            ->body("Produsul a fost mutat la {$targetSupplier->name}.")
                            ->send();
                    }),
            ]);
    }
}
