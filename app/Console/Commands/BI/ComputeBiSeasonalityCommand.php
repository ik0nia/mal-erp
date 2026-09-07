<?php

namespace App\Console\Commands\BI;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Calculează indicii de sezonalitate lunari per produs din vânzările reale
 * multi-anuale (winmentor_vanzari_raw, tipizate 2019+). index = media lunii
 * calendaristice / media lunară generală. Se aplică în recomandările de
 * achiziție (orizontul de comandă e ponderat cu sezonul care URMEAZĂ).
 */
class ComputeBiSeasonalityCommand extends Command
{
    protected $signature = 'bi:compute-seasonality';

    protected $description = 'Calculează sezonalitatea lunară per produs din istoricul multi-anual de vânzări';

    /** Articolele speciale de plăți — aceleași excluderi ca la velocity. */
    private const EXCLUDED_SKUS = [
        '594008496559910', '594008496559378', '594008496559911',
        '594008496559525', '594008496560735',
    ];

    public function handle(): int
    {
        $skuList = implode("','", self::EXCLUDED_SKUS);

        // Cantități nete pe produs × an × lună (ani compleți: 2019 → anul trecut)
        $anMax = (int) now()->subYear()->year;

        $rows = DB::select("
            SELECT sku, an, luna, GREATEST(0, SUM(cantitate)) qty
            FROM winmentor_vanzari_raw
            WHERE sku IS NOT NULL AND sku != '' AND sku NOT IN ('{$skuList}')
              AND (part_id IS NULL OR part_id != '728020531')
              AND an BETWEEN 2019 AND {$anMax}
            GROUP BY sku, an, luna
            HAVING qty > 0
        ");

        $this->info('Combinații produs×an×lună: '.count($rows));

        $bySku = [];
        foreach ($rows as $r) {
            $bySku[$r->sku][] = $r;
        }

        $now      = now();
        $upserts  = [];
        $products = 0;

        foreach ($bySku as $sku => $entries) {
            // Cerință minimă: vânzări în ≥12 luni distincte, pe ≥2 ani — altfel zgomot
            $ani = array_unique(array_column($entries, 'an'));
            if (count($entries) < 12 || count($ani) < 2) {
                continue;
            }

            // Media pe fiecare lună calendaristică (peste anii în care produsul a existat)
            $perLuna = [];
            foreach ($entries as $e) {
                $perLuna[$e->luna][] = (float) $e->qty;
            }

            $mediiLuna = [];
            foreach (range(1, 12) as $luna) {
                $vals = $perLuna[$luna] ?? [];
                $mediiLuna[$luna] = $vals ? array_sum($vals) / count($vals) : 0.0;
            }

            $mediaGenerala = array_sum($mediiLuna) / 12;
            if ($mediaGenerala <= 0) {
                continue;
            }

            foreach ($mediiLuna as $luna => $media) {
                // Cap [0.4, 2.5] — sezonalitate reală, fără extreme din ani singulari
                $idx = max(0.4, min(2.5, $media / $mediaGenerala));

                $upserts[] = [
                    'reference_product_id' => $sku,
                    'luna'                 => $luna,
                    'idx'                  => round($idx, 3),
                    'luni_cu_vanzari'      => count($entries),
                    'computed_at'          => $now,
                ];
            }
            $products++;
        }

        foreach (array_chunk($upserts, 500) as $chunk) {
            DB::table('bi_seasonality')->upsert($chunk, ['reference_product_id', 'luna'], ['idx', 'luni_cu_vanzari', 'computed_at']);
        }

        // Produse care nu mai îndeplinesc criteriile — curățate
        DB::table('bi_seasonality')->where('computed_at', '<', $now)->delete();

        $this->info("✓ Sezonalitate calculată pentru {$products} produse (".count($upserts).' rânduri).');
        return self::SUCCESS;
    }
}
