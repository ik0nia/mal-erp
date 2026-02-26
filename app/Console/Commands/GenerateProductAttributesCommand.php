<?php

namespace App\Console\Commands;

use Anthropic\Client as AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generează atribute tehnice structurate pentru produsele WinMentor placeholder
 * folosind Claude, bazate pe denumirea normalizată + categoria produsului.
 *
 * Atributele sunt stocate în tabela woo_product_attributes și sunt consistente
 * cu atributele existente pe produsele reale WooCommerce (Brand, Material etc.)
 * pentru a putea fi sincronizate ulterior în WooCommerce.
 */
class GenerateProductAttributesCommand extends Command
{
    protected $signature = 'products:generate-attributes
                            {--limit=      : Max produse de procesat}
                            {--batch-size=10 : Produse per apel Claude}
                            {--regenerate  : Re-generează și produsele care au deja atribute}';

    protected $description = 'Generează atribute tehnice structurate pentru produsele WinMentor folosind Claude';

    private AnthropicClient $claude;
    private string $model;

    /** Atributele globale existente pe site — pentru consistență */
    public const KNOWN_ATTRIBUTES = [
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

    /** Culori valide — exclude termeni care nu sunt culori reale */
    private const NON_COLORS = [
        'standard', 'rentabil', 'economic', 'clasic', 'premium', 'professional',
        'profi', 'basic', 'plus', 'extra', 'super', 'mega', 'mini', 'maxi',
    ];

    /** Categorii de siguranțe/tablouri electrice */
    private const ELECTRIC_PROTECTION_CATS = [
        'tablouri', 'siguranțe', 'intreruptoare automate', 'disjunctoare',
    ];

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY nu este setat în .env');
            return self::FAILURE;
        }

        $this->claude    = new AnthropicClient(apiKey: $apiKey);
        $this->model     = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
        $limit           = $this->option('limit') ? (int) $this->option('limit') : null;
        $batchSize       = max(1, min(20, (int) $this->option('batch-size')));
        $regenerate      = (bool) $this->option('regenerate');

        $this->info("Generare atribute — model: {$this->model}, batch: {$batchSize}");

        // Construim harta categorie pentru toate produsele placeholder
        $categoryMap = $this->buildCategoryMap();
        $this->info('Categorii încărcate: ' . count($categoryMap) . ' produse.');

        // Query produse
        $query = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->select('id', 'name', 'brand', 'unit', 'weight');

        if (! $regenerate) {
            $withAttrs = DB::table('woo_product_attributes')
                ->distinct()
                ->pluck('woo_product_id');
            $query->whereNotIn('id', $withAttrs);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->get();
        $total    = $products->count();

        if ($total === 0) {
            $this->info('Niciun produs de procesat.');
            return self::SUCCESS;
        }

        $this->info("Produse de procesat: {$total}");

        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        foreach ($products->chunk($batchSize) as $batch) {
            $batch = $batch->values();
            $from  = $processed + 1;
            $to    = $processed + $batch->count();

            $this->info("[{$from}–{$to} / {$total}] Generare atribute...");

            try {
                $results = $this->generateBatch($batch->toArray(), $categoryMap);

                foreach ($results as $productId => $attrs) {
                    if (empty($attrs)) {
                        $processed++;
                        $failed++;
                        continue;
                    }

                    // Sanitizare automată post-generare
                    $product = $batch->firstWhere('id', $productId);
                    $category = $categoryMap[$productId] ?? '';
                    $attrs = $this->sanitizeAttributes($attrs, $product?->name ?? '', $category);

                    // Șterge atributele vechi dacă regenerăm
                    if ($regenerate) {
                        DB::table('woo_product_attributes')
                            ->where('woo_product_id', $productId)
                            ->where('source', 'generated')
                            ->delete();
                    }

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

                    $name = $batch->firstWhere('id', $productId)->name ?? '';
                    $this->line("  #{$productId} {$name} — " . count($rows) . ' atribute');
                    $processed++;
                    $succeeded++;
                }

                // Produse lipsă din răspuns
                foreach ($batch as $product) {
                    if (! isset($results[$product->id])) {
                        $processed++;
                        $failed++;
                        $this->warn("  #{$product->id} — lipsește din răspuns");
                    }
                }
            } catch (\Throwable $e) {
                $this->warn('  Batch eșuat: ' . $e->getMessage());
                Log::warning('GenerateProductAttributes batch failed: ' . $e->getMessage());

                foreach ($batch as $product) {
                    $processed++;
                    $failed++;
                }

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                    $this->warn('  Rate limited — aștept 30s...');
                    sleep(30);
                }
            }

            usleep(200_000); // 0.2s între batch-uri
        }

