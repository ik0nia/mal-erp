<?php

namespace App\Console\Commands;

use Anthropic\Client as AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates and improves content for non-placeholder WooCommerce products:
 *   - name           (only if unclear abbreviations or typos)
 *   - short_description
 *   - description    (HTML)
 *   - attributes     (saved to woo_product_attributes)
 *
 * Optionally enriches context via DataForSEO organic search before Claude.
 *
 * Usage:
 *   php artisan products:evaluate-woo-content
 *   php artisan products:evaluate-woo-content --enrich          # + DataForSEO specs
 *   php artisan products:evaluate-woo-content --worker=1 --workers=3
 */
class EvaluateWooProductContentCommand extends Command
{
    protected $signature = 'products:evaluate-woo-content
                            {--limit=        : Max products to process (default: all)}
                            {--batch-size=5  : Products per Claude API call}
                            {--sku=          : Process a single product by SKU}
                            {--force         : Re-evaluate even if content already looks good}
                            {--enrich        : Fetch live specs from DataForSEO before Claude}
                            {--worker=1      : Worker index (1-based)}
                            {--workers=1     : Total number of parallel workers}';

    protected $description = 'Evaluate and improve name/short_description/description/attributes for WooCommerce products using Claude';

    private AnthropicClient $claude;
    private string $model;
    private bool $useDataForSeo = false;

    // ── Known attribute names (same as GenerateProductAttributesCommand) ──────
    private const KNOWN_ATTRIBUTES = [
        'Brand', 'Material', 'Culoare', 'Tip produs', 'Utilizare', 'Tip montaj',
        'Lungime (mm)', 'Lățime (mm)', 'Grosime (mm)', 'Înălțime (mm)', 'Adâncime (mm)',
        'Lungime (cm)', 'Lățime (cm)', 'Grosime (cm)',
        'Lungime (m)', 'Lățime (m)',
        'Diametru (mm)', 'Greutate (kg)', 'Volum (L)',
        'Putere (W)', 'Putere maxima (W)', 'Tensiune (V)', 'Curent nominal (A)', 'Curent maxim (A)',
        'Dulie', 'Temperatura culoare (K)', 'Flux luminos (lm)',
        'Curba de declansare', 'Numar poli', 'Numar module', 'Numar prize',
        'Diametru filet (mm)', 'Tip filet', 'Tip atasament',
        'mp/pachet', 'buc/pachet', 'Dimensiuni',
    ];

    private const NON_COLORS = [
        'standard', 'rentabil', 'economic', 'clasic', 'premium', 'professional',
        'profi', 'basic', 'plus', 'extra', 'super', 'mega', 'mini', 'maxi',
    ];

    private const ELECTRIC_PROTECTION_CATS = [
        'tablouri', 'siguranțe', 'intreruptoare automate', 'disjunctoare',
    ];

