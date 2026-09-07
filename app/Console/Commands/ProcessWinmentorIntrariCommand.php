<?php

namespace App\Console\Commands;

use App\Models\ProductPurchasePriceLog;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\WinmentorIntrariUnmatchedSku;
use App\Models\WinmentorPriceAnomaly;
use App\Models\WooProduct;
use App\Services\BnrExchangeRateService;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWinmentorIntrariCommand extends Command
{
    protected $signature = 'winmentor:process-intrari
                            {--firma=MAL2019 : Firma de procesat}
                            {--batch=500 : Rânduri procesate per iterație}
                            {--price-spike-threshold=50 : % creștere preț pentru alertă}
                            {--price-drop-threshold=40 : % scădere preț pentru alertă}
                            {--sku-reuse-threshold=200 : % schimbare preț pentru detectare refolosire SKU}';

    protected $description = 'Procesează intrările raw din WinMentor și populează product_purchase_price_logs + anomalii';

    // ─── Indexuri în memorie ─────────────────────────────────────────────────────
    private array $productIndex   = []; // sku → woo_product_id
    private array $supplierIndex  = []; // winmentor_id → supplier_id
    private array $latestPrices   = []; // woo_product_id → [price, supplier_id, date]
    private array $supplierCache  = []; // part_id → ['id' => ?, 'name' => ?]
    private array $eurPartIds     = []; // winmentor_id-uri ale furnizorilor cu default_currency=EUR
    private array $bnrRateCache   = []; // date → curs EUR/RON (BNR)

    private int $spikeThreshold;
    private int $dropThreshold;
    private int $skuReuseThreshold;

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma  = $this->option('firma');
        $batch  = (int) $this->option('batch');
        $this->spikeThreshold    = (int) $this->option('price-spike-threshold');
        $this->dropThreshold     = (int) $this->option('price-drop-threshold');
        $this->skuReuseThreshold = (int) $this->option('sku-reuse-threshold');

        $this->buildIndexes($firma, $bridge);

        $total = DB::table('winmentor_intrari_raw')
            ->where('firma', $firma)
            ->whereNull('processed_at')
            ->count();

        $this->info("Rânduri de procesat: {$total}");
        $this->line(str_repeat('─', 60));

        $processed = 0;
        $saved     = 0;
        $unmatched = 0;
        $anomalies = 0;

        // Pre-load IDs in chronological order (chunkById+orderBy is buggy — cursor skips rows)
        $ids = DB::table('winmentor_intrari_raw')
            ->where('firma', $firma)
            ->whereNull('processed_at')
            ->orderBy('data_intrare')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids->chunk($batch) as $batchIds) {
            $rows = DB::table('winmentor_intrari_raw')
                ->whereIn('id', $batchIds)
                ->orderBy('data_intrare')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                try {
                    $result = $this->processRow($row, $firma, $bridge);
                } catch (\Throwable $e) {
                    Log::error("[ProcessIntrari] Eroare la rândul raw id={$row->id} sku={$row->sku}: {$e->getMessage()}", ['exception' => $e]);
                    $this->warn("  eroare la id={$row->id} sku={$row->sku}: {$e->getMessage()}");
                    $result = 'error';
                }

                match ($result) {
                    'saved'     => $saved++,
                    'unmatched' => $unmatched++,
                    'anomaly'   => $anomalies++,
                    default     => null,
                };

                $processed++;
            }

            $this->line("  procesat: {$processed} | salvat: {$saved} | neidentificat: {$unmatched} | anomalii: {$anomalies}");
        }

        // Actualizează product_suppliers
        $this->info('Actualizez product_suppliers...');
        $updated = $this->updateProductSuppliers($firma);

        $this->line(str_repeat('─', 60));
        $this->info("Finalizat: {$saved} prețuri salvate, {$unmatched} SKU-uri neidentificate, {$anomalies} anomalii, {$updated} furnizori actualizați.");

        Log::channel('winmentor_sync')->info("[WinMentor ProcessIntrari] Finalizat firma={$firma}", compact('saved', 'unmatched', 'anomalies'));

        return self::SUCCESS;
    }

    // ─── Procesare rând individual ────────────────────────────────────────────────

    private function processRow(object $row, string $firma, WinmentorBridgeClient $bridge): string
    {
        $sku     = $row->sku;
        $partId  = $row->part_id;
        $pretRaw = (float) $row->pret;
        $date    = $row->data_intrare;
        $moneda  = strtoupper($row->moneda ?? '');
        $cursRon = $row->curs_bnr ? (float) $row->curs_bnr : null;

        $partId = $partId !== null ? ltrim($partId, '0') : null;

        // WinMentor nu exportă câmpul monedă — deducem din furnizor
        if ($moneda === '' && isset($this->eurPartIds[$partId])) {
            $moneda = 'EUR';
        }
        if ($moneda === '') {
            $moneda = 'RON';
        }

        // Marchează ca procesat indiferent de rezultat
        DB::table('winmentor_intrari_raw')
            ->where('id', $row->id)
            ->update(['processed_at' => now()]);

        if (! $sku || $pretRaw <= 0 || ! $date) return 'skip';

        // Conversie EUR → RON dacă e cazul
        $pretRon = $pretRaw;
        if ($moneda === 'EUR') {
            if (! $cursRon) {
                // Curs lipsă în raw — fetch din BNR (cu cache)
                if (! array_key_exists($date, $this->bnrRateCache)) {
                    $this->bnrRateCache[$date] = app(BnrExchangeRateService::class)->getEurRate($date);
                }
                $cursRon = $this->bnrRateCache[$date];
            }

            if ($cursRon > 0) {
                $pretRon = round($pretRaw * $cursRon, 4);
                // Actualizează raw cu cursul pentru audit
                DB::table('winmentor_intrari_raw')
                    ->where('id', $row->id)
                    ->update(['moneda' => 'EUR', 'curs_bnr' => $cursRon]);
            } else {
                Log::warning("[ProcessIntrari] Curs BNR lipsă pentru EUR la data {$date}, sku={$sku} — skip");
                return 'skip';
            }
        }

        // Match produs
        $productId = $this->productIndex[$sku] ?? null;
        if (! $productId) {
            $this->recordUnmatched($row, $firma, $bridge);
            return 'unmatched';
        }

        // Match furnizor
        [$supplierId, $supplierNameRaw] = $partId ? $this->resolveSupplier($partId, $bridge) : [null, null];

        // Detectare anomalie (pe prețul în RON)
        $anomalyType = $this->detectAnomaly($productId, $supplierId, $partId, $pretRon);

        if ($anomalyType === WinmentorPriceAnomaly::TYPE_POSSIBLE_SKU_REUSE) {
            $this->saveAnomaly($row, $productId, $supplierId, $partId, $pretRon, $date, $anomalyType, $firma);
            return 'anomaly';
        }

        // Deduplicare (pe prețul RON convertit)
        $exists = ProductPurchasePriceLog::where('woo_product_id', $productId)
            ->where('acquired_at', $date)
            ->where('unit_price', $pretRon)
            ->where('nr_doc', $row->nr_doc)
            ->where('firma', $firma)
            ->exists();

        if ($exists) return 'skip';

        $hasAnomaly = $anomalyType !== null;

        ProductPurchasePriceLog::create([
            'woo_product_id'    => $productId,
            'supplier_id'       => $supplierId,
            'supplier_name_raw' => $supplierNameRaw,
            'unit_price'        => $pretRon,
            'currency'          => 'RON',
            'exchange_rate'     => $moneda !== 'RON' ? $cursRon : null,
            'acquired_at'       => $date,
            'source'            => 'winmentor_import',
            'uom'               => $row->uom,
            'firma'             => $firma,
            'nr_doc'            => $row->nr_doc,
            'quantity'          => $row->cantitate,
            'intrare_raw_id'    => $row->id,
            'has_anomaly'       => $hasAnomaly,
        ]);

        if ($hasAnomaly) {
            $this->saveAnomaly($row, $productId, $supplierId, $partId, $pretRon, $date, $anomalyType, $firma);
        }

        // Actualizează cache prețuri (RON)
        $this->latestPrices[$productId] = [
            'price'       => $pretRon,
            'supplier_id' => $supplierId,
            'part_id'     => $partId,
            'date'        => $date,
        ];

        return 'saved';
    }

    // ─── Detectare anomalii ───────────────────────────────────────────────────────

    private function detectAnomaly(int $productId, ?int $supplierId, ?string $partId, float $newPrice): ?string
    {
        $prev = $this->latestPrices[$productId] ?? null;
        if (! $prev || ! $prev['price']) return null;

        $prevPrice = (float) $prev['price'];
        if ($prevPrice <= 0) return null;

        $changePct = (($newPrice - $prevPrice) / $prevPrice) * 100;

        // Posibilă refolosire SKU: schimbare masivă + furnizor diferit (necunoscut ≠ diferit)
        if (abs($changePct) >= $this->skuReuseThreshold && $partId !== null && $partId !== $prev['part_id']) {
            return WinmentorPriceAnomaly::TYPE_POSSIBLE_SKU_REUSE;
        }

        // Spike preț
        if ($changePct >= $this->spikeThreshold) {
            return WinmentorPriceAnomaly::TYPE_PRICE_SPIKE;
        }

        // Scădere preț
        if ($changePct <= -$this->dropThreshold) {
            return WinmentorPriceAnomaly::TYPE_PRICE_DROP;
        }

        // Schimbare furnizor (fără săritură mare de preț)
        if ($supplierId && $prev['supplier_id'] && $supplierId !== $prev['supplier_id']) {
            return WinmentorPriceAnomaly::TYPE_SUPPLIER_CHANGE;
        }

        return null;
    }

    private function saveAnomaly(object $row, int $productId, ?int $supplierId, ?string $partId, float $newPrice, string $date, string $type, string $firma): void
    {
        $prev      = $this->latestPrices[$productId] ?? null;
        $prevPrice = $prev ? (float) $prev['price'] : null;
        $changePct = ($prevPrice && $prevPrice > 0) ? (($newPrice - $prevPrice) / $prevPrice * 100) : null;

        WinmentorPriceAnomaly::create([
            'sku'                 => $row->sku,
            'woo_product_id'      => $productId,
            'firma'               => $firma,
            'anomaly_type'        => $type,
            'previous_price'      => $prevPrice,
            'new_price'           => $newPrice,
            'price_change_pct'    => $changePct ? round($changePct, 2) : null,
            'previous_part_id'    => $prev['part_id'] ?? null,
            'new_part_id'         => $partId,
            'previous_supplier_id'=> $prev['supplier_id'] ?? null,
            'new_supplier_id'     => $supplierId,
            'previous_date'       => $prev['date'] ?? null,
            'new_date'            => $date,
            'intrare_raw_id'      => $row->id,
        ]);
    }

    // ─── SKU-uri neidentificate ───────────────────────────────────────────────────

    private function recordUnmatched(object $row, string $firma, WinmentorBridgeClient $bridge): void
    {
        $supplierName = null;
        if ($row->part_id && isset($this->supplierCache[$row->part_id])) {
            $supplierName = $this->supplierCache[$row->part_id]['name'];
        }

        WinmentorIntrariUnmatchedSku::upsert([
            'sku'               => $row->sku,
            'firma'             => $firma,
            'last_part_id'      => $row->part_id,
            'last_supplier_name'=> $supplierName,
            'appearances_count' => 1,
            'last_price'        => $row->pret,
            'last_uom'          => $row->uom,
            'first_seen_at'     => $row->data_intrare,
            'last_seen_at'      => $row->data_intrare,
            'created_at'        => now(),
            'updated_at'        => now(),
        ], ['sku', 'firma'], [
            'last_part_id'      => DB::raw('last_part_id'),
            'last_supplier_name'=> DB::raw('last_supplier_name'),
            'appearances_count' => DB::raw('appearances_count + 1'),
            'last_price'        => DB::raw("IF(last_seen_at <= '{$row->data_intrare}', VALUES(last_price), last_price)"),
            'last_uom'          => DB::raw("IF(last_seen_at <= '{$row->data_intrare}', VALUES(last_uom), last_uom)"),
            'first_seen_at'     => DB::raw("IF(first_seen_at > '{$row->data_intrare}', VALUES(first_seen_at), first_seen_at)"),
            'last_seen_at'      => DB::raw("IF(last_seen_at < '{$row->data_intrare}', VALUES(last_seen_at), last_seen_at)"),
            'updated_at'        => now(),
        ]);
    }

    // ─── Rezolvare furnizor ───────────────────────────────────────────────────────

    private function resolveSupplier(string $partId, WinmentorBridgeClient $bridge): array
    {
        if (! $partId) return [null, null];

        if (isset($this->supplierCache[$partId])) {
            $c = $this->supplierCache[$partId];
            return [$c['id'], $c['id'] ? null : $c['name']];
        }

        if (isset($this->supplierIndex[$partId])) {
            $id = $this->supplierIndex[$partId];
            $this->supplierCache[$partId] = ['id' => $id, 'name' => null];
            return [$id, null];
        }

        // Rezolvare locală prin winmentor_parteneri (wm_id sau cod_extern) — fără apel la bridge
        $local = DB::table('winmentor_parteneri')
            ->whereRaw("TRIM(LEADING '0' FROM wm_id) = ?", [$partId])
            ->orWhereRaw("TRIM(LEADING '0' FROM COALESCE(cod_extern, '')) = ?", [$partId])
            ->first(['wm_id', 'cod_extern', 'denumire', 'cod_fiscal']);
        if ($local) {
            $cui = preg_replace('/[^0-9]/', '', $local->cod_fiscal ?? '');
            $existing = null;
            if ($cui) {
                $existing = Supplier::where('vat_number', 'like', "%{$cui}%")->first();
            }
            $existing ??= Supplier::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($local->denumire))])->first();
            if ($existing) {
                if (! $existing->winmentor_id) {
                    $existing->update(['winmentor_id' => $local->wm_id]);
                }
                $this->supplierIndex[$partId] = $existing->id;
                $this->supplierCache[$partId] = ['id' => $existing->id, 'name' => null];

                return [$existing->id, null];
            }

            // Partener cunoscut, dar fără furnizor în ERP — măcar numele real, nu ID-ul
            $this->supplierCache[$partId] = ['id' => null, 'name' => $local->denumire];

            return [null, $local->denumire];
        }

        // Caută în WinMentor și creează furnizor inactiv dacă îl găsește
        try {
            $partener = $bridge->searchPartenerById($partId);
            if ($partener) {
                $name = $partener['denumire'] ?? $partId;
                $cui  = preg_replace('/[^0-9]/', '', $partener['codFiscal'] ?? '');

                // Încearcă să potrivim după CUI
                if ($cui) {
                    $existing = Supplier::where('vat_number', 'like', "%{$cui}%")->first();
                    if ($existing) {
                        if (! $existing->winmentor_id) {
                            $existing->update(['winmentor_id' => $partId]);
                        }
                        $this->supplierIndex[$partId]  = $existing->id;
                        $this->supplierCache[$partId]  = ['id' => $existing->id, 'name' => null];
                        return [$existing->id, null];
                    }
                }

                // Creează furnizor inactiv
                $supplier = Supplier::create([
                    'name'         => $name,
                    'vat_number'   => $cui ?: null,
                    'winmentor_id' => $partId,
                    'is_active'    => false,
                ]);

                $this->supplierIndex[$partId] = $supplier->id;
                $this->supplierCache[$partId] = ['id' => $supplier->id, 'name' => null];
                return [$supplier->id, null];
            }
        } catch (\Throwable) {}

        // Nu am putut identifica — salvăm ID-ul ca raw name
        $this->supplierCache[$partId] = ['id' => null, 'name' => $partId];
        return [null, $partId];
    }

    // ─── Update product_suppliers ─────────────────────────────────────────────────

    private function updateProductSuppliers(string $firma): int
    {
        $updated = 0;

        ProductSupplier::with('supplier')->chunkById(200, function ($chunk) use ($firma, &$updated) {
            foreach ($chunk as $ps) {
                $latest = ProductPurchasePriceLog::where('woo_product_id', $ps->woo_product_id)
                    ->where('firma', $firma)
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

    // ─── Build indexes ────────────────────────────────────────────────────────────

    private function buildIndexes(string $firma, WinmentorBridgeClient $bridge): void
    {
        $this->info('Construiesc indexuri...');

        $this->productIndex  = WooProduct::whereNotNull('sku')->pluck('id', 'sku')->all();

        // Furnizorii pot apărea în intrări cu wm_id SAU cod_extern (ID-ul legacy) —
        // indexăm pe ambele, prin winmentor_parteneri (chei fără zerouri de umplutură).
        $this->supplierIndex = [];
        $this->eurPartIds    = [];
        $eurIds = Supplier::where('default_currency', 'EUR')->pluck('id')->flip()->all();
        foreach (Supplier::whereNotNull('winmentor_id')->get(['id', 'winmentor_id']) as $s) {
            $keys = [ltrim($s->winmentor_id, '0')];
            $p = DB::table('winmentor_parteneri')
                ->where('wm_id', $s->winmentor_id)->orWhere('cod_extern', $s->winmentor_id)->first(['wm_id', 'cod_extern']);
            if ($p) {
                $keys[] = ltrim($p->wm_id, '0');
                if ($p->cod_extern) $keys[] = ltrim($p->cod_extern, '0');
            }
            foreach (array_unique(array_filter($keys)) as $k) {
                $this->supplierIndex[$k] = $s->id;
                if (isset($eurIds[$s->id])) $this->eurPartIds[$k] = true;
            }
        }

        // Preîncarcă ultimele prețuri cunoscute per produs (din DB, sortat cronologic)
        ProductPurchasePriceLog::where('firma', $firma)
            ->orderBy('acquired_at')
            ->select(['woo_product_id', 'unit_price', 'supplier_id', 'acquired_at'])
            ->chunk(1000, function ($rows) {
                foreach ($rows as $r) {
                    $this->latestPrices[$r->woo_product_id] = [
                        'price'       => $r->unit_price,
                        'supplier_id' => $r->supplier_id,
                        'part_id'     => null,
                        'date'        => $r->acquired_at,
                    ];
                }
            });

        $this->info('  Produse: ' . count($this->productIndex) . ', Furnizori: ' . count($this->supplierIndex));
    }
}
