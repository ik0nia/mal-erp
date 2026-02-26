<?php

namespace App\Console\Commands;

use Anthropic\Client as AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates professional Romanian SEO descriptions for WinMentor placeholder
 * products using Claude.
 *
 * For each product the command produces:
 *  - short_description  — 1-2 sentences (plain text), ideal for product cards
 *  - description        — full HTML (200-350 words): intro paragraph, features
 *                         list, usage context; optimised for search engines and
 *                         AI shopping agents
 *
 * Products are processed in batches of 10 per Claude API call.
 */
class GenerateProductDescriptionsCommand extends Command
{
    protected $signature = 'products:generate-descriptions
                            {--limit= : Max products to process (default: all)}
                            {--batch-size=10 : Products per Claude API call}
                            {--regenerate : Re-generate products that already have a description}';

    protected $description = 'Generate SEO-optimised Romanian descriptions for WinMentor placeholder products using Claude';

    private AnthropicClient $claude;
    private string $model;

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');

        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY is not set in .env.');
            return self::FAILURE;
        }

        $this->claude = new AnthropicClient(apiKey: $apiKey);
        $this->model  = config('services.anthropic.model', 'claude-haiku-4-5-20251001');

        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;
        $batchSize  = max(1, min(10, (int) $this->option('batch-size')));
        $regenerate = (bool) $this->option('regenerate');

        $this->info("Description generator — model: {$this->model}, batch: {$batchSize}");

        // Load category map: woo_product_id → full path string
        $categoryMap = $this->buildCategoryMap();
        $this->info('Loaded category map for ' . count($categoryMap) . ' products.');

        // Query
        $query = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->select('id', 'name', 'brand', 'unit', 'weight', 'erp_notes');

        if (! $regenerate) {
            $query->where(function ($q) {
                $q->whereNull('description')
                    ->orWhere('description', '');
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->get();
        $total    = $products->count();

        if ($total === 0) {
            $this->info('No products need descriptions.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} products to describe.");

        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        foreach ($products->chunk($batchSize) as $batch) {
            $batch = $batch->values();
            $count = $batch->count();
            $from  = $processed + 1;
            $to    = $processed + $count;

            $this->info("[{$from}–{$to} / {$total}] Generating descriptions...");

            try {
                $results = $this->generateBatch($batch->toArray(), $categoryMap);

                foreach ($results as $productId => $texts) {
                    DB::table('woo_products')
                        ->where('id', $productId)
                        ->update([
                            'short_description' => $texts['short'],
                            'description'       => $texts['full'],
                            'updated_at'        => now(),
                        ]);

                    $name = $batch->firstWhere('id', $productId)->name ?? '';
                    $this->line("  #{$productId} {$name}");
                    $processed++;
                    $succeeded++;
                }

                // Products missing from response
                foreach ($batch as $product) {
                    if (! isset($results[$product->id])) {
                        $processed++;
                        $failed++;
                        $this->warn("  #{$product->id} — missing in response");
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("  Batch failed: " . $e->getMessage());
                Log::warning('GenerateProductDescriptions batch failed: ' . $e->getMessage());

                foreach ($batch as $product) {
                    $processed++;
                    $failed++;
                }

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                    $this->warn('  Rate limited — sleeping 30s...');
                    sleep(30);
                }
            }

            usleep(200000); // 0.2 s between batches
        }

        $this->newLine();
        $this->info("Done. Total: {$total} | OK: {$succeeded} | Failed: {$failed}");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Claude batch generation
    // -------------------------------------------------------------------------

    /**
     * @param  object[]             $products   stdClass rows
     * @param  array<int, string>   $categoryMap  productId → category path
     * @return array<int, array{short: string, full: string}>
     */
    private function generateBatch(array $products, array $categoryMap): array
    {
        $prompt = $this->buildPrompt($products, $categoryMap);

        $message = $this->claude->messages->create(
            maxTokens: 8000,
            messages: [['role' => 'user', 'content' => $prompt]],
            model: $this->model,
        );

        $text = '';
        foreach ($message->content as $block) {
            if (isset($block->text)) {
                $text .= $block->text;
            }
        }

        return $this->parseResponse($text, $products);
    }

    private function buildPrompt(array $products, array $categoryMap): string
    {
        $glossary = config('product_glossary.prompt_context', '');

        $lines = '';
        foreach ($products as $p) {
            $category = $categoryMap[$p->id] ?? null;
            $meta     = array_filter([
                $category     ? "Categorie: {$category}"    : null,
                $p->brand     ? "Brand: {$p->brand}"        : null,
                $p->unit      ? "Unitate: {$p->unit}"       : null,
                $p->weight    ? "Greutate: {$p->weight} kg" : null,
                $p->erp_notes ? "Note: {$p->erp_notes}"    : null,
            ]);
            $metaStr = $meta ? ' [' . implode(', ', $meta) . ']' : '';
            $lines  .= "  {$p->id}: {$p->name}{$metaStr}\n";
        }

        return <<<PROMPT
Ești un specialist în copywriting pentru un magazin online de materiale de construcții și bricolaj din România.

{$glossary}

Generează descrieri profesionale în ROMÂNĂ pentru următoarele produse:

{$lines}

## Reguli stricte

**short_description** (1-2 propoziții, max 160 caractere):
- Răspunde la întrebarea "ce este și pentru ce se folosește?"
- Fără prețuri, fără fraze goale de genul "produs de calitate"
- Tonul: direct, informativ, profesional

**description** (HTML, 200-350 cuvinte):
- Structură: paragraf introductiv → `<ul>` cu 4-6 caracteristici/specificații → paragraf aplicații/utilizare
- Folosește tag-uri HTML: `<p>`, `<ul>`, `<li>`, `<strong>`
- Include cuvinte cheie relevante natural în text (pentru SEO și agenți AI)
- Specific tehnic acolo unde denumirea permite deduceri (ex: watt-aj, material, standard)
- Nu inventa specificații — bazează-te strict pe ce poți deduce din denumire și categorie

## Format răspuns

Răspunde EXCLUSIV cu un JSON valid, fără text înainte sau după:
{
  "PRODUCT_ID": {
    "short": "...",
    "full": "..."
  }
}
PROMPT;
    }

    /**
     * @param  object[]  $products
     * @return array<int, array{short: string, full: string}>
     */
    private function parseResponse(string $text, array $products): array
    {
        // Strip markdown code fences
        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $text);

        $validIds = array_map(fn ($p) => $p->id, $products);
        $result   = [];

        // Strategy 1: try to decode the whole JSON object
        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $data = json_decode($m[0], true);

            if (is_array($data)) {
                foreach ($data as $idStr => $texts) {
                    $id = (int) $idStr;

                    if (! in_array($id, $validIds, true)) {
                        continue;
                    }

                    $short = trim($texts['short'] ?? '');
                    $full  = trim($texts['full']  ?? '');

                    if (! empty($short) && ! empty($full)) {
                        $result[$id] = ['short' => $short, 'full' => $full];
                    }
                }

                if (! empty($result)) {
                    return $result;
                }
            }
        }

        // Strategy 2: extract each product block individually using regex
        // Handles truncated or malformed outer JSON
        foreach ($validIds as $id) {
            // Match "ID": { "short": "...", "full": "..." }
            $pattern = '/"?' . $id . '"?\s*:\s*\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s';

            if (preg_match($pattern, $text, $m)) {
                $block = json_decode('{' . $m[1] . '}', true);

                if (is_array($block)) {
                    $short = trim($block['short'] ?? '');
                    $full  = trim($block['full']  ?? '');

                    if (! empty($short) && ! empty($full)) {
                        $result[$id] = ['short' => $short, 'full' => $full];
                    }
                }
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a map of productId → full category path for all placeholder products.
     *
     * @return array<int, string>
     */
    private function buildCategoryMap(): array
    {
        $rows = DB::table('woo_product_category as pc')
            ->join('woo_categories as wc', 'wc.id', '=', 'pc.woo_category_id')
            ->leftJoin('woo_categories as parent', 'parent.id', '=', 'wc.parent_id')
            ->leftJoin('woo_categories as gp', 'gp.id', '=', 'parent.parent_id')
            ->select(
                'pc.woo_product_id',
                'wc.name as cat',
                'parent.name as parent',
                'gp.name as gp'
            )
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $parts = array_filter([$row->gp, $row->parent, $row->cat]);
            $map[(int) $row->woo_product_id] = implode(' > ', $parts);
        }

        return $map;
    }
}