    // ──────────────────────────────────────────────────────────────────────────

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY is not set in .env.');
            return self::FAILURE;
        }

        $this->claude        = new AnthropicClient(apiKey: $apiKey);
        $this->model         = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
        $this->useDataForSeo = (bool) $this->option('enrich');

        $limit     = $this->option('limit')   ? (int) $this->option('limit')   : null;
        $batchSize = max(1, min(10, (int) $this->option('batch-size')));
        $singleSku = $this->option('sku');
        $force     = (bool) $this->option('force');
        $worker    = max(1, (int) $this->option('worker'));
        $workers   = max(1, (int) $this->option('workers'));

        $enrichNote = $this->useDataForSeo ? ' +DataForSEO' : '';
        $workerNote = $workers > 1 ? ", worker: {$worker}/{$workers}" : '';
        $this->info("Woo content evaluator — model: {$this->model}, batch: {$batchSize}{$enrichNote}{$workerNote}");

        $categoryMap = $this->buildCategoryMap();
        $this->info('Category map loaded for ' . count($categoryMap) . ' products.');

        // Importă atributele native din WooCommerce JSON dacă nu sunt deja în tabelă
        $imported = $this->importWooNativeAttributes();
        if ($imported > 0) {
            $this->info("Importate {$imported} atribute native din WooCommerce JSON.");
        }

        $query = DB::table('woo_products')
            ->where('is_placeholder', false)
            ->select('id', 'name', 'sku', 'brand', 'short_description', 'description', 'unit');

        if ($workers > 1) {
            $query->whereRaw('(id % ?) = ?', [$workers, $worker - 1]);
        }

        if ($singleSku) {
            $query->where('sku', $singleSku);
        } elseif (! $force) {
            $query->where(function ($q) {
                $q->whereNull('short_description')
                  ->orWhere('short_description', '')
                  ->orWhereNull('description')
                  ->orWhere('description', '')
                  ->orWhereRaw('LENGTH(description) < 200')
                  ->orWhere('description', 'like', '%data-start%')
                  ->orWhereNotExists(fn($sub) => $sub
                      ->from('woo_product_attributes')
                      ->whereColumn('woo_product_id', 'woo_products.id'));
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->get();
        $total    = $products->count();

        if ($total === 0) {
            $this->info('No products need evaluation.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} products to evaluate.");

        $processed = 0;
        $improved  = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($products->chunk($batchSize) as $batch) {
            $batch = $batch->values();
            $count = $batch->count();
            $from  = $processed + 1;
            $to    = $processed + $count;

            $this->info("[{$from}–{$to} / {$total}] Evaluating...");

            try {
                // Opțional: îmbogățire specs din DataForSEO înainte de Claude
                $specsMap = $this->useDataForSeo
                    ? $this->fetchSpecsBatch($batch->toArray())
                    : [];

                $results = $this->evaluateBatch($batch->toArray(), $categoryMap, $specsMap);

                foreach ($results as $productId => $fields) {
                    $updates  = [];
                    $product  = $batch->firstWhere('id', $productId);
                    $name     = $product->name ?? '';
                    $sku      = $product->sku  ?? '';
                    $category = $categoryMap[$productId] ?? '';

                    if (! empty($fields['name'])) {
                        $updates['name'] = $fields['name'];
                    }
                    if (! empty($fields['short'])) {
                        $updates['short_description'] = $fields['short'];
                    }
                    if (! empty($fields['full'])) {
                        $updates['description'] = $fields['full'];
                    }

                    // Salvare atribute
                    $attrsSaved = 0;
                    if (! empty($fields['attributes']) && is_array($fields['attributes'])) {
                        $attrs = $this->sanitizeAttributes(
                            $fields['attributes'],
                            $updates['name'] ?? $name,
                            $category
                        );
                        $attrsSaved = $this->saveAttributes($productId, $attrs);
                    }

                    if (! empty($updates)) {
                        $updates['updated_at'] = now();
                        DB::table('woo_products')->where('id', $productId)->update($updates);
                    }

                    $changedFields = array_keys(array_diff_key($updates, ['updated_at' => 1]));
                    if ($attrsSaved > 0) {
                        $changedFields[] = "{$attrsSaved} atribute";
                    }

                    if (! empty($changedFields)) {
                        $this->line("  ✓ #{$productId} [{$sku}] {$name} — " . implode(', ', $changedFields));
                        $improved++;
                    } else {
                        $this->line("  = #{$productId} [{$sku}] {$name} — no changes");
                        $skipped++;
                    }

                    $processed++;
                }

                foreach ($batch as $product) {
                    if (! isset($results[$product->id])) {
                        $this->warn("  #{$product->id} [{$product->sku}] — missing in response");
                        $processed++;
                        $failed++;
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("  Batch failed: " . $e->getMessage());
                Log::warning('EvaluateWooProductContent batch failed: ' . $e->getMessage());

                foreach ($batch as $product) {
                    $processed++;
                    $failed++;
                }

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                    $this->warn('  Rate limited — sleeping 30s...');
                    sleep(30);
                }
            }

            usleep(300_000);
        }

        $this->newLine();
        $this->info("Done. Total: {$total} | Improved: {$improved} | Already OK: {$skipped} | Failed: {$failed}");

        return self::SUCCESS;
    }

    // ── DataForSEO enrichment ─────────────────────────────────────────────────

    /**
     * Fetches organic search snippets for each product in the batch.
     * Returns map: productId → specs string
     *
     * @param  object[]          $products
     * @return array<int, string>
     */
    private function fetchSpecsBatch(array $products): array
    {
        $login    = env('DATAFORSEO_LOGIN', '');
        $password = env('DATAFORSEO_PASSWORD', '');

        if (empty($login) || empty($password)) {
            return [];
        }

        // Trimite toate căutările într-un singur request batch
        $tasks = [];
        foreach ($products as $p) {
            $tasks[] = [
                'keyword'       => $this->buildSearchQuery($p->name) . ' specificatii tehnice',
                'language_code' => 'ro',
                'location_code' => 2642,
                'depth'         => 5,
            ];
        }

        $ch = curl_init('https://api.dataforseo.com/v3/serp/google/organic/live/advanced');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($tasks),
            CURLOPT_USERPWD        => "{$login}:{$password}",
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            return [];
        }

        $data   = json_decode($body, true);
        $result = [];

        foreach ($products as $idx => $product) {
            $items    = $data['tasks'][$idx]['result'][0]['items'] ?? [];
            $snippets = [];

            foreach (array_slice($items, 0, 4) as $item) {
                if (($item['type'] ?? '') === 'organic' && ! empty($item['description'])) {
                    $snippets[] = strip_tags($item['description']);
                }
            }

            if (! empty($snippets)) {
                $result[$product->id] = implode(' | ', $snippets);
            }
        }

        return $result;
    }

    private function buildSearchQuery(string $name): string
    {
        $name = preg_replace('/\b\d+[\dxX*.,\/″""]+(?:mm|cm|m|kg|w|v|a|l|ml|g)?\b/i', '', $name);
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    // ── Claude batch ──────────────────────────────────────────────────────────

    /**
     * @param  object[]           $products
     * @param  array<int, string> $categoryMap
     * @param  array<int, string> $specsMap     productId → specs from web
     * @return array<int, array{name?:string, short?:string, full?:string, attributes?:array}>
     */
    private function evaluateBatch(array $products, array $categoryMap, array $specsMap): array
    {
        $existingAttrs = $this->loadExistingAttributes(array_column($products, 'id'));
        $prompt = $this->buildPrompt($products, $categoryMap, $specsMap, $existingAttrs);

        $message = $this->claude->messages->create(
            maxTokens: 8000,
            messages:  [['role' => 'user', 'content' => $prompt]],
            model:     $this->model,
        );

        $text = '';
        foreach ($message->content as $block) {
            if (isset($block->text)) $text .= $block->text;
        }

        return $this->parseResponse($text, $products);
    }

    private function buildPrompt(array $products, array $categoryMap, array $specsMap, array $existingAttrs = []): string
    {
        $glossary   = config('product_glossary.prompt_context', '');
        $knownAttrs = implode(', ', self::KNOWN_ATTRIBUTES);

        $lines = '';
        foreach ($products as $p) {
            $category = $categoryMap[$p->id] ?? null;
            $desc     = strip_tags($p->description ?? '');
            $desc     = preg_replace('/\s+data-[a-z-]+="\d+"/', '', $desc);
            $desc     = mb_substr(trim(preg_replace('/\s+/', ' ', $desc)), 0, 400);
            $specs    = $specsMap[$p->id] ?? null;

            $lines .= "ID: {$p->id}\n";
            $lines .= "SKU: {$p->sku}\n";
            $lines .= "Denumire: {$p->name}\n";
            if ($category)  $lines .= "Categorie: {$category}\n";
            if ($p->brand)  $lines .= "Brand: {$p->brand}\n";
            if ($p->unit)   $lines .= "Unitate: {$p->unit}\n";
            $lines .= "Short description curentă: " . (empty(trim($p->short_description ?? '')) ? '(lipsă)' : mb_substr(trim(strip_tags($p->short_description)), 0, 200)) . "\n";
            $lines .= "Description curentă: " . (empty($desc) ? '(lipsă)' : $desc) . "\n";
            if ($specs) $lines .= "Specificații găsite pe web: {$specs}\n";
            $existing = $existingAttrs[$p->id] ?? [];
            if (! empty($existing)) {
                $attrStr = implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($existing), $existing));
                $lines .= "Atribute existente (NU le duplica): {$attrStr}\n";
            }
            $lines .= "---\n";
        }

        return <<<PROMPT
Ești un specialist în copywriting și catalogare pentru un magazin online de materiale de construcții și bricolaj din România.

{$glossary}

Mai jos sunt produse WooCommerce cu conținutul lor actual. Evaluează și îmbunătățește fiecare câmp.

{$lines}

## Atribute cunoscute pe site (folosește exact aceste nume):
{$knownAttrs}

## Reguli conținut

**name**: Returnează valoare NUMAI dacă denumirea are prescurtări neclare (Fi, Pl, Int, Ext) sau greșeli evidente. Altfel null.

**short** (1-2 propoziții, max 180 caractere, text simplu):
- Returnează ÎNTOTDEAUNA dacă lipsește sau e slabă
- "Ce este și pentru ce se folosește?"

**full** (HTML, 200-350 cuvinte): Returnează dacă lipsește, e scurtă, conține data-start/data-end, sau e slabă.
- Structură: paragraf intro → `<ul>` 4-6 caracteristici → paragraf aplicații
- Tag-uri: `<p>`, `<ul>`, `<li>`, `<strong>`
- Folosește specificațiile de pe web dacă sunt disponibile

## Reguli atribute

Extrage atribute tehnice bazându-te pe denumire, categorie și specificațiile de pe web.
1. **Brand** — dacă apare în denumire sau specificații
2. **Dimensiuni** — valori numerice exacte cu unitate corectă
3. **Material** — doar dacă este explicit sau deductibil sigur
4. **Dulie** — cod standard: E14, E27, GU10 etc.
5. **Temperatura culoare (K)** — 3000K→"3000 (Cald)", 4000K→"4000 (Neutru)", 6500K→"6500 (Rece)"
6. **Culoare** — doar culori reale (Negru, Alb, Gri etc.), NU cuvinte ca "Standard", "Classic"
7. **Siguranțe/disjunctoare** — valoarea numerică (10,16,20A) = `Curent nominal (A)`, litera (B,C,D) = `Curba de declansare`
8. **Tablouri** — nr. module = `Numar module`, ingropat/aparent = `Tip montaj`
9. **Lichide** (vopsele, adezivi) — cantitatea în litri = `Volum (L)`, NU `Greutate (kg)`
10. Max 6-8 atribute per produs. Valori string simple, fără speculații.
11. Returnează `{}` (obiect gol) dacă nu poți extrage atribute sigure.

## Format răspuns

Răspunde EXCLUSIV cu JSON valid, fără text înainte sau după.
Folosește null pentru câmpurile care nu necesită modificare.

{
  "PRODUCT_ID": {
    "name": null,
    "short": "...",
    "full": "...",
    "attributes": {
      "Brand": "Knauf",
      "Material": "Gips-carton"
    }
  }
}
PROMPT;
    }

    /**
     * @param  object[]  $products
     * @return array<int, array{name?:string, short?:string, full?:string, attributes?:array}>
     */
    private function parseResponse(string $text, array $products): array
    {
        $text     = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $text);
        $validIds = array_map(fn ($p) => $p->id, $products);
        $result   = [];

        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $data = json_decode($m[0], true);

            if (is_array($data)) {
                foreach ($data as $idStr => $fields) {
                    $id = (int) $idStr;
                    if (! in_array($id, $validIds, true)) continue;

                    $entry = [];
                    if (! empty($fields['name']))        $entry['name']       = trim($fields['name']);
                    if (! empty($fields['short']))        $entry['short']      = trim($fields['short']);
                    if (! empty($fields['full']))         $entry['full']       = trim($fields['full']);
                    if (isset($fields['attributes']) && is_array($fields['attributes'])) {
                        $entry['attributes'] = $fields['attributes'];
                    }
                    $result[$id] = $entry;
                }

                if (! empty($result)) return $result;
            }
        }

        // Fallback per-produs
        foreach ($validIds as $id) {
            $pattern = '/"?' . $id . '"?\s*:\s*\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s';
            if (preg_match($pattern, $text, $m)) {
                $block = json_decode('{' . $m[1] . '}', true);
                if (is_array($block)) {
                    $entry = [];
                    if (! empty($block['name']))  $entry['name']  = trim($block['name']);
                    if (! empty($block['short'])) $entry['short'] = trim($block['short']);
                    if (! empty($block['full']))  $entry['full']  = trim($block['full']);
                    if (isset($block['attributes']) && is_array($block['attributes'])) {
                        $entry['attributes'] = $block['attributes'];
                    }
                    $result[$id] = $entry;
                }
            }
        }

        return $result;
    }

    // ── Attributes ────────────────────────────────────────────────────────────

    private function saveAttributes(int $productId, array $attrs): int
    {
        if (empty($attrs)) return 0;

        // Atributele existente din WooCommerce — nu le suprascriem
        $existingNames = DB::table('woo_product_attributes')
            ->where('woo_product_id', $productId)
            ->pluck('name')
            ->map(fn($n) => mb_strtolower($n, 'UTF-8'))
            ->toArray();

        // Filtrăm atributele generate care ar duplica cele existente
        $attrs = array_filter($attrs, fn($name) =>
            ! in_array(mb_strtolower($name, 'UTF-8'), $existingNames, true),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($attrs)) return 0;

        // Șterge doar atributele generate anterior (nu cele din WooCommerce)
        DB::table('woo_product_attributes')
            ->where('woo_product_id', $productId)
            ->where('source', 'generated')
            ->delete();

        $now      = now();
        $position = 0;
        $rows     = [];

        foreach ($attrs as $name => $value) {
            if (empty($name) || $value === '' || $value === null) continue;
            $rows[] = [
                'woo_product_id' => $productId,
                'name'           => $name,
                'value'          => (string) $value,
                'position'       => $position++,
                'is_visible'     => true,
                'source'         => 'generated',
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        if (! empty($rows)) {
            DB::table('woo_product_attributes')->insert($rows);
        }

        return count($rows);
    }

    private function sanitizeAttributes(array $attrs, string $productName, string $category): array
    {
        $categoryLower = mb_strtolower($category, 'UTF-8');
        $nameLower     = mb_strtolower($productName, 'UTF-8');
        $result        = [];

        foreach ($attrs as $name => $value) {
            $v = trim((string) $value);
            if ($v === '') continue;

            if ($name === 'Putere (W)' && $this->isElectricProtection($categoryLower, $nameLower)) {
                $name = 'Curent nominal (A)';
            }
            if ($name === 'Tensiune (V)' && preg_match('/^\d+(\.\d+)?A$/i', $v)) {
                $name = 'Curent maxim (A)';
                $v    = rtrim($v, 'Aa');
            }
            if ($name === 'Dulie') {
                $lower = mb_strtolower($v, 'UTF-8');
                if (in_array($lower, ['mica','mică','mic','small','e14'], true)) $v = 'E14';
                elseif (in_array($lower, ['mare','large','e27'], true))          $v = 'E27';
            }
            if ($name === 'Culoare') {
                if (in_array(mb_strtolower($v, 'UTF-8'), self::NON_COLORS, true)) continue;
            }
            if ($name === 'Greutate (kg)' && $this->isLiquidProduct($nameLower)) {
                if (preg_match('/\d[\.,]\d+\s*l\b|\d+\s*l\b/i', $productName)) {
                    $name = 'Volum (L)';
                }
            }
            if ($name === 'Lungime (mm)' && is_numeric($v) && (float) $v > 10000) {
                $name = 'Lungime (m)';
                $v    = (string) round((float) $v / 1000, 1);
            }

            $result[$name] = $v;
        }

        return $result;
    }

    private function isElectricProtection(string $cat, string $name): bool
    {
        foreach (self::ELECTRIC_PROTECTION_CATS as $kw) {
            if (str_contains($cat, $kw)) return true;
        }
        return str_contains($name, 'siguranț') || str_contains($name, 'disjunctor');
    }

    private function isLiquidProduct(string $name): bool
    {
        foreach (['vopsea','email','grund','silicon','adeziv','lac','diluant','degresant','spuma','mortar','amorsa'] as $kw) {
            if (str_contains($name, $kw)) return true;
        }
        return false;
    }

    // ── WooCommerce native attributes import ──────────────────────────────────

    /**
     * Importă atributele din coloana `data` JSON în woo_product_attributes
     * cu source='woocommerce', doar pentru produsele care nu au deja atribute native.
     */
    private function importWooNativeAttributes(): int
    {
        $products = DB::table('woo_products')
            ->where('is_placeholder', false)
            ->whereRaw("JSON_LENGTH(JSON_EXTRACT(data, '$.attributes')) > 0")
            ->whereNotExists(fn($q) => $q
                ->from('woo_product_attributes')
                ->whereColumn('woo_product_id', 'woo_products.id')
                ->where('source', 'woocommerce'))
            ->get(['id', 'data']);

        $imported = 0;
        $now      = now();

        foreach ($products as $product) {
            $decoded    = json_decode($product->data, true);
            $attributes = $decoded['attributes'] ?? [];
            $position   = 0;
            $rows       = [];

            foreach ($attributes as $attr) {
                $name    = trim($attr['name'] ?? '');
                $options = $attr['options'] ?? [];
                $value   = is_array($options) ? implode(', ', $options) : (string) $options;

                if (empty($name) || empty($value)) continue;

                $rows[] = [
                    'woo_product_id' => $product->id,
                    'name'           => $name,
                    'value'          => $value,
                    'position'       => $position++,
                    'is_visible'     => (bool) ($attr['visible'] ?? true),
                    'source'         => 'woocommerce',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            if (! empty($rows)) {
                DB::table('woo_product_attributes')->insert($rows);
                $imported += count($rows);
            }
        }

        return $imported;
    }

    /**
     * Returnează atributele existente pentru un set de produse.
     * Folosit pentru a transmite contextul la Claude.
     *
     * @param  int[]  $productIds
     * @return array<int, array<string, string>>
     */
    private function loadExistingAttributes(array $productIds): array
    {
        $rows = DB::table('woo_product_attributes')
            ->whereIn('woo_product_id', $productIds)
            ->get(['woo_product_id', 'name', 'value']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->woo_product_id][$row->name] = $row->value;
        }

        return $map;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<int, string> */
    private function buildCategoryMap(): array
    {
        $rows = DB::table('woo_product_category as pc')
            ->join('woo_categories as wc', 'wc.id', '=', 'pc.woo_category_id')
            ->leftJoin('woo_categories as parent', 'parent.id', '=', 'wc.parent_id')
            ->leftJoin('woo_categories as gp', 'gp.id', '=', 'parent.parent_id')
            ->select('pc.woo_product_id', 'wc.name as cat', 'parent.name as parent', 'gp.name as gp')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $parts = array_filter([$row->gp, $row->parent, $row->cat]);
            $map[(int) $row->woo_product_id] = implode(' > ', $parts);
        }

        return $map;
    }
}
