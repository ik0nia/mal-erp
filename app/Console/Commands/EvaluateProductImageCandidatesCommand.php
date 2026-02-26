<?php

namespace App\Console\Commands;

use Anthropic\Client as AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Uses Claude Vision (claude-haiku) to evaluate image candidates for WinMentor products.
 *
 * For each product with pending candidates it:
 *  1. Downloads thumbnails from Bing CDN (or the main image URL as fallback).
 *  2. Sends them to Claude with the product name and asks for a relevance score + watermark detection.
 *  3. Marks the best candidate as 'approved', the rest as 'rejected'.
 *
 * Run AFTER images:search-placeholder-products has collected candidates.
 */
class EvaluateProductImageCandidatesCommand extends Command
{
    protected $signature = 'images:evaluate-candidates
                            {--limit=      : Max number of products to evaluate (default: all)}
                            {--re-evaluate : Re-evaluate products that already have an approved image}
                            {--worker=1    : Worker index (1-based)}
                            {--workers=1   : Total number of parallel workers}';

    protected $description = 'Use Claude Vision to evaluate and approve the best image candidate per product';

    private AnthropicClient $claude;

    private string $model;

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');

        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY is not set in .env. Cannot proceed.');
            return self::FAILURE;
        }

        $this->claude = new AnthropicClient(apiKey: $apiKey);
        $this->model  = config('services.anthropic.model', 'claude-haiku-4-5-20251001');

        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;
        $reEvaluate = (bool) $this->option('re-evaluate');
        $worker     = max(1, (int) $this->option('worker'));
        $workers    = max(1, (int) $this->option('workers'));

        $this->info("Claude Vision image evaluator — model: {$this->model}" . ($workers > 1 ? ", worker: {$worker}/{$workers}" : ''));

        // Group pending candidates by product
        $subQuery = DB::table('product_image_candidates')
            ->select('woo_product_id')
            ->where('status', 'pending')
            ->groupBy('woo_product_id');

        if (! $reEvaluate) {
            // Skip products that already have an approved candidate
            $subQuery->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('product_image_candidates as approved')
                    ->whereColumn('approved.woo_product_id', 'product_image_candidates.woo_product_id')
                    ->where('approved.status', 'approved');
            });
        }

        if ($workers > 1) {
            $subQuery->whereRaw('(woo_product_id % ?) = ?', [$workers, $worker - 1]);
        }

        if ($limit !== null) {
            $subQuery->limit($limit);
        }

        $productIds = $subQuery->pluck('woo_product_id');
        $total      = $productIds->count();

        if ($total === 0) {
            $this->info('No products with pending candidates found.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} products to evaluate.");

        $processed = 0;
        $approved  = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($productIds as $productId) {
            $processed++;

            $product = DB::table('woo_products')->where('id', $productId)->select('id', 'name')->first();
            if (! $product) {
                $failed++;
                continue;
            }

            $candidates = DB::table('product_image_candidates')
                ->where('woo_product_id', $productId)
                ->where('status', 'pending')
                ->get();

            $this->info("[{$processed}/{$total}] #{$productId}: \"{$product->name}\" — {$candidates->count()} candidate(s)");

            try {
                $bestId = $this->evaluateWithClaude($product->name, $candidates->toArray());

                if ($bestId !== null) {
                    // Approve the best, reject the rest
                    DB::table('product_image_candidates')
                        ->where('woo_product_id', $productId)
                        ->where('status', 'pending')
                        ->update(['status' => 'rejected', 'updated_at' => now()]);

                    DB::table('product_image_candidates')
                        ->where('id', $bestId)
                        ->update(['status' => 'approved', 'updated_at' => now()]);

                    // Apply the approved image directly to the product
                    $imageUrl = DB::table('product_image_candidates')
                        ->where('id', $bestId)
                        ->value('image_url');

                    if ($imageUrl) {
                        DB::table('woo_products')
                            ->where('id', $productId)
                            ->update(['main_image_url' => $imageUrl, 'updated_at' => now()]);
                    }

                    $this->info("  Approved #{$bestId} → applied to product.");
                    $approved++;
                } else {
                    $this->warn('  No suitable candidate found by Claude — all remain pending.');
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->warn('  Error: ' . $e->getMessage());
                Log::warning("EvaluateProductImages: product #{$productId} failed — " . $e->getMessage());
                $failed++;
            }

            // Small delay to respect API rate limits (haiku is generous but let's be safe)
            if ($processed < $total) {
                usleep(500000); // 0.5 s
            }
        }

        $this->newLine();
        $this->info("Done. Processed: {$processed} | Approved: {$approved} | No match: {$skipped} | Errors: {$failed}");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    /**
     * Download candidate thumbnails and ask Claude to pick the best one.
     * Returns the DB id of the winning candidate, or null if none is suitable.
     *
     * @param  object[] $candidates  stdClass rows from product_image_candidates
     */
    private function evaluateWithClaude(string $productName, array $candidates): ?int
    {
        if (empty($candidates)) {
            return null;
        }

        // If only one candidate, still run it through Claude to verify it's relevant
        $contentBlocks = [];
        $indexMap      = []; // Claude index (1-based) → candidate DB id

        $contentBlocks[] = [
            'type' => 'text',
            'text' => $this->buildPrompt($productName, count($candidates)),
        ];

        foreach ($candidates as $i => $candidate) {
            $referer   = $candidate->source_page_url ?? '';
            $imageData = $this->fetchImageAsBase64($candidate->thumbnail_url ?: $candidate->image_url, $referer);

            if ($imageData === null) {
                $this->line("    Candidate #{$candidate->id}: could not download image — skipping.");
                continue;
            }

            $indexMap[$i + 1] = $candidate->id;

            $contentBlocks[] = [
                'type' => 'text',
                'text' => "Image " . ($i + 1) . " (source: " . parse_url($candidate->source_page_url ?: $candidate->image_url, PHP_URL_HOST) . "):",
            ];
            $contentBlocks[] = [
                'type'   => 'image',
                'source' => $imageData,
            ];
        }

        if (empty($indexMap)) {
            return null;
        }

        // Single candidate with no alternatives: just verify it's suitable
        $message = $this->claude->messages->create(
            maxTokens: 256,
            messages: [
                [
                    'role'    => 'user',
                    'content' => $contentBlocks,
                ],
            ],
            model: $this->model,
        );

        $text = $this->extractTextFromMessage($message);

        return $this->parseBestIndex($text, $indexMap);
    }

    /**
     * Build the evaluation prompt for Claude.
     */
    private function buildPrompt(string $productName, int $count): string
    {
        $noun = $count === 1 ? 'image' : "{$count} images";

        return <<<PROMPT
You are evaluating product photos for a Romanian hardware, construction & building materials store.
Products include: adhesives, paints, grouts, profiles, panels, tools, batteries, chemicals, insulation, etc.

Product name: "{$productName}"

I will show you {$noun}. For each image evaluate:
1. RELEVANCE (1-10): Does this image show the correct product?
   - BRAND: If the product name includes a brand (e.g. Baumit, Sika, Ceresit, Bison, Henkel, Hardy), the image MUST show that exact brand. A Ceresit photo for a Baumit product scores 1.
   - TYPE: The product category must match (grout is not paint, cleaner is not foam, battery is not a car battery).
   - SIZE/COLOR variants of the same brand+type are acceptable (e.g. 2KG vs 5KG, grey vs white).
2. WATERMARK: Only reject stock-photo watermarks (Shutterstock, Getty, etc.). Product labels and store branding are fine.
3. QUALITY: Packaging shots, catalog photos, and store product pages are all acceptable.

Pick the BEST image. Return null only if ALL images show the wrong brand, wrong product type, or have stock-photo watermarks.

Reply ONLY in this JSON format (no extra text):
{"best": 1, "scores": [{"index": 1, "relevance": 8, "watermark": false, "quality": "good"}]}
or {"best": null} if none is suitable.
PROMPT;
    }

    /**
     * Fetch an image URL and return it as an Anthropic base64 image source block,
     * or null on failure.
     *
     * @return array{type: 'base64', media_type: string, data: string}|null
     */
    private function fetchImageAsBase64(string $url, string $referer = ''): ?array
    {
        if (empty($url)) {
            return null;
        }

        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
        ];

        if (! empty($referer)) {
            $headers[] = 'Referer: ' . $referer;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body    = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($status !== 200 || empty($body)) {
            return null;
        }

        // Determine media type (Claude supports jpeg, png, gif, webp)
        $mediaType = match (true) {
            str_contains($ctype, 'png')  => 'image/png',
            str_contains($ctype, 'gif')  => 'image/gif',
            str_contains($ctype, 'webp') => 'image/webp',
            default                      => 'image/jpeg',
        };

        // Reject images that are too small (icon-sized) or too large (>4 MB)
        $size = strlen($body);
        if ($size < 1000 || $size > 4 * 1024 * 1024) {
            return null;
        }

        return [
            'type'       => 'base64',
            'media_type' => $mediaType,
            'data'       => base64_encode($body),
        ];
    }

    /**
     * Extract plain text from a Claude SDK message response.
     */
    private function extractTextFromMessage(mixed $message): string
    {
        $text = '';
        foreach ($message->content as $block) {
            if (isset($block->text)) {
                $text .= $block->text;
            }
        }
        return trim($text);
    }

    /**
     * Parse Claude's JSON response and return the winning candidate DB id.
     *
     * @param  array<int, int> $indexMap  1-based Claude index → DB id
     */
    private function parseBestIndex(string $text, array $indexMap): ?int
    {
        // Strip markdown code fences if present
        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $text);
        $text = trim($text);

        // Find the first JSON object in the response
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);

            if (is_array($data) && isset($data['best'])) {
                $best = $data['best'];

                if ($best === null || $best === 'null' || $best === 0) {
                    return null;
                }

                $bestInt = (int) $best;
                return $indexMap[$bestInt] ?? null;
            }
        }

        // Fallback: look for a plain number
        if (preg_match('/"best"\s*:\s*(\d+)/', $text, $m)) {
            $bestInt = (int) $m[1];
            return $indexMap[$bestInt] ?? null;
        }

        return null;
    }
}
