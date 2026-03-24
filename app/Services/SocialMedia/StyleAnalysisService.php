<?php

namespace App\Services\SocialMedia;

use App\Models\AiUsageLog;
use App\Models\AppSetting;
use App\Models\SocialAccount;
use App\Models\SocialStyleProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StyleAnalysisService
{
    private static function model(): string
    {
        return config('app.malinco.ai.models.sonnet', 'claude-sonnet-4-6');
    }
    private const LOG_SOURCE = 'social_style_analysis';

    /**
     * Analizează postările istorice și salvează un profil de stil.
     */
    public function analyzeAndSave(SocialAccount $account, array $posts): SocialStyleProfile
    {
        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY);

        if (blank($apiKey)) {
            throw new \RuntimeException('Cheia API Anthropic lipsește din setări.');
        }

        // Luăm maxim 50 postări cu text
        $postsWithText = array_filter($posts, fn ($p) => ! empty($p['message']));
        $sample        = array_slice(array_values($postsWithText), 0, 50);

        if (empty($sample)) {
            throw new \RuntimeException('Nu există postări cu text pentru analiză de stil.');
        }

        // Construim textul postărilor
        $postsText = '';
        foreach ($sample as $i => $p) {
            $postsText .= ($i + 1) . ". " . ($p['message'] ?? '(fără text)') . "\n---\n";
        }

        // Descărcăm maxim 10 imagini pentru analiza vizuală reală
        $imageUrls = collect($sample)
            ->filter(fn ($p) => ! empty($p['full_picture']))
            ->pluck('full_picture')
            ->take(10)
            ->values()
            ->all();

        $imageParts = $this->downloadImagesAsBase64($imageUrls);

        // Construim mesajul cu imagini reale pentru Claude Vision
        $contentParts = [];

        // Text introductiv
        $contentParts[] = [
            'type' => 'text',
            'text' => "Analizează postările de pe pagina Facebook \"{$account->name}\" (firmă de materiale de construcții din România).\n\nPOSTĂRI (text):\n{$postsText}\n\nMai jos sunt imaginile reale de pe pagina lor Facebook. Analizează-le cu atenție pentru a descrie stilul vizual autentic.",
        ];

        // Adăugăm imaginile
        foreach ($imageParts as $imgData) {
            $contentParts[] = $imgData;
        }

        // Cererea finală
        $contentParts[] = [
            'type' => 'text',
            'text' => "Pe baza textelor și imaginilor de mai sus, returnează EXCLUSIV JSON valid:\n{\n  \"tone\": \"Descriere detaliată a tonului: formal/informal, entuziast/neutru, tipul de limbaj folosit\",\n  \"vocabulary\": \"Cuvinte și expresii recurente, lungimea medie a textului, structura propozițiilor, emoji-uri folosite\",\n  \"hashtag_patterns\": \"Tipuri de hashtag-uri folosite, numărul mediu, categorii tematice\",\n  \"caption_structure\": \"Cum încep și se termină postările, dacă folosesc emoji-uri, cifre, întrebări, CTA, adresă/contact\",\n  \"visual_style\": \"Descrie DETALIAT stilul vizual REAL văzut în imagini: tip fotografie, paleta de culori dominantă, compoziție, atmosferă, dacă apar persoane/spații/produse, fundaluri, iluminare, stil grafic. Această descriere va fi folosită ca prompt pentru Gemini AI să genereze imagini similare.\"\n}",
        ];

        $prompt = $contentParts;

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
            'model'      => self::model(),
            'max_tokens' => 4096,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Claude API error: ' . $response->json('error.message', $response->body()));
        }

        AiUsageLog::record(
            self::LOG_SOURCE,
            self::model(),
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            ['account_id' => $account->id, 'posts_analyzed' => count($sample)]
        );

        $content = trim($response->json('content.0.text', ''));
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
            $content = $m[1];
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("StyleAnalysisService: JSON invalid pentru account #{$account->id}: {$content}");
            throw new \RuntimeException('Claude a returnat un răspuns invalid.');
        }

        // Dezactivăm profilul anterior
        $account->styleProfiles()->where('is_active', true)->update(['is_active' => false]);

        $profile = SocialStyleProfile::create([
            'social_account_id' => $account->id,
            'posts_analyzed'    => count($sample),
            'tone'              => $decoded['tone'] ?? null,
            'vocabulary'        => $decoded['vocabulary'] ?? null,
            'hashtag_patterns'  => $decoded['hashtag_patterns'] ?? null,
            'caption_structure' => $decoded['caption_structure'] ?? null,
            'visual_style'      => $decoded['visual_style'] ?? null,
            'raw_analysis'      => $content,
            'is_active'         => true,
            'generated_at'      => now(),
        ]);

        $account->update(['style_analyzed_at' => now()]);

        return $profile;
    }

    /**
     * Descarcă imagini de la URL-uri și le returnează ca parts pentru Claude Vision.
     */
    private function downloadImagesAsBase64(array $urls): array
    {
        $parts = [];

        foreach ($urls as $url) {
            try {
                $response = Http::timeout(15)->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $binary   = $response->body();
                $mimeType = $response->header('Content-Type') ?: 'image/jpeg';
                // Păstrăm doar tipul MIME de bază (fără parametri)
                $mimeType = strtok($mimeType, ';');

                if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                    $mimeType = 'image/jpeg';
                }

                $parts[] = [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $mimeType,
                        'data'       => base64_encode($binary),
                    ],
                ];
            } catch (\Throwable $e) {
                Log::warning("StyleAnalysisService: nu am putut descărca imaginea {$url}: " . $e->getMessage());
            }
        }

        return $parts;
    }
}
