<?php

namespace App\Services\SocialMedia;

use App\Models\AiUsageLog;
use App\Models\AppSetting;
use App\Models\SocialPost;
use App\Models\SocialStyleProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocialCaptionService
{
    private static function model(): string
    {
        return config('app.malinco.ai.models.sonnet', 'claude-sonnet-4-6');
    }

    private static function modelHaiku(): string
    {
        return config('app.malinco.ai.models.haiku', 'claude-haiku-4-5-20251001');
    }
    private const LOG_SOURCE = 'social_caption';

    /**
     * Generează caption, hashtag-uri și prompt Gemini pentru o postare.
     * Returnează ['caption' => string, 'hashtags' => string, 'image_prompt' => string]
     */
    public function generate(SocialPost $post, ?SocialStyleProfile $styleProfile = null): array
    {
        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY);

        if (blank($apiKey)) {
            throw new \RuntimeException('Cheia API Anthropic lipsește din setări.');
        }

        $prompt = $this->buildPrompt($post, $styleProfile);

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
            'model'      => self::model(),
            'max_tokens' => 1024,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Claude API error: ' . $response->json('error.message', $response->body()));
        }

        AiUsageLog::record(
            self::LOG_SOURCE,
            self::model(),
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            ['post_id' => $post->id]
        );

        $content = trim($response->json('content.0.text', ''));

        // Curăță eventual markdown ```json
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
            $content = $m[1];
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("SocialCaptionService: JSON invalid pentru post #{$post->id}: {$content}");
            throw new \RuntimeException('Claude a returnat un răspuns invalid.');
        }

        return [
            'caption'          => trim($decoded['caption'] ?? ''),
            'hashtags'         => '',
            'image_prompt'     => trim($decoded['image_prompt'] ?? ''),
            'graphic_title'    => trim($decoded['graphic_title'] ?? ''),
            'graphic_subtitle' => trim($decoded['graphic_subtitle'] ?? ''),
        ];
    }

    /**
     * Generează DOAR textele graficii (fără caption complet) — apel mic/ieftin.
     * Returnează ['graphic_title' => string, 'graphic_subtitle' => string]
     */
    public function generateGraphicTextsOnly(SocialPost $post): array
    {
        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY);
        if (blank($apiKey)) throw new \RuntimeException('Cheia API Anthropic lipsește.');

        $brandInfo = '';
        if ($post->brand) {
            $b = $post->brand;
            $brandInfo = "Brand: {$b->name}" . (filled($b->description) ? "\nDescriere: {$b->description}" : '');
        } elseif ($post->product) {
            $p = $post->product;
            $brandInfo = "Produs: {$p->name}" . (filled($p->short_description) ? "\nDescriere: {$p->short_description}" : '');
        }

        $type = match ($post->brief_type) {
            'brand'   => 'postare de brand',
            'promo'   => 'postare promoțională',
            'product' => 'postare produs',
            default   => 'postare generală',
        };

        $prompt = <<<PROMPT
Ești specialist social media pentru Malinco — firmă românească de materiale de construcții.

Tip postare: {$type}
{$brandInfo}
Brief: {$post->brief_direction}

Generează EXCLUSIV JSON (fără text în afară):
{
  "graphic_title": "MAX 4 CUVINTE IMPACTANTE MAJUSCULE — titlul principal al imaginii",
  "graphic_subtitle": "O frază de 40-55 caractere, concisă, cu beneficiu clar"
}

