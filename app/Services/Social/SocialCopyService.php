<?php

namespace App\Services\Social;

use App\Models\AppSetting;
use App\Models\SmPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocialCopyService
{
    /**
     * Generează caption, hashtag-uri și textele pentru grafică.
     * Returnează array cu: caption, hashtags[], graphic_texts{}, image_prompt.
     */
    public function generate(SmPost $post): array
    {
        $context = $this->buildContext($post);

        Log::info('[Social] Generare copy Claude', ['post_id' => $post->id, 'type' => $post->type]);

        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY)
            ?? config('services.anthropic.api_key');

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 1500,
            'system'     => $this->systemPrompt(),
            'messages'   => [['role' => 'user', 'content' => $context]],
        ]);

        $raw = $response->json('content.0.text', '');

        return $this->parseResponse($raw);
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
Ești copywriter expert pentru rețelele sociale ale companiei Malinco, un importator și distribuitor de produse pentru construcții și amenajări interioare din România.

Regulile tale:
- Scrii EXCLUSIV în limba română corectă, cu diacritice corecte (ș ț ă î â)
- Tonul este profesionist dar cald, de business serios, nu robotic
- Eviti calchierile din engleză (nu "suntem excited", ci "suntem entuziasmați")
- Accentul cade întotdeauna pe calitatea și avantajele produsului, NICIODATĂ pe preț
- Prețul NU apare niciodată în grafică decât dacă e explicit cerut (și chiar și atunci — rar)
- Folosești hashtag-uri relevante în română și în domeniu
- Răspunzi DOAR cu JSON valid, fără text suplimentar

Culorile brandului Malinco: roșu #C41E3A, negru #1A1A1A, alb #FFFFFF, gri deschis #F5F5F5.
PROMPT;
    }

    private function buildContext(SmPost $post): string
    {
        $source = $post->sourceable;
        $type   = $post->type;

        $info = match ($type) {
            SmPost::TYPE_PRODUCT  => $this->productContext($source),
            SmPost::TYPE_CATEGORY => $this->categoryContext($source),
            SmPost::TYPE_BRAND    => $this->brandContext($source),
            default               => '',
        };

        $platforms = implode(' și ', $post->platforms ?? ['Facebook', 'Instagram']);

        return <<<PROMPT
Generează conținut pentru o postare de tip "{$type}" pe {$platforms}.

{$info}

Răspunde cu JSON în formatul următor:
{
  "caption": "textul postării (2-4 propoziții, natural, în română)",
  "hashtags": ["hashtag1", "hashtag2", ...],
  "graphic_texts": {
    "title": "titlu scurt și impactant pentru grafică (max 5 cuvinte, în română)",
    "subtitle": "subtitlu descriptiv (max 10 cuvinte, în română)",
    "cta": "îndemn la acțiune scurt în română (ex: Descoperă acum, Află mai mult)",
    "advantages": ["avantaj 1 în română", "avantaj 2 în română", "avantaj 3 în română"]
  },
  "style_variant": "alege unul din: minimal_light | split_layout | dark_premium | bottom_band | geometric | editorial — în funcție de caracterul produsului (ex: scule/utilaje → dark_premium sau split_layout; materiale de construcții → bottom_band sau minimal_light; adezivi/chimice → geometric sau editorial; branduri premium → dark_premium sau editorial)",
  "image_prompt": "prompt evocativ în engleză care descrie: cum arată produsul fizic (formă, culori, materiale), ce atmosferă vizuală vrei (ex: powerful tool on a workshop bench, sleek adhesive tube with industrial background), ce lighting (dramatic studio, soft diffused, sharp spotlight) — NU menționezi logo-ul Malinco, NU menționezi stilul grafic, NU menționezi text overlay — acestea le adăugăm noi. Fii specific și evocativ, nu generic."
}
PROMPT;
    }

    private function productContext($product): string
    {
        if (! $product) return 'Produs necunoscut.';

        $name  = $product->name ?? '—';
        $brand = $product->brand ?? '—';
        $desc  = data_get($product, 'data.description', '');
        $desc  = strip_tags($desc);
        $desc  = \Illuminate\Support\Str::limit($desc, 400);

        return <<<CTX
Produs: {$name}
Brand: {$brand}
Descriere: {$desc}

Creează o postare care evidențiază calitatea și beneficiile produsului. Nu menționa prețul.
CTX;
    }

    private function categoryContext($category): string
    {
        if (! $category) return 'Categorie necunoscută.';

        $name = $category->name ?? '—';

        return <<<CTX
Categorie de produse: {$name}

Creează o postare care prezintă această categorie de produse Malinco, evidențiind varietatea și calitatea gamei.
CTX;
    }

    private function brandContext($brand): string
    {
        if (! $brand) return 'Brand necunoscut.';

        $name = $brand->name ?? '—';
        $desc = $brand->description ?? '';
        $site = $brand->website_url ?? '';

        return <<<CTX
Brand partener: {$name}
Descriere: {$desc}
Website: {$site}

Creează o postare care prezintă parteneriatul Malinco cu brandul {$name}, subliniind calitatea și încrederea.
CTX;
    }

    private function parseResponse(string $raw): array
    {
        // Extrage JSON din răspuns (poate fi înconjurat de ```json ... ```)
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $raw, $m)) {
            $raw = $m[1];
        }

        $data = json_decode(trim($raw), true);

        if (! $data) {
            Log::warning('[Social] Claude a returnat JSON invalid', ['raw' => $raw]);
            return [
                'caption'       => '',
                'hashtags'      => [],
                'graphic_texts' => [],
                'image_prompt'  => '',
            ];
        }

        return [
            'caption'       => $data['caption'] ?? '',
            'hashtags'      => $data['hashtags'] ?? [],
            'graphic_texts' => $data['graphic_texts'] ?? [],
            'style_variant' => $data['style_variant'] ?? null,
            'image_prompt'  => $data['image_prompt'] ?? '',
        ];
    }
}
