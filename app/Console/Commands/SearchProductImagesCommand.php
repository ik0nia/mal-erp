<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Searches Bing Images for each placeholder WinMentor product and stores
 * the top image candidates in product_image_candidates.
 *
 * Strategy:
 *  1. Translate the Romanian product name to English (Google Translate public endpoint).
 *  2. Search Bing Images with the English query (international sources, no API key).
 *  3. Fall back to the Romanian query if EN returns nothing.
 *  4. Filter out known watermark / stock-photo domains.
 *  5. Score remaining candidates by size and source quality; keep top 3.
 */
class SearchProductImagesCommand extends Command
{
    protected $signature = 'images:search-placeholder-products
                            {--limit= : Limit number of products to process (default: all)}
                            {--restart : Reprocess products that already have candidates}';

    protected $description = 'Search Bing Images for placeholder WinMentor products and store candidates';

    private string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private string $cookieJarFile = '';

    /** Stock-photo domains that always have watermarks. */
    private array $watermarkDomains = [
        'shutterstock.com',
        'gettyimages.com',
        'istockphoto.com',
        'depositphotos.com',
        'dreamstime.com',
        'stock.adobe.com',
        'alamy.com',
        '123rf.com',
        'bigstockphoto.com',
        'stockfresh.com',
        'canstockphoto.com',
        'vectorstock.com',
        'vecteezy.com',
    ];

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public function handle(): int
    {
        if (! function_exists('curl_init')) {
            $this->error('PHP cURL extension is required but not available.');
            return self::FAILURE;
        }

        $limit   = $this->option('limit') ? (int) $this->option('limit') : null;
        $restart = (bool) $this->option('restart');

        $this->cookieJarFile = sys_get_temp_dir() . '/bing_cookies_' . getmypid() . '.txt';

        $this->info('Starting image search for placeholder WinMentor products...');

        // Warm up a Bing session
        $this->info('Initialising Bing session...');
        $warmup = $this->curlGet('https://www.bing.com/images/search?q=product', [
            'Accept: text/html,application/xhtml+xml,*/*;q=0.9',
            'Accept-Language: en-US,en;q=0.9',
        ]);
        if ($warmup['status'] < 200 || $warmup['status'] >= 400) {
            $this->warn("Bing warmup returned HTTP {$warmup['status']} — continuing anyway.");
        } else {
            $this->info('Bing session ready.');
        }

        // Build product query
        $dbQuery = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->select('id', 'name');

        if (! $restart) {
            $dbQuery->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('product_image_candidates')
                    ->whereColumn('product_image_candidates.woo_product_id', 'woo_products.id');
            });
        }

        if ($limit !== null) {
            $dbQuery->limit($limit);
        }

        $products = $dbQuery->get();
        $total    = $products->count();

        if ($total === 0) {
            $this->info('No placeholder products found to process.');
            $this->cleanup();
            return self::SUCCESS;
        }

        $this->info("Found {$total} products to process.");

        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        foreach ($products as $product) {
            $processed++;
            $roQuery = $this->normalizeQuery($product->name);

            if (empty($roQuery)) {
                $this->warn("[{$processed}/{$total}] Skipping #{$product->id} — empty query after normalization.");
                $failed++;
                continue;
            }

            // Translate to English for broader coverage
            $enQuery   = $this->translateToEnglish($roQuery);
            $displayEn = ($enQuery !== $roQuery) ? " / EN: \"{$enQuery}\"" : '';
            $this->info("[{$processed}/{$total}] #{$product->id}: \"{$product->name}\"");
            $this->line("  Query RO: \"{$roQuery}\"{$displayEn}");

            try {
                // Search Romanian query first (local sources preferred),
                // fall back to English if no good results found
                $results = $this->searchBing($roQuery);

                if (empty($results) && $enQuery !== $roQuery) {
                    $this->line('  No RO results — retrying with EN query...');
                    usleep(1500000);
                    $results = $this->searchBing($enQuery);
                }

                if (empty($results)) {
                    $this->warn('  No results found.');
                    $failed++;
                } else {
                    $inserted = $this->saveCandidates($product->id, $roQuery, $results);
                    $this->info("  Saved {$inserted} image candidate(s).");
                    $succeeded++;
                }
            } catch (\Throwable $e) {
                $this->warn('  Error: ' . $e->getMessage());
                Log::warning("SearchProductImages: product #{$product->id} failed — " . $e->getMessage());
                $failed++;

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                    $this->warn('  Rate limited — sleeping 60s...');
                    sleep(60);
                }
            }

            if ($processed < $total) {
                usleep(random_int(3000000, 5000000));
            }
        }

        $this->newLine();
        $this->info("Done. Processed: {$processed} | Succeeded: {$succeeded} | Failed/skipped: {$failed}");

        $this->cleanup();
        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Bing image search
    // -------------------------------------------------------------------------

    /**
     * Search Bing Images and return up to 3 filtered, scored candidates.
     *
     * @return array<int, array{image: string, thumbnail: string, url: string, title: string, width: int|null, height: int|null, score: int}>
     */
    private function searchBing(string $query): array
    {
        $url = 'https://www.bing.com/images/search?q=' . urlencode($query)
            . '&form=HDRSC2&first=1&tsc=ImageBasicHover';

        $result = $this->curlGet($url, [
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
            'Referer: https://www.bing.com/',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: same-origin',
        ]);

        if ($result['status'] === 429) {
            $this->warn("  Bing rate limit (429) — sleeping 45s...");
            sleep(45);
            $result = $this->curlGet($url, [
                'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
                'Referer: https://www.bing.com/',
            ]);
        }

        if ($result['status'] !== 200) {
            throw new \RuntimeException("Bing returned HTTP {$result['status']} for query \"{$query}\"");
        }

        return $this->parseBingResults($result['body']);
    }

    /**
     * Parse Bing HTML response. Bing embeds image metadata as JSON in m="{...}"
     * attributes on elements with class "iusc".
     *
     * @return array<int, array{image: string, thumbnail: string, url: string, title: string, width: int|null, height: int|null, score: int}>
     */
    private function parseBingResults(string $html): array
    {
        // Extract all m="..." attribute values (JSON, HTML-entity-encoded)
        preg_match_all('/class="iusc"[^>]+m="(\{[^"]+\})"/', $html, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $candidates = [];

        foreach ($matches[1] as $raw) {
            // Decode HTML entities, then decode JSON
            $json = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $data = json_decode($json, true);

            if (! is_array($data) || empty($data['murl'])) {
                continue;
            }

            $imageUrl = $data['murl'];
            $srcUrl   = $data['purl'] ?? '';
            $thumbUrl = $data['turl'] ?? '';
            $title    = $data['t']    ?? '';

            // Skip watermarked sources
            if ($this->isWatermarkDomain($imageUrl) || $this->isWatermarkDomain($srcUrl)) {
                continue;
            }

            // Skip very small images (icons, logos)
            // Bing doesn't always include pixel dimensions in the HTML JSON;
            // use the display-size attributes as a proxy if present.
            $width  = isset($data['iw']) ? (int) $data['iw'] : null;
            $height = isset($data['ih']) ? (int) $data['ih'] : null;

            if ($width !== null && $height !== null && ($width < 200 || $height < 200)) {
                continue;
            }

            $score = $this->scoreResult($imageUrl, $srcUrl, $width, $height);

            $candidates[] = [
                'image'     => $imageUrl,
                'thumbnail' => $thumbUrl,
                'url'       => $srcUrl,
                'title'     => mb_substr($title, 0, 255),
                'width'     => $width,
                'height'    => $height,
                'score'     => $score,
            ];
        }

        // Sort by score, take top 3
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, 3);
    }

    // -------------------------------------------------------------------------
    // Translation
    // -------------------------------------------------------------------------

    /**
     * Translate Romanian text to English via the Google Translate public endpoint.
     * No API key required; returns original text on failure.
     */
    private function translateToEnglish(string $text): string
    {
        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=ro&tl=en&dt=t&q=' . urlencode($text);

        $result = $this->curlGet($url, [
            'Accept: application/json, text/plain, */*',
        ]);

        if ($result['status'] !== 200 || empty($result['body'])) {
            return $text;
        }

        $data = json_decode($result['body'], true);

        if (! isset($data[0]) || ! is_array($data[0])) {
            return $text;
        }

        $translated = '';
        foreach ($data[0] as $segment) {
            if (isset($segment[0]) && is_string($segment[0])) {
                $translated .= $segment[0];
            }
        }

        return trim($translated) ?: $text;
    }

    // -------------------------------------------------------------------------
    // Filtering & scoring
    // -------------------------------------------------------------------------

    private function isWatermarkDomain(string $url): bool
    {
        foreach ($this->watermarkDomains as $domain) {
            if (str_contains($url, $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Score an image candidate 0–100. Higher = better.
     */
    private function scoreResult(string $imageUrl, string $srcUrl, ?int $width, ?int $height): int
    {
        $score = 50;

        // Prefer larger images
        if ($width !== null && $height !== null) {
            $area = $width * $height;
            if ($area >= 800 * 800) {
                $score += 20;
            } elseif ($area >= 400 * 400) {
                $score += 10;
            }

            // Prefer roughly square images (typical product photos)
            $ratio = max($width, $height) / max(1, min($width, $height));
            if ($ratio < 1.5) {
                $score += 10;
            }
        }

        // Prefer JPEG/PNG (not GIF/SVG/WEBP)
        if (preg_match('/\.(jpg|jpeg|png)(\?.*)?$/i', $imageUrl)) {
            $score += 5;
        }

        // Prefer known e-commerce / manufacturer sources
        $preferredDomains = [
            'emag.ro', 'altex.ro', 'cel.ro', 'pcgarage.ro', 'flanco.ro',
            'amazon.com', 'amazon.de', 'amazon.co.uk', 'amazon.fr',
            'aliexpress.com', 'alibaba.com', 'made-in-china.com',
            'ebay.com', 'ebay.de', 'ebay.co.uk',
            'wikipedia.org', 'wikimedia.org',
            'manufacturer.com',
        ];
        foreach ($preferredDomains as $d) {
            if (str_contains($srcUrl, $d)) {
                $score += 15;
                break;
            }
        }

        return max(0, min(100, $score));
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    private function saveCandidates(int $productId, string $searchQuery, array $results): int
    {
        $now  = now();
        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                'woo_product_id'  => $productId,
                'search_query'    => $searchQuery,
                'image_url'       => $result['image'],
                'thumbnail_url'   => $result['thumbnail'] ?: null,
                'source_page_url' => $result['url']        ?: null,
                'image_title'     => $result['title']      ?: null,
                'width'           => $result['width'],
                'height'          => $result['height'],
                'status'          => 'pending',
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        DB::table('product_image_candidates')->insert($rows);

        return count($rows);
    }

    // -------------------------------------------------------------------------
    // HTTP via cURL with shared cookie jar
    // -------------------------------------------------------------------------

    /**
     * @param  string[] $extraHeaders  Raw header strings, e.g. "Accept: text/html"
     * @return array{status: int, body: string, error: string}
     */
    private function curlGet(string $url, array $extraHeaders = []): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => array_merge(
                ['User-Agent: ' . $this->userAgent],
                $extraHeaders
            ),
            CURLOPT_COOKIEJAR      => $this->cookieJarFile,
            CURLOPT_COOKIEFILE     => $this->cookieJarFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',   // auto-accept gzip/deflate/br
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => $body ?: '',
            'error'  => $error,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalizeQuery(string $name): string
    {
        $query = mb_strtolower($name, 'UTF-8');
        $query = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $query);
        $query = preg_replace('/\b\d{3,}\b/', ' ', $query);
        $query = preg_replace('/\b\d{1,2}\b/', ' ', $query);
        $query = preg_replace('/\s+/', ' ', $query);
        $query = trim($query);

        $words = explode(' ', $query);
        $words = array_filter($words, fn ($w) => mb_strlen($w, 'UTF-8') >= 3);
        $words = array_values($words);
        $words = array_slice($words, 0, 6);

        return implode(' ', $words);
    }

    private function cleanup(): void
    {
        if ($this->cookieJarFile && file_exists($this->cookieJarFile)) {
            @unlink($this->cookieJarFile);
        }
    }
}
