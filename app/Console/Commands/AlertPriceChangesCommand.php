<?php

namespace App\Console\Commands;

use App\Models\ProductPriceLog;
use App\Models\User;
use App\Models\WinmentorPriceAnomaly;
use App\Notifications\PriceChangeAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Detectează anomalii de prețuri de achiziție nealertate și trimite notificări dacă:
 *   1. Prețul de achiziție a crescut semnificativ și prețul de vânzare nu a fost actualizat
 *   2. Prețul de achiziție a scăzut și prețul de vânzare nu a fost actualizat (oportunitate)
 *   3. Marja brută a scăzut sub pragul minim (indiferent dacă a fost alertat sau nu)
 *
 * Rulat zilnic la 08:30.
 */
class AlertPriceChangesCommand extends Command
{
    protected $signature = 'erp:alert-price-changes
                            {--min-margin=10 : Marjă minimă acceptabilă (%) sub care se alertează}
                            {--spike-threshold=15 : Creștere preț achiziție (%) considerată semnificativă}
                            {--drop-threshold=10 : Scădere preț achiziție (%) considerată semnificativă}
                            {--dry-run : Afișează fără a trimite notificări și fără a marca anomaliile}';

    protected $description = 'Alertează buyer-ii și managerii despre modificări de prețuri de achiziție cu impact pe marjă';

