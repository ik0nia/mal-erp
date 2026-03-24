<?php

namespace App\Filament\App\Resources\SupplierResource\Pages;

use App\Filament\App\Resources\SupplierResource;
use App\Filament\App\Resources\SupplierResource\RelationManagers\ContactsRelationManager;
use App\Filament\App\Resources\SupplierResource\RelationManagers\FeedsRelationManager;
use App\Filament\App\Resources\SupplierResource\RelationManagers\EmailsRelationManager;
use App\Filament\App\Resources\SupplierResource\RelationManagers\ProductsRelationManager;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recalculatePrices')
                ->label('Recalculează prețuri vânzare')
                ->icon('heroicon-o-calculator')
                ->color('warning')
                ->modalHeading('Recalculează prețuri de vânzare')
                ->modalDescription('Se actualizează prețul de vânzare (cu TVA 21%) pentru toate produsele cu preț de achiziție setat.')
                ->form([
                    Forms\Components\TextInput::make('adaos')
                        ->label('Adaos comercial (%)')
                        ->numeric()
                        ->default(fn () => $this->record->default_markup ?? 20)
                        ->minValue(0)
                        ->maxValue(500)
                        ->suffix('%')
                        ->required()
                        ->helperText('TVA aplicat automat: 21%'),
                    Forms\Components\Placeholder::make('preview')
                        ->label('Produse afectate')
                        ->content(function () {
                            $count = DB::table('product_suppliers')
                                ->where('supplier_id', $this->record->id)
                                ->whereNotNull('purchase_price')
                                ->where('purchase_price', '>', 0)
                                ->count();
                            return $count . ' produse cu preț de achiziție setat';
                        }),
                ])
                ->modalSubmitActionLabel('Aplică')
                ->action(function (array $data): void {
                    $multiplier = round((1 + (float) $data['adaos'] / 100) * 1.21, 10);

                    $updated = DB::table('woo_products')
                        ->join('product_suppliers as ps', 'ps.woo_product_id', '=', 'woo_products.id')
                        ->where('ps.supplier_id', $this->record->id)
                        ->whereNotNull('ps.purchase_price')
                        ->where('ps.purchase_price', '>', 0)
                        ->update([
                            'woo_products.regular_price' => DB::raw("ROUND(ps.purchase_price * {$multiplier}, 2)"),
                            'woo_products.price'         => DB::raw("ROUND(ps.purchase_price * {$multiplier}, 2)"),
                            'woo_products.updated_at'    => now(),
                        ]);

                    // Salvează adaosul ca default pentru furnizor
                    $this->record->update(['default_markup' => $data['adaos']]);

                    Notification::make()
                        ->title("{$updated} produse actualizate")
                        ->body("Adaos {$data['adaos']}% + TVA 21% aplicat. Adaosul a fost salvat ca implicit pentru acest furnizor.")
                        ->success()
                        ->send();
                }),

            Actions\EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return SupplierResource::infolist($schema);
    }

    public function getRelationManagers(): array
    {
        return [
            FeedsRelationManager::class,
            ContactsRelationManager::class,
            ProductsRelationManager::class,
        ];
    }
}
