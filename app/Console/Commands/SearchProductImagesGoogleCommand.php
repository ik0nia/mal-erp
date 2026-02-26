<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caută imagini pentru produsele WinMentor placeholder folosind
 * Google Custom Search API (Image Search).
 *
 * Autentificare: Service Account JWT (prioritar) sau API Key.
 * Cost: $5 / 1000 căutări (primele 100/zi gratuit).
 */
class SearchProductImagesGoogleCommand extends Command
{
    protected $signature = 'images:search-google
                            {--limit=      : Max produse de procesat}
                            {--restart     : Reprocesează și produsele care au deja candidați Google}
                            {--only-empty  : Procesează doar produsele fără nicio imagine aprobată}
                            {--results=5   : Număr rezultate per căutare (max 10)}';

    protected $description = 'Caută imagini via Google Custom Search API și salvează candidați';

    private array $watermarkDomains = [
        'shutterstock.com', 'gettyimages.com', 'istockphoto.com',
        'depositphotos.com', 'dreamstime.com', 'stock.adobe.com',
        'alamy.com', '123rf.com', 'bigstockphoto.com', 'vectorstock.com',
        'vecteezy.com', 'freepik.com', 'flaticon.com',
    ];

    /** Bearer token obținut prin JWT service account */
    private ?string $bearerToken = null;

    /** Expiră la timestamp Unix */
    private int $tokenExpiresAt = 0;

