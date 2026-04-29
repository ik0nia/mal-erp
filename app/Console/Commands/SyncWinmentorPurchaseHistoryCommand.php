<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\ProductPurchasePriceLog;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\WooProduct;
use App\Services\Winmentor\WinmentorBridgeClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncWinmentorPurchaseHistoryCommand extends Command
{
    protected $signature = 'winmentor:sync-purchase-history
                            {--delay=600 : Milisecunde pauză între apeluri API}
                            {--stop-after=3 : Oprire după N luni consecutive fără date}
                            {--dry-run : Simulează fără a salva nimic}';

    protected $description = 'Importă istoricul complet al prețurilor de achiziție din WinMentor Bridge';

    private WinmentorBridgeClient $bridge;
    private int $delayMs;
    private bool $dryRun;

    /** partID WinMentor → ['id' => ?int, 'name' => string] */
    private array $supplierCache = [];

    /** SKU → woo_product_id */
    private array $productIndex = [];

    /** winmentor_id → supplier_id */
    private array $supplierIndex = [];

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $this->bridge  = $bridge;
        $this->delayMs = max(100, (int) $this->option('delay'));
        $this->dryRun  = (bool) $this->option('dry-run');
        $stopAfter     = max(1, (int) $this->option('stop-after'));

        if ($this->dryRun) {
            $this->warn('[DRY-RUN] Nu se salvează nimic.');
        }

        $health = $bridge->health();
        if (! ($health['success'] ?? false)) {
            $this->error('WinMentor Bridge nu este accesibil.');
            return self::FAILURE;
        }

        if (! ($health['data']['comConnected'] ?? false)) {
            $this->error('WinMentor Bridge este activ dar COM nu este conectat — pornește WinMentor mai întâi.');
            return self::FAILURE;
        }

        $this->buildIndexes();

        $conn         = IntegrationConnection::find(5);
        $originalAn   = $conn->bridgeAn();
        $originalLuna = $conn->bridgeLuna();

        $totalImported  = 0;
        $totalSkipped   = 0;
        $emptyMonths    = 0;
        $current        = Carbon::now()->startOfMonth();

        $this->info("Start import istoric achiziții WinMentor (delay={$this->delayMs}ms, stop după {$stopAfter} luni goale)");
        $this->line(str_repeat('─', 60));

        while (true) {
            $an   = $current->year;
            $luna = $current->month;

            // Selectează luna
            try {
                $this->pause();
                $this->bridge->selectFirmaForMonth($an, $luna);
            } catch (\Throwable $e) {
                $this->warn("  [{$luna}/{$an}] Eroare selectFirma: {$e->getMessage()}");
                $emptyMonths++;
                if ($emptyMonths >= $stopAfter) break;
                $current->subMonth();
                continue;
            }

            // Obține intrările
            try {
                $this->pause();
                $intrari = $this->bridge->getIntrari();
            } catch (\Throwable $e) {
                $this->warn("  [{$luna}/{$an}] Eroare getIntrari: {$e->getMessage()}");
                $emptyMonths++;
                if ($emptyMonths >= $stopAfter) break;
                $current->subMonth();
                continue;
            }

            if (empty($intrari)) {
                $emptyMonths++;
                $this->line("  [{$luna}/{$an}] 0 întrări (luni goale consecutive: {$emptyMonths}/{$stopAfter})");
                if ($emptyMonths >= $stopAfter) break;
                $current->subMonth();
                continue;
            }

            $emptyMonths = 0;
            [$imported, $skipped] = $this->processIntrari($intrari, $an, $luna);
            $totalImported += $imported;
            $totalSkipped  += $skipped;

            $this->line("  [{$luna}/{$an}] " . count($intrari) . " întrări → {$imported} noi, {$skipped} duplicate");

            $current->subMonth();
        }

        // Restaurează luna originală
        try {
            $this->pause();
            $this->bridge->selectFirmaForMonth($originalAn, $originalLuna);
        } catch (\Throwable) {}

        $this->line(str_repeat('─', 60));
        $this->info("Total importat: {$totalImported} înregistrări noi, {$totalSkipped} duplicate sărite.");

        if (! $this->dryRun && $totalImported > 0) {
            $this->info('Actualizez product_suppliers (purchase_price, last_purchase_*)...');
            $updated = $this->updateProductSuppliers();
            $this->info("product_suppliers actualizat: {$updated} rânduri.");
        }

        Log::channel('daily')->info('[WinMentor SyncPurchaseHistory] Finalizat', [
            'imported' => $totalImported,
            'skipped'  => $totalSkipped,
        ]);

        return self::SUCCESS;
    }

    // ─── Procesare lună ─────────────────────────────────────────────────────────

    private function processIntrari(array $intrari, int $an, int $luna): array
    {
        $imported = 0;
        $skipped  = 0;

        foreach ($intrari as $row) {
            if (! is_array($row) || count($row) < 7) continue;

            $partId  = trim($row[0] ?? '');
            $dateStr = trim($row[1] ?? '');
            $sku     = trim($row[3] ?? '');
            $uom     = trim($row[5] ?? '') ?: null;
            $pretStr = trim($row[6] ?? '');

            if (! $sku || ! $pretStr || ! $dateStr) continue;

            // Preț (format românesc cu virgulă)
            $pret = (float) str_replace(',', '.', $pretStr);
            if ($pret <= 0) continue;

            // Produs
            $productId = $this->productIndex[$sku] ?? null;
            if (! $productId) continue;

            // Dată
            try {
                $date = Carbon::createFromFormat('d.m.Y', $dateStr)->toDateString();
            } catch (\Throwable) {
                continue;
            }

            // Furnizor
            [$supplierId, $supplierNameRaw] = $this->resolveSupplier($partId);

            // Deduplicare
            if ($this->entryExists($productId, $date, $pret, $supplierId, $supplierNameRaw)) {
                $skipped++;
                continue;
            }

            if (! $this->dryRun) {
                ProductPurchasePriceLog::create([
                    'woo_product_id'    => $productId,
                    'supplier_id'       => $supplierId,
                    'supplier_name_raw' => $supplierNameRaw,
                    'unit_price'        => $pret,
                    'currency'          => 'RON',
                    'acquired_at'       => $date,
                    'source'            => 'winmentor_import',
                    'uom'               => $uom,
                ]);
            }

            $imported++;
        }

        return [$imported, $skipped];
    }

    // ─── Rezolvare furnizor ──────────────────────────────────────────────────────

    /**
     * Returnează [supplier_id|null, supplier_name_raw|null].
     * Încearcă mai întâi prin winmentor_id, apoi prin apel API dacă necesar.
     */
    private function resolveSupplier(string $partId): array
    {
        if (! $partId) return [null, null];

        // Din cache local
        if (isset($this->supplierCache[$partId])) {
            $cached = $this->supplierCache[$partId];
            return [$cached['id'], $cached['id'] ? null : $cached['name']];
        }

        // Din indexul ERP (winmentor_id)
        if (isset($this->supplierIndex[$partId])) {
            $id = $this->supplierIndex[$partId];
            $this->supplierCache[$partId] = ['id' => $id, 'name' => null];
            return [$id, null];
        }

        // Nu e în ERP — caută numele în WinMentor (doar la import real, nu dry-run)
        $name = $partId; // fallback
        if (! $this->dryRun) {
            try {
                $this->pause();
                $partener = $this->bridge->searchPartenerById($partId);
                if ($partener) {
                    $name = $partener['denumire'] ?? $partId;

                    $cui = preg_replace('/[^0-9]/', '', $partener['codFiscal'] ?? '');
                    if ($cui) {
                        $supplier = Supplier::where('vat_number', 'like', "%{$cui}%")->first();
                        if ($supplier) {
                            if (! $supplier->winmentor_id) {
                                $supplier->update(['winmentor_id' => $partId]);
                                $this->supplierIndex[$partId] = $supplier->id;
                            }
                            $this->supplierCache[$partId] = ['id' => $supplier->id, 'name' => null];
                            return [$supplier->id, null];
                        }
                    }
                }
            } catch (\Throwable) {
                // Continuăm cu name = partId
            }
        }

        $this->supplierCache[$partId] = ['id' => null, 'name' => $name];
        return [null, $name];
    }

    // ─── Deduplicare ────────────────────────────────────────────────────────────

    private function entryExists(int $productId, string $date, float $pret, ?int $supplierId, ?string $supplierNameRaw): bool
    {
        $q = ProductPurchasePriceLog::where('woo_product_id', $productId)
            ->where('acquired_at', $date)
            ->where('unit_price', $pret)
            ->where('source', 'winmentor_import');

        if ($supplierId) {
            $q->where('supplier_id', $supplierId);
        } else {
            $q->where('supplier_name_raw', $supplierNameRaw);
        }

        return $q->exists();
    }

    // ─── Actualizare product_suppliers ──────────────────────────────────────────

    private function updateProductSuppliers(): int
    {
        $updated = 0;

        ProductSupplier::with('supplier')->chunkById(200, function ($chunk) use (&$updated) {
            foreach ($chunk as $ps) {
                $latest = ProductPurchasePriceLog::where('woo_product_id', $ps->woo_product_id)
                    ->where(function ($q) use ($ps) {
                        $q->where('supplier_id', $ps->supplier_id);
                        if ($ps->supplier?->name) {
                            $q->orWhere('supplier_name_raw', $ps->supplier->name);
                        }
                    })
                    ->latest('acquired_at')
                    ->first();

                if (! $latest) continue;

                $updates = [
                    'last_purchase_price' => $latest->unit_price,
                    'last_purchase_date'  => $latest->acquired_at,
                ];

                if (! $ps->purchase_price) {
                    $updates['purchase_price'] = $latest->unit_price;
                }

                $ps->update($updates);
                $updated++;
            }
        });

        return $updated;
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function buildIndexes(): void
    {
        $this->info('Construiesc indexuri produse și furnizori...');

        $this->productIndex = WooProduct::whereNotNull('sku')
            ->pluck('id', 'sku')
            ->all();

        $this->supplierIndex = Supplier::whereNotNull('winmentor_id')
            ->pluck('id', 'winmentor_id')
            ->all();

        $this->info('  Produse: ' . count($this->productIndex) . ', Furnizori cu winmentor_id: ' . count($this->supplierIndex));
    }

    private function pause(): void
    {
        usleep($this->delayMs * 1000);
    }
}
