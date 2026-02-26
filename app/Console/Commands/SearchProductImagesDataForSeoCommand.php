<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caută imagini pentru produse folosind DataForSEO Google Images SERP API.
 *
 * Cost: $0.0006 per căutare (Live Advanced).
 * Auth: Basic Auth cu DATAFORSEO_LOGIN și DATAFORSEO_PASSWORD din .env.
 */
class SearchProductImagesDataForSeoCommand extends Command
{
    protected $signature = 'images:search-dataforseo
                            {--limit=      : Max produse de procesat}
                            {--restart     : Reprocesează și produsele care au deja candidați dataforseo}
                            {--only-empty  : Procesează doar produsele fără imagine aprobată}
                            {--results=10  : Număr rezultate per căutare (max 100)}
                            {--placeholder : Procesează produsele WinMentor placeholder (implicit: woo non-placeholder)}
                            {--worker=1    : Worker index (1-based)}
                            {--workers=1   : Total number of parallel workers}';

    protected $description = 'Caută imagini via DataForSEO Google Images API și salvează candidați';

    private string $apiBase  = 'https://api.dataforseo.com/v3/serp/google/images/live/advanced';
    private string $login    = '';
    private string $password = '';

    private array $watermarkDomains = [
        'shutterstock.com', 'gettyimages.com', 'istockphoto.com',
        'depositphotos.com', 'dreamstime.com', 'stock.adobe.com',
        'alamy.com', '123rf.com', 'bigstockphoto.com', 'vectorstock.com',
        'vecteezy.com', 'freepik.com', 'flaticon.com',
    ];

    public function handle(): int
    {
        $this->login    = env('DATAFORSEO_LOGIN', '');
        $this->password = env('DATAFORSEO_PASSWORD', '');

        if (empty($this->login) || empty($this->password)) {
            $this->error('Lipsesc DATAFORSEO_LOGIN sau DATAFORSEO_PASSWORD din .env');
            return self::FAILURE;
        }

        $limit       = $this->option('limit')   ? (int) $this->option('limit')   : null;
        $restart     = (bool) $this->option('restart');
        $onlyEmpty   = (bool) $this->option('only-empty');
        $numResults  = max(1, min(100, (int) $this->option('results')));
        $placeholder = (bool) $this->option('placeholder');
        $worker      = max(1, (int) $this->option('worker'));
        $workers     = max(1, (int) $this->option('workers'));

        $this->info("DataForSEO Google Images — results: {$numResults}/căutare" . ($workers > 1 ? ", worker: {$worker}/{$workers}" : ''));

        $query = DB::table('woo_products')->select('id', 'name', 'sku');

        if ($placeholder) {
            $query->where('is_placeholder', true)->where('source', 'winmentor_csv');
        } else {
            $query->where('is_placeholder', false);
        }

        if ($workers > 1) {
            $query->whereRaw('(id % ?) = ?', [$workers, $worker - 1]);
        }

        if ($onlyEmpty) {
            $query->where(fn($q) => $q->whereNull('main_image_url')->orWhere('main_image_url', ''));
        }

        if (! $restart) {
            $query->whereNotExists(fn($sub) => $sub
                ->from('product_image_candidates')
                ->whereColumn('woo_product_id', 'woo_products.id')
                ->where('source', 'dataforseo')
            );
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
        $this->line(sprintf('Cost estimat: ~$%.4f', $total * 0.002)); // live/advanced rate

        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        foreach ($products as $product) {
            $processed++;
            $searchQuery = $this->buildQuery($product->name);

            $this->info("[{$processed}/{$total}] #{$product->id} [{$product->sku}]: \"{$product->name}\"");

            try {
                $candidates = $this->searchImages($searchQuery, $numResults);

                if (empty($candidates)) {
                    $this->warn('  Niciun rezultat utilizabil.');
                    $failed++;
                } else {
                    $inserted = $this->saveCandidates($product->id, $searchQuery, $candidates);
                    $this->line("  Salvați {$inserted} candidați.");
                    $succeeded++;
                }
            } catch (\Throwable $e) {
                $this->warn('  Eroare: ' . $e->getMessage());
                Log::warning("SearchProductImagesDataForSeo #{$product->id}: " . $e->getMessage());
                $failed++;

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                    $this->warn('  Rate limit — aștept 30s...');
                    sleep(30);
                }
            }

            if ($processed < $total) {
                usleep(200_000); // 0.2s între cereri
            }
        }

        $this->newLine();
        $this->info("Gata. Procesate: {$processed} | Găsite: {$succeeded} | Eșuate: {$failed}");

        return self::SUCCESS;
    }

