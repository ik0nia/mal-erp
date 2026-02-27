<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PushWinmentorProductsToWooCommand extends Command
{
    protected $signature = 'woo:push-winmentor-products
                            {--dry-run : Afișează payload-ul fără a trimite}
                            {--limit=0 : Limitează numărul de produse procesate (0 = toate)}
                            {--batch=10 : Produse per request batch}
                            {--skip-images : Trimite fără imagini (util când WP blochează upload)}';

    protected $description = 'Creează produsele WinMentor (placeholder) în WooCommerce via API batch';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $limit    = (int) $this->option('limit');
        $batchSize = max(1, min(50, (int) $this->option('batch')));

        $this->info('Push produse WinMentor → WooCommerce' . ($dryRun ? ' [DRY RUN]' : ''));

        // Conexiunea WooCommerce
        $connection = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $connection) {
            $this->error('Nicio conexiune WooCommerce activă găsită.');
            return self::FAILURE;
        }

        $this->line("Conexiune: {$connection->base_url} (id={$connection->id})");

        // Produse de creat: WinMentor, publish, placeholder (woo_id fake)
        $query = WooProduct::query()
            ->where('source', WooProduct::SOURCE_WINMENTOR_CSV)
            ->where('status', 'publish')
            ->where('is_placeholder', true)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        $this->line("Produse de creat: {$total}");

        if ($total === 0) {
            $this->info('Niciun produs de creat.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $sample = $query->limit(2)->get();
            foreach ($sample as $p) {
                $this->line('--- PAYLOAD SAMPLE ---');
                $this->line(json_encode($this->buildPayload($p), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            return self::SUCCESS;
        }

        $client  = new WooClient($connection);
        $created = 0;
        $failed  = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunk($batchSize, function ($products) use ($client, $connection, &$created, &$failed, $bar) {
            $payloads   = [];
            $productMap = [];

            foreach ($products as $product) {
                $payload = $this->buildPayload($product, (bool) $this->option('skip-images'));
                $payloads[]                    = $payload;
                $productMap[$product->sku]     = $product;
            }

            try {
                $response = $client->createProductsBatch($payloads);

                foreach ($response['created'] as $result) {
                    $sku     = (string) ($result['sku'] ?? '');
                    $wooId   = (int) ($result['id'] ?? 0);
                    $product = $productMap[$sku] ?? null;

                    if (! $product || $wooId <= 0) {
                        $failed++;
                        continue;
                    }

                    WooProduct::query()->whereKey($product->id)->update([
                        'woo_id'         => $wooId,
                        'is_placeholder' => false,
                        'updated_at'     => now(),
                    ]);

                    $created++;
                }

                foreach ($response['errors'] as $err) {
                    $failed++;
                    $sku  = (string) ($err['sku'] ?? ($err['data']['sku'] ?? '?'));
                    $code = (string) ($err['code'] ?? ($err['error']['code'] ?? 'unknown'));
                    $msg  = (string) ($err['message'] ?? ($err['error']['message'] ?? ''));
                    $this->newLine();
                    $this->warn("  ERR [{$code}] sku={$sku}: {$msg}");
                }

                // Produse trimise dar absente din răspuns (nici create, nici eroare)
                $returned = count($response['created']) + count($response['errors']);
                $missing  = count($payloads) - $returned;
                if ($missing > 0) {
                    $failed += $missing;
                    $this->newLine();
                    $this->warn("  {$missing} produse absente din răspunsul WooCommerce (posibil timeout sau eroare silențioasă)");
                }
            } catch (Throwable $e) {
                $failed += count($products);
                $this->newLine();
                $this->error('Batch error: ' . $e->getMessage());
            }

            $bar->advance(count($products));
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Create: {$created} | Eșuate: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(WooProduct $product, bool $skipImages = false): array
    {
        // Categorii WooCommerce
        $categories = DB::table('woo_product_category as wpc')
            ->join('woo_categories as wc', 'wc.id', '=', 'wpc.woo_category_id')
            ->where('wpc.woo_product_id', $product->id)
            ->where('wc.woo_id', '>', 0)
            ->pluck('wc.woo_id')
            ->map(fn ($id) => ['id' => (int) $id])
            ->values()
            ->all();

        // Atribute
        $rawAttrs = DB::table('woo_product_attributes')
            ->where('woo_product_id', $product->id)
            ->orderBy('position')
            ->get(['name', 'value', 'is_visible', 'position']);

        $attrsGrouped = [];
        foreach ($rawAttrs as $attr) {
            $key = mb_strtolower(trim($attr->name));
            if (! isset($attrsGrouped[$key])) {
                $attrsGrouped[$key] = [
                    'name'      => $attr->name,
                    'visible'   => (bool) $attr->is_visible,
                    'variation' => false,
                    'position'  => (int) $attr->position,
                    'options'   => [],
                ];
            }
            if ($attr->value !== null && $attr->value !== '') {
                $attrsGrouped[$key]['options'][] = $attr->value;
            }
        }

        $attributes = array_values($attrsGrouped);

        // Stoc total
        $stockQty = (int) DB::table('product_stocks')
            ->where('woo_product_id', $product->id)
            ->sum('quantity');

        // Imagine
        $images = [];
        if (! $skipImages && $product->main_image_url) {
            $images[] = ['src' => $product->main_image_url];
        }

        // Dimensiuni
        $dimensions = [];
        if ($product->dim_length || $product->dim_width || $product->dim_height) {
            $dimensions = [
                'length' => (string) ($product->dim_length ?? ''),
                'width'  => (string) ($product->dim_width  ?? ''),
                'height' => (string) ($product->dim_height ?? ''),
            ];
        }

        // Shipping class din JSON
        $data          = is_string($product->data) ? json_decode($product->data, true) : (array) $product->data;
        $shippingClass = (string) ($data['shipping_class'] ?? '');

        return [
            'name'            => $product->name,
            'type'            => 'simple',
            'status'          => 'publish',
            'sku'             => $product->sku,
            'slug'            => $product->slug,
            'description'     => $product->description ?? '',
            'regular_price'   => (string) ($product->regular_price ?? ''),
            'manage_stock'    => true,
            'backorders'      => 'yes',
            'stock_quantity'  => $stockQty,
            'stock_status'    => $stockQty > 0 ? 'instock' : 'outofstock',
            'weight'          => $product->weight ? (string) $product->weight : '',
            'dimensions'      => $dimensions ?: ['length' => '', 'width' => '', 'height' => ''],
            'shipping_class'  => $shippingClass,
            'categories'      => $categories,
            'images'          => $images,
            'attributes'      => $attributes,
            'tax_status'      => 'taxable',
            'tax_class'       => '',
            'meta_data'       => [
                ['key' => '_winmentor_source', 'value' => '1'],
            ],
        ];
    }
}
