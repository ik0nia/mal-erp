<?php

namespace App\Services\Social;

use App\Models\AppSetting;
use App\Models\SmPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generează un design Fabric.js JSON dinamic pentru fiecare postare.
 * Claude creează forme, gradiente, elemente grafice — fiecare postare e unică.
 * Renderer-ul Node.js înlocuiește slot-urile cu datele reale.
 */
class SocialDesignService
{
    public function generateCanvasJson(SmPost $post, array $copy): array
    {
        $type         = $post->type;
        $title        = data_get($copy, 'graphic_texts.title', '');
        $subtitle     = data_get($copy, 'graphic_texts.subtitle', '');
        $advantages   = data_get($copy, 'graphic_texts.advantages', []);
        $styleVariant = $copy['style_variant'] ?? null;
        $sourceName   = $post->source_name;

        Log::info('[Social] Generare design Fabric.js', ['post_id' => $post->id, 'style' => $styleVariant]);

        $prompt = $this->buildPrompt($type, $title, $subtitle, $advantages, $styleVariant, $sourceName);

        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY)
            ?? config('services.anthropic.api_key');

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 4000,
            'system'     => $this->systemPrompt(),
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $raw = $response->json('content.0.text', '');

        return $this->parseJson($raw);
    }

    private function systemPrompt(): string
    {
        return <<<SYSTEM
Ești un designer grafic expert în social media pentru Malinco, brand românesc de materiale de construcții.

Generezi Fabric.js JSON (canvas 1080x1080) cu design dinamic, professional și WOW.

REGULI STRICTE:
1. Răspunzi DOAR cu JSON valid, fără text înainte sau după
2. Canvas-ul are întotdeauna width:1080, height:1080
3. Zona de conținut: de la y=0 până la y=910 (jos: bara Malinco roșie 170px)
4. Bara jos roșie (#C41E3A) de la y=910 până la y=1080 — mereu prezentă
5. Logo-ul Malinco e în bara jos — NU îl adăugi tu în JSON
6. SLOT-URI obligatorii (type:rect cu data.slot_role):
   - "product_image": zona unde apare poza produsului (dreapta, mare)
   - "title": zona titlului (poți folosi și textbox cu text placeholder)
   - "subtitle": zona subtitlului
7. Fiecare postare trebuie să aibă un design UNIC și DINAMIC

ELEMENTE VIZUALE PE CARE LE POȚI FOLOSI:
- Rect-uri colorate cu opacity pentru fonduri și panele
- Cercuri mari parțial vizibile (overflow) pentru dinamism
- Linii diagonale ca accent grafic
- Gradient-uri simulate cu mai multe rect-uri suprapuse cu opacity scăzută
- Text decorativ mare în fundal (opacity 0.05-0.10) pentru textură
- Badge-uri rotunjite (rx/ry) pentru label-uri
- Forme geometrice: triunghiuri simulate din rect-uri rotite

CULORILE MALINCO:
- Roșu principal: #C41E3A
- Întunecat: #1A1A1A
- Alb: #FFFFFF
- Gri deschis: #F5F5F5
- Accente: poți folosi #8B1A2A (roșu mai întunecat), #E8E0D8 (bej cald)

FONTURI disponibile: "Montserrat" (titluri bold), "Open Sans" (corp text)

FORMAT obiect Fabric.js:
- Rect: {"type":"rect","left":X,"top":Y,"width":W,"height":H,"fill":"#COLOR","opacity":0.X,"rx":0,"ry":0,"angle":0,"selectable":true}
- Text: {"type":"textbox","left":X,"top":Y,"width":W,"text":"PLACEHOLDER","fontSize":N,"fontFamily":"Montserrat","fontWeight":"bold","fill":"#COLOR","opacity":1}
- Slot rect: {"type":"rect","left":X,"top":Y,"width":W,"height":H,"fill":"#F0F0F0","opacity":0.3,"data":{"slot_role":"product_image"}}
SYSTEM;
    }

    private function buildPrompt(string $type, string $title, string $subtitle, array $advantages, ?string $style, string $sourceName): string
    {
        $advList   = collect($advantages)->take(3)->implode(', ');
        $styleHint = $this->styleHint($style);

        return <<<PROMPT
Creează un design Fabric.js JSON 1080x1080 pentru o postare de tip "{$type}" despre "{$sourceName}".

CONTEXT IMPORTANT: Imaginea va avea un FUNDAL FOTOGRAFIC AI dramatic dedesubt (studio cinematic).
Canvas-ul tău se pune CA OVERLAY TRANSPARENT deasupra fundalului. NU adăuga fundal solid — lasă fundalul AI să transpară.

Date postare:
- Titlu: {$title}
- Subtitlu: {$subtitle}
- Avantaje: {$advList}
- Stil vizual: {$styleHint}

Structură OBLIGATORIE a obiectelor (în această ordine):

1. OVERLAY TEXT (stânga) — rect semi-transparent negru pentru lizibilitate text:
   {"type":"rect","left":0,"top":0,"width":520,"height":910,"fill":"#000000","opacity":0.55}
   + gradienturi fade pe marginea dreaptă (2-3 rect-uri cu opacity descrescătoare)

2. LINIE ACCENT roșie verticală stânga:
   {"type":"rect","left":0,"top":0,"width":5,"height":910,"fill":"#C41E3A"}

3. LABEL categorie mic sus stânga (charSpacing mare, roșu)

4. TITLU mare bold alb — slot_role "title" — fontSize 80-100px Montserrat Bold

5. SUBTITLU gri deschis — slot_role "subtitle"

6. Lista avantaje (3 textbox-uri + cercuri roșii ca bullet)

7. CTA button outline roșu

8. CARD ALB pentru produs (dreapta) — rect alb cu rx:6, opacity:0.95, aprox left:530 top:40 width:520 height:840
   URMAT IMEDIAT de slot product_image cu aceleași dimensiuni:
   {"type":"rect","left":530,"top":40,"width":520,"height":840,"fill":"transparent","data":{"slot_role":"product_image"}}

9. BARA ROȘIE jos — OBLIGATORIU:
   {"type":"rect","left":0,"top":910,"width":1080,"height":170,"fill":"#C41E3A"}

10. SLOT logo Malinco în bara roșie:
    {"type":"rect","left":310,"top":938,"width":460,"height":114,"fill":"transparent","data":{"slot_role":"malinco_logo"}}

Returnează DOAR JSON-ul Fabric.js valid, fără text suplimentar.
PROMPT;
    }

    private function styleHint(?string $style): string
    {
        return match ($style) {
            'minimal_light'  => 'curat și minimalist, fundal deschis, accente subtile',
            'split_layout'   => 'split diagonal dinamic, jumătate produs / jumătate text cu panel roșu',
            'dark_premium'   => 'întunecat premium cu spotlight pe produs, accente roșii luminoase',
            'bottom_band'    => 'fundal neutru, produs mare centrat, bandă puternică jos',
            'geometric'      => 'forme geometrice mari în fundal, cercuri și dreptunghiuri suprapuse',
            'editorial'      => 'layout editorial de revistă, grid lines subtile, tipografie oversize',
            default          => 'dinamic și modern, mixt de elemente geometrice și spațiu curat',
        };
    }

    private function parseJson(string $raw): array
    {
        // Extrage JSON din răspuns
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $raw, $m)) {
            $raw = $m[1];
        }

        $data = json_decode(trim($raw), true);

        if (! $data || ! isset($data['objects'])) {
            Log::warning('[Social] Design JSON invalid de la Claude', ['raw' => substr($raw, 0, 200)]);
            return $this->fallbackJson();
        }

        // Asigurăm că bara roșie jos există mereu
        $hasBar = collect($data['objects'])->contains(fn($o) =>
            ($o['data']['slot_role'] ?? '') === 'bottom_bar' ||
            (($o['fill'] ?? '') === '#C41E3A' && ($o['top'] ?? 0) >= 900)
        );

        if (! $hasBar) {
            $data['objects'][] = [
                'type' => 'rect', 'left' => 0, 'top' => 910,
                'width' => 1080, 'height' => 170,
                'fill' => '#C41E3A', 'opacity' => 1, 'selectable' => false,
            ];
        }

        return $data;
    }

    private function fallbackJson(): array
    {
        return [
            'version' => '6.0.0',
            'objects' => [
                ['type' => 'rect', 'left' => 0, 'top' => 0, 'width' => 1080, 'height' => 910, 'fill' => '#F8F7F5', 'selectable' => false],
                ['type' => 'rect', 'left' => 0, 'top' => 910, 'width' => 1080, 'height' => 170, 'fill' => '#C41E3A', 'selectable' => false],
                ['type' => 'rect', 'left' => 0, 'top' => 0, 'width' => 8, 'height' => 910, 'fill' => '#C41E3A', 'selectable' => false],
                ['type' => 'rect', 'left' => 500, 'top' => 80, 'width' => 520, 'height' => 750, 'fill' => '#F0F0F0', 'opacity' => 0.3, 'data' => ['slot_role' => 'product_image']],
                ['type' => 'textbox', 'left' => 50, 'top' => 200, 'width' => 400, 'text' => 'TITLU', 'fontSize' => 72, 'fontFamily' => 'Montserrat', 'fontWeight' => 'bold', 'fill' => '#1A1A1A', 'data' => ['slot_role' => 'title']],
                ['type' => 'textbox', 'left' => 50, 'top' => 350, 'width' => 400, 'text' => 'Subtitlu produs', 'fontSize' => 30, 'fontFamily' => 'Open Sans', 'fill' => '#555555', 'data' => ['slot_role' => 'subtitle']],
            ],
        ];
    }
}
