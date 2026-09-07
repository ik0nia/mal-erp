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
use Illuminate\Database\Eloquent\Model;
use Throwable;

class ViewWooProduct extends ViewRecord
{
    protected static string $resource = WooProductResource::class;

    public function getHeading(): string
    {
        return '';
    }

    public function getBreadcrumbs(): array
    {
        /** @var WooProduct $product */
        $product = $this->record;
        $product->loadMissing('categories.parent');

        $breadcrumbs = [
            WooProductResource::getUrl() => 'Produse',
        ];

        // Ia categoria cea mai specifică (cea cu cel mai mic count sau cu parent_id setat)
        $category = $product->categories
            ->sortByDesc(fn ($c) => $c->parent_id ? 1 : 0)
            ->first();

        if ($category) {
            foreach ($category->getAncestorsPath() as $ancestor) {
                $url = WooProductResource::getUrl('index', [
                    'tableFilters' => ['category_id' => ['value' => (string) $ancestor->id]],
                ]);
                $breadcrumbs[$url] = $ancestor->name;
            }
        }

        $breadcrumbs[] = $product->decoded_name ?? $product->name;

        return $breadcrumbs;
    }

    // getEloquentQuery() filtrează is_placeholder=false, deci placeholder-urile
    // (ex. produse WinMentor/Temad nepublicate) ar genera 404. Interogăm direct.
    protected function resolveRecord(int|string $key): Model
    {
        return WooProduct::findOrFail($key);
    }

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

