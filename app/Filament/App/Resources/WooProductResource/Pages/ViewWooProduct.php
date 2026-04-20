<?php

namespace App\Filament\App\Resources\WooProductResource\Pages;

use App\Filament\App\Resources\WooProductResource;
use App\Jobs\ImportToyaImagesJob;
use App\Models\ProductImage;
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
                    \Filament\Actions\Action::make('go_to_replacement')
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

            // ── Gallery: setează poza principală ─────────────────────────
            Actions\Action::make('gallery_set_primary')
                ->label('Setează ca principală')
                ->extraAttributes(['class' => 'hidden'])
                ->action(function (array $arguments): void {
                    $imageId = (int) ($arguments['image_id'] ?? 0);
                    if (! $imageId) {
                        return;
                    }

                    /** @var ProductImage|null $image */
                    $image = ProductImage::where('id', $imageId)
                        ->where('woo_product_id', $this->record->id)
                        ->first();

                    if (! $image) {
                        Notification::make()->danger()->title('Imaginea nu a fost găsită.')->send();

                        return;
                    }

                    $image->setAsPrimary();
                    $this->record->refresh();

                    Notification::make()->success()->title('Imaginea principală a fost actualizată.')->send();
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record->getRouteKey()]));
                }),

            // ── Gallery: șterge o imagine ─────────────────────────────────
            Actions\Action::make('gallery_delete_image')
                ->label('Șterge imaginea')
                ->extraAttributes(['class' => 'hidden'])
                ->requiresConfirmation()
                ->modalHeading('Șterge imaginea?')
                ->modalDescription('Această acțiune nu poate fi anulată.')
                ->modalSubmitActionLabel('Șterge')
                ->action(function (array $arguments): void {
                    $imageId = (int) ($arguments['image_id'] ?? 0);
                    if (! $imageId) {
                        return;
                    }

                    /** @var ProductImage|null $image */
                    $image = ProductImage::where('id', $imageId)
                        ->where('woo_product_id', $this->record->id)
                        ->first();

                    if (! $image) {
                        Notification::make()->danger()->title('Imaginea nu a fost găsită.')->send();

                        return;
                    }

                    $wasPrimary = $image->is_primary;
                    $image->delete();

                    // Dacă era principală, setăm prima rămasă ca principală
                    if ($wasPrimary) {
                        $next = ProductImage::where('woo_product_id', $this->record->id)->orderBy('sort_order')->orderBy('id')->first();
                        if ($next) {
                            $next->setAsPrimary();
                        } else {
                            WooProduct::where('id', $this->record->id)->update(['main_image_url' => null]);
                        }
                    }

                    Notification::make()->success()->title('Imaginea a fost ștearsă.')->send();
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record->getRouteKey()]));
                }),

            // ── Gallery: adaugă URL manual ────────────────────────────────
            Actions\Action::make('gallery_add_url')
                ->label('Adaugă URL imagine')
                ->extraAttributes(['class' => 'hidden'])
                ->modalHeading('Adaugă imagine')
                ->modalSubmitActionLabel('Adaugă')
                ->form([
                    \Filament\Forms\Components\TextInput::make('url')
                        ->label('URL imagine')
                        ->url()
                        ->required()
                        ->placeholder('https://...'),
                    \Filament\Forms\Components\Toggle::make('is_primary')
                        ->label('Setează ca principală')
                        ->default(fn () => $this->record->images()->count() === 0),
                ])
                ->action(function (array $data): void {
                    /** @var WooProduct $product */
                    $product = $this->record;

                    // Evităm duplicate
                    $exists = ProductImage::where('woo_product_id', $product->id)
                        ->where('url', $data['url'])
                        ->exists();

                    if ($exists) {
                        Notification::make()->warning()->title('Această imagine există deja.')->send();

                        return;
                    }

                    $maxOrder = ProductImage::where('woo_product_id', $product->id)->max('sort_order') ?? -1;

                    $image = ProductImage::create([
                        'woo_product_id' => $product->id,
                        'url'            => $data['url'],
                        'sort_order'     => $maxOrder + 1,
                        'is_primary'     => false,
                        'source'         => ProductImage::SOURCE_MANUAL,
                    ]);

                    if ($data['is_primary'] ?? false) {
                        $image->setAsPrimary();
                    }

                    Notification::make()->success()->title('Imaginea a fost adăugată.')->send();
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $product->getRouteKey()]));
                }),

            // ── Gallery: mută imaginea mai în față (swap sort_order cu precedenta) ──
            Actions\Action::make('gallery_move_before')
                ->label('Mută mai în față')
                ->extraAttributes(['class' => 'hidden'])
                ->action(function (array $arguments): void {
                    $imageId = (int) ($arguments['image_id'] ?? 0);
                    if (! $imageId) {
                        return;
                    }

                    $image = ProductImage::where('id', $imageId)
                        ->where('woo_product_id', $this->record->id)
                        ->first();

                    if (! $image) {
                        return;
                    }

                    $prev = ProductImage::where('woo_product_id', $this->record->id)
                        ->where('sort_order', '<', $image->sort_order)
                        ->orderByDesc('sort_order')
                        ->first();

                    if ($prev) {
                        [$image->sort_order, $prev->sort_order] = [$prev->sort_order, $image->sort_order];
                        $image->save();
                        $prev->save();
                    }

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record->getRouteKey()]));
                }),

            // ── Gallery: mută imaginea mai în spate (swap sort_order cu următoarea) ──
            Actions\Action::make('gallery_move_after')
                ->label('Mută mai în spate')
                ->extraAttributes(['class' => 'hidden'])
                ->action(function (array $arguments): void {
                    $imageId = (int) ($arguments['image_id'] ?? 0);
                    if (! $imageId) {
                        return;
                    }

                    $image = ProductImage::where('id', $imageId)
                        ->where('woo_product_id', $this->record->id)
                        ->first();

                    if (! $image) {
                        return;
                    }

                    $next = ProductImage::where('woo_product_id', $this->record->id)
                        ->where('sort_order', '>', $image->sort_order)
                        ->orderBy('sort_order')
                        ->first();

                    if ($next) {
                        [$image->sort_order, $next->sort_order] = [$next->sort_order, $image->sort_order];
                        $image->save();
                        $next->save();
                    }

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record->getRouteKey()]));
                }),

            // ── Gallery: import poze din Toya ─────────────────────────────
            Actions\Action::make('gallery_import_toya')
                ->label('Import poze Toya')
                ->extraAttributes(['class' => 'hidden'])
                ->modalHeading('Import imagini din Toya')
                ->modalDescription('Se vor importa toate imaginile suplimentare din feedul Toya. Imaginile deja existente nu vor fi duplicate.')
                ->modalSubmitActionLabel('Importă')
                ->visible(fn () => $this->record->source === WooProduct::SOURCE_TOYA_API)
                ->action(function (): void {
                    ImportToyaImagesJob::dispatch($this->record->id);
                    Notification::make()->success()
                        ->title('Job trimis')
                        ->body('Imaginile vor fi importate în câteva secunde. Reîncarcă pagina după.')
                        ->send();
                }),

        ];
    }
}
