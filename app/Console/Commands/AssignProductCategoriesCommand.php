<?php

namespace App\Console\Commands;

use Anthropic\Client as AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Uses Claude to assign the most specific WooCommerce category to each
 * WinMentor placeholder product that currently has no category.
 *
 * Products are processed in batches of 20 per Claude call to keep costs low.
 * The fallback category is "Necategorisite" (id=164) for products that don't
 * clearly match anything.
 */
class AssignProductCategoriesCommand extends Command
{
    protected $signature = 'categories:assign-placeholder-products
                            {--limit= : Max number of products to process (default: all)}
                            {--batch-size=20 : Products per Claude API call}
                            {--reassign : Also re-assign products that already have a category}';

    protected $description = 'Use Claude to assign the most specific category to uncategorized WinMentor products';

    private AnthropicClient $claude;
    private string $model;

    /** Fallback category when Claude finds no match. */
    private const FALLBACK_CATEGORY_ID = 164; // Necategorisite

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');

        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY is not set in .env.');
            return self::FAILURE;
        }

        $this->claude = new AnthropicClient(apiKey: $apiKey);
        $this->model  = config('services.anthropic.model', 'claude-haiku-4-5-20251001');

        $limit     = $this->option('limit') ? (int) $this->option('limit') : null;
        $batchSize = max(1, min(50, (int) $this->option('batch-size')));
        $reassign  = (bool) $this->option('reassign');

        $this->info("Category assignment — model: {$this->model}, batch size: {$batchSize}");

        // Build & display category tree for confirmation
        $categoryTree = $this->buildCategoryTree();
        $this->info('Loaded ' . count($categoryTree['flat']) . ' categories.');

        // Query uncategorized placeholder products
        $query = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->select('id', 'name');

        if (! $reassign) {
            $query->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('woo_product_category')
                    ->whereColumn('woo_product_id', 'woo_products.id');
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->get();
        $total    = $products->count();

        if ($total === 0) {
            $this->info('No uncategorized placeholder products found.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} products to categorize.");

        $processed = 0;
        $assigned  = 0;
        $fallback  = 0;
        $failed    = 0;

        // Process in batches
        foreach ($products->chunk($batchSize) as $batch) {
            $batchList = $batch->values();
            $count     = $batchList->count();
            $from      = $processed + 1;
            $to        = $processed + $count;

            $this->info("[{$from}–{$to} / {$total}] Asking Claude to categorize {$count} products...");

            try {
                $assignments = $this->classifyBatch($batchList->toArray(), $categoryTree);

                foreach ($assignments as $productId => $categoryId) {
                    $processed++;

                    // If reassign: delete existing category links first
                    if ($reassign) {
                        DB::table('woo_product_category')
                            ->where('woo_product_id', $productId)
                            ->delete();
                    }

                    DB::table('woo_product_category')->insertOrIgnore([
                        'woo_product_id'  => $productId,
                        'woo_category_id' => $categoryId,
                    ]);

                    $catName = $categoryTree['flat'][$categoryId] ?? "id={$categoryId}";

                    if ($categoryId === self::FALLBACK_CATEGORY_ID) {
                        $fallback++;
                        $this->line("  #{$productId} → [fallback] {$catName}");
                    } else {
                        $assigned++;
                        // Find product name for display
                        $prodName = collect($batchList)->firstWhere('id', $productId)->name ?? '';
                        $this->line("  #{$productId} {$prodName} → {$catName}");
                    }
                }

                // Handle products Claude didn't return (shouldn't happen, but safety net)
                foreach ($batchList as $product) {
                    if (! isset($assignments[$product->id])) {
                        $processed++;
                        DB::table('woo_product_category')->insertOrIgnore([
                            'woo_product_id'  => $product->id,
                            'woo_category_id' => self::FALLBACK_CATEGORY_ID,
                        ]);
                        $fallback++;
                        $this->line("  #{$product->id} → [missing in response, fallback]");
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("  Batch failed: " . $e->getMessage());
                Log::warning("AssignProductCategories batch failed: " . $e->getMessage());

                // Mark entire batch as processed with fallback
                foreach ($batchList as $product) {
                    $processed++;
                    DB::table('woo_product_category')->insertOrIgnore([
                        'woo_product_id'  => $product->id,
                        'woo_category_id' => self::FALLBACK_CATEGORY_ID,
                    ]);
                    $fallback++;
                    $failed++;
                }

                // Pause longer on error (may be rate limited)
                sleep(5);
                continue;
            }

            // Small pause between batches to respect rate limits
            usleep(300000); // 0.3 s — haiku handles high throughput
        }

        $this->newLine();
        $this->info("Done. Total: {$total} | Assigned: {$assigned} | Fallback: {$fallback} | Errors: {$failed}");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Claude classification
    // -------------------------------------------------------------------------

    /**
     * Ask Claude to pick the best category for each product in the batch.
     *
     * @param  object[] $products  stdClass rows with id, name
     * @return array<int, int>  productId → categoryId
     */
    private function classifyBatch(array $products, array $categoryTree): array
    {
        $prompt = $this->buildPrompt($products, $categoryTree['prompt_text']);

        $message = $this->claude->messages->create(
            maxTokens: 1024,
            messages: [
                ['role' => 'user', 'content' => $prompt],
            ],
            model: $this->model,
        );

        $text = '';
        foreach ($message->content as $block) {
            if (isset($block->text)) {
                $text .= $block->text;
            }
        }

        return $this->parseAssignments($text, $products, $categoryTree['flat']);
    }

    private function buildPrompt(array $products, string $categoryText): string
    {
        $productLines = '';
        foreach ($products as $p) {
            $productLines .= "  {$p->id}: {$p->name}\n";
        }

        return <<<PROMPT
You are a product categorization expert for a Romanian hardware and construction materials store.

## Available categories (id: full path)
{$categoryText}

## Products to categorize
{$productLines}

## Task
For each product, assign the MOST SPECIFIC matching category. Rules:
- Always pick the deepest (leaf) category that fits, not a parent category
- If a product could fit multiple categories, pick the most relevant one
- If nothing fits, use category id {self::FALLBACK_CATEGORY_ID} (Necategorisite)
- Product names are in Romanian

Respond ONLY with a JSON object mapping product_id (string) to category_id (integer):
{"PRODUCT_ID": CATEGORY_ID, "PRODUCT_ID": CATEGORY_ID, ...}
PROMPT;
    }

    /**
     * Parse Claude's JSON response into a productId → categoryId map.
     *
     * @param  object[]       $products
     * @param  array<int,string> $flatCategories  id → full path name
     * @return array<int, int>
     */
    private function parseAssignments(string $text, array $products, array $flatCategories): array
    {
        // Strip markdown code fences
        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $text);

        // Extract the first JSON object
        if (! preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            throw new \RuntimeException('Claude response contained no JSON: ' . substr($text, 0, 200));
        }

        $data = json_decode($m[0], true);

        if (! is_array($data)) {
            throw new \RuntimeException('Could not decode Claude JSON: ' . $m[0]);
        }

        $validIds = array_keys($flatCategories);
        $result   = [];

        foreach ($data as $productIdStr => $categoryIdRaw) {
            $productId  = (int) $productIdStr;
            $categoryId = (int) $categoryIdRaw;

            // Validate the product ID belongs to this batch
            $validProduct = collect($products)->first(fn ($p) => $p->id === $productId);
            if (! $validProduct) {
                continue;
            }

            // Validate the category exists; fall back if not
            if (! in_array($categoryId, $validIds, true)) {
                $categoryId = self::FALLBACK_CATEGORY_ID;
            }

            $result[$productId] = $categoryId;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Category tree builder
    // -------------------------------------------------------------------------

    /**
     * Build category data structures needed for prompting and validation.
     *
     * @return array{
     *   flat: array<int, string>,
     *   prompt_text: string
     * }
     */
    private function buildCategoryTree(): array
    {
        $categories = DB::table('woo_categories')
            ->select('id', 'name', 'parent_id')
            ->orderBy('parent_id')
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        // Build full path for each category (e.g. "Electrice > Becuri")
        $paths = [];
        foreach ($categories as $cat) {
            $paths[$cat->id] = $this->buildPath($cat->id, $categories);
        }

        // Build compact text for the prompt, grouped by top-level
        $lines   = [];
        $topLevel = $categories->where('parent_id', 0)->sortBy('name');

        foreach ($topLevel as $top) {
            $lines[] = "{$top->id}: {$top->name}";
            $children = $categories->where('parent_id', $top->id)->sortBy('name');

            foreach ($children as $child) {
                $lines[] = "  {$child->id}: {$top->name} > {$child->name}";
                $grandchildren = $categories->where('parent_id', $child->id)->sortBy('name');

                foreach ($grandchildren as $gc) {
                    $lines[] = "    {$gc->id}: {$top->name} > {$child->name} > {$gc->name}";
                }
            }
        }

        return [
            'flat'        => $paths,
            'prompt_text' => implode("\n", $lines),
        ];
    }

    /**
     * Recursively build the full path string for a category.
     */
    private function buildPath(int $id, \Illuminate\Support\Collection $all, int $depth = 0): string
    {
        if ($depth > 5) {
            return $all[$id]->name ?? (string) $id;
        }

        $cat = $all[$id] ?? null;
        if (! $cat) {
            return (string) $id;
        }

        if (! $cat->parent_id || ! isset($all[$cat->parent_id])) {
            return $cat->name;
        }

        return $this->buildPath($cat->parent_id, $all, $depth + 1) . ' > ' . $cat->name;
    }
}