Reguli:
- graphic_title: maxim 4 cuvinte, majuscule, în română, cât mai catchy (ex: IZOLAȚIE PREMIUM, FORȚĂ ȘI DURABILITATE, CONSTRUIEȘTE INTELIGENT)
- INTERZIS în graphic_title: "DISPONIBIL", "ACUM", "NOU", "REDUCERE", "STOC" — titlul exprimă calitate sau valoare permanentă, nu disponibilitate temporară
- graphic_subtitle: complement natural al titlului, ton direct, fără prețuri
- Limba: română
PROMPT;

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(20)->post('https://api.anthropic.com/v1/messages', [
            'model'      => self::modelHaiku(),
            'max_tokens' => 150,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $content = trim($response->json('content.0.text', ''));
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) $content = $m[1];
        $decoded = json_decode($content, true);

        AiUsageLog::record(self::LOG_SOURCE, self::modelHaiku(),
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            ['post_id' => $post->id, 'type' => 'graphic_only']
        );

        return [
            'graphic_title'    => trim($decoded['graphic_title'] ?? ''),
            'graphic_subtitle' => trim($decoded['graphic_subtitle'] ?? ''),
        ];
    }

    private function buildPrompt(SocialPost $post, ?SocialStyleProfile $styleProfile): string
    {
        $brandContext = "Malinco este o firmă românească specializată în materiale de construcții și amenajări.";

        $typeContext = match ($post->brief_type) {
            'product' => "Aceasta este o postare despre un produs specific din catalog.",
            'brand'   => "Aceasta este o postare de brand — promovează imaginea și valorile companiei.",
            'promo'   => "Aceasta este o postare promoțională — prezintă o ofertă sau reducere.",
            'general' => "Aceasta este o postare generală despre activitatea companiei.",
            default   => '',
        };

        $productContext = '';
        if ($post->product) {
            $p = $post->product;
            $productContext = <<<TEXT

PRODUS:
- Nume: {$p->name}
- SKU: {$p->sku}
- Preț: {$p->price} RON
- Descriere scurtă: {$p->short_description}
TEXT;
        }

        $brandContext2 = '';
        if ($post->brand) {
            $b = $post->brand;
            $brandContext2 = <<<TEXT

BRAND PROMOVAT:
- Nume: {$b->name}
- Website: {$b->website_url}
- Descriere: {$b->description}
TEXT;
        }

        $styleContext = '';
        if ($styleProfile) {
            $styleContext = "\n\nSTILUL EXISTENT AL PAGINII (respectă-l):\n" . $styleProfile->toPromptContext();
        }

        $direction = $post->brief_direction;

        return <<<PROMPT
Ești un specialist în social media pentru o firmă de materiale de construcții din România.

{$brandContext}
{$typeContext}{$productContext}{$brandContext2}{$styleContext}

DIRECȚIA POSTĂRII:
{$direction}

Generează conținut pentru o postare Facebook. Returnează EXCLUSIV JSON valid (fără text înainte sau după):

{
  "caption": "TEXTUL COMPLET AL POSTĂRII — vezi structura exactă mai jos",
  "image_prompt": "Prompt detaliat în engleză pentru Gemini AI",
  "graphic_title": "TITLU SCURT IMPACTANT PENTRU GRAFICĂ — max 3-4 cuvinte, în română, MAJUSCULE",
  "graphic_subtitle": "Subtitlu grafică — max 55 caractere, o frază concisă care completează titlul"
}

━━━ STRUCTURA OBLIGATORIE A CAPTION-ULUI ━━━

Postarea trebuie să respecte EXACT această structură (fiecare element pe rând nou):

[Emoji] Titlu/hook atrăgător

Fraza introductivă sau contextul (1-2 propoziții).

✔️ Caracteristică / avantaj 1
✔️ Caracteristică / avantaj 2
✔️ Caracteristică / avantaj 3
(sau 👉 în loc de ✔️, după caz)

[Fraza de call-to-action — îndeamnă cititorul să acționeze]

Calitatea face diferența! Construiește inteligent cu Malinco! ✨

📍 Sântandrei, nr. 311, vis-a-vis de Primărie
🌐 www.malinco.ro
✉️ office@malinco.ro
☎️ 0359 444 999

La cerere asigurăm transport. Plata se poate face la livrare, cu cardul bancar sau cash.

━━━ REGULI GRAFICĂ (graphic_title + graphic_subtitle) ━━━
- graphic_title: maxim 3-4 cuvinte scurte, impactante, MAJUSCULE, în română
  - Pentru postări de brand: o sintagmă care surprinde esența brandului (ex: "CALITATE GERMANĂ", "IZOLAȚIE PREMIUM", "FORȚĂ ȘI DURABILITATE")
  - Pentru postări de produs: numele scurt al produsului sau categoria (ex: "CĂRĂMIDĂ CLINCHER", "OSB STRUCTURAT")
  - NU folosi numele brandului/produsului exact dacă poți formula ceva mai catchy
- graphic_subtitle: o frază de 40-55 caractere care completează titlul (nu repeta caption-ul)
  - Ton: direct, concis, cu beneficiu clar
  - Exemple: "Parteneri de încredere pentru construcții solide", "Rezistă decenii, arată impecabil"

━━━ REGULI STRICTE ━━━
- NU adăuga hashtag-uri (zero hashtag-uri)
- Adresa este ÎNTOTDEAUNA exact: "📍 Sântandrei, nr. 311, vis-a-vis de Primărie" — nu schimba nimic
- Telefonul este ÎNTOTDEAUNA: "☎️ 0359 444 999" — nu inventa alt număr
- Fiecare element din footer (📍 🌐 ✉️ ☎️) pe rând separat
- Nu include prețuri dacă nu sunt specificate în direcție
- Limba: română

━━━ REGULI IMAGINE (image_prompt) ━━━
- Scrie promptul în engleză
- Descrie DOAR subiectul/conținutul imaginii: ce produse, materiale sau scene să apară
- NU descrie stilul fotografic, iluminarea, fundalul sau compoziția — acestea vor fi preluate automat din stilul grafic existent al paginii
- NU adăuga text, logo sau litere în imagine
- Exemplu corect: "Austrotherm EPS insulation panels, white foam boards showing thickness and texture"
- Exemplu greșit: "Product photography of panels on neutral background with soft lighting"
PROMPT;
    }
}