    // ─── Search ────────────────────────────────────────────────────────────────

    private function searchImages(string $query, int $num): array
    {
        $payload = [[
            'keyword'       => $query,
            'language_code' => 'ro',
            'location_code' => 2642, // Romania
            'device'        => 'desktop',
            'depth'         => min($num, 100),
        ]];

        $ch = curl_init($this->apiBase);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_USERPWD        => "{$this->login}:{$this->password}",
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("cURL error: {$error}");
        }

        $data = json_decode($body, true);

        if ($status !== 200 || ($data['status_code'] ?? 0) !== 20000) {
            $msg = $data['status_message'] ?? "HTTP {$status}";
            throw new \RuntimeException("DataForSEO error: {$msg}");
        }

        $task = $data['tasks'][0] ?? null;
        if (! $task || ($task['status_code'] ?? 0) !== 20000) {
            $msg = $task['status_message'] ?? 'task failed';
            throw new \RuntimeException("Task error: {$msg}");
        }

        $items = $task['result'][0]['items'] ?? [];

        return $this->parseItems($items);
    }

    private function parseItems(array $items): array
    {
        $candidates = [];

        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'images_search') {
                continue;
            }

            $imageUrl = $item['source_url'] ?? null;
            $pageUrl  = $item['url']        ?? null;
            $title    = $item['title']      ?? '';

            if (empty($imageUrl)) {
                continue;
            }

            $domain = parse_url($imageUrl, PHP_URL_HOST) ?? '';
            if ($this->isWatermarkDomain($domain)) {
                continue;
            }

            // Scor bazat pe domeniu și tip fișier
            $score = 5;

            foreach (['emag', 'dedeman', 'leroy', 'praktiker', 'hornbach', 'bricodepot', 'altex', 'cel.ro', 'mathaus', 'brico', 'utilul', 'ambient'] as $shop) {
                if (str_contains((string) $pageUrl, $shop) || str_contains($domain, $shop)) {
                    $score = min(10, $score + 2);
                    break;
                }
            }

            if (preg_match('/\.(jpg|jpeg|png)(\?.*)?$/i', $imageUrl)) {
                $score++;
            }

            $candidates[] = [
                'image_url'  => $imageUrl,
                'page_url'   => $pageUrl,
                'title'      => mb_substr($title, 0, 255),
                'score'      => $score,
            ];
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, 5);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function buildQuery(string $name): string
    {
        // Scapă de dimensiuni ca "125MM", "3/4″", "20KG" pentru o căutare mai generică
        // Unitatea este obligatorie — altfel numerele model (ex: "2430", "CR123") ar fi șterse greșit
        $name = preg_replace('/\b\d+[\dxX*.,\/″""]*\s*(?:mm|cm|m\b|kg|w\b|v\b|a\b|l\b|ml|g\b|buc)\b/i', '', $name);
        $name = preg_replace('/[″""]/u', '', $name);
        $name = preg_replace('/\s+/', ' ', trim($name));

        return $name ?: $name;
    }

    private function saveCandidates(int $productId, string $query, array $candidates): int
    {
        $now     = now();
        $inserts = [];

        foreach ($candidates as $c) {
            $inserts[] = [
                'woo_product_id'  => $productId,
                'image_url'       => $c['image_url'],
                'thumbnail_url'   => null,
                'source_page_url' => $c['page_url'] ?? null,
                'image_title'     => $c['title'],
                'width'           => null,
                'height'          => null,
                'search_query'    => mb_substr($query, 0, 255),
                'source'          => 'dataforseo',
                'status'          => 'pending',
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        if (empty($inserts)) {
            return 0;
        }

        DB::table('product_image_candidates')->insert($inserts);

        return count($inserts);
    }

    private function isWatermarkDomain(string $domain): bool
    {
        foreach ($this->watermarkDomains as $bad) {
            if (str_contains($domain, $bad)) return true;
        }
        return false;
    }
}
