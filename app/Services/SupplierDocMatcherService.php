<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\EmailParsedDocument;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupplierDocMatcherService
{
    public function match(EmailParsedDocument $record): void
    {
        $supplierId = $record->supplier_id;
        if (! $supplierId) {
            $record->update(['match_status' => 'unmatched']); return;
        }

        $supplier = Supplier::find($supplierId);
        if (! $supplier?->winmentor_id) {
            $record->update(['match_status' => 'no_entry']); return;
        }

        $products = $record->products ?? [];
        $docDate  = $record->doc_date;

        if (empty($products) || ! $docDate) {
            $record->update(['match_status' => 'no_entry']); return;
        }

        $docDate = Carbon::parse($docDate);

        $productBySkuRaw = DB::table('woo_products')
            ->whereNotNull('sku')
            ->get(['id', 'sku', 'name', 'winmentor_name'])
            ->keyBy(fn($p) => ltrim($p->sku, '0'));

        // ── PASS 0: nr_doc direct (numărul din document = nr_doc WM) ──────
        $bestDoc      = $this->matchByDocNumber($record->doc_number, $supplier->winmentor_id, $docDate);
        $matchedBySku = false;

        if ($bestDoc) {
            // Preluăm rowurile WM pentru acel doc
            $wmRows = DB::table('winmentor_intrari_raw')
                ->where('part_id', $supplier->winmentor_id)
                ->where('nr_doc', $bestDoc)
                ->get(['nr_doc', 'sku', 'cantitate', 'uom', 'data_intrare']);

            [$status, $discrepancies] = $this->buildDiscrepancies($wmRows, $products, false, $productBySkuRaw);

            // Dacă am găsit documentul prin nr_doc (semnal direct), statusul minim e partial
            // chiar dacă cantitățile diferă (UOM mismatch: paleți vs bucăți)
            if ($status === 'unmatched') {
                $status = 'partial';
            }

            $record->update([
                'winmentor_doc_nr' => $bestDoc,
                'match_status'     => $status,
                'discrepancies'    => $discrepancies,
            ]);
            return;
        }

        // ── PASS 1+2: scoring combinat qty 70% + SKU 30% ─────────────────
        // Fereastră ±7 zile (avizele pot fi înregistrate cu întârziere)
        $wmEntries = DB::table('winmentor_intrari_raw')
            ->where('part_id', $supplier->winmentor_id)
            ->whereBetween('data_intrare', [
                $docDate->copy()->subDays(7)->toDateString(),
                $docDate->copy()->addDays(7)->toDateString(),
            ])
            ->get(['nr_doc', 'sku', 'cantitate', 'uom', 'data_intrare']);

        if ($wmEntries->isEmpty()) {
            $record->update(['match_status' => 'no_entry']); return;
        }

        $wmByDoc = $wmEntries->groupBy('nr_doc');

        [$bestDoc, $bestScore, $matchedBySku, $absQtyMatches] = $this->scoreCombined($wmByDoc, $products);

        // Numărul de produse pozitive (fără retururi)
        $positiveCount = count(array_filter($products, fn($p) => round((float)($p['cantitate'] ?? 0), 3) > 0));

        // Acceptăm dacă:
        // - scorul combinat ≥ 0.35 (cel puțin ~50% cantități potrivite)
        // - SAU cel puțin 3 cantități coincid absolut pe doc cu ≥5 produse
        //   (avize cu mix paleți+bucăți unde fracțiunile nu coincid dar bucățile da)
        $accepted = $bestDoc && ($bestScore >= 0.35 || ($absQtyMatches >= 3 && $positiveCount >= 5));

        if (! $accepted) {
            $record->update(['match_status' => 'unmatched', 'winmentor_doc_nr' => null]); return;
        }

        [$status, $discrepancies] = $this->buildDiscrepancies(
            $wmByDoc[$bestDoc],
            $products,
            $matchedBySku,
            $productBySkuRaw
        );

        // Dacă scorerul a confirmat documentul (≥0.35), statusul minim e partial
        if ($status === 'unmatched') {
            $status = 'partial';
        }

        $record->update([
            'winmentor_doc_nr' => $bestDoc,
            'match_status'     => $status,
            'discrepancies'    => $discrepancies,
        ]);
    }

    // ── PASS 0: caută WM nr_doc bazat pe numărul documentului ─────────────
    // WM operatorii introduc nr facturii/avizului ca nr_doc (uneori fără prefix alfanumeric
    // și fără zero-padding). Ex: AROCAV26000242 → 242, FTEI216747 → 216747

    private function matchByDocNumber(?string $docNumber, string $wmPartId, Carbon $docDate): ?string
    {
        if (! $docNumber || trim($docNumber) === '') return null;

        $candidates = $this->docNrCandidates($docNumber);
        if (empty($candidates)) return null;

        return DB::table('winmentor_intrari_raw')
            ->where('part_id', $wmPartId)
            ->whereIn('nr_doc', $candidates)
            ->whereBetween('data_intrare', [
                $docDate->copy()->subDays(10)->toDateString(),
                $docDate->copy()->addDays(10)->toDateString(),
            ])
            ->value('nr_doc');
    }

    // Generează variante posibile de nr_doc din numărul documentului furnizorului.
    // WM stochează de obicei valoarea numerică a documentului, fără prefix alfanumeric
    // și fără zero-padding. Strategia: încercăm sufixe de 2-8 cifre (de la dreapta)
    // și prefixe-skip (de la stânga) pe fiecare grup numeric.
    // Ex: AROCAV26000242 → 242 (suffix 3), FTEI216747 → 216747, 1301606830 → 6830 (suffix 4)

    private function docNrCandidates(string $docNumber): array
    {
        $candidates = [];

        preg_match_all('/\d+/', $docNumber, $matches);
        foreach ($matches[0] as $group) {
            // 1. Valoarea brută și ca integer
            $candidates[] = $group;
            $asInt = (string) (int) $group;
            if ($asInt !== $group) $candidates[] = $asInt;

            // 2. Sufixe de 2-8 cifre de la dreapta (prinde și 1301606830 → 6830)
            for ($len = 2; $len <= min(8, strlen($group) - 1); $len++) {
                $suffix = ltrim(substr($group, -$len), '0');
                if ($suffix !== '' && strlen($suffix) >= 2) {
                    $candidates[] = $suffix;
                }
            }

            // 3. Skip de la stânga cu strip zeros (prinde și 26000242 → 242)
            for ($skip = 1; $skip < min(strlen($group), 5); $skip++) {
                $tail = ltrim(substr($group, $skip), '0');
                if ($tail !== '' && strlen($tail) >= 2) {
                    $candidates[] = $tail;
                }
            }
        }

        return array_unique(array_filter($candidates));
    }

    // ── Scoring combinat: qty 70% + SKU 30% ───────────────────────────────
    // Returnează [$bestDocNr, $combinedScore, $matchedBySku, $bestAbsQtyMatches]

    private function scoreCombined($wmByDoc, array $products): array
    {
        $bestDoc            = null;
        $bestScore          = 0.0;
        $bestMatchedBySku   = false;
        $bestAbsQtyMatches  = 0;

        // Cantitățile pozitive din document
        $docQtys = collect($products)
            ->map(fn($p) => round((float) ($p['cantitate'] ?? 0), 3))
            ->filter(fn($q) => $q > 0)
            ->sort()->values();

        $docCount = $docQtys->count();

        foreach ($wmByDoc as $docNr => $wmRows) {

            // ── Scor SKU ──────────────────────────────────────────────────
            $wmSkus     = $wmRows->keyBy(fn($r) => ltrim($r->sku, '0'));
            $skuMatched = 0;
            foreach ($products as $prod) {
                $gtin = ltrim($prod['gtin'] ?? '', '0');
                $cod  = ltrim($prod['cod_furnizor'] ?? '', '0');
                if (($gtin && $wmSkus->has($gtin)) || ($cod && $wmSkus->has($cod))) {
                    $skuMatched++;
                }
            }
            $skuScore = $docCount > 0 ? $skuMatched / $docCount : 0.0;

            // ── Scor cantități (consumăm WM pe măsură ce potrivim) ────────
            $wmArr = $wmRows
                ->map(fn($r) => round((float) $r->cantitate, 3))
                ->filter(fn($q) => $q > 0)
                ->sort()->values()->all();

            $qtyMatched = 0;
            foreach ($docQtys->all() as $qty) {
                $idx = array_search($qty, $wmArr);
                if ($idx !== false) {
                    $qtyMatched++;
                    array_splice($wmArr, $idx, 1);
                }
            }
            $qtyScore = $docCount > 0 ? $qtyMatched / $docCount : 0.0;

            // ── Scoring combinat: qty 70%, SKU 30% ────────────────────────
            $combined = ($qtyScore * 0.7) + ($skuScore * 0.3);

            if ($skuScore >= 1.0) {
                $combined = max($combined, 0.95);
            }

            if ($combined > $bestScore || ($qtyMatched > $bestAbsQtyMatches && $combined >= ($bestScore - 0.05))) {
                $bestScore          = $combined;
                $bestDoc            = $docNr;
                $bestMatchedBySku   = $skuMatched > 0;
                $bestAbsQtyMatches  = $qtyMatched;
            }
        }

        return [$bestDoc, $bestScore, $bestMatchedBySku, $bestAbsQtyMatches];
    }

    // ── Report helper: AI suggestions (nu schimbă match_status) ──────────

    public function getAiSuggestionsForReport(array $products, Supplier $supplier, $productBySkuRaw): array
    {
        if (empty($products) || ! $supplier->winmentor_id) return [];

        $wmEntries = DB::table('winmentor_intrari_raw')
            ->where('part_id', $supplier->winmentor_id)
            ->whereYear('data_intrare', now()->year)
            ->get(['nr_doc', 'sku', 'cantitate', 'uom', 'data_intrare']);

        if ($wmEntries->isEmpty()) return [];

        $wmByDoc = $wmEntries->groupBy('nr_doc');

        return $this->callAiForNameMatching($products, $wmByDoc, $supplier, $productBySkuRaw);
    }

    // ── AI matching pe denumiri (doar pentru raport) ───────────────────────

    private function callAiForNameMatching(array $unmappedProducts, $wmByDoc, Supplier $supplier, $productBySkuRaw): array
    {
        $allWmSkus = $wmByDoc->flatten(1)->unique('sku')->map(function ($row) use ($productBySkuRaw) {
            $erpProd = $productBySkuRaw->get(ltrim($row->sku, '0'));
            return [
                'sku'      => $row->sku,
                'denumire' => $erpProd?->winmentor_name ?: $erpProd?->name ?: '?',
            ];
        })->values()->all();

        if (empty($allWmSkus)) return [];

        $furnizorProducts = array_map(fn($p) => [
            'sku'      => ltrim($p['gtin'] ?? $p['cod_furnizor'] ?? '', '0'),
            'denumire' => $p['denumire'] ?? '',
        ], $unmappedProducts);

        $furnizorJson = json_encode($furnizorProducts, JSON_UNESCAPED_UNICODE);
        $erpJson      = json_encode($allWmSkus, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Ești un expert în produse de construcții. Trebuie să asociezi produse din nomenclatorul furnizorului cu produse din ERP.

Furnizor: {$supplier->name}

PRODUSE FURNIZOR (de asociat):
{$furnizorJson}

PRODUSE ERP (candidați):
{$erpJson}

Pentru fiecare produs furnizor, găsește cel mai potrivit produs ERP bazat pe denumire (același produs, poate fi scris diferit).
Returnează DOAR JSON array:
[{"sku_furnizor": "...", "sku_erp": "...", "confidence": 0.0-1.0}]

Reguli:
- confidence >= 0.85 doar dacă ești sigur că e același produs
- confidence 0.6-0.84 dacă probabil același produs
- Nu include în rezultat dacă confidence < 0.6
- Nu inventa SKU-uri care nu există în lista ERP
PROMPT;

        try {
            $apiKey   = AppSetting::get('anthropic_api_key') ?: env('ANTHROPIC_API_KEY');
            $response = Http::timeout(30)->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 1024,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

            $raw = trim($response->json('content.0.text', '[]'));
            $raw = preg_replace('/^```json\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);

            $aiResults = json_decode($raw, true) ?? [];

            $erpBySkuRaw  = collect($allWmSkus)->keyBy(fn($r) => ltrim($r['sku'], '0'));
            $furnBySkuRaw = collect($furnizorProducts)->keyBy(fn($r) => $r['sku']);

            return array_map(function ($r) use ($erpBySkuRaw, $furnBySkuRaw) {
                $skuFurn = ltrim($r['sku_furnizor'] ?? '', '0');
                $skuErp  = ltrim($r['sku_erp'] ?? '', '0');
                return [
                    'sku_furnizor' => $skuFurn,
                    'den_furnizor' => $furnBySkuRaw[$skuFurn]['denumire'] ?? '',
                    'sku_erp'      => $skuErp,
                    'den_erp'      => $erpBySkuRaw[$skuErp]['denumire'] ?? '',
                    'confidence'   => (float) ($r['confidence'] ?? 0.7),
                ];
            }, array_filter($aiResults, fn($r) => ($r['confidence'] ?? 0) >= 0.6));

        } catch (\Throwable $e) {
            Log::warning("SupplierDocMatcherService AI names error: " . $e->getMessage());
            return [];
        }
    }

    // ── Construire discrepanțe ─────────────────────────────────────────────
    // Praguri: matched ≥ 75%, partial ≥ 25%
    // Potrivire: SKU exact → cantitate exactă (consumând WM rows)

    private function buildDiscrepancies($wmRows, array $products, bool $_, $productBySkuRaw): array
    {
        $wmSkuIdx = $wmRows->keyBy(fn($r) => ltrim($r->sku, '0'));

        // Pool de WM rows disponibile pentru qty matching (consumabile)
        $wmPool = $wmRows->map(fn($r) => [
            'row'  => $r,
            'used' => false,
        ])->values()->all();

        $discrepancies = [];
        $matchedCount  = 0;

        // Totalul include doar produsele cu cantitate > 0 (excludem retururi/mostre negative)
        $positiveProducts = array_filter($products, fn($p) => round((float) ($p['cantitate'] ?? 0), 3) > 0);
        $total = count($positiveProducts);

        foreach ($products as $prod) {
            $gtin     = ltrim($prod['gtin'] ?? '', '0');
            $cod      = ltrim($prod['cod_furnizor'] ?? '', '0');
            $cantFurn = round((float) ($prod['cantitate'] ?? 0), 3);
            $skuFurn  = $gtin ?: $cod;

            $wmRow   = null;
            $poolIdx = null;

            // 1. SKU exact (GTIN sau cod furnizor)
            if ($gtin && $wmSkuIdx->has($gtin)) {
                $wmRow = $wmSkuIdx[$gtin];
            } elseif ($cod && $wmSkuIdx->has($cod)) {
                $wmRow = $wmSkuIdx[$cod];
            } elseif ($cantFurn > 0) {
                // 2. Cantitate exactă — consumăm primul WM row neutilizat cu aceeași cantitate
                foreach ($wmPool as $i => &$item) {
                    if (! $item['used'] && abs(round((float) $item['row']->cantitate, 3) - $cantFurn) < 0.001) {
                        $wmRow   = $item['row'];
                        $poolIdx = $i;
                        break;
                    }
                }
                unset($item);
            }

            if (! $wmRow) {
                $discrepancies[] = [
                    'tip'           => 'sku_negasit_in_wm',
                    'sku_furnizor'  => $skuFurn,
                    'den_furnizor'  => $prod['denumire'] ?? '',
                    'sku_nostru'    => null,
                    'den_nostru'    => null,
                    'pret_furnizor' => $prod['pret_unitar'] ?? null,
                    'pret_nostru'   => null,
                    'cant_furnizor' => $cantFurn,
                    'cant_nostru'   => null,
                ];
                continue;
            }

            // Marcăm WM row ca folosit (doar pentru pool, nu pentru SKU idx)
            if ($poolIdx !== null) {
                $wmPool[$poolIdx]['used'] = true;
            }

            $cantWm  = round((float) $wmRow->cantitate, 3);
            $skuWm   = $wmRow->sku;
            $erpProd = $productBySkuRaw->get(ltrim($skuWm, '0'));

            $pretNostru = $erpProd
                ? DB::table('product_purchase_price_logs')
                    ->where('woo_product_id', $erpProd->id)
                    ->orderByDesc('acquired_at')->value('unit_price')
                : null;

            if (abs($cantFurn - $cantWm) > 0.001) {
                // Cantitatea numerică diferă — singura discrepanță relevantă
                $discrepancies[] = [
                    'tip'           => 'cantitate_diferita',
                    'sku_furnizor'  => $skuFurn,
                    'den_furnizor'  => $prod['denumire'] ?? '',
                    'sku_nostru'    => $skuWm,
                    'den_nostru'    => $erpProd?->winmentor_name ?: $erpProd?->name,
                    'pret_furnizor' => $prod['pret_unitar'] ?? null,
                    'pret_nostru'   => $pretNostru ? (float) $pretNostru : null,
                    'cant_furnizor' => $cantFurn,
                    'cant_nostru'   => $cantWm,
                ];
            } else {
                // Cantitate numerică ok → potrivire, indiferent de UOM sau SKU diferit
                $matchedCount++;
            }
        }

        $matchRatio = $total > 0 ? $matchedCount / $total : 0;

        $status = match (true) {
            $matchRatio >= 0.75 => 'matched',
            $matchRatio >= 0.25 => 'partial',
            default             => 'unmatched',
        };

        return [$status, $discrepancies];
    }
}
