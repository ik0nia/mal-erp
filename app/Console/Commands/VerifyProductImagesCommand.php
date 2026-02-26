<?php

namespace App\Console\Commands;

use Anthropic\Client as AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifică cu Claude Vision dacă imaginea curentă a unui produs corespunde
 * cu denumirea produsului. Dacă nu corespunde:
 *  - șterge main_image_url de pe produs
 *  - marchează candidatul ca 'rejected'
 *  - produsul intră din nou în coada de search/evaluare
 */
class VerifyProductImagesCommand extends Command
{
    protected $signature = 'products:verify-images
                            {--limit=50  : Câte produse să verifice per rulare}
                            {--dry-run   : Afișează problemele fără să aplice schimbările}';

    protected $description = 'Verifică cu Claude Vision dacă imaginile aprobate corespund produselor';

    private AnthropicClient $claude;
    private string $model;

    // Scor minim pentru a păstra imaginea (0-10)
    private const MIN_ACCEPTABLE_SCORE = 4;

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY nu este setat în .env');
            return self::FAILURE;
        }

        $this->claude = new AnthropicClient(apiKey: $apiKey);
        $this->model  = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
        $limit        = (int) $this->option('limit');
        $dryRun       = (bool) $this->option('dry-run');

        $this->info("Verificare imagini — model: {$this->model}, limit: {$limit}" . ($dryRun ? ' [DRY RUN]' : ''));

        // Produse cu imagine aprobată care nu au fost verificate recent
        $products = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->whereNotNull('main_image_url')
            ->where('main_image_url', '!=', '')
            ->select('id', 'name', 'main_image_url')
            ->limit($limit)
            ->get();

        $total   = $products->count();
        $kept    = 0;
        $rejected = 0;
        $errors  = 0;

        $this->info("Produse de verificat: {$total}");

        foreach ($products as $product) {
            $this->line("  #{$product->id} {$product->name}");

            try {
                $score = $this->scoreImage($product->name, $product->main_image_url);

                if ($score >= self::MIN_ACCEPTABLE_SCORE) {
                    $this->line("    ✓ Score {$score}/10 — OK");
                    $kept++;
                } else {
                    $this->warn("    ✗ Score {$score}/10 — IMAGINE NEPOTRIVITĂ, se respinge");
                    $rejected++;

                    if (! $dryRun) {
                        // Marchează candidatul ca rejected
                        DB::table('product_image_candidates')
                            ->where('woo_product_id', $product->id)
                            ->where('image_url', $product->main_image_url)
                            ->update(['status' => 'rejected', 'updated_at' => now()]);

                        // Șterge imaginea de pe produs — va fi re-căutată/evaluată
                        DB::table('woo_products')
                            ->where('id', $product->id)
                            ->update(['main_image_url' => null, 'updated_at' => now()]);
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("    ! Eroare: " . $e->getMessage());
                Log::warning("VerifyProductImages #{$product->id}: " . $e->getMessage());
                $errors++;
            }

            usleep(300_000); // 0.3s între produse
        }

        $this->newLine();
        $this->info("Gata. Păstrate: {$kept} | Respinse: {$rejected} | Erori: {$errors}" . ($dryRun ? ' [DRY RUN]' : ''));

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function scoreImage(string $productName, string $imageUrl): int
    {
        $imageData = $this->fetchImageAsBase64($imageUrl);

        if ($imageData === null) {
            return 0; // Nu poate fi descărcată — respinge
        }

        $message = $this->claude->messages->create(
            maxTokens: 100,
            messages: [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $imageData['mime'],
                            'data'       => $imageData['data'],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => "Produs: \"{$productName}\"\n\nCât de bine corespunde această imagine produsului de mai sus?\n\nRăspunde DOAR cu un număr întreg de la 0 la 10:\n- 0-3: imagine complet greșită sau watermark / logo / text / stoc inutilizabil\n- 4-6: imagine parțial potrivită (produs similar dar nu exact)\n- 7-10: imagine corectă și clară a produsului\n\nRăspunde cu un singur număr.",
                    ],
                ],
            ]],
            model: $this->model,
        );

        $text = '';
        foreach ($message->content as $block) {
            if (isset($block->text)) $text .= $block->text;
        }

        if (preg_match('/\b(\d+)\b/', trim($text), $m)) {
            return min(10, max(0, (int) $m[1]));
        }

        return 5; // Scor neutru dacă nu poate parsa
    }

    private function fetchImageAsBase64(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ERP-ImageVerifier/1.0)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || empty($body) || strlen($body) < 1000) {
            return null;
        }

        if (strlen($body) > 3_000_000) {
            return null; // Prea mare
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($body);

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return null;
        }

        return ['data' => base64_encode($body), 'mime' => $mime];
    }
}
