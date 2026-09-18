<?php

namespace App\Console\Commands\BI;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sezonalitate lunară PER CATEGORIE din istoricul multi-anual de vânzări.
 *
 * Metodă (validată pe date 2019–2025):
 *  - indice per an = qty_lună / media_lunară_a_anului (elimină creșterea an-peste-an)
 *  - MEDIANĂ între ani (robust la COVID-2020 / goluri de date)
 *  - renormalizare la medie 1.0 (sezonul e redistributiv, neutru pe total anual)
 *  - clamp [0.25, 3.0] (păstrează iarna reală: ciment ~0.42, dar taie zgomotul)
 *  - prag minim: ≥2 ani valizi (an valid = ≥8 luni cu vânzări)
 *  - fallback: categorie fără date → categorie-părinte → global (category_id = 0)
 *
 * category_id = 0 → curba GLOBALĂ (produse fără categorie / categorii fără date).
 */
class ComputeBiSeasonalityCategoryCommand extends Command
{
    protected $signature = 'bi:compute-seasonality-category';
    protected $description = 'Calculează sezonalitatea lunară per categorie din istoricul multi-anual de vânzări';

    /** SKU-uri interne (aceleași ca la viteză). */
    private const EXCLUDED_SKUS = [
        '594008496559910', '594008496559378', '594008496559911',
        '594008496559525', '594008496560735',
    ];
    private const EXCLUDED_PART_ID = '728020531';

    private const CLAMP_MIN = 0.25;
    private const CLAMP_MAX = 3.0;
    private const MIN_VALID_YEARS = 2;
    private const MIN_MONTHS_PER_YEAR = 8;