    public function handle(): int
    {
        $minMargin       = (int) $this->option('min-margin');
        $spikeThreshold  = (int) $this->option('spike-threshold');
        $dropThreshold   = (int) $this->option('drop-threshold');
        $dryRun          = $this->option('dry-run');

        // ── 1. Anomalii nealertate ───────────────────────────────────────────────
        $anomalies = WinmentorPriceAnomaly::whereIn('anomaly_type', [
                WinmentorPriceAnomaly::TYPE_PRICE_SPIKE,
                WinmentorPriceAnomaly::TYPE_PRICE_DROP,
            ])
            ->whereNull('alerted_at')
            ->whereNotNull('woo_product_id')
            ->with(['product', 'newSupplier'])
            ->get();

        $this->info("Anomalii nealertate: {$anomalies->count()}");

        $spikes  = [];
        $drops   = [];
        $alertIds = [];

        foreach ($anomalies as $anomaly) {
            $product = $anomaly->product;
            if (! $product) continue;

            $sellPrice = (float) $product->regular_price;
            if ($sellPrice <= 0) continue;

            $newPrice  = (float) $anomaly->new_price;
            $oldPrice  = (float) $anomaly->previous_price;
            if ($newPrice <= 0) continue;

            $pct    = (float) $anomaly->price_change_pct;
            $margin = $newPrice > 0 ? round(($sellPrice - $newPrice) / $newPrice * 100, 1) : null;

            $supplier = $anomaly->newSupplier?->name
                ?? $anomaly->new_part_id
                ?? '—';

            // Verifică dacă prețul de vânzare a fost actualizat după data intrării
            $sellUpdated = ProductPriceLog::where('woo_product_id', $anomaly->woo_product_id)
                ->where('changed_at', '>', $anomaly->new_date)
                ->exists();

            if ($sellUpdated) {
                // Prețul de vânzare a fost deja actualizat — marcăm ca alertat și continuăm
                $alertIds[] = $anomaly->id;
                continue;
            }

            $row = [
                'anomaly_id' => $anomaly->id,
                'name'       => $product->name,
                'sku'        => $product->sku ?? '—',
                'pct'        => abs($pct),
                'old_price'  => number_format($oldPrice, 2, '.', ''),
                'new_price'  => number_format($newPrice, 2, '.', ''),
                'sell_price' => number_format($sellPrice, 2, '.', ''),
                'margin'     => $margin,
                'supplier'   => $supplier,
            ];

            if ($anomaly->anomaly_type === WinmentorPriceAnomaly::TYPE_PRICE_SPIKE && abs($pct) >= $spikeThreshold) {
                $spikes[]   = $row;
                $alertIds[] = $anomaly->id;
            } elseif ($anomaly->anomaly_type === WinmentorPriceAnomaly::TYPE_PRICE_DROP && abs($pct) >= $dropThreshold) {
                $drops[]    = $row;
                $alertIds[] = $anomaly->id;
            } else {
                // Sub pragul de alertare — marcăm oricum ca alertat să nu re-procesăm
                $alertIds[] = $anomaly->id;
            }
        }

        // ── 2. Marje sub prag (cu deduplicare) ──────────────────────────────────
        // Alertăm un produs doar dacă:
        //   a) Nu a mai fost alertat niciodată pentru marjă, SAU
        //   b) S-a mai facut o achiziție nouă după ultima alertă (last_purchase_date > margin_alerted_at)
        //      ȘI marja este încă sub prag.
        $margins         = [];
        $marginAlertIds  = []; // ProductSupplier IDs de marcat ca alertate

        \App\Models\ProductSupplier::whereNotNull('last_purchase_price')
            ->where('last_purchase_price', '>', 0)
            ->with(['product', 'supplier'])
            ->chunk(500, function ($chunk) use ($minMargin, &$margins, &$marginAlertIds) {
                foreach ($chunk as $ps) {
                    $product = $ps->product;
                    if (! $product) continue;

                    $sellPrice     = (float) $product->regular_price;
                    $purchasePrice = (float) $ps->last_purchase_price;

                    if ($sellPrice <= 0 || $purchasePrice <= 0) continue;

                    $margin = ($sellPrice - $purchasePrice) / $purchasePrice * 100;

                    if ($margin >= $minMargin) continue;

                    // Deduplicare: dacă am alertat deja și nu a mai apărut o achiziție nouă, sărim.
                    if ($ps->margin_alerted_at) {
                        $lastPurchase = $ps->last_purchase_date
                            ? \Illuminate\Support\Carbon::parse($ps->last_purchase_date)
                            : null;
                        $alertedAt = \Illuminate\Support\Carbon::parse($ps->margin_alerted_at);

                        if (! $lastPurchase || ! $lastPurchase->gt($alertedAt)) {
                            continue; // fără achiziție nouă de la ultima alertă — nu re-alertăm
                        }
                    }

                    $margins[]       = [
                        'name'           => $product->name,
                        'sku'            => $product->sku ?? '—',
                        'purchase_price' => number_format($purchasePrice, 2, '.', ''),
                        'sell_price'     => number_format($sellPrice, 2, '.', ''),
                        'margin'         => round($margin, 1),
                        'supplier'       => $ps->supplier?->name ?? '—',
                    ];
                    $marginAlertIds[] = $ps->id;
                }
            });

        // Sortăm marjele crescător (cele mai grave primele)
        usort($margins, fn ($a, $b) => $a['margin'] <=> $b['margin']);

        // ── 3. Raport CLI ────────────────────────────────────────────────────────
        $this->line(str_repeat('─', 60));
        $this->info('Creșteri semnificative nealertate: ' . count($spikes));
        $this->info('Scăderi semnificative nealertate: '  . count($drops));
        $this->info('Produse cu marjă sub ' . $minMargin . '%: ' . count($margins));

        if ($dryRun) {
            $this->table(['Tip', 'Produs', 'SKU', 'Δ%', 'Achiz.', 'Vânzare', 'Marjă', 'Furnizor'],
                array_map(fn ($r) => ['SPIKE', $r['name'], $r['sku'], '+' . $r['pct'] . '%', $r['new_price'], $r['sell_price'], $r['margin'] . '%', $r['supplier']], $spikes)
                + array_map(fn ($r) => ['DROP',  $r['name'], $r['sku'], '-' . $r['pct'] . '%', $r['new_price'], $r['sell_price'], $r['margin'] . '%', $r['supplier']], $drops)
            );
            $this->warn('Dry-run — nicio notificare trimisă.');
            return self::SUCCESS;
        }

        // ── 4. Trimite notificarea ───────────────────────────────────────────────
        if (! empty($spikes) || ! empty($drops) || ! empty($margins)) {
            $recipients = User::where(function ($q) {
                $q->whereIn('role', [
                    User::ROLE_MANAGER_ACHIZITII,
                    User::ROLE_MANAGER,
                    User::ROLE_DIRECTOR_FINANCIAR,
                ])
                ->orWhere('is_super_admin', true);
            })->get();

            $notification = new PriceChangeAlertNotification($spikes, $drops, $margins);

            foreach ($recipients as $user) {
                $user->notify($notification);
            }

            $this->info("Notificări trimise la {$recipients->count()} utilizatori.");
        } else {
            $this->info('Nicio alertă de trimis.');
        }

        // ── 5. Marchează anomaliile + marjele ca alertate ───────────────────────
        if (! empty($alertIds)) {
            WinmentorPriceAnomaly::whereIn('id', $alertIds)
                ->update(['alerted_at' => now()]);
        }

        if (! empty($marginAlertIds)) {
            \App\Models\ProductSupplier::whereIn('id', $marginAlertIds)
                ->update(['margin_alerted_at' => now()]);
        }

        Log::channel('winmentor_sync')->info('[AlertPriceChanges] Finalizat', [
            'spikes'  => count($spikes),
            'drops'   => count($drops),
            'margins' => count($margins),
            'alerted' => count($alertIds),
        ]);

        return self::SUCCESS;
    }
}