        $this->newLine();
        $this->info("Gata. Total: {$total} | OK: {$succeeded} | Eșuate: {$failed}");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    /**
     * @param  object[]            $products
     * @param  array<int, string>  $categoryMap
     * @return array<int, array<string, string>>
     */
    private function generateBatch(array $products, array $categoryMap): array
    {
        $prompt  = $this->buildPrompt($products, $categoryMap);
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

    private function buildPrompt(array $products, array $categoryMap): string
    {
        $glossary    = config('product_glossary.prompt_context', '');
        $knownAttrs  = implode(', ', self::KNOWN_ATTRIBUTES);

        $lines = '';
        foreach ($products as $p) {
            $category = $categoryMap[$p->id] ?? null;
            $meta     = array_filter([
                $category  ? "Categorie: {$category}"    : null,
                $p->brand  ? "Brand: {$p->brand}"        : null,
                $p->unit   ? "Unitate: {$p->unit}"       : null,
                $p->weight ? "Greutate: {$p->weight} kg" : null,
            ]);
            $metaStr = $meta ? ' [' . implode(', ', $meta) . ']' : '';
            $lines  .= "  {$p->id}: {$p->name}{$metaStr}\n";
        }

        return <<<PROMPT
Ești un specialist în catalogarea produselor pentru un magazin online de materiale de construcții și bricolaj din România.

{$glossary}

## Atribute existente pe site (folosește exact aceste nume când se potrivesc):
{$knownAttrs}

## Sarcina ta

Extrage atribute tehnice structurate pentru fiecare produs de mai jos.
Bazează-te STRICT pe informațiile din denumire și categorie — nu inventa valori.

{$lines}

## Reguli

1. **Brand** — extrage întotdeauna dacă apare în denumire (KNAUF, BISON, V-TAC, ADEPLAST, MOELLER etc.)
2. **Dimensiuni** — extrage valori numerice cu unitatea corectă (ex: Putere (W) → "4.5", Diametru (mm) → "125")
3. **Material** — doar dacă este explicit sau deductibil sigur (Cupru, PPR, PVC, Pexal, INOX, Aluminiu etc.)
4. **Dulie** — folosește ÎNTOTDEAUNA codul standard: "dulie mică"/"dulie E14" → E14, "dulie mare"/"dulie E27" → E27, GU10, GU5.3 etc.
5. **Temperatura culoare (K)** — pentru LED: 3000K → "3000 (Cald)", 4000K → "4000 (Neutru)", 6500K → "6500 (Rece)"
6. **Tip filet** — Interior, Exterior, Interior-Exterior
7. **Culoare** — DOAR dacă este o culoare reală (Negru, Alb, Gri, Roșu etc.). NU pune cuvinte ca "Rentabil", "Standard", "Clasic" — acestea NU sunt culori.
8. **Siguranțe automate / disjunctoare** — valoarea numerică din denumire (10, 16, 20, 25, 32...) este `Curent nominal (A)`, NU `Putere (W)`. Litera din denumire (B, C, D) este `Curba de declansare`. Folosește OBLIGATORIU `Curent nominal (A)` pentru aceste produse.
9. **Tablouri electrice** — numărul de module/posturi → `Numar module`. Tipul montajului (ingropat/aparent) → `Tip montaj`.
10. **Produse lichide** (vopsele, emailuri, grunduri, siliconi, adezivi lichizi) — cantitatea în litri este `Volum (L)`, NU `Greutate (kg)`.
11. **Lungimi mari** — dacă lungimea depășește 10m, folosește `Lungime (m)` în loc de `Lungime (mm)`.
12. **Tensiune vs Curent** — `Tensiune (V)` este în volți (ex: "230"). `Curent maxim (A)` sau `Curent nominal (A)` este în amperi. NU pune valori de amperi la Tensiune.
13. Maxim 6-8 atribute per produs. Nu adăuga atribute goale sau speculative.
14. Valoarea este întotdeauna un string simplu (nu array, nu obiect).

## Format răspuns

Răspunde EXCLUSIV cu JSON valid, fără text înainte sau după:
{
  "PRODUCT_ID": {
    "Brand": "Knauf",
    "Material": "Gips-carton",
    "Lățime (mm)": "48"
  }
}
PROMPT;
    }

    /**
     * @param  object[]  $products
     * @return array<int, array<string, string>>
     */
    private function parseResponse(string $text, array $products): array
    {
        // Elimină code fences
        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $text);

        $validIds = array_map(fn ($p) => $p->id, $products);
        $result   = [];

        // Strategy 1: JSON complet
        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $data = json_decode($m[0], true);

            if (is_array($data)) {
                foreach ($data as $idStr => $attrs) {
                    $id = (int) $idStr;
                    if (! in_array($id, $validIds, true)) continue;
                    if (is_array($attrs) && ! empty($attrs)) {
                        $result[$id] = $attrs;
                    }
                }

                if (! empty($result)) return $result;
            }
        }