    public function handle(): int
    {
        $lastComplete = (int) now()->subYear()->year;
        $firstYear    = 2019;
        $today        = now()->toDateString();

        $this->info("Sezonalitate pe categorie · ani {$firstYear}–{$lastComplete}");

        $skuIn = "'" . implode("','", self::EXCLUDED_SKUS) . "'";

        // 1) GLOBAL — toate vânzările (an, lună)
        $globalRows = DB::select("
            SELECT an, luna, SUM(GREATEST(0, cantitate)) qty, COUNT(*) linii
            FROM winmentor_vanzari_raw
            WHERE an BETWEEN ? AND ?
              AND sku IS NOT NULL AND sku <> '' AND sku NOT IN ({$skuIn})
              AND (part_id IS NULL OR part_id <> ?)
            GROUP BY an, luna
        ", [$firstYear, $lastComplete, self::EXCLUDED_PART_ID]);

        [$globalIdx, $globalYears, $globalLines] = $this->indexFrom($this->pivotYearMonth($globalRows));
        if ($globalIdx === null) {
            $this->error('Nu am putut calcula curba globală — abort.');
            return self::FAILURE;
        }

        // 2) PER CATEGORIE — (categorie, an, lună)
        $catRows = DB::select("
            SELECT pc.woo_category_id cat, v.an an, v.luna luna,
                   SUM(GREATEST(0, v.cantitate)) qty, COUNT(*) linii
            FROM winmentor_vanzari_raw v
            JOIN woo_products wp ON wp.sku = v.sku
            JOIN woo_product_category pc ON pc.woo_product_id = wp.id
            WHERE v.an BETWEEN ? AND ?
              AND v.sku IS NOT NULL AND v.sku <> '' AND v.sku NOT IN ({$skuIn})
              AND (v.part_id IS NULL OR v.part_id <> ?)
            GROUP BY pc.woo_category_id, v.an, v.luna
        ", [$firstYear, $lastComplete, self::EXCLUDED_PART_ID]);

        // grupăm pe categorie
        $byCat = [];
        foreach ($catRows as $r) {
            $byCat[(int) $r->cat][(int) $r->an][(int) $r->luna] = ['qty' => (float) $r->qty, 'lines' => (int) $r->linii];
        }

        // indice propriu per categorie (unde are date suficiente)
        $own = [];
        foreach ($byCat as $catId => $ym) {
            [$idx, $years, $lines] = $this->indexFrom($ym);
            if ($idx !== null) {
                $own[$catId] = ['idx' => $idx, 'years' => $years, 'lines' => $lines];
            }
        }

        // harta părinte (woo_categories.id → parent_id)
        $parent = DB::table('woo_categories')->pluck('parent_id', 'id')->map(fn ($p) => $p ? (int) $p : null)->all();

        // toate categoriile care au produse (ca orice produs să aibă lookup)
        $allCats = DB::table('woo_product_category')->distinct()->pluck('woo_category_id')->map(fn ($c) => (int) $c)->all();

        $rows = [];
        // rândurile globale (category_id = 0)
        foreach (range(1, 12) as $m) {
            $rows[] = $this->row(0, $m, $globalIdx[$m], $globalYears, $globalLines, 'global', $today);
        }

        foreach ($allCats as $catId) {
            if (isset($own[$catId])) {
                $o = $own[$catId];
                foreach (range(1, 12) as $m) {
                    $rows[] = $this->row($catId, $m, $o['idx'][$m], $o['years'], $o['lines'], 'own', $today);
                }
                continue;
            }
            // urcă pe lanțul de părinți până găsim unul cu date proprii
            $src = 'global'; $use = $globalIdx; $yrs = $globalYears; $lns = $globalLines;
            $p = $parent[$catId] ?? null; $guard = 0;
            while ($p !== null && $guard++ < 10) {
                if (isset($own[$p])) { $src = 'parent'; $use = $own[$p]['idx']; $yrs = $own[$p]['years']; $lns = $own[$p]['lines']; break; }
                $p = $parent[$p] ?? null;
            }
            foreach (range(1, 12) as $m) {
                $rows[] = $this->row($catId, $m, $use[$m], $yrs, $lns, $src, $today);
            }
        }

        DB::table('bi_seasonality_category')->upsert(
            $rows,
            ['woo_category_id', 'month'],
            ['seasonal_index', 'sample_years', 'sample_lines', 'source', 'computed_for', 'updated_at']
        );

        $ownCount = count($own);
        $this->info("Gata: " . count($allCats) . " categorii (proprii: {$ownCount}, restul fallback) + global. " . count($rows) . " rânduri.");
        return self::SUCCESS;
    }

    /** [year => [month => ['qty'=>, 'lines'=>]]] normalizat din rânduri SQL. */
    private function pivotYearMonth(array $rows): array
    {
        $ym = [];
        foreach ($rows as $r) {
            $ym[(int) $r->an][(int) $r->luna] = ['qty' => (float) $r->qty, 'lines' => (int) $r->linii];
        }
        return $ym;
    }

    /**
     * Din [year => [month => qty/lines]] → [indices(1..12), validYears, totalLines] sau [null,0,0].
     */
    private function indexFrom(array $ym): array
    {
        $perMonthRatios = array_fill(1, 12, []);
        $validYears = 0;
        $totalLines = 0;

        foreach ($ym as $year => $months) {
            $monthsWithSales = count(array_filter($months, fn ($x) => $x['qty'] > 0));
            $totalLines += array_sum(array_map(fn ($x) => $x['lines'], $months));
            if ($monthsWithSales < self::MIN_MONTHS_PER_YEAR) {
                continue; // an parțial / prea puține date
            }
            $yearQty = array_sum(array_map(fn ($x) => $x['qty'], $months));
            $avg = $yearQty / 12;
            if ($avg <= 0) {
                continue;
            }
            $validYears++;
            foreach (range(1, 12) as $m) {
                $q = $months[$m]['qty'] ?? 0.0;
                $perMonthRatios[$m][] = $q / $avg;
            }
        }

        if ($validYears < self::MIN_VALID_YEARS) {
            return [null, 0, $totalLines];
        }

        // mediană per lună
        $idx = [];
        foreach (range(1, 12) as $m) {
            $idx[$m] = $this->median($perMonthRatios[$m]);
        }

        // renormalizare la medie 1.0 (neutru pe total anual)
        $mean = array_sum($idx) / 12;
        if ($mean > 0) {
            foreach ($idx as $m => $v) {
                $idx[$m] = $v / $mean;
            }
        }

        // clamp
        foreach ($idx as $m => $v) {
            $idx[$m] = max(self::CLAMP_MIN, min(self::CLAMP_MAX, $v));
        }

        return [$idx, $validYears, $totalLines];
    }

    private function median(array $a): float
    {
        if (empty($a)) {
            return 1.0;
        }
        sort($a);
        $n = count($a);
        return $n % 2 ? $a[($n - 1) / 2] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2;
    }

    private function row(int $cat, int $month, float $idx, int $years, int $lines, string $src, string $today): array
    {
        return [
            'woo_category_id' => $cat,
            'month'           => $month,
            'seasonal_index'  => round($idx, 3),
            'sample_years'    => $years,
            'sample_lines'    => $lines,
            'source'          => $src,
            'computed_for'    => $today,
            'created_at'      => now(),
            'updated_at'      => now(),
        ];
    }
}
