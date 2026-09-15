<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\WooCommerce\WooDirectSqlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Corectează dimensiunile Toya cu dims_source = NULL, care au fost împărțite
 * greșit la 10 de vechiul import (mmToCm), deși feed-ul Toya e DEJA în cm.
 * Corecția anterioară (dims_source='rescaled') a sărit exact setul NULL.
 *
 * Metodă autoritară: re-ia valoarea din feed (LengthHE, fallback LengthMC),
 * la valoarea de față (fără ÷10), scrie în ERP și împinge în Woo.
 * Verificat 2026-09-15: 52/52 produse din setul NULL erau stocat = feed/10.
 */
class FixToyaNullDimensionsCommand extends Command
{
    protected $signature = 'toya:fix-null-dimensions
                            {--dry-run : Doar raportează, nu scrie nimic}
                            {--limit=0 : Limitează numărul de produse (0 = toate)}
                            {--no-push : Nu împinge în Woo (doar ERP)}';

    protected $description = 'Repară dimensiunile Toya ÷10-greșite (dims_source NULL) din feed';

    private const API_BASE     = 'https://pim.toya.pl/dataapi';
    private const TOYA_SUPPLIER = 112;
    private const PLACEHOLDER_WOO_ID = 8000000000000000000; // woo_id sintetice = placeholdere

    public function handle(WooDirectSqlService $woo): int
    {
        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_TOYA_API_KEY)
            ?? env('TOYA_API_KEY', 'D83FD59A4902793862EB8304');

        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int) $this->option('limit');

        $q = DB::table('product_suppliers as ps')
            ->join('woo_products as w', 'w.id', '=', 'ps.woo_product_id')
            ->where('ps.supplier_id', self::TOYA_SUPPLIER)
            ->whereNull('w.dims_source')
            ->whereNotNull('w.dim_length')
            ->where('w.dim_length', '>', 0)
            ->orderBy('w.id');

        if ($limit > 0) {
            $q->limit($limit);
        }

        $products = $q->get([
            'w.id', 'w.woo_id', 'w.status', 'w.weight', 'w.name',
            'w.dim_length', 'w.dim_width', 'w.dim_height', 'ps.supplier_sku',
        ]);

        $this->info("Produse Toya cu dims_source NULL: {$products->count()}");
        if ($products->isEmpty()) {
            return self::SUCCESS;
        }

        // ── Backup ÎNAINTE de orice modificare ────────────────────────────────
        if (! $dryRun) {
            $backup = $products->map(fn ($p) => [
                'id'         => $p->id,
                'woo_id'     => $p->woo_id,
                'sku'        => $p->supplier_sku,
                'dim_length' => $p->dim_length,
                'dim_width'  => $p->dim_width,
                'dim_height' => $p->dim_height,
            ])->all();
            $file = 'toya_dim_backup_' . now()->format('Y_m_d_His') . '.json';
            Storage::put($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Backup: storage/app/{$file} ({$products->count()} produse)");
        }

        $pushItems = [];
        $updated = 0; $nofeed = 0; $unchanged = 0; $errors = 0; $anomalies = 0;
        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products as $p) {
            $bar->advance();

            try {
                $raw = $this->fetchFeed($apiKey, (string) $p->supplier_sku);
            } catch (\Throwable $e) {
                $errors++;
                continue;
            }
            if (! $raw) { $nofeed++; continue; }

            $L = $this->faceValue($raw['LengthHE'] ?? null) ?? $this->faceValue($raw['LengthMC'] ?? null);
            $W = $this->faceValue($raw['WidthHE']  ?? null) ?? $this->faceValue($raw['WidthMC']  ?? null);
            $H = $this->faceValue($raw['HeightHE'] ?? null) ?? $this->faceValue($raw['HeightMC'] ?? null);

            if ($L === null && $W === null && $H === null) { $nofeed++; continue; }

            // Siguranță: valoarea din feed trebuie să fie ~10× cea stocată (confirmat pattern ÷10).
            // Dacă NU e (ex. deja corect, sau altă anomalie), NU atinge produsul — raportează.
            $oldL = (float) $p->dim_length;
            if ($L !== null && $oldL > 0 && abs($L - $oldL * 10) > max(0.2, $oldL)) {
                if (abs($L - $oldL) < 0.06) { $unchanged++; continue; } // deja corect
                $anomalies++;
                $this->newLine();
                $this->warn("  ANOMALIE #{$p->id} [{$p->supplier_sku}] {$p->name}: stocat {$oldL} vs feed {$L} — sărit");
                continue;
            }

            if (! $dryRun) {
                DB::table('woo_products')->where('id', $p->id)->update([
                    'dim_length'  => $L !== null ? (string) $L : $p->dim_length,
                    'dim_width'   => $W !== null ? (string) $W : $p->dim_width,
                    'dim_height'  => $H !== null ? (string) $H : $p->dim_height,
                    'dims_source' => 'rescaled',
                    'updated_at'  => now(),
                ]);
            }
            $updated++;

            // Eligibil pentru push în Woo?
            $wooId = (int) $p->woo_id;
            if ($p->status === 'publish' && $wooId > 0 && $wooId < self::PLACEHOLDER_WOO_ID && (float) $p->weight > 0) {
                $pushItems[] = [
                    'id'     => $wooId,
                    'weight' => (string) $p->weight,
                    'length' => (string) ($L ?? $p->dim_length),
                    'width'  => (string) ($W ?? $p->dim_width),
                    'height' => (string) ($H ?? $p->dim_height),
                ];
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("ERP: {$updated} actualizate, {$unchanged} deja corecte, {$nofeed} fără dims în feed, {$anomalies} anomalii sărite, {$errors} erori fetch");

        // ── Push în Woo ───────────────────────────────────────────────────────
        if (! $dryRun && ! $this->option('no-push') && ! empty($pushItems)) {
            $this->info('Push în Woo: ' . count($pushItems) . ' produse...');
            $okPush = 0; $failPush = 0;
            foreach (array_chunk($pushItems, 20) as $chunk) {
                $res = $woo->updateDimensions($chunk);
                $okPush += $res['updated'] ?? 0;
                $failPush += $res['failed'] ?? 0;
            }
            $this->info("Woo: {$okPush} împinse, {$failPush} eșuate");
        } elseif ($dryRun) {
            $this->comment('DRY-RUN: nimic scris. ' . count($pushItems) . ' ar fi fost împinse în Woo.');
        }

        return self::SUCCESS;
    }

    private function fetchFeed(string $apiKey, string $code): ?array
    {
        $response = Http::withoutVerifying()->timeout(30)
            ->get(self::API_BASE, ['key' => $apiKey, 'action' => 'getData', 'code' => $code]);
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        return ($json['success'] ?? false) ? ($json['data'] ?? null) : null;
    }

    /** Valoarea din feed ca atare (feed-ul Toya e în cm), fără ÷10. */
    private function faceValue(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === 'N/A') {
            return null;
        }
        $v = (float) $value;
        return $v > 0 ? round($v, 2) : null;
    }
}