        // Strategy 2: extragere per produs
        foreach ($validIds as $id) {
            $pattern = '/"?' . $id . '"?\s*:\s*\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s';
            if (preg_match($pattern, $text, $m)) {
                $block = json_decode('{' . $m[1] . '}', true);
                if (is_array($block) && ! empty($block)) {
                    $result[$id] = $block;
                }
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Sanitizare post-generare
    // -------------------------------------------------------------------------

    /**
     * Corectează automat greșeli comune de atribute.
     *
     * @param  array<string, string>  $attrs
     * @return array<string, string>
     */
    private function sanitizeAttributes(array $attrs, string $productName, string $category): array
    {
        $categoryLower = mb_strtolower($category, 'UTF-8');
        $nameLower     = mb_strtolower($productName, 'UTF-8');
        $result        = [];

        foreach ($attrs as $name => $value) {
            $valueTrimmed = trim((string) $value);
            if ($valueTrimmed === '') continue;

            // 1. Siguranțe: Putere (W) → Curent nominal (A)
            if ($name === 'Putere (W)' && $this->isElectricProtectionProduct($categoryLower, $nameLower)) {
                $name = 'Curent nominal (A)';
            }

            // 2. Tensiune (V) cu valoare în amperi (conține 'A' sau este <50)
            if ($name === 'Tensiune (V)' && preg_match('/^\d+(\.\d+)?A$/i', $valueTrimmed)) {
                $name = 'Curent maxim (A)';
                $valueTrimmed = rtrim($valueTrimmed, 'Aa');
            }

            // 3. Dulie: "Mica"/"Mică" → E14, "Mare" → E27
            if ($name === 'Dulie') {
                $lower = mb_strtolower($valueTrimmed, 'UTF-8');
                if (in_array($lower, ['mica', 'mică', 'mic', 'small', 'e14'], true)) {
                    $valueTrimmed = 'E14';
                } elseif (in_array($lower, ['mare', 'mare', 'large', 'e27'], true)) {
                    $valueTrimmed = 'E27';
                }
            }

            // 4. Culoare cu valori non-cromatice → elimină
            if ($name === 'Culoare') {
                $lowerVal = mb_strtolower($valueTrimmed, 'UTF-8');
                if (in_array($lowerVal, self::NON_COLORS, true)) {
                    continue; // skip
                }
            }

            // 5. Volum vs Greutate: produse lichide
            if ($name === 'Greutate (kg)' && $this->isLiquidProduct($nameLower)) {
                // dacă unitatea originală era litri (L în denumire), corectăm
                if (preg_match('/\d[\.,]\d+\s*l\b|\d+\s*l\b/i', $productName)) {
                    $name = 'Volum (L)';
                }
            }

            // 6. Lungime (mm) > 10000 → conversie în Lungime (m)
            if ($name === 'Lungime (mm)' && is_numeric($valueTrimmed) && (float) $valueTrimmed > 10000) {
                $name         = 'Lungime (m)';
                $valueTrimmed = (string) round((float) $valueTrimmed / 1000, 1);
            }

            // 7. Utilizare pentru module tablou → Numar module
            if ($name === 'Utilizare' && preg_match('/^(\d+)\s*modul/i', $valueTrimmed, $m)) {
                $name         = 'Numar module';
                $valueTrimmed = $m[1];
            }

            $result[$name] = $valueTrimmed;
        }

        return $result;
    }

    private function isElectricProtectionProduct(string $categoryLower, string $nameLower): bool
    {
        foreach (self::ELECTRIC_PROTECTION_CATS as $keyword) {
            if (str_contains($categoryLower, $keyword)) return true;
        }
        return str_contains($nameLower, 'siguranț') || str_contains($nameLower, 'disjunctor')
            || preg_match('/\bsig\b|\bsig\.\b/i', $nameLower);
    }

    private function isLiquidProduct(string $nameLower): bool
    {
        $liquidKeywords = ['vopsea', 'email', 'grund', 'silicon', 'adeziv', 'lac', 'diluant',
            'degresant', 'spuma', 'mortar', 'amorsa', 'impregnant'];
        foreach ($liquidKeywords as $kw) {
            if (str_contains($nameLower, $kw)) return true;
        }
        return false;
    }

    /** @return array<int, string> productId → full category path */
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