    /** Sincronizarea galeriei pe site — după orice modificare, dacă produsul există în Woo. */
    private function dispatchImageSync(): void
    {
        if ($this->record->woo_id && ! $this->record->is_placeholder) {
            \App\Jobs\SyncProductImagesToWooJob::dispatch($this->record->id)->onQueue('default');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // ── Creează în WooCommerce (produse fără woo_id valid) ─────────
            Actions\Action::make('create_in_woo')
                ->label('Creează în WooCommerce')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('primary')
                ->visible(function (): bool {
                    /** @var WooProduct $product */
                    $product = $this->record;
                    // ID valid WooCommerce = număr mic; ID-urile false importate sunt > 1 miliard
                    return ! $product->woo_id || $product->woo_id > 1_000_000_000;
                })
                ->requiresConfirmation()
                ->modalHeading('Creează produsul în WooCommerce')
                ->modalDescription('Produsul va fi creat și publicat pe site cu datele disponibile (nume, SKU, preț, categorie, imagine).')
                ->modalSubmitActionLabel('Creează și publică')
                ->action(function (): void {
                    /** @var WooProduct $product */
                    $product = $this->record;

                    if (! $product->connection_id) {
                        Notification::make()->danger()->title('Nicio conexiune WooCommerce configurată')->send();
                        return;
                    }

                    $client = new WooClient($product->connection);

                    // Categorii WooCommerce
                    $catIds = $product->categories()
                        ->whereNotNull('woo_id')
                        ->pluck('woo_id')
                        ->map(fn ($id) => ['id' => (int) $id])
                        ->values()
                        ->all();

                    $payload = [
                        'name'          => $product->name,
                        'sku'           => $product->sku,
                        'status'        => 'publish',
                        'regular_price' => (string) ($product->regular_price ?? ''),
                        'stock_status'  => $product->stock_status ?? 'instock',
                        'manage_stock'  => false,
                        'type'          => 'simple',
                    ];

                    if ($catIds) {
                        $payload['categories'] = $catIds;
                    }

                    if ($product->main_image_url) {
                        $payload['images'] = [['src' => $product->main_image_url]];
                    }

                    try {
                        $result  = $client->createProductsBatch([$payload]);
                        $created = $result['created'][0] ?? null;
                        $errors  = $result['errors'] ?? [];

                        // Dacă a eșuat din cauza imaginii, reîncercăm fără imagine
                        if (! $created && $errors) {
                            $errCode = data_get($errors, '0.error.code', '');
                            if (str_contains($errCode, 'image')) {
                                unset($payload['images']);
                                $result2  = $client->createProductsBatch([$payload]);
                                $created  = $result2['created'][0] ?? null;
                                $errors   = $result2['errors'] ?? [];
                            }
                        }

                        if ($created) {
                            $product->update(['woo_id' => $created['id'], 'status' => 'publish']);
                            Notification::make()->success()
                                ->title('Produs creat în WooCommerce')
                                ->body('woo_id: ' . $created['id'] . ($payload['images'] ?? null ? '' : ' (fără imagine — URL incompatibil)'))
                                ->send();
                        } else {
                            $msg = data_get($errors, '0.error.message', 'Eroare necunoscută');
                            Notification::make()->danger()->title('Eroare la creare')->body($msg)->send();
                            return;
                        }
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Eroare WooCommerce')->body($e->getMessage())->send();
                        return;
                    }

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $product->getRouteKey()]));
                }),

            // ── Toggle publish/unpublish ──────────────────────────────────
            Actions\Action::make('toggle_publish')
                ->label(fn () => $this->record->status === 'publish' ? 'Retrage de pe site' : 'Publică pe site')
                ->icon(fn () => $this->record->status === 'publish' ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                ->color(fn () => $this->record->status === 'publish' ? 'danger' : 'success')
                ->visible(fn () => (bool) $this->record->woo_id && (bool) $this->record->connection_id)
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->record->status === 'publish' ? 'Retrage produsul de pe site?' : 'Publică produsul pe site?')
                ->modalDescription(fn () => $this->record->status === 'publish'
                    ? 'Produsul va deveni draft și nu va mai fi vizibil pe site.'
                    : 'Produsul va fi publicat și va deveni vizibil pe site.')
                ->modalSubmitActionLabel(fn () => $this->record->status === 'publish' ? 'Retrage' : 'Publică')
                ->action(function (): void {
                    /** @var WooProduct $product */
                    $product   = $this->record;
                    $newStatus = $product->status === 'publish' ? 'draft' : 'publish';

                    try {
                        $client   = new WooClient($product->connection);
                        $response = $client->updateProductStatus((int) $product->woo_id, $newStatus);
                        $confirmed = $response['status'] ?? $newStatus;
                        $product->update(['status' => $confirmed]);

                        Notification::make()
                            ->success()
                            ->title($confirmed === 'publish' ? 'Produs publicat pe site' : 'Produs retras de pe site')
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Eroare WooCommerce')->body($e->getMessage())->send();

                        return;
                    }

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $product->getRouteKey()]));
                }),

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
            // ── Gallery: UPLOAD imagini de pe calculator ───────────────────
            Actions\Action::make('gallery_upload')
                ->label('Urcă imagini')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalHeading('Urcă imagini pentru produs')
                ->modalDescription('Imaginile se salvează în ERP și se sincronizează automat pe site (prima urcată devine principală dacă produsul nu are deja una).')
                ->modalSubmitActionLabel('Urcă și sincronizează')
                ->form([
                    \Filament\Forms\Components\FileUpload::make('files')
                        ->label('Imagini')
                        ->image()
                        ->multiple()
                        ->maxFiles(10)
                        ->maxSize(8192)
                        ->disk('public')
                        ->directory(fn (): string => 'product-images/'.$this->record->id)
                        ->imageEditor()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var WooProduct $product */
                    $product  = $this->record;
                    $maxOrder = ProductImage::where('woo_product_id', $product->id)->max('sort_order') ?? -1;
                    $hadNone  = $product->images()->count() === 0;
                    $added    = 0;

                    foreach ((array) ($data['files'] ?? []) as $path) {
                        $url = rtrim(config('app.url'), '/').'/storage/'.ltrim($path, '/');

                        $image = ProductImage::create([
                            'woo_product_id' => $product->id,
                            'url'            => $url,
                            'local_path'     => $path,
                            'sort_order'     => ++$maxOrder,
                            'is_primary'     => false,
                            'source'         => ProductImage::SOURCE_MANUAL,
                        ]);

                        if ($hadNone && $added === 0) {
                            $image->setAsPrimary();
                        }
                        $added++;
                    }

                    $this->dispatchImageSync();
                    Notification::make()->success()->title($added.' imagine(i) urcate')
                        ->body('Sincronizarea cu site-ul rulează în fundal (câteva secunde).')->send();
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $product->getRouteKey()]));
                }),

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
                    $this->dispatchImageSync();

                    Notification::make()->success()->title('Imaginea principală a fost actualizată — se sincronizează pe site.')->send();
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

                    $this->dispatchImageSync();
                    Notification::make()->success()->title('Imaginea a fost ștearsă — se sincronizează pe site.')->send();
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

                    $this->dispatchImageSync();
                    Notification::make()->success()->title('Imaginea a fost adăugată — se sincronizează pe site.')->send();
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
                        $this->dispatchImageSync();
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
                        $this->dispatchImageSync();
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
