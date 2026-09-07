<?php

namespace App\Filament\App\Resources\WooProductResource\Pages;

use App\Filament\App\Resources\WooProductResource;
use App\Models\IntegrationConnection;
use App\Models\ProductImage;
use App\Models\ProductSupplier;
use App\Models\WooCategory;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Creare produs NOU din ERP, cu publicare automată pe site: date complete
 * (preț, categorii, descrieri, dimensiuni, furnizor, imagini) → WooCommerce
 * → woo_id salvat local → sync-urile existente preiau de aici (meta furnizor,
 * prețuri, stoc).
 */
class CreateWooProduct extends CreateRecord
{
    protected static string $resource = WooProductResource::class;

    protected static ?string $title = 'Produs nou';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Informații de bază')
                ->columns(12)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Denumire produs')->required()->maxLength(255)->columnSpan(8),
                    Forms\Components\TextInput::make('sku')
                        ->label('SKU / EAN')->required()->maxLength(100)
                        ->unique(table: 'woo_products', column: 'sku')
                        ->helperText('Codul de bare (EAN) sau codul intern — unic.')
                        ->columnSpan(4),
                    Forms\Components\TextInput::make('regular_price')
                        ->label('Preț vânzare (cu TVA)')->numeric()->minValue(0)->step('0.01')
                        ->suffix('RON')->required()->columnSpan(3),
                    Forms\Components\Select::make('stock_status')
                        ->label('Disponibilitate')
                        ->options(['instock' => 'În stoc', 'outofstock' => 'Stoc epuizat', 'onbackorder' => 'La comandă'])
                        ->default('instock')->native(false)->columnSpan(3),
                    Forms\Components\Select::make('publish_status')
                        ->label('Publicare pe site')
                        ->options(['publish' => 'Publicat imediat', 'draft' => 'Ciornă (nepublicat)'])
                        ->default('publish')->native(false)->columnSpan(3),
                    Forms\Components\TextInput::make('unit')
                        ->label('Unitate de măsură')->placeholder('buc')->maxLength(20)->columnSpan(3),
                    Forms\Components\Select::make('category_ids')
                        ->label('Categorii site')
                        ->multiple()->searchable()->preload()
                        ->options(fn () => WooCategory::whereNotNull('woo_id')->orderBy('name')->pluck('name', 'id')->all())
                        ->columnSpanFull(),
                ]),

            Section::make('Descrieri')
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    Forms\Components\Textarea::make('short_description')
                        ->label('Descriere scurtă')->rows(2),
                    Forms\Components\RichEditor::make('description')
                        ->label('Descriere completă'),
                ]),

            Section::make('Dimensiuni & greutate (pentru transport)')
                ->columns(4)->columnSpanFull()->collapsible()->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('weight')->label('Greutate (kg)')->numeric()->minValue(0)->step('0.001'),
                    Forms\Components\TextInput::make('dim_length')->label('Lungime (cm)')->numeric()->minValue(0),
                    Forms\Components\TextInput::make('dim_width')->label('Lățime (cm)')->numeric()->minValue(0),
                    Forms\Components\TextInput::make('dim_height')->label('Înălțime (cm)')->numeric()->minValue(0),
                ]),

            Section::make('Furnizor')
                ->columns(12)->columnSpanFull()->collapsible()
                ->schema([
                    Forms\Components\Select::make('supplier_id')
                        ->label('Furnizor')
                        ->searchable()->preload()
                        ->options(fn () => \App\Models\Supplier::orderBy('name')->pluck('name', 'id')->all())
                        ->columnSpan(6),
                    Forms\Components\TextInput::make('supplier_sku')
                        ->label('Cod la furnizor')->maxLength(100)->columnSpan(3),
                    Forms\Components\TextInput::make('purchase_price')
                        ->label('Preț achiziție (fără TVA)')->numeric()->minValue(0)->step('0.0001')->suffix('RON')->columnSpan(3),
                ]),

            Section::make('Imagini')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\FileUpload::make('images')
                        ->label('Imagini produs (prima devine principală)')
                        ->image()->multiple()->maxFiles(10)->maxSize(8192)
                        ->disk('public')->directory('product-images/new')
                        ->imageEditor()->reorderable(),
                ]),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $connection = IntegrationConnection::where('provider', 'woocommerce')->where('is_active', true)->first();

        $product = WooProduct::create([
            'connection_id'   => $connection?->id,
            'name'            => trim($data['name']),
            'sku'             => trim($data['sku']),
            'regular_price'   => (float) $data['regular_price'],
            'price'           => (float) $data['regular_price'],
            'status'          => $data['publish_status'] ?? 'publish',
            'stock_status'    => $data['stock_status'] ?? 'instock',
            'type'            => 'simple',
            'unit'            => trim((string) ($data['unit'] ?? '')) ?: null,
            'description'       => $data['description'] ?? null,
            'short_description' => $data['short_description'] ?? null,
            'weight'          => filled($data['weight'] ?? null) ? (float) $data['weight'] : null,
            'dim_length'      => filled($data['dim_length'] ?? null) ? (float) $data['dim_length'] : null,
            'dim_width'       => filled($data['dim_width'] ?? null) ? (float) $data['dim_width'] : null,
            'dim_height'      => filled($data['dim_height'] ?? null) ? (float) $data['dim_height'] : null,
        ]);

        // Categorii locale
        if (! empty($data['category_ids'])) {
            $product->categories()->sync($data['category_ids']);
        }

        // Furnizor
        if (! empty($data['supplier_id'])) {
            ProductSupplier::create([
                'woo_product_id' => $product->id,
                'supplier_id'    => (int) $data['supplier_id'],
                'supplier_sku'   => trim((string) ($data['supplier_sku'] ?? '')) ?: null,
                'purchase_price' => filled($data['purchase_price'] ?? null) ? (float) $data['purchase_price'] : null,
                'is_preferred'   => true,
            ]);
        }

        // Imagini locale (prima = principală)
        $urls = [];
        foreach ((array) ($data['images'] ?? []) as $i => $path) {
            $url    = rtrim(config('app.url'), '/').'/storage/'.ltrim($path, '/');
            $urls[] = $url;
            ProductImage::create([
                'woo_product_id' => $product->id,
                'url'            => $url,
                'local_path'     => $path,
                'sort_order'     => $i,
                'is_primary'     => $i === 0,
                'source'         => ProductImage::SOURCE_MANUAL,
            ]);
            if ($i === 0) {
                $product->update(['main_image_url' => $url]);
            }
        }

        // ── Publicare pe site ─────────────────────────────────────────────
        $this->pushToWoo($product, $data, $urls, $connection);

        return $product;
    }

    private function pushToWoo(WooProduct $product, array $data, array $imageUrls, ?IntegrationConnection $connection): void
    {
        if (! $connection) {
            Notification::make()->warning()->title('Produs creat DOAR în ERP')
                ->body('Nicio conexiune WooCommerce activă — publicarea pe site nu s-a făcut.')->persistent()->send();
            return;
        }

        $catWooIds = WooCategory::whereIn('id', $data['category_ids'] ?? [])
            ->whereNotNull('woo_id')->pluck('woo_id')
            ->map(fn ($id) => ['id' => (int) $id])->values()->all();

        $payload = [
            'name'              => $product->name,
            'sku'               => $product->sku,
            'type'              => 'simple',
            'status'            => $product->status,
            'regular_price'     => (string) $product->regular_price,
            'stock_status'      => $product->stock_status,
            'manage_stock'      => false,
            'description'       => (string) ($product->description ?? ''),
            'short_description' => (string) ($product->short_description ?? ''),
        ];

        if ($product->weight)  $payload['weight'] = (string) $product->weight;
        if ($product->dim_length || $product->dim_width || $product->dim_height) {
            $payload['dimensions'] = [
                'length' => (string) ($product->dim_length ?? ''),
                'width'  => (string) ($product->dim_width ?? ''),
                'height' => (string) ($product->dim_height ?? ''),
            ];
        }
        if ($catWooIds)  $payload['categories'] = $catWooIds;
        if ($imageUrls)  $payload['images'] = array_map(fn ($u) => ['src' => $u], $imageUrls);

        try {
            $client  = new WooClient($connection);
            $result  = $client->createProductsBatch([$payload]);
            $created = $result['created'][0] ?? null;

            if (! $created) {
                $msg = data_get($result, 'errors.0.error.message', 'Eroare necunoscută');
                Notification::make()->danger()->title('Produs creat în ERP, dar NU pe site')
                    ->body('WooCommerce a refuzat: '.$msg.' — corectează și folosește „Creează în WooCommerce" de pe pagina produsului.')
                    ->persistent()->send();
                return;
            }

            $product->update([
                'woo_id' => $created['id'],
                'data'   => $created,
            ]);

            // ID-urile media din răspuns — pentru sync-urile viitoare de galerie
            foreach (collect($created['images'] ?? [])->values() as $i => $wooImg) {
                ProductImage::where('woo_product_id', $product->id)
                    ->orderBy('sort_order')->skip($i)->limit(1)
                    ->update(['woo_media_id' => $wooImg['id'] ?? null]);
            }

            // Meta furnizor pe site (jobul existent)
            if (! empty($data['supplier_id'])) {
                \App\Jobs\SyncProductSupplierMetaJob::dispatch($product->id)->onQueue('default');
            }

            Notification::make()->success()->title('Produs creat și publicat pe site')
                ->body('woo_id: '.$created['id'].' — '.($created['permalink'] ?? ''))
                ->persistent()->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Produs creat în ERP, dar publicarea a eșuat')
                ->body($e->getMessage().' — folosește „Creează în WooCommerce" de pe pagina produsului.')
                ->persistent()->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record->getRouteKey()]);
    }
}
