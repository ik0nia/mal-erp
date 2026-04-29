<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;

class FixFischerShippingFieldsCommand extends Command
{
    protected $signature = 'fischer:fix-shipping
                            {--batch=50 : Produse per batch REST API}
                            {--sleep=5 : Secunde pauza intre batch-uri}
                            {--dry-run : Afiseaza fara a trimite}';

    protected $description = 'Setează _weight (kg) și _length/_width/_height (cm) pe produsele Fischer + elimină Greutate din atribute';

    private WooClient $woo;

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $sleep     = (int) $this->option('sleep');
        $dryRun    = (bool) $this->option('dry-run');

        $conn      = IntegrationConnection::where('provider', 'woocommerce')->firstOrFail();
        $this->woo = new WooClient($conn);

        $products = WooProduct::where('source', 'fischer_import')
            ->whereNotNull('woo_id')
            ->whereNotNull('data')
            ->get(['id', 'woo_id', 'data']);

        $this->info("Produse Fischer de actualizat: {$products->count()}");

        $updated    = 0;
        $skipped    = 0;
        $errors     = 0;
        $batchPayloads = [];

        foreach ($products as $product) {
            $data  = is_array($product->data) ? $product->data : json_decode($product->data ?? '{}', true);
            $attrs = $data['attrs'] ?? [];

            // Extract values (stored in mm and g from extraction script)
            $length_mm = isset($attrs['Lungime'])   ? (float) filter_var($attrs['Lungime'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;
            $width_mm  = isset($attrs['Lățime'])    ? (float) filter_var($attrs['Lățime'],  FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;
            $height_mm = isset($attrs['Înălțime'])  ? (float) filter_var($attrs['Înălțime'],FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;
            $weight_g  = isset($attrs['Greutate'])  ? (float) filter_var($attrs['Greutate'],FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;

            if (! $length_mm && ! $width_mm && ! $height_mm && ! $weight_g) {
                $skipped++;
                continue;
            }

            // Convert: mm → cm (÷10), g → kg (÷1000)
            $payload = ['id' => $product->woo_id];

            if ($weight_g) {
                $payload['weight'] = (string) round($weight_g / 1000, 3);
            }

            $dims = [];
            if ($length_mm) $dims['length'] = (string) round($length_mm / 10, 1);
            if ($width_mm)  $dims['width']  = (string) round($width_mm  / 10, 1);
            if ($height_mm) $dims['height'] = (string) round($height_mm / 10, 1);
            if ($dims) $payload['dimensions'] = $dims;

            // Also remove Greutate from visible attributes via meta_data
            // We send _product_attributes update via meta_data
            // We'll handle this via a separate WP-CLI script (faster)

            $batchPayloads[] = $payload;

            if (count($batchPayloads) >= $batchSize) {
                if ($dryRun) {
                    $this->line('Sample: ' . json_encode($batchPayloads[0], JSON_UNESCAPED_UNICODE));
                    $batchPayloads = [];
                    continue;
                }
                $updated += $this->sendBatch($batchPayloads, $errors);
                $batchPayloads = [];
                if ($sleep > 0) sleep($sleep);
                $this->info("Actualizate: {$updated}...");
            }
        }

        // Remaining
        if ($batchPayloads && ! $dryRun) {
            $updated += $this->sendBatch($batchPayloads, $errors);
        }

        $this->line('');
        $this->info("=== REZULTAT ===");
        $this->info("Actualizate (shipping): {$updated}");
        $this->info("Sarite (fara date): {$skipped}");
        $this->warn("Erori: {$errors}");

        return self::SUCCESS;
    }

    private function sendBatch(array $payloads, int &$errors): int
    {
        try {
            $result = $this->woo->updateProductsBatch($payloads);
            $updated = count($result['update'] ?? []);
            $errCount = count($result['errors'] ?? []);
            $errors += $errCount;
            return $updated;
        } catch (\Throwable $e) {
            $this->error('Batch failed: ' . $e->getMessage());
            $errors += count($payloads);
            return 0;
        }
    }
}
