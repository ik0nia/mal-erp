<?php

namespace App\Jobs;

use App\Models\AppSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Populează supplier_sku (codul Toya) pe product_suppliers pentru furnizorul Toya.
 *
 * Mod de funcționare:
 *  - Dacă $codes e gol → e job-ul orchestrator: preia codurile din API, le împarte
 *    în batch-uri de 500 și dispatch-ează câte un sub-job per batch.
 *  - Dacă $codes e furnizat → procesează acel batch (fetch getData per cod, match EAN).
 */
class FixToyaSupplierSkuJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 540; // 9 min (sub limita Horizon de 10 min)

    private const API_BASE    = 'https://pim.toya.pl/dataapi';
    private const SUPPLIER_ID = 112;
    private const BATCH_SIZE  = 500;

    public function __construct(
        private readonly array $codes = [],
    ) {}

    public function handle(): void
    {
        if (empty($this->codes)) {
            $this->orchestrate();
        } else {
            $this->processBatch();
        }
    }

    private function orchestrate(): void
    {
        $apiKey = $this->getApiKey();
        if (! $apiKey) return;

        Log::info('[FixToyaSupplierSku] Orchestrator — preiau codurile din getPricesRo...');

        $prices = $this->fetchBulk($apiKey, 'getPricesRo');

        if (empty($prices)) {
            Log::error('[FixToyaSupplierSku] getPricesRo a returnat 0 coduri.');
            return;
        }

        $codes  = array_keys($prices);
        $total  = count($codes);
        $chunks = array_chunk($codes, self::BATCH_SIZE);

        Log::info("[FixToyaSupplierSku] {$total} coduri → " . count($chunks) . ' batch-uri de câte ' . self::BATCH_SIZE);

        foreach ($chunks as $i => $chunk) {
            self::dispatch($chunk)
                ->onQueue('default')
                ->delay(now()->addSeconds($i * 2)); // 2s între batch-uri
        }

        Log::info('[FixToyaSupplierSku] Toate batch-urile au fost dispatched.');
    }

    private function processBatch(): void
    {
        $apiKey = $this->getApiKey();
        if (! $apiKey) return;

        // Pre-load: EAN → ps.id (doar cele fără supplier_sku)
        $eanToPsId = DB::table('product_suppliers as ps')
            ->join('woo_products as wp', 'wp.id', '=', 'ps.woo_product_id')
            ->where('ps.supplier_id', self::SUPPLIER_ID)
            ->whereNull('ps.supplier_sku')
            ->select('ps.id as ps_id', 'wp.sku as ean')
            ->get()
            ->keyBy('ean');

        if ($eanToPsId->isEmpty()) {
            return; // Nimic de reparat
        }

        $updated = 0;
        $failed  = 0;

        foreach ($this->codes as $i => $code) {
            if ($eanToPsId->isEmpty()) break;

            try {
                $response = Http::withoutVerifying()
                    ->timeout(15)
                    ->get(self::API_BASE, ['key' => $apiKey, 'action' => 'getData', 'code' => $code]);

                if (! $response->successful()) {
                    $failed++;
                    continue;
                }

                $json = $response->json();
                if (! ($json['success'] ?? false)) {
                    $failed++;
                    continue;
                }

                $ean = (string) ($json['data']['Ean'] ?? '');

                if ($ean && $eanToPsId->has($ean)) {
                    $psId = $eanToPsId->get($ean)->ps_id;
                    DB::table('product_suppliers')
                        ->where('id', $psId)
                        ->update(['supplier_sku' => $code, 'updated_at' => now()]);
                    $eanToPsId->forget($ean);
                    $updated++;
                }
            } catch (\Throwable) {
                $failed++;
            }

            // Rate limit: 50ms pause every 10 requests
            if (($i + 1) % 10 === 0) {
                usleep(50_000);
            }
        }

        if ($updated > 0 || $failed > 0) {
            Log::info("[FixToyaSupplierSku] Batch done: updated={$updated} failed={$failed} (din " . count($this->codes) . ' coduri)');
        }
    }

    private function getApiKey(): ?string
    {
        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_TOYA_API_KEY)
            ?? env('TOYA_API_KEY', 'D83FD59A4902793862EB8304');

        if (! $apiKey) {
            Log::error('[FixToyaSupplierSku] API key Toya lipsește.');
        }

        return $apiKey ?: null;
    }

    private function fetchBulk(string $apiKey, string $action): array
    {
        $response = Http::withoutVerifying()
            ->timeout(60)
            ->get(self::API_BASE, ['key' => $apiKey, 'action' => $action]);

        return $response->successful() ? ($response->json() ?? []) : [];
    }
}
