<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FillSupplierSkuFromIntrariCommand extends Command
{
    protected $signature = 'supplier:fill-sku-from-intrari
                            {--supplier=* : Numele furnizorilor (poate fi repetat; gol = toți)}
                            {--threshold=80 : Prag minim similar_text % pentru fuzzy match (fallback)}
                            {--dry-run : Doar afișează ce ar face, fără update}';

    protected $description = 'Completează supplier_sku din WinMentor (Bridge + intrări raw)';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $threshold   = (int) $this->option('threshold');
        $dryRun      = (bool) $this->option('dry-run');
        $filterNames = array_filter($this->option('supplier'));

        if ($dryRun) {
            $this->warn('=== DRY RUN — nu se modifică nimic ===');
        }

        // ── 1. Descarcă indexul codExternAlt → codExtern din MentorAPI ──
        $this->info('Descarcă articoli din MentorAPI...');
        $bridgeIndex = $this->fetchBridgeIndex($bridge);
        $this->info('  ' . count($bridgeIndex['altToExtern']) . ' articoli cu codExternAlt indexați');
        $this->info('  ' . count($bridgeIndex['denToExtern']) . ' denumiri indexate');

        // ── 2. Toți furnizorii care au produse fără supplier_sku ──
        $supplierQuery = DB::table('product_suppliers as ps')
            ->join('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->join('woo_products as w', 'w.id', '=', 'ps.woo_product_id')
            ->where('w.status', 'publish')
            ->where(function ($q) {
                $q->whereNull('ps.supplier_sku')->orWhere('ps.supplier_sku', '');
            })
            ->select('s.id as supplier_id', 's.name as supplier_name')
            ->distinct()
            ->orderBy('s.name');

        $suppliers = $supplierQuery->get();

        if ($filterNames) {
            $suppliers = $suppliers->filter(function ($s) use ($filterNames) {
                foreach ($filterNames as $filter) {
                    if (stripos($s->supplier_name, $filter) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }

        $this->info("Furnizori de procesat: {$suppliers->count()}");

        $totals = ['bridge' => 0, 'exact' => 0, 'sku' => 0, 'fuzzy' => 0, 'no_match' => 0];

        foreach ($suppliers as $supplier) {
            $result = $this->processSupplier($supplier, $bridgeIndex, $threshold, $dryRun);
            foreach ($result as $k => $v) {
                $totals[$k] += $v;
            }
        }

        $this->newLine();
        $totalOk = $totals['bridge'] + $totals['exact'] + $totals['sku'] + $totals['fuzzy'];
        $this->info("=== TOTAL ===");
        $this->info("Completate: {$totalOk} (bridge: {$totals['bridge']}, exact: {$totals['exact']}, SKU: {$totals['sku']}, fuzzy: {$totals['fuzzy']})");
        $this->info("Fără potrivire: {$totals['no_match']}");

        return self::SUCCESS;
    }

    private function processSupplier(object $supplier, array $bridgeIndex, int $threshold, bool $dryRun): array
    {
        $stats = ['bridge' => 0, 'exact' => 0, 'sku' => 0, 'fuzzy' => 0, 'no_match' => 0];

        // Produse fără SKU furnizor
        $products = DB::table('product_suppliers as ps')
            ->join('woo_products as w', 'w.id', '=', 'ps.woo_product_id')
            ->where('ps.supplier_id', $supplier->supplier_id)
            ->where('w.status', 'publish')
            ->where(function ($q) {
                $q->whereNull('ps.supplier_sku')->orWhere('ps.supplier_sku', '');
            })
            ->select('ps.id as ps_id', 'w.id as woo_id', 'w.name', 'w.sku')
            ->get();

        if ($products->isEmpty()) {
            return $stats;
        }

        // SKU-uri din WinMentor intrări pentru acest furnizor
        $wmItems = DB::table('winmentor_intrari_raw')
            ->where('den_furnizor', 'LIKE', '%' . $this->extractSupplierKeyword($supplier->supplier_name) . '%')
            ->where('sku', '!=', '')
            ->whereNotNull('sku')
            ->select('sku', 'den_articol')
            ->distinct()
            ->get();

        // Index: EAN (din intrări) → prezent la acest furnizor
        $wmEanSet = $wmItems->pluck('sku')->unique()->flip()->toArray();

        // Index: denumire UPPER → EAN
        $wmByName = [];
        foreach ($wmItems as $item) {
            $key = mb_strtoupper(trim($item->den_articol));
            $wmByName[$key] = $item->sku;
        }

        // Reverse index: EAN → codExternAlt (cod catalog furnizor)
        $externToAlt = $bridgeIndex['externToAlt'];

        $updates = [];

        foreach ($products as $p) {
            $nameUpper = mb_strtoupper(trim($p->name));

            // ── Metoda 1 (BRIDGE): woo_sku = codExternAlt → codExtern (EAN) → verifică în intrări furnizor ──
            // supplier_sku = woo_sku (care E deja codul catalog furnizor)
            if ($p->sku) {
                $ean = $bridgeIndex['altToExtern'][$p->sku] ?? null;
                if ($ean && isset($wmEanSet[$ean])) {
                    $updates[] = ['ps_id' => $p->ps_id, 'sku' => $p->sku, 'method' => 'bridge', 'name' => $p->name];
                    $stats['bridge']++;
                    continue;
                }

                // Poate woo_sku e deja EAN-ul (cod_extern) → supplier_sku = codExternAlt
                if (isset($wmEanSet[$p->sku])) {
                    $alt = $externToAlt[$p->sku] ?? null;
                    if ($alt) {
                        $updates[] = ['ps_id' => $p->ps_id, 'sku' => $alt, 'method' => 'bridge', 'name' => $p->name];
                        $stats['bridge']++;
                        continue;
                    }
                }
            }

            // ── Metoda 2 (DENUMIRE EXACTĂ): denumire ERP → denumire WM → EAN → codExternAlt ──
            if (isset($wmByName[$nameUpper])) {
                $ean = $wmByName[$nameUpper];
                $alt = $externToAlt[$ean] ?? null;
                if ($alt) {
                    $updates[] = ['ps_id' => $p->ps_id, 'sku' => $alt, 'method' => 'exact', 'name' => $p->name];
                    $stats['exact']++;
                    continue;
                }
            }

            // ── Metoda 3 (BRIDGE DENUMIRE): denumire WM din bridge → match pe intrări → codExternAlt ──
            if ($p->sku) {
                $wmDen = $bridgeIndex['altToDen'][$p->sku] ?? null;
                if ($wmDen) {
                    $wmDenUpper = mb_strtoupper(trim($wmDen));
                    if (isset($wmByName[$wmDenUpper])) {
                        // woo_sku = codExternAlt deja (altfel nu ar fi în altToDen)
                        $updates[] = ['ps_id' => $p->ps_id, 'sku' => $p->sku, 'method' => 'bridge', 'name' => $p->name];
                        $stats['bridge']++;
                        continue;
                    }
                }
            }

            // ── Metoda 4 (FUZZY fallback): similar_text cu validare numere ──
            if ($wmItems->isEmpty()) {
                $stats['no_match']++;
                continue;
            }

            $bestScore  = 0;
            $bestSku    = '';
            $bestName   = '';
            $erpNumbers = $this->extractNumbers($nameUpper);

            foreach ($wmItems as $wm) {
                $wmName = mb_strtoupper(trim($wm->den_articol));
                similar_text($nameUpper, $wmName, $pct);
                if ($pct > $bestScore) {
                    $wmNumbers = $this->extractNumbers($wmName);
                    if ($this->numbersMatch($erpNumbers, $wmNumbers)
                        && !$this->qualitativeWordsConflict($nameUpper, $wmName)) {
                        $bestScore = $pct;
                        $bestSku   = $wm->sku;
                        $bestName  = $wm->den_articol;
                    }
                }
            }

            if ($bestScore >= $threshold) {
                // Fuzzy: $bestSku e EAN, trebuie convertit la codExternAlt
                $altCode = $externToAlt[$bestSku] ?? null;
                if ($altCode) {
                    $updates[] = [
                        'ps_id'   => $p->ps_id,
                        'sku'     => $altCode,
                        'method'  => 'fuzzy',
                        'name'    => $p->name,
                        'wm_name' => $bestName,
                        'score'   => round($bestScore),
                    ];
                    $stats['fuzzy']++;
                } else {
                    $stats['no_match']++;
                }
            } else {
                $stats['no_match']++;
            }
        }

        // Afișare rezultate
        $total = $stats['bridge'] + $stats['exact'] + $stats['sku'] + $stats['fuzzy'];

        if ($total > 0) {
            $this->info("  {$supplier->supplier_name}: {$total} completate (bridge: {$stats['bridge']}, exact: {$stats['exact']}, SKU: {$stats['sku']}, fuzzy: {$stats['fuzzy']}) | {$stats['no_match']} fără match");
        } else {
            $this->line("  {$supplier->supplier_name}: {$stats['no_match']} fără match");
        }

        // Afișăm fuzzy matches pentru verificare
        foreach ($updates as $u) {
            if ($u['method'] === 'fuzzy') {
                $this->line("    FUZZY ({$u['score']}%): {$u['name']} → {$u['wm_name']} [SKU={$u['sku']}]");
            }
        }

        if (!$dryRun) {
            foreach ($updates as $u) {
                DB::table('product_suppliers')
                    ->where('id', $u['ps_id'])
                    ->update(['supplier_sku' => $u['sku'], 'updated_at' => now()]);
            }
        }

        return $stats;
    }

    /**
     * Descarcă toți articolii din MentorAPI și construiește indexuri.
     */
    private function fetchBridgeIndex(WinmentorBridgeClient $bridge): array
    {
        $altToExtern = []; // codExternAlt → codExtern (EAN)
        $externToAlt = []; // codExtern (EAN) → codExternAlt (cod catalog furnizor)
        $altToDen    = []; // codExternAlt → denumire WM
        $denToExtern = []; // denumire UPPER → codExtern (EAN)

        $page = 1;
        $pageSize = 500;

        do {
            $result     = $bridge->getArticolePaginated($page, $pageSize);
            $items      = $result['items'] ?? [];
            $totalPages = (int) ($result['totalPages'] ?? 1);

            foreach ($items as $item) {
                $codExtern = trim($item['codExtern'] ?? '');
                $codAlt    = trim($item['codExternAlt'] ?? '');
                $den       = trim($item['denumire'] ?? '');

                if ($codExtern !== '') {
                    if ($codAlt !== '') {
                        $altToExtern[$codAlt] = $codExtern;
                        $externToAlt[$codExtern] = $codAlt;
                        $altToDen[$codAlt]    = $den;
                    }
                    if ($den !== '') {
                        $denToExtern[mb_strtoupper($den)] = $codExtern;
                    }
                }
            }

            $page++;
        } while ($page <= $totalPages);

        return compact('altToExtern', 'externToAlt', 'altToDen', 'denToExtern');
    }

    private function extractNumbers(string $text): array
    {
        preg_match_all('/\d+[.,\/]\d+|\d+/', $text, $matches);

        return array_map(function ($n) {
            return str_replace(',', '.', $n);
        }, $matches[0]);
    }

    private function numbersMatch(array $erpNumbers, array $wmNumbers): bool
    {
        if (empty($erpNumbers) && empty($wmNumbers)) {
            return true;
        }
        if (empty($erpNumbers) || empty($wmNumbers)) {
            return true;
        }

        $found = 0;
        foreach ($erpNumbers as $num) {
            if (in_array($num, $wmNumbers)) {
                $found++;
            }
        }

        return $found >= count($erpNumbers) * 0.7;
    }

    private function qualitativeWordsConflict(string $erp, string $wm): bool
    {
        // Culori — dacă ERP are o culoare și WM are o altă culoare, e conflict
        $colors = ['NEGRU', 'ALB', 'ROSU', 'VERDE', 'ALBASTRU', 'GALBEN', 'MARO',
                   'GRI', 'BEJ', 'CREM', 'TERACOT', 'AURIU', 'ARGINTIU', 'BRONZ',
                   'ROZ', 'PORTOCALIU'];

        $erpColors = array_values(array_filter($colors, fn($c) => str_contains($erp, $c)));
        $wmColors  = array_values(array_filter($colors, fn($c) => str_contains($wm, $c)));

        if (!empty($erpColors) && !empty($wmColors)) {
            if ($erpColors !== $wmColors) {
                return true;
            }
        }

        // Alte perechi de cuvinte incompatibile
        $pairs = [
            ['MICA', 'MARE'],
            ['MIC', 'MARE'],
            ['MOALE', 'ASPRU'],
            ['STANGA', 'DREAPTA'],
            ['SIMPLU', 'SONERIE'],
            ['TELEFON', 'TV'],
            ['ZINCAT', 'ROTATIV'],
            ['INT-INT', 'INT-EXT'],
            ['CUTTER', 'RAZUITOR'],
            ['INGUST', 'LIN'],
            ['INGUST', 'STRIAT'],
            ['INGUST', 'DIF NIVEL'],
            ['INTERIOR', 'EXTERIOR'],
            ['INTERIOR', 'EXT'],
        ];

        foreach ($pairs as [$a, $b]) {
            if ((str_contains($erp, $a) && str_contains($wm, $b))
                || (str_contains($erp, $b) && str_contains($wm, $a))) {
                return true;
            }
        }

        return false;
    }

    private function extractSupplierKeyword(string $name): string
    {
        $clean = preg_replace('/\b(SRL|S\.R\.L\.|SA|S\.A\.)\b/i', '', $name);
        $clean = trim($clean);

        $words = preg_split('/[\s\-]+/', $clean);
        foreach ($words as $word) {
            $word = trim($word, ' .');
            if (mb_strlen($word) >= 4) {
                return $word;
            }
        }

        return $words[0] ?? $name;
    }
}
