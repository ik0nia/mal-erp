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

            Actions\Action::make('reconciliereScadentar')
                ->label('Reconciliere scadențar')
                ->icon('heroicon-o-scale')
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->email === 'codrut@ikonia.ro' && SupplierResource::wmFinance($this->record) !== null)
                ->modalHeading('Reconciliere scadențar furnizor')
                ->modalDescription(function (): string {
                    $f = SupplierResource::wmFinance($this->record);
                    return 'Sold real (țintă): ' . ($f['sold'] ?? '—')
                        . '. Bifate = rămân DESCHISE. Debifează facturile deja stinse (plătite/compensate) până când scadențarul deschis bate cu soldul real.';
                })
                ->modalSubmitActionLabel('Salvează reconcilierea')
                ->fillForm(function (): array {
                    $pids = SupplierResource::wmPartIds($this->record);
                    $overSet = array_flip(\Illuminate\Support\Facades\DB::table('winmentor_factura_overrides')
                        ->whereIn('part_id', $pids)->where('directie', 'furnizor')->pluck('row_key')->all());
                    $rows = \Illuminate\Support\Facades\DB::table('winmentor_solduri_raw')
                        ->where('directie', 'furnizor')->whereIn('part_id', $pids)
                        ->whereRaw('ABS(rest_de_plata) >= 0.01')->get();
                    $open = [];
                    foreach ($rows as $r) {
                        $k = \App\Models\WinmentorFacturaOverride::rowKey($r->nr_factura, $r->data_factura, $r->rest_de_plata);
                        if (! isset($overSet[$k])) {
                            $open[] = $k;
                        }
                    }
                    return ['deschise' => $open];
                })
                ->form([
                    \Filament\Forms\Components\CheckboxList::make('deschise')
                        ->label('Facturi în scadențar (bifate = rămân deschise)')
                        ->options(function (): array {
                            $pids = SupplierResource::wmPartIds($this->record);
                            $rows = \Illuminate\Support\Facades\DB::table('winmentor_solduri_raw')
                                ->where('directie', 'furnizor')->whereIn('part_id', $pids)
                                ->whereRaw('ABS(rest_de_plata) >= 0.01')->orderByDesc('data_factura')->get();
                            $opt = [];
                            foreach ($rows as $r) {
                                $k = \App\Models\WinmentorFacturaOverride::rowKey($r->nr_factura, $r->data_factura, $r->rest_de_plata);
                                $opt[$k] = trim(($r->tip_document ?? '') . ' ' . $r->nr_factura)
                                    . ' · ' . ($r->data_factura ? \Carbon\Carbon::parse($r->data_factura)->format('d.m.Y') : '')
                                    . ' · ' . number_format((float) $r->rest_de_plata, 2, ',', '.') . ' lei';
                            }
                            return $opt;
                        })
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(1),
                ])
                ->action(function (array $data): void {
                    $pids    = SupplierResource::wmPartIds($this->record);
                    $primary = (string) $this->record->winmentor_id;
                    $keep    = array_flip($data['deschise'] ?? []);
                    $rows = \Illuminate\Support\Facades\DB::table('winmentor_solduri_raw')
                        ->where('directie', 'furnizor')->whereIn('part_id', $pids)
                        ->whereRaw('ABS(rest_de_plata) >= 0.01')->get();
                    $marcate = 0;
                    foreach ($rows as $r) {
                        $k = \App\Models\WinmentorFacturaOverride::rowKey($r->nr_factura, $r->data_factura, $r->rest_de_plata);
                        if (isset($keep[$k])) {
                            \Illuminate\Support\Facades\DB::table('winmentor_factura_overrides')
                                ->whereIn('part_id', $pids)->where('row_key', $k)->where('directie', 'furnizor')->delete();
                        } else {
                            \Illuminate\Support\Facades\DB::table('winmentor_factura_overrides')->updateOrInsert(
                                ['part_id' => $primary, 'row_key' => $k, 'directie' => 'furnizor'],
                                ['nr_factura' => $r->nr_factura, 'data_factura' => $r->data_factura, 'action' => 'settled', 'source' => 'manual', 'user_id' => auth()->id(), 'updated_at' => now(), 'created_at' => now()]
                            );
                            $marcate++;
                        }
                    }
                    \Illuminate\Support\Facades\Cache::forget("supp_wm_fin_{$this->record->id}");
                    Notification::make()
                        ->title('Reconciliere salvată')
                        ->body("{$marcate} facturi marcate ca stinse. Scadențarul deschis a fost actualizat.")
                        ->success()
                        ->send();
                }),

            Actions\Action::make('refreshFinance')
                ->label('Reîmprospătează financiar')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->email === 'codrut@ikonia.ro')
                ->action(function (): void {
                    \Illuminate\Support\Facades\Cache::forget("supp_wm_fin_{$this->record->id}");
                    Notification::make()
                        ->title('Date financiare reîmprospătate')
                        ->body('Soldul live din WinMentor a fost reîncărcat.')
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
