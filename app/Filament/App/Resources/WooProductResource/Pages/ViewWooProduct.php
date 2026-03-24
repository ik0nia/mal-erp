<?php

namespace App\Filament\App\Resources\WooProductResource\Pages;

use App\Filament\App\Resources\PurchaseRequestResource;
use App\Filament\App\Resources\WooProductResource;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\WooCategory;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewWooProduct extends ViewRecord
{
    protected static string $resource = WooProductResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var WooProduct $product */
        $product = $this->record;

        // Banner produs vechi — are înlocuitor setat
        if ($product->substituted_by_id) {
            $replacement = $product->substitutedBy;
            Notification::make('substitution_warning')
                ->warning()
                ->persistent()
                ->title('Produs cu înlocuitor setat')
                ->body('La achiziții viitoare comandați: ' . ($replacement?->name ?? 'produs necunoscut'))
                ->actions([
                    \Filament\Notifications\Actions\Action::make('go_to_replacement')
                        ->label('Mergi la înlocuitor →')
                        ->url(WooProductResource::getUrl('view', ['record' => $product->substituted_by_id]))
                        ->button(),
                ])
                ->send();
        }

        // Banner produs nou — înlocuiește altele
        if ($product->substitutes()->exists()) {
            $count = $product->substitutes()->count();
            Notification::make('is_replacement_for')
                ->info()
                ->persistent()
                ->title("Acest produs înlocuiește {$count} " . ($count === 1 ? 'produs' : 'produse'))
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resync_from_woo')
                ->label('Resync WooCommerce')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->extraAttributes(['class' => 'hidden'])
                ->visible(function (): bool {
                    if (! $this->record->woo_id || ! $this->record->connection_id) {
                        return false;
                    }
                    $user = auth()->user();
                    if (! $user instanceof User) {
                        return false;
                    }

                    return $user->isAdmin()
                        || in_array($user->role, [
                            User::ROLE_MANAGER,
                            User::ROLE_DIRECTOR_VANZARI,
                        ], true);
                })
                ->modalHeading('Resync din WooCommerce')
                ->modalDescription('Alege ce date vrei să aduci din WooCommerce.')
                ->modalSubmitActionLabel('Sincronizează')
                ->form([
                    \Filament\Forms\Components\CheckboxList::make('fields')
                        ->label('Câmpuri de sincronizat')
                        ->options([
                            'image'       => 'Imagine principală',
                            'prices'      => 'Prețuri (regular, sale, price)',
                            'status'      => 'Status publicare',
                            'stock'       => 'Stoc (stock_status, manage_stock)',
                            'name'        => 'Denumire și slug',
                            'description' => 'Descriere (scurtă și lungă)',
                            'dimensions'  => 'Dimensiuni și greutate',
                            'categories'  => 'Categorii',
                        ])
                        ->default(['image'])
                        ->columns(2)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var WooProduct $product */
                    $product = $this->record;
                    $fields  = $data['fields'] ?? [];

                    if (empty($fields)) {
                        Notification::make()->warning()->title('Niciun câmp selectat')->send();

                        return;
                    }

                    try {
                        $client = new WooClient($product->connection);
                        $d      = $client->getProduct((int) $product->woo_id);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Eroare WooCommerce')->body($e->getMessage())->send();

                        return;
                    }

                    $nullable = fn (mixed $v): ?string => (is_string($v) && $v !== '') ? $v : null;
                    $updates  = [];

                    if (in_array('image', $fields)) {
                        $src = data_get($d, 'images.0.src');
                        $updates['main_image_url'] = is_string($src) && $src !== '' ? $src : null;
                        // Actualizăm și images în data
                    }
                    if (in_array('prices', $fields)) {
                        $updates['regular_price'] = $nullable($d['regular_price'] ?? null);
                        $updates['sale_price']     = $nullable($d['sale_price'] ?? null);
                        $updates['price']          = $nullable($d['price'] ?? null);
                    }
                    if (in_array('status', $fields)) {
                        $updates['status'] = $nullable($d['status'] ?? null);
                    }
                    if (in_array('stock', $fields)) {
                        $updates['stock_status'] = $nullable($d['stock_status'] ?? null);
                        $updates['manage_stock']  = isset($d['manage_stock']) ? (bool) $d['manage_stock'] : $product->manage_stock;
                    }
                    if (in_array('name', $fields)) {
                        $updates['name'] = html_entity_decode((string) ($d['name'] ?? $product->name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $updates['slug'] = $nullable($d['slug'] ?? null);
                    }
                    if (in_array('description', $fields)) {
                        $updates['description']       = $nullable($d['description'] ?? null);
                        $updates['short_description'] = $nullable($d['short_description'] ?? null);
                    }
                    if (in_array('dimensions', $fields)) {
                        $w = trim((string) ($d['weight'] ?? ''));
                        $updates['weight']     = $w !== '' ? $w : null;
                        $updates['dim_length'] = $nullable($d['dimensions']['length'] ?? null);
                        $updates['dim_width']  = $nullable($d['dimensions']['width'] ?? null);
                        $updates['dim_height'] = $nullable($d['dimensions']['height'] ?? null);
                    }

                    // Întotdeauna actualizăm data complet dacă am făcut fetch
                    $updates['data'] = $d;

                    $product->update($updates);

                    if (in_array('categories', $fields)) {
                        $catWooIds = collect($d['categories'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
                        if ($catWooIds) {
                            $catIds = WooCategory::query()
                                ->where('connection_id', $product->connection_id)
                                ->whereIn('woo_id', $catWooIds)
                                ->pluck('id')->all();
                            $product->categories()->sync($catIds);
                        }
                    }

                    Notification::make()->success()->title('Produs re-sincronizat din WooCommerce')->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $product->getRouteKey()]));
                }),

            Actions\Action::make('add_to_necesar')
                ->label('Adaugă la necesar')
                ->icon('heroicon-o-shopping-cart')
                ->color('danger')
                ->extraAttributes(['class' => 'hidden'])
                ->modalHeading(fn () => 'Adaugă la necesar: '.($this->record->decoded_name ?? $this->record->name))
                ->modalDescription(fn () => $this->record->substituted_by_id
                    ? '⚠️ Atenție: acest produs are un înlocuitor setat. Considerați să comandați "' . ($this->record->substitutedBy?->name ?? '') . '" în schimb.'
                    : null)
                ->modalSubmitActionLabel('Adaugă')
                ->form(WooProductResource::necesarModalForm())
                ->action(function (array $data): void {
                    $user = auth()->user();
                    if (! $user instanceof User) {
                        return;
                    }

                    /** @var WooProduct $product */
                    $product  = $this->record;
                    $draft    = PurchaseRequest::getOrCreateDraft($user);
                    $existing = $draft->items()->where('woo_product_id', $product->id)->first();

                    if ($existing) {
                        $existing->update(['quantity' => (float) $existing->quantity + (float) $data['quantity']]);
                    } else {
                        $draft->items()->create([
                            'woo_product_id' => $product->id,
                            'quantity'       => $data['quantity'],
                            'is_urgent'      => $data['is_urgent'] ?? false,
                            'notes'          => $data['notes'] ?? null,
                        ]);
                    }

                    Notification::make()
                        ->success()
                        ->title('Produs adăugat la necesar')
                        ->actions([
                            \Filament\Notifications\Actions\Action::make('open_cart')
                                ->label('Deschide coșul →')
                                ->url(PurchaseRequestResource::getUrl('edit', ['record' => $draft->id])),
                        ])
                        ->send();
                }),
        ];
    }
}
