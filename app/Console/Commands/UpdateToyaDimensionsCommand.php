<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class UpdateToyaDimensionsCommand extends Command
{
    protected $signature = 'toya:update-dimensions
                            {--limit=0    : Limitează numărul de produse procesate (0 = toate)}
                            {--dry-run    : Afișează ce s-ar seta fără a salva}';

    protected $description = 'Actualizează dim_length/width/height/weight din câmpurile HE/MC ale API-ului Toya';

    private string $apiKey;

    public function handle(): int
    {
        $this->apiKey = AppSetting::getEncrypted(AppSetting::KEY_TOYA_API_KEY)
            ?? env('TOYA_API_KEY', 'D83FD59A4902793862EB8304');

        $dryRun = $this->option('dry-run');
        $limit  = (int) $this->option('limit');

        if ($dryRun) {
            $this->warn('--- MOD DRY-RUN ---');
        }

        $query = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNotNull('sku');

        if ($limit > 0) {
            $query->limit($limit);
        }

        // Luăm codul Toya real din product_suppliers.supplier_sku
        // (SKU-ul din woo_products e EAN-ul, codul Toya e în product_suppliers)
        $products = $query->get(['id', 'sku', 'weight', 'dim_length', 'dim_width', 'dim_height']);

        $toyaCodes = \Illuminate\Support\Facades\DB::table('product_suppliers')
            ->whereIn('woo_product_id', $products->pluck('id'))
            ->pluck('supplier_sku', 'woo_product_id');

        $this->info('Produse de procesat: ' . $products->count());

        $bar   = $this->output->createProgressBar($products->count());
        $stats = ['updated' => 0, 'no_he_mc' => 0, 'failed' => 0];

        foreach ($products as $product) {
            $toyaCode = $toyaCodes[$product->id] ?? null;

            if (! $toyaCode) {
                $stats['failed']++;
                $bar->advance();
                continue;
            }

            try {
                $raw = $this->fetchProduct($toyaCode);
                if (! $raw) {
                    $stats['failed']++;
                    $bar->advance();
                    continue;
                }

                // Dimensiuni: HE (ambalaj unitar) fallback MC (cutie master), mm → cm
                $dimLength = $this->mmToCm($raw['LengthHE'] ?? null) ?? $this->mmToCm($raw['LengthMC'] ?? null);
                $dimWidth  = $this->mmToCm($raw['WidthHE'] ?? null)  ?? $this->mmToCm($raw['WidthMC'] ?? null);
                $dimHeight = $this->mmToCm($raw['HeightHE'] ?? null) ?? $this->mmToCm($raw['HeightMC'] ?? null);

                // Greutate brută (cu ambalaj)
                $weight = isset($raw['BruttoWeight']['value']) && (float) $raw['BruttoWeight']['value'] > 0
                    ? (float) $raw['BruttoWeight']['value']
                    : (isset($raw['NettoWeight']['value']) ? (float) $raw['NettoWeight']['value'] : null);

                // Ambalare/comandare
                $qtyPerInnerBox   = $this->toInt($raw['IB'] ?? null);
                $qtyPerCarton     = $this->toInt($raw['MC'] ?? null);
                $cartonsPerPallet = $this->toInt($raw['AC'] ?? null);
                $eanCarton        = isset($raw['EanMC']) && $raw['EanMC'] !== 'N/A' ? (string) $raw['EanMC'] : null;

                if (! $dimLength && ! $dimWidth && ! $dimHeight && ! $qtyPerCarton) {
                    $stats['no_he_mc']++;
                    $bar->advance();
                    continue;
                }

                if ($dryRun) {
                    $this->newLine();
                    $this->line("  [{$toyaCode}] L={$dimLength} W={$dimWidth} H={$dimHeight} kg={$weight} | IB={$qtyPerInnerBox} MC={$qtyPerCarton} AC={$cartonsPerPallet}");
                } else {
                    $update = array_filter([
                        'dim_length'          => $dimLength,
                        'dim_width'           => $dimWidth,
                        'dim_height'          => $dimHeight,
                        'weight'              => $weight,
                        'qty_per_inner_box'   => $qtyPerInnerBox,
                        'qty_per_carton'      => $qtyPerCarton,
                        'cartons_per_pallet'  => $cartonsPerPallet,
                        'ean_carton'          => $eanCarton,
                    ], fn ($v) => $v !== null);

                    if (! empty($update)) {
                        $product->update($update);
                    }
                }

                $stats['updated']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Statistică', 'Valoare'],
            [
                ['Actualizate',         $stats['updated']],
                ['Fără dim HE/MC',      $stats['no_he_mc']],
                ['Erori/fără cod Toya', $stats['failed']],
            ]
        );

        return self::SUCCESS;
    }

    private function fetchProduct(string $code): ?array
    {
        $response = Http::withoutVerifying()->timeout(30)
            ->get('https://pim.toya.pl/dataapi', [
                'key'    => $this->apiKey,
                'action' => 'getData',
                'code'   => $code,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return ($json['success'] ?? false) ? ($json['data'] ?? null) : null;
    }

    private function mmToCm(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === 'N/A') {
            return null;
        }
        $v = (float) $value;

        return $v > 0 ? round($v / 10, 2) : null;
    }

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'N/A') {
            return null;
        }
        $v = (int) $value;

        return $v > 0 ? $v : null;
    }
}