    public function handle(): int
    {
        $cx = env('GOOGLE_CUSTOM_SEARCH_CX');

        if (empty($cx)) {
            $this->error('Lipsește GOOGLE_CUSTOM_SEARCH_CX din .env');
            return self::FAILURE;
        }

        // Determinăm metoda de autentificare
        $saPath = storage_path('app/google-sa.json');
        $apiKey = env('GOOGLE_CUSTOM_SEARCH_KEY');

        if (file_exists($saPath)) {
            $this->info('Autentificare: Service Account JWT');
            $authMode = 'sa';
        } elseif (! empty($apiKey)) {
            $this->info('Autentificare: API Key');
            $authMode = 'key';
        } else {
            $this->error('Nicio metodă de autentificare disponibilă. Adaugă google-sa.json sau GOOGLE_CUSTOM_SEARCH_KEY.');
            return self::FAILURE;
        }

        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;
        $restart    = (bool) $this->option('restart');
        $onlyEmpty  = (bool) $this->option('only-empty');
        $numResults = max(1, min(10, (int) $this->option('results')));

        $this->info("Google Custom Search — imagini produse (cx: {$cx}, results: {$numResults}/căutare)");

        $query = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->select('id', 'name');

        if ($onlyEmpty) {
            $query->where(fn ($q) => $q
                ->whereNull('main_image_url')
                ->orWhere('main_image_url', '')
            );
        }

        if (! $restart) {
            $query->whereNotExists(fn ($sub) => $sub
                ->from('product_image_candidates')
                ->whereColumn('product_image_candidates.woo_product_id', 'woo_products.id')
                ->where('source', 'google')
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
        $this->line("Cost estimat: ~\$" . number_format(($total / 1000) * 5, 2) . " (5$/1000 căutări)");

        // Test autentificare înainte de a începe
        try {
            $token = $authMode === 'sa' ? $this->getBearerToken() : null;
        } catch (\Throwable $e) {
            $this->error('Eroare autentificare: ' . $e->getMessage());
            return self::FAILURE;
        }

        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        foreach ($products as $product) {
            $processed++;
            $searchQuery = $this->buildQuery($product->name);

            $this->info("[{$processed}/{$total}] #{$product->id}: \"{$product->name}\"");

            try {
                if ($authMode === 'sa') {
                    $candidates = $this->searchGoogleWithBearer($cx, $searchQuery, $numResults);
                } else {
                    $candidates = $this->searchGoogleWithKey($apiKey, $cx, $searchQuery, $numResults);
                }

                if (empty($candidates)) {
                    $this->warn('  Niciun rezultat.');
                    $failed++;
                } else {
                    $inserted = $this->saveCandidates($product->id, $searchQuery, $candidates);
                    $this->line("  Salvați {$inserted} candidați.");
                    $succeeded++;
                }
            } catch (\Throwable $e) {
                $this->warn('  Eroare: ' . $e->getMessage());
                Log::warning("SearchProductImagesGoogle #{$product->id}: " . $e->getMessage());
                $failed++;

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rateLimitExceeded')) {
                    $this->warn('  Rate limit — aștept 60s...');
                    sleep(60);
                }
            }

            if ($processed < $total) {
                usleep(300_000); // 0.3s între cereri
            }
        }

        $this->newLine();
        $this->info("Gata. Procesate: {$processed} | Găsite: {$succeeded} | Eșuate: {$failed}");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Service Account JWT authentication
    // -------------------------------------------------------------------------

    /**
     * Returnează un Bearer token valid, re-obținând dacă a expirat.
     */
    private function getBearerToken(): string
    {
        if ($this->bearerToken && time() < $this->tokenExpiresAt - 60) {
            return $this->bearerToken;
        }

        $saPath = storage_path('app/google-sa.json');
        $sa     = json_decode(file_get_contents($saPath), true);

        if (empty($sa['private_key']) || empty($sa['client_email'])) {
            throw new \RuntimeException('google-sa.json invalid: lipsesc private_key sau client_email');
        }

        $now = time();
        $jwt = $this->buildJwt($sa['client_email'], $sa['private_key'], $now);
        [$token, $expiresIn] = $this->exchangeJwtForToken($jwt);

        $this->bearerToken    = $token;
        $this->tokenExpiresAt = $now + $expiresIn;

        return $this->bearerToken;
    }

    /**
     * Construiește și semnează un JWT RS256 pentru Google OAuth2.
     */
    private function buildJwt(string $clientEmail, string $privateKey, int $now): string
    {
        $header  = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'iss'   => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/cse',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $unsigned = "{$header}.{$payload}";

        $pkey = openssl_pkey_get_private($privateKey);
        if (! $pkey) {
            throw new \RuntimeException('Nu pot încărca cheia privată din service account');
        }

        $signature = '';
        if (! openssl_sign($unsigned, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Eșec semnare JWT: ' . openssl_error_string());
        }

        return "{$unsigned}." . $this->base64UrlEncode($signature);
    }

    /**
     * Schimbă JWT-ul pe un access_token la token endpoint-ul Google.
     * Returnează [token, expires_in].
     */
    private function exchangeJwtForToken(string $jwt): array
    {
        $postData = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($body, true) ?? [];

        if ($status !== 200 || empty($data['access_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? "HTTP {$status}";
            throw new \RuntimeException("JWT token exchange eșuat: {$err}");
        }

        return [$data['access_token'], (int) ($data['expires_in'] ?? 3600)];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // -------------------------------------------------------------------------
    // Search methods
    // -------------------------------------------------------------------------

    private function searchGoogleWithBearer(string $cx, string $query, int $num): array
    {
        $token = $this->getBearerToken();

        $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
            'cx'         => $cx,
            'q'          => $query,
            'searchType' => 'image',
            'num'        => $num,
            'imgSize'    => 'medium',
            'safe'       => 'active',
            'gl'         => 'ro',
            'hl'         => 'ro',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                "Authorization: Bearer {$token}",
            ],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            $error = json_decode($body, true)['error']['message'] ?? "HTTP {$status}";
            throw new \RuntimeException("Google API error: {$error}");
        }

        return $this->parseResults(json_decode($body, true));
    }

    private function searchGoogleWithKey(string $apiKey, string $cx, string $query, int $num): array
    {
        $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
            'key'        => $apiKey,
            'cx'         => $cx,
            'q'          => $query,
            'searchType' => 'image',
            'num'        => $num,
            'imgSize'    => 'medium',
            'safe'       => 'active',
            'gl'         => 'ro',
            'hl'         => 'ro',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            $error = json_decode($body, true)['error']['message'] ?? "HTTP {$status}";
            throw new \RuntimeException("Google API error: {$error}");
        }

        return $this->parseResults(json_decode($body, true));
    }

    private function parseResults(?array $data): array
    {
        $items = $data['items'] ?? [];

        $candidates = [];

        foreach ($items as $item) {
            $imageUrl = $item['link'] ?? null;
            $pageUrl  = $item['image']['contextLink'] ?? null;
            $title    = $item['title'] ?? '';
            $width    = $item['image']['width'] ?? null;
            $height   = $item['image']['height'] ?? null;

            if (empty($imageUrl)) {
                continue;
            }

            $domain = parse_url($imageUrl, PHP_URL_HOST) ?? '';
            if ($this->isWatermarkDomain($domain)) {
                continue;
            }

            $score = 5;
            if ($width && $height) {
                $px = $width * $height;
                if ($px >= 200000) $score = 9;
                elseif ($px >= 90000) $score = 8;
                elseif ($px >= 40000) $score = 7;
                elseif ($px >= 10000) $score = 6;
            }

            foreach (['emag', 'dedeman', 'leroy', 'praktiker', 'hornbach', 'bricodepot', 'altex', 'cel.ro'] as $shop) {
                if (str_contains($domain, $shop)) {
                    $score = min(10, $score + 1);
                    break;
                }
            }

            $candidates[] = [
                'image_url'     => $imageUrl,
                'thumbnail_url' => $item['image']['thumbnailLink'] ?? null,
                'page_url'      => $pageUrl,
                'title'         => mb_substr($title, 0, 255),
                'width'         => $width,
                'height'        => $height,
                'score'         => $score,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, 5);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildQuery(string $productName): string
    {
        $name = preg_replace('/\b\d+[\dxX*.,]+(?:mm|cm|m|kg|w|v|a|l|ml)\b/i', '', $productName);
        $name = preg_replace('/\s+/', ' ', trim($name));

        return $name ?: $productName;
    }

    private function saveCandidates(int $productId, string $query, array $candidates): int
    {
        $now     = now();
        $inserts = [];

        foreach ($candidates as $c) {
            $inserts[] = [
                'woo_product_id'  => $productId,
                'image_url'       => $c['image_url'],
                'thumbnail_url'   => $c['thumbnail_url'] ?? null,
                'source_page_url' => $c['page_url'] ?? null,
                'image_title'     => mb_substr($c['title'] ?? '', 0, 255),
                'width'           => $c['width'] ?? null,
                'height'          => $c['height'] ?? null,
                'search_query'    => mb_substr($query, 0, 255),
                'source'          => 'google',
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
            if (str_contains($domain, $bad)) {
                return true;
            }
        }
        return false;
    }
}
