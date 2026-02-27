<?php

namespace App\Console\Commands;

use Anthropic\Client as AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Setează weight, dimensions și shipping_class pentru toate produsele.
 *
 * Prioritate:
 *  1. Valori deja existente în data JSON (skip dacă --force nu e setat)
 *  2. Extrage din atributele existente (Greutate, Lungime etc.)
 *  3. Extrage din numele produsului (regex Xkg, Xcm etc.)
 *  4. Claude estimează din denumire + categorie (batch, fără web search)
 *
 * volum-atipic = greutate > 10kg SAU orice dimensiune > 100cm
 */
class SetProductDimensionsCommand extends Command
{
    protected $signature = 'products:set-dimensions
                            {--limit=         : Max produse de procesat}
                            {--force          : Suprascrie și produsele care au deja dimensiuni}
                            {--worker=1       : Worker index (1-based)}
                            {--workers=1      : Total number of parallel workers}
                            {--batch-size=10  : Produse per apel Claude}
                            {--web-search     : Folosește web search pentru produse fără dimensiuni (batch-size max 5)}
                            {--dry-run        : Afișează fără să salveze}';

    protected $description = 'Setează greutate, dimensiuni și shipping_class (volum-atipic) pentru toate produsele';

    private AnthropicClient $claude;
    private string $model = 'claude-haiku-4-5-20251001';

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY nu este setat în .env');
            return self::FAILURE;
        }

        $this->claude  = new AnthropicClient(apiKey: $apiKey);
        $limit         = $this->option('limit')     ? (int) $this->option('limit') : null;
        $force         = (bool) $this->option('force');
        $worker        = max(1, (int) $this->option('worker'));
        $workers       = max(1, (int) $this->option('workers'));
        $webSearch     = (bool) $this->option('web-search');
        $batchSize     = $webSearch ? max(1, min(5, (int) $this->option('batch-size'))) : max(1, min(20, (int) $this->option('batch-size')));
        $dryRun        = (bool) $this->option('dry-run');

        $this->info("Set dimensions — worker: {$worker}/{$workers}" . ($webSearch ? ' [WEB SEARCH]' : '') . ($dryRun ? ' [DRY RUN]' : ''));

        $query = DB::table('woo_products')
            ->select('id', 'name', 'sku', 'data')
            ->orderBy('id');

        if ($workers > 1) {
            $query->whereRaw('(id % ?) = ?', [$workers, $worker - 1]);
        }

        if (!$force) {
            // Procesează produse care nu au weight SAU nu au length (ambele sunt obligatorii)
            $query->where(function ($q) {
                $q->whereRaw("(JSON_UNQUOTE(JSON_EXTRACT(data,'$.weight')) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(data,'$.weight')) IN ('', 'null'))")
                  ->orWhereRaw("(JSON_UNQUOTE(JSON_EXTRACT(data,'$.dimensions.length')) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(data,'$.dimensions.length')) IN ('', 'null'))");
            });
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

        // Preload atribute existente pentru toate produsele
        $productIds  = $products->pluck('id')->toArray();
        $attrsMap    = $this->loadAttributes($productIds);
        $categoryMap = $this->buildCategoryMap($productIds);

        $processed  = 0;
        $fromName   = 0;
        $fromAttrs  = 0;
        $fromClaude = 0;
        $bulky      = 0;

        foreach ($products->chunk($batchSize) as $batch) {
            $batch = $batch->values();

            // Pas 1: extrage dimensiuni din atribute și din denumire
            // Produsul e "complet" doar dacă are greutate ȘI lungime — altfel merge la Claude
            $needsClaude = [];

            foreach ($batch as $product) {
                $partial = $this->extractFromAttrsAndName($product, $attrsMap[$product->id] ?? []);

                if ($partial !== null && $partial['weight'] !== null && $partial['length'] !== null) {
                    // Greutate + lungime rezolvate fără Claude
                    if ($partial['source'] === 'attrs') $fromAttrs++;
                    else $fromName++;
                    if (!$dryRun) $this->saveResult($product, $partial);
                    if ($this->isBulky($partial)) $bulky++;
                    $this->logResult($product, $partial, $processed + 1, $total);
                } else {
                    // Greutate sau lungime lipsă → Claude (cu ce s-a extras deja)
                    $needsClaude[] = ['product' => $product, 'partial' => $partial];
                }
                $processed++;
            }

            // Pas 2: Claude estimează greutatea și dimensiunile lipsă
            if (!empty($needsClaude)) {
                try {
                    $claudeProducts = array_column($needsClaude, 'product');
                    $claudeResults  = $webSearch
                        ? $this->askClaudeWithWebSearch($claudeProducts, $categoryMap, $needsClaude)
                        : $this->askClaude($claudeProducts, $categoryMap);

                    foreach ($needsClaude as $item) {
                        $product = $item['product'];
                        $partial = $item['partial'];
                        $result  = $claudeResults[$product->id] ?? null;

                        if ($result === null) {
                            // Claude n-a returnat nimic → fallback 0.2kg
                            $result = ['weight' => 0.2, 'length' => null, 'width' => null, 'height' => null, 'source' => 'claude'];
                        }

                        // Îmbinăm cu dimensiunile extrase anterior (dacă există)
                        if ($partial) {
                            if ($partial['length'] !== null && $result['length'] === null) $result['length'] = $partial['length'];
                            if ($partial['width']  !== null && $result['width']  === null) $result['width']  = $partial['width'];
                            if ($partial['height'] !== null && $result['height'] === null) $result['height'] = $partial['height'];
                        }

                        // Greutate minimă garantată: 0.2kg
                        if (($result['weight'] ?? 0) < 0.2) {
                            $result['weight'] = 0.2;
                        }

                        $fromClaude++;
                        if (!$dryRun) $this->saveResult($product, $result);
                        if ($this->isBulky($result)) $bulky++;
                        $this->logResult($product, $result, '?', $total);
                    }
                } catch (\Throwable $e) {
                    $this->warn('Claude error: ' . $e->getMessage());
                    Log::warning('SetProductDimensions Claude error: ' . $e->getMessage());
                    if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                        sleep(30);
                    }
                }
            }

            usleep(300_000);
        }

        $this->newLine();
        $this->info("Gata. Total: {$total} | Din atribute: {$fromAttrs} | Din nume: {$fromName} | Claude: {$fromClaude} | Volum-atipic: {$bulky}");
        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    /**
     * Parsează greutatea dintr-o valoare de atribut, cu detecție automată kg/g.
     * Returnează greutatea în kg sau null dacă nu poate fi parseată.
     */
    private function parseWeightFromAttr(string $value): ?float
    {
        $v = trim($value);

        // Valori non-numerice → skip
        if (!preg_match('/\d/', $v)) return null;

        // Valori per-suprafață (gr/mp, kg/mp) → nu e greutate produs → skip
        if (preg_match('/\//i', $v)) return null;

        $num = (float) str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $v));
        if ($num <= 0) return null;

        // Detectăm dacă valoarea e în grame: "300 g", "530g", "75 g", "134 g" etc.
        // dar NU kg, gram (se poate confunda cu kg)
        $isGrams = preg_match('/\d\s*(g|gr|gram)\b(?!.*kg)/i', $v) && !preg_match('/kg/i', $v);

        return $isGrams ? max(0.001, round($num / 1000, 4)) : $num;
    }

    /**
     * Încearcă să extragă weight + dimensions din atributele existente și din denumire.
     * Returnează array cu weight, length, width, height sau null dacă nu a reușit.
     */
    private function extractFromAttrsAndName(object $product, array $attrs): ?array
    {
        $weight = null;
        $length = null;
        $width  = null;
        $height = null;
        $source = 'name';

        // ── Din atribute existente ──────────────────────────────────────────
        foreach ($attrs as $name => $value) {
            $n = mb_strtolower($name);
            $v = (float) str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $value));
            if ($v <= 0) continue;

            if (str_contains($n, 'greutate')) {
                $w = $this->parseWeightFromAttr($value);
                if ($w !== null) { $weight = $w; $source = 'attrs'; }
            } elseif ($n === 'lungime (mm)') {
                $length = round($v / 10, 1); $source = 'attrs'; // mm → cm
            } elseif ($n === 'lungime (cm)') {
                $length = $v; $source = 'attrs';
            } elseif ($n === 'lungime (m)') {
                $length = $v * 100; $source = 'attrs'; // m → cm
            } elseif ($n === 'lățime (mm)') {
                $width = round($v / 10, 1); $source = 'attrs';
            } elseif ($n === 'lățime (cm)') {
                $width = $v; $source = 'attrs';
            } elseif ($n === 'înălțime (mm)' || $n === 'adâncime (mm)') {
                $height = round($v / 10, 1); $source = 'attrs';
            } elseif ($n === 'înălțime (cm)' || $n === 'adâncime (cm)') {
                $height = $v; $source = 'attrs';
            }
        }

        // ── Din denumire ────────────────────────────────────────────────────
        $name = $product->name;

        // Greutate: "25kg", "25 kg", "25KG"
        if ($weight === null && preg_match('/(\d+(?:[.,]\d+)?)\s*kg\b/i', $name, $m)) {
            $weight = (float) str_replace(',', '.', $m[1]);
        }

        // Greutate în tone: "1.5t" sau "1,5T"
        if ($weight === null && preg_match('/(\d+(?:[.,]\d+)?)\s*t\b/i', $name, $m)) {
            $weight = (float) str_replace(',', '.', $m[1]) * 1000;
        }

        // Lungime explicită în cm: "90cm", "120 cm"
        if ($length === null && preg_match('/(\d+(?:[.,]\d+)?)\s*cm\b/i', $name, $m)) {
            $length = (float) str_replace(',', '.', $m[1]);
        }

        // Lungime în m: "1.5m", "2 m", "500ml" NU (ml = litri)
        if ($length === null && preg_match('/(\d+(?:[.,]\d+)?)\s*m\b(?!l)/i', $name, $m)) {
            $length = (float) str_replace(',', '.', $m[1]) * 100;
        }

        // Lungime în mm: "100mm"
        if ($length === null && preg_match('/(\d{3,4})\s*mm\b/i', $name, $m)) {
            $length = round((float) $m[1] / 10, 1);
        }

        // Format dimensiuni: "NxMxP" (ex: 4×6×500, 50x100mm, 3050×1220)
        if (preg_match('/(\d+(?:[.,]\d+)?)[×x\*](\d+(?:[.,]\d+)?)[×x\*](\d+(?:[.,]\d+)?)\s*(mm|cm|m)?/i', $name, $m)) {
            $unit  = strtolower($m[4] ?? '');
            $vals  = [(float)str_replace(',','.',$m[1]), (float)str_replace(',','.',$m[2]), (float)str_replace(',','.',$m[3])];
            rsort($vals);
            $mult = match($unit) { 'mm' => 0.1, 'm' => 100, default => 1 };
            if ($length === null) $length = round($vals[0] * $mult, 1);
            if ($width  === null) $width  = round($vals[1] * $mult, 1);
            if ($height === null) $height = round($vals[2] * $mult, 1);
        }

        // Dacă am cel puțin greutatea sau lungimea, returnăm
        if ($weight !== null || $length !== null) {
            return compact('weight', 'length', 'width', 'height', 'source');
        }

        return null; // Trebuie Claude
    }

    private function askClaude(array $products, array $categoryMap): array
    {
        $lines = '';
        foreach ($products as $p) {
            $cat = $categoryMap[$p->id] ?? '';
            $lines .= "ID:{$p->id} | Denumire: {$p->name}" . ($cat ? " | Categorie: {$cat}" : '') . "\n";
        }

        $prompt = <<<PROMPT
Ești un expert în produse de construcții, bricolaj și hardware.

Pentru fiecare produs de mai jos, estimează:
- **weight_kg**: greutatea în kg (număr zecimal) — OBLIGATORIU, minim 0.2, NICIODATĂ null
- **length_cm**: lungimea (cea mai mare dimensiune) în cm
- **width_cm**: lățimea în cm (null dacă nu știi)
- **height_cm**: înălțimea/grosimea în cm (null dacă nu știi)

Reguli:
1. Dacă denumirea conține explicit "Xkg" sau "X kg" → weight_kg = X
2. Dacă denumirea conține "Xcm", "Xm", "Xmm" → convertește în cm pentru length_cm
3. weight_kg este ÎNTOTDEAUNA obligatoriu — estimează realist pe baza tipului:
   - Accesoriu mic (șurub, conector, colier, niplu, dop): 0.02–0.2kg
   - Bec, priză, întrerupător, senzor: 0.1–0.3kg
   - Robinet, vană, fitinguri DN15-DN50: 0.2–1kg
   - Sculă mică (șurubelniță, cheie): 0.1–0.5kg
   - Sculă mare (polizor, bormaşină): 1–3kg
   - Sac ciment/adeziv 25kg: 25kg, 60×40×10cm
   - Profil metalic/PVC 3m: 1–5kg, 300cm
   - Placă gips-carton: 9kg/mp
   - Cablu electric (rola 100m): 5–15kg
4. Fii realist — nu supraestima și nu subestima. Minim absolut: 0.2kg.

{$lines}

Răspunde EXCLUSIV cu JSON valid (weight_kg mereu prezent și ≥ 0.2):
{
  "PRODUCT_ID": {"weight_kg": 2.5, "length_cm": 45, "width_cm": 30, "height_cm": 10},
  "PRODUCT_ID2": {"weight_kg": 0.2, "length_cm": 10, "width_cm": 7, "height_cm": 4}
}
PROMPT;

        $response = $this->claude->messages->create(
            maxTokens: 2000,
            model:     $this->model,
            messages:  [['role' => 'user', 'content' => $prompt]],
        );

        $text = '';
        foreach ($response->content as $block) {
            if (isset($block->text)) $text .= $block->text;
        }

        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $text);
        $validIds = array_map(fn($p) => $p->id, $products);
        $result   = [];

        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $data = json_decode($m[0], true) ?? [];
            foreach ($data as $idStr => $info) {
                $id = (int) $idStr;
                if (!in_array($id, $validIds, true) || !is_array($info)) continue;
                $w = isset($info['weight_kg']) ? (float) $info['weight_kg'] : 0;
                $result[$id] = [
                    'weight' => max(0.2, $w), // minim 0.2kg garantat
                    'length' => isset($info['length_cm']) && $info['length_cm'] > 0 ? (float) $info['length_cm'] : null,
                    'width'  => isset($info['width_cm'])  && $info['width_cm']  > 0 ? (float) $info['width_cm']  : null,
                    'height' => isset($info['height_cm']) && $info['height_cm'] > 0 ? (float) $info['height_cm'] : null,
                    'source' => 'claude',
                ];
            }
        }

        return $result;
    }

    /**
     * Varianta cu web search: Claude caută online dimensiunile produselor.
     * $needsClaude = [['product' => ..., 'partial' => ...], ...]
     */
    private function askClaudeWithWebSearch(array $products, array $categoryMap, array $needsClaude): array
    {
        // Construim context cu greutatea deja știută (dacă există) pentru fiecare produs
        $partialByProduct = [];
        foreach ($needsClaude as $item) {
            $partialByProduct[$item['product']->id] = $item['partial'];
        }

        $lines = '';
        foreach ($products as $p) {
            $cat     = $categoryMap[$p->id] ?? '';
            $partial = $partialByProduct[$p->id] ?? null;
            $data    = is_array($p->data) ? $p->data : (json_decode($p->data ?? '{}', true) ?? []);
            $knownW  = ($partial['weight'] ?? null) ?? (float)($data['weight'] ?? 0);

            $line = "ID:{$p->id} | {$p->name}";
            if ($p->sku) $line .= " | SKU/EAN: {$p->sku}";
            if ($cat)    $line .= " | Categorie: {$cat}";
            if ($knownW > 0) $line .= " | Greutate știută: {$knownW}kg";
            $lines .= $line . "\n";
        }

        $prompt = <<<PROMPT
Ești un expert în produse de construcții și bricolaj. Caută online dimensiunile ambalajului/produsului pentru fiecare produs de mai jos.

Folosește web search pentru a găsi dimensiunile exacte (lungime × lățime × înălțime în cm) pe site-uri de producători sau vânzători.

{$lines}

Pentru fiecare produs returnează:
- **weight_kg**: greutatea în kg — dacă e deja știută, confirmă sau corectează; minim 0.2kg
- **length_cm**: lungimea maximă în cm (cea mai mare dimensiune)
- **width_cm**: lățimea în cm
- **height_cm**: înălțimea/grosimea în cm

Răspunde EXCLUSIV cu JSON valid:
{
  "PRODUCT_ID": {"weight_kg": 2.5, "length_cm": 45, "width_cm": 30, "height_cm": 10},
  "PRODUCT_ID2": {"weight_kg": 0.2, "length_cm": 8, "width_cm": 5, "height_cm": 3}
}
PROMPT;

        $response = $this->claude->messages->create(
            maxTokens: 2000,
            model:     $this->model,
            messages:  [['role' => 'user', 'content' => $prompt]],
            tools:     [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => count($products) * 2]],
        );

        $text = '';
        foreach ($response->content as $block) {
            if (isset($block->text)) $text .= $block->text;
        }

        $text     = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $text);
        $validIds = array_map(fn($p) => $p->id, $products);
        $result   = [];

        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $data = json_decode($m[0], true) ?? [];
            foreach ($data as $idStr => $info) {
                $id = (int) $idStr;
                if (!in_array($id, $validIds, true) || !is_array($info)) continue;
                $w = isset($info['weight_kg']) ? (float) $info['weight_kg'] : 0;
                $result[$id] = [
                    'weight' => max(0.2, $w),
                    'length' => isset($info['length_cm']) && $info['length_cm'] > 0 ? (float) $info['length_cm'] : null,
                    'width'  => isset($info['width_cm'])  && $info['width_cm']  > 0 ? (float) $info['width_cm']  : null,
                    'height' => isset($info['height_cm']) && $info['height_cm'] > 0 ? (float) $info['height_cm'] : null,
                    'source' => 'web',
                ];
            }
        }

        return $result;
    }

    private function isBulky(array $result): bool
    {
        if (($result['weight'] ?? 0) > 10) return true;
        if (($result['length'] ?? 0) > 100) return true;
        if (($result['width']  ?? 0) > 100) return true;
        if (($result['height'] ?? 0) > 100) return true;
        return false;
    }

    private function saveResult(object $product, array $result): void
    {
        $data = is_array($product->data) ? $product->data : (json_decode($product->data ?? '{}', true) ?? []);

        // Scriem greutatea doar dacă produsul nu o are deja (sau e forțat prin result['source']='attrs'/'name')
        $existingWeight = $data['weight'] ?? '';
        $hasExistingWeight = $existingWeight !== '' && $existingWeight !== 'null' && (float)$existingWeight > 0;
        if ($result['weight'] !== null && (!$hasExistingWeight || in_array($result['source'] ?? '', ['attrs', 'name']))) {
            $data['weight'] = (string) $result['weight'];
        }

        $dim = $data['dimensions'] ?? ['length' => '', 'width' => '', 'height' => ''];
        if ($result['length'] !== null) $dim['length'] = (string) $result['length'];
        if ($result['width']  !== null) $dim['width']  = (string) $result['width'];
        if ($result['height'] !== null) $dim['height'] = (string) $result['height'];
        $data['dimensions'] = $dim;

        $data['shipping_class'] = $this->isBulky($result) ? 'volum-atipic' : '';

        DB::table('woo_products')
            ->where('id', $product->id)
            ->update(['data' => json_encode($data), 'updated_at' => now()]);
    }

    private function logResult(object $product, array $result, mixed $idx, int $total): void
    {
        $bulky  = $this->isBulky($result) ? ' <fg=red>[VOLUM-ATIPIC]</>' : '';
        $src    = $result['source'];
        $w      = $result['weight'] !== null ? $result['weight'] . 'kg' : '?';
        $dims   = implode('×', array_filter([
            $result['length'] !== null ? $result['length'] . 'cm' : null,
            $result['width']  !== null ? $result['width']  . 'cm' : null,
            $result['height'] !== null ? $result['height'] . 'cm' : null,
        ]));
        $this->line("[{$idx}/{$total}] #{$product->id} {$product->name} — {$w}" . ($dims ? ", {$dims}" : '') . " ({$src}){$bulky}");
    }

    private function loadAttributes(array $productIds): array
    {
        $rows = DB::table('woo_product_attributes')
            ->whereIn('woo_product_id', $productIds)
            ->get(['woo_product_id', 'name', 'value']);

        $map = [];
        foreach ($rows as $row) {
            $map[$row->woo_product_id][$row->name] = $row->value;
        }
        return $map;
    }

    private function buildCategoryMap(array $productIds): array
    {
        $rows = DB::table('woo_product_category as pc')
            ->join('woo_categories as c', 'c.id', '=', 'pc.woo_category_id')
            ->leftJoin('woo_categories as p', 'p.id', '=', 'c.parent_id')
            ->whereIn('pc.woo_product_id', $productIds)
            ->select('pc.woo_product_id', 'c.name as cat', 'p.name as parent')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->woo_product_id] = $r->parent ? $r->parent . ' > ' . $r->cat : $r->cat;
        }
        return $map;
    }
}
