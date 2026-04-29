<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\WinmentorArticleSnapshot;
use App\Models\WooProduct;
use App\Services\Winmentor\WinmentorBridgeClient;
use App\Services\WooCommerce\WooClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Detectează modificări de SKU sau denumire în WinMentor Bridge față de
 * starea anterioară (snapshot local) și sincronizează modificările în ERP și WooCommerce.
 *
 * Scenarii gestionate:
 *   1. Denumire schimbată (SKU neschimbat) → update name în woo_products + WooCommerce
 *   2. SKU schimbat (denumirea recunoscută dintr-un snapshot anterior) → update sku în woo_products + WooCommerce
 *
 * La orice modificare trimite e-mail la contact@malinco.ro și codrut@ikonia.ro.
 */
class DetectWinmentorArticleChangesCommand extends Command
{
    protected $signature = 'winmentor:detect-article-changes
                            {--dry-run : Afișează modificările fără a le salva}';

    protected $description = 'Detectează modificări SKU/denumire în WinMentor și sincronizează cu ERP + WooCommerce';

    private const NOTIFY_EMAILS = ['office@malinco.ro', 'codrut@ikonia.ro'];

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $dryRun = $this->option('dry-run');

        // ── 1. Conexiune Bridge ──────────────────────────────────────────────────
        $connection = IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_WINMENTOR_BRIDGE)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            $this->error('Conexiune WinMentor Bridge nu a fost găsită sau e inactivă.');
            return self::FAILURE;
        }

        $settings = is_array($connection->settings)
            ? $connection->settings
            : json_decode($connection->settings, true);

        $firma    = $settings['firma']    ?? 'MAL2019';
        $luna     = (int) ($settings['luna']     ?? now()->month);
        $an       = (int) ($settings['an']       ?? now()->year);
        $gestiune = $settings['gestiune'] ?? 'MP';
        $clasa    = $settings['clasa']    ?? '1';

        $bridge->selectFirmaForMonth($an, $luna, $firma);

        // ── 2. Fetch articole din Bridge ─────────────────────────────────────────
        $bridgeArticles = $bridge->getAllArticole($gestiune, $clasa);
        $this->info('Articole Bridge (clasa ' . $clasa . ', gestiune ' . $gestiune . '): ' . count($bridgeArticles));

        if (empty($bridgeArticles)) {
            $this->warn('Niciun articol primit din Bridge. Verifică configurația conexiunii.');
            return self::SUCCESS;
        }

        // ── 3. Snapshot local ────────────────────────────────────────────────────
        $snapshotBySku  = WinmentorArticleSnapshot::all()->keyBy('cod_extern');
        $snapshotByName = WinmentorArticleSnapshot::all()->keyBy('denumire');

        // Prima rulare: populăm snapshot-ul fără a face modificări
        if ($snapshotBySku->isEmpty()) {
            $this->info('Prima rulare — populez snapshot-ul inițial fără modificări.');
            if (! $dryRun) {
                $this->populateSnapshot($bridgeArticles);
            }
            return self::SUCCESS;
        }

        $changes      = [];
        $toUpdate     = []; // snapshot_id => [cod_extern, denumire]
        $toInsert     = []; // articole noi (absent din snapshot)
        $newInWm      = []; // articole noi în WM care lipsesc din ERP (pentru alertă)

        // ── 4a. Detectare articole WinMentor lipsă din ERP (toate articolele, nu doar cu stoc) ─
        $allWmArticole = $bridge->fetchAllArticoleSkuMap();
        $existingSkus  = WooProduct::pluck('sku')->map(fn ($s) => strtolower(trim($s)))->flip();

        foreach ($allWmArticole as $skuLower => $article) {
            // Filtrăm după aceeași clasă și gestiune ca getAllArticole
            if (($article['simbolClasa']  ?? '') !== $clasa)    continue;
            if (($article['gestImplicita'] ?? $article['simbolGestiune'] ?? '') !== $gestiune) continue;

            $sku      = $article['codExtern'] ?? $article['codIntern'] ?? null;
            $denumire = trim($article['denumire'] ?? '');

            if (! $sku || ! $denumire) {
                continue;
            }

            // Lipsă din ERP — alertăm o dată la 24h per SKU (cache throttle)
            if (! $existingSkus->has($skuLower)) {
                $cacheKey = "wm_missing_erp_{$sku}";
                if (! Cache::has($cacheKey)) {
                    $newInWm[] = ['sku' => $sku, 'denumire' => $denumire];
                    $this->line("  [ARTICOL NOU LIPSĂ DIN ERP] {$sku} — {$denumire}");
                    if (! $dryRun) {
                        Cache::put($cacheKey, true, now()->addHours(24));
                    }
                }

                // Adăugăm în snapshot dacă nu e deja
                if (! $snapshotBySku->has($sku) && ! isset($toInsert[$sku])) {
                    $toInsert[$sku] = [
                        'cod_extern' => $sku,
                        'denumire'   => $denumire,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        // ── 4. Detectare modificări ──────────────────────────────────────────────
        $wooConnections = IntegrationConnection::where('location_id', $connection->location_id)
            ->where('provider', 'woocommerce')
            ->where('is_active', true)
            ->get();

        foreach ($bridgeArticles as $sku => $article) {
            $denumire = trim($article['denumire'] ?? '');

            if (! $sku || ! $denumire) {
                continue;
            }

            $bySkuSnap  = $snapshotBySku->get($sku);
            $byNameSnap = $snapshotByName->get($denumire);

            if ($bySkuSnap) {
                // SKU există în snapshot
                if ($bySkuSnap->denumire !== $denumire) {
                    // ── Denumire schimbată ──────────────────────────────────────
                    $change = $this->applyNameChange($sku, $bySkuSnap->denumire, $denumire, $wooConnections, $dryRun);
                    if ($change) {
                        $changes[]          = $change;
                        $toUpdate[$bySkuSnap->id] = ['cod_extern' => $sku, 'denumire' => $denumire];
                    }
                }
                // else: neschimbat
            } elseif ($byNameSnap) {
                // Denumirea găsită în snapshot cu alt SKU → SKU schimbat
                $oldSku = $byNameSnap->cod_extern;
                if ($oldSku !== $sku) {
                    $change = $this->applySkuChange($oldSku, $sku, $denumire, $wooConnections, $dryRun);
                    if ($change) {
                        $changes[]               = $change;
                        $toUpdate[$byNameSnap->id] = ['cod_extern' => $sku, 'denumire' => $denumire];
                    }
                }
            } else {
                // Articol nou în Bridge (stocuri) — adăugăm în snapshot dacă nu e deja
                $skuKey = strtolower(trim($sku));
                if (! isset($toInsert[$skuKey])) {
                    $toInsert[$skuKey] = [
                        'cod_extern' => $sku,
                        'denumire'   => $denumire,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        // ── 5. Salvare snapshot ──────────────────────────────────────────────────
        if (! $dryRun) {
            foreach ($toUpdate as $id => $data) {
                WinmentorArticleSnapshot::whereKey($id)->update(array_merge($data, ['updated_at' => now()]));
            }

            foreach (array_chunk(array_values($toInsert), 500) as $chunk) {
                WinmentorArticleSnapshot::insert($chunk);
            }
        }

        // ── 6. Notificare ────────────────────────────────────────────────────────
        // Trimitere email doar pentru modificări SKU/denumire — alertele "lipsă din ERP" sunt dezactivate momentan
        if (! empty($changes) && ! $dryRun) {
            $this->sendChangeEmail($changes, []);
        }

        if (! empty($newInWm)) {
            $this->warn('Articole WM clasa 1 lipsă din ERP: ' . count($newInWm) . ' (emailuri dezactivate momentan)');
        }

        $this->line(str_repeat('─', 60));
        $this->info(sprintf(
            'Modificări: %d | Articole noi în WM fără ERP: %d | Articole noi în snapshot: %d | Total Bridge: %d',
            count($changes),
            count($newInWm),
            count($toInsert),
            count($bridgeArticles)
        ));

        return self::SUCCESS;
    }

    // ─── Name change ─────────────────────────────────────────────────────────────

    private function applyNameChange(
        string $sku,
        string $oldName,
        string $newName,
        $wooConnections,
        bool $dryRun
    ): ?array {
        $product = WooProduct::where('sku', $sku)->first();

        if (! $product) {
            // Articol în Bridge dar nu în ERP — ignorăm
            return null;
        }

        $change = [
            'tip'       => 'DENUMIRE',
            'sku'       => $sku,
            'vechi'     => $oldName,
            'nou'       => $newName,
            'erp_name'  => $product->name,
            'woo_id'    => $product->woo_id,
        ];

        $this->line("  [DENUMIRE] SKU {$sku}: \"{$oldName}\" → \"{$newName}\"");

        if (! $dryRun) {
            // Actualizăm DOAR winmentor_name — denumirea ERP/site rămâne neatinsă
            // (poate fi scrisă diferit intenționat față de WinMentor)
            $product->update(['winmentor_name' => $newName]);

            Log::channel('winmentor_sync')->info('[DetectArticleChanges] Denumire schimbată (winmentor_name actualizat, name/WooCommerce neatinse)', $change);
        }

        return $change;
    }

    // ─── SKU change ──────────────────────────────────────────────────────────────

    private function applySkuChange(
        string $oldSku,
        string $newSku,
        string $denumire,
        $wooConnections,
        bool $dryRun
    ): ?array {
        $product = WooProduct::where('sku', $oldSku)->first();

        if (! $product) {
            return null;
        }

        $change = [
            'tip'       => 'SKU',
            'denumire'  => $denumire,
            'sku_vechi' => $oldSku,
            'sku_nou'   => $newSku,
            'woo_id'    => $product->woo_id,
        ];

        $this->line("  [SKU] \"{$denumire}\": {$oldSku} → {$newSku}");

        if (! $dryRun) {
            $product->update(['sku' => $newSku]);

            foreach ($wooConnections as $wooConn) {
                if ($product->woo_id) {
                    try {
                        (new WooClient($wooConn))->updateProduct($product->woo_id, ['sku' => $newSku]);
                    } catch (\Throwable $e) {
                        $change['woo_error'] = $e->getMessage();
                        Log::warning('[DetectArticleChanges] WooCommerce SKU update eșuat pentru ' . $oldSku . ': ' . $e->getMessage());
                    }
                }
            }

            Log::channel('winmentor_sync')->info('[DetectArticleChanges] SKU schimbat', $change);
        }

        return $change;
    }

    // ─── Prima rulare ─────────────────────────────────────────────────────────────

    private function populateSnapshot(array $articles): void
    {
        $rows = [];
        $now  = now();

        foreach ($articles as $sku => $article) {
            $denumire = trim($article['denumire'] ?? '');
            if (! $sku || ! $denumire) {
                continue;
            }
            $rows[] = [
                'cod_extern' => $sku,
                'denumire'   => $denumire,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            WinmentorArticleSnapshot::insert($chunk);
        }

        $this->info('Snapshot inițial populat cu ' . count($rows) . ' articole.');
    }

    // ─── Email notificare ─────────────────────────────────────────────────────────

    private function sendChangeEmail(array $changes, array $newInWm = []): void
    {
        $nameChanges = count(array_filter($changes, fn ($c) => $c['tip'] === 'DENUMIRE'));
        $skuChanges  = count(array_filter($changes, fn ($c) => $c['tip'] === 'SKU'));

        $summary = [];
        if ($skuChanges) {
            $summary[] = "{$skuChanges} SKU schimbat";
        }
        if ($nameChanges) {
            $summary[] = "{$nameChanges} denumire schimbată";
        }
        if (count($newInWm)) {
            $summary[] = count($newInWm) . ' articol(e) noi lipsă din ERP';
        }

        $subject = '[ERP Malinco] Modificări articole WinMentor — ' . implode(', ', $summary);

        // Limităm la 100 articole per email — evităm emailuri uriașe
        $newInWmCapped   = array_slice($newInWm, 0, 100);
        $newInWmOverflow = max(0, count($newInWm) - 100);

        try {
            Mail::send('mail.article-changes', compact('changes', 'newInWmCapped', 'newInWmOverflow', 'subject'), function ($message) use ($subject) {
                $message->to(self::NOTIFY_EMAILS)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::error('[DetectArticleChanges] Email notificare eșuat: ' . $e->getMessage());
        }
    }
}
