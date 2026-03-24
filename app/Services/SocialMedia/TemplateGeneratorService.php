<?php

namespace App\Services\SocialMedia;

use App\Models\AiUsageLog;
use App\Models\AppSetting;
use App\Models\GraphicTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Template generation pipeline — four passes:
 *
 * STEP A — Claude detects semantic patterns AND visual style signals
 *   Claude is good at: "this is a product-right layout; title feels large and extrabold;
 *                       product occupies ~60% of the layout; footer is strong and thick"
 *   Claude is bad at: exact pixel/percentage coordinates → we never ask for those
 *
 * STEP B — Backend applies pre-designed layout recipes
 *   Each composition family has a hand-crafted recipe with mathematically safe positions.
 *   Recipe elements are filtered based on which optional roles Claude detected.
 *
 * STEP C — Style refinement pass (NEW)
 *   Adjusts element proportions and sizes to match inferred visual style signals:
 *   logo size, product dominance, footer prominence, title scale, spacing feel.
 *
 * STEP D — Layout normalization pass
 *   Hard constraints enforced last: footer pinned, safe margins, overlap prevention.
 *   Always runs after style refinement to guarantee structural safety.
 */
class TemplateGeneratorService
{
    private const CANVAS_SIZE = 1080;

    private static function model(): string
    {
        return config('app.malinco.ai.models.sonnet', 'claude-sonnet-4-6');
    }
    private const MAX_IMAGES  = 8;

    // ─── Default style profile (Malinco brand baseline) ──────────────────────
    // Used when Claude does not provide a specific signal or when a value is invalid.
    // These defaults reflect actual Malinco visual style: bold, product-forward, strong footer.

    private const DEFAULT_STYLE_PROFILE = [
        'title_size'        => 'large',      // big, attention-grabbing title
        'title_weight'      => 'extrabold',   // Montserrat 800+
        'logo_size'         => 'medium',      // prominent but not oversized
        'product_dominance' => 'high',        // product image is the hero
        'footer_prominence' => 'strong',      // thick solid color bar
        'spacing_feel'      => 'balanced',    // good breathing room without excess
        'accent_style'      => 'moderate',    // some panels/separators, not overwhelming
    ];

    // ─── Role metadata ────────────────────────────────────────────────────────

    private const ROLE_ZINDEX = [
        'background_shape' => 0,
        'diagonal_panel'   => 0,   // decorative — behind everything
        'background_split' => 0,   // decorative — second color zone
        'color_accent_bar' => 1,   // decorative — horizontal/vertical band
        'footer_bar'       => 1,
        'separator'        => 2,
        'color_block'      => 2,
        'highlight_panel'  => 3,
        'product_image'    => 4,
        'brand_logo'       => 5,
        'partner_logo'     => 5,
        'badge'            => 6,
        'title'            => 7,
        'subtitle'         => 7,
        'simple_text'      => 7,
        'contact_text'     => 7,
        'bullet_list'      => 8,
        'CTA_text'         => 9,
        'cta_button'       => 10,
    ];

    private const ROLE_EDITABLE = [
        'background_shape' => false,
        'diagonal_panel'   => false,
        'background_split' => false,
        'color_accent_bar' => false,
        'footer_bar'       => false,
        'separator'        => false,
        'highlight_panel'  => true,
        'color_block'      => true,
        'brand_logo'       => true,
        'partner_logo'     => true,
        'product_image'    => true,
        'badge'            => true,
        'title'            => true,
        'subtitle'         => true,
        'simple_text'      => true,
        'contact_text'     => true,
        'bullet_list'      => true,
        'CTA_text'         => true,
        'cta_button'       => true,
    ];

    // Decorative roles that bypass structural constraints in normalizeLayout()
    private const DECORATIVE_ROLES = ['diagonal_panel', 'background_split', 'color_accent_bar'];

    private const AI_MAPPABLE_ROLES = [
        'title', 'subtitle', 'product_image', 'brand_logo', 'partner_logo',
        'badge', 'CTA_text', 'cta_button', 'simple_text', 'bullet_list', 'contact_text',
    ];

    private const ALWAYS_RECT_ROLES  = ['background_shape', 'footer_bar', 'separator', 'highlight_panel', 'color_block', 'badge'];
    private const ALWAYS_IMAGE_ROLES = ['product_image', 'brand_logo', 'partner_logo'];

    // ─── Layout Recipes ───────────────────────────────────────────────────────
    // All coordinates are percentages of canvas (0–100).
    // These are baseline positions — style refinement (Step C) adjusts them further.
    // 'req' = always included; optional elements included only when Claude detected them.
    // 'excl' = roles mutually exclusive with this element (keep first detected).

    private const RECIPES = [

        // ── Product right, text left ──────────────────────────────────────────
        'product_right_text_left' => [
            ['role' => 'background_shape', 'type' => 'rect',    'x' => 0,  'y' => 0,  'w' => 100, 'h' => 100, 'req' => true],
            ['role' => 'footer_bar',       'type' => 'rect',    'x' => 0,  'y' => 88, 'w' => 100, 'h' => 12,  'req' => true],
            ['role' => 'brand_logo',       'type' => 'image',   'x' => 5,  'y' => 4,  'w' => 22,  'h' => 9,   'req' => true],
            ['role' => 'product_image',    'type' => 'image',   'x' => 52, 'y' => 10, 'w' => 44,  'h' => 76,  'req' => true],
            ['role' => 'title',            'type' => 'textbox', 'x' => 5,  'y' => 18, 'w' => 44,  'h' => 24,  'req' => true,  'ph' => 'Titlu principal'],
            ['role' => 'contact_text',     'type' => 'textbox', 'x' => 5,  'y' => 90, 'w' => 68,  'h' => 7,   'req' => true,  'ph' => 'www.malinco.ro  |  0359 444 999'],
            ['role' => 'highlight_panel',  'type' => 'rect',    'x' => 0,  'y' => 14, 'w' => 52,  'h' => 72,  'req' => false],
            ['role' => 'partner_logo',     'type' => 'image',   'x' => 28, 'y' => 4,  'w' => 20,  'h' => 9,   'req' => false],
            ['role' => 'subtitle',         'type' => 'textbox', 'x' => 5,  'y' => 45, 'w' => 44,  'h' => 14,  'req' => false, 'ph' => 'Subtitlu produs', 'excl' => ['bullet_list']],
            ['role' => 'bullet_list',      'type' => 'textbox', 'x' => 5,  'y' => 45, 'w' => 44,  'h' => 24,  'req' => false, 'excl' => ['subtitle']],
            ['role' => 'badge',            'type' => 'rect',    'x' => 5,  'y' => 62, 'w' => 22,  'h' => 10,  'req' => false],
            ['role' => 'CTA_text',         'type' => 'textbox', 'x' => 5,  'y' => 74, 'w' => 38,  'h' => 8,   'req' => false, 'ph' => 'malinco.ro →'],
            ['role' => 'separator',        'type' => 'rect',    'x' => 0,  'y' => 87, 'w' => 100, 'h' => 1,   'req' => false],
        ],

        // ── Product left, text right ──────────────────────────────────────────
        'product_left_text_right' => [
            ['role' => 'background_shape', 'type' => 'rect',    'x' => 0,  'y' => 0,  'w' => 100, 'h' => 100, 'req' => true],
            ['role' => 'footer_bar',       'type' => 'rect',    'x' => 0,  'y' => 88, 'w' => 100, 'h' => 12,  'req' => true],
            ['role' => 'brand_logo',       'type' => 'image',   'x' => 73, 'y' => 4,  'w' => 22,  'h' => 9,   'req' => true],
            ['role' => 'product_image',    'type' => 'image',   'x' => 4,  'y' => 10, 'w' => 44,  'h' => 76,  'req' => true],
            ['role' => 'title',            'type' => 'textbox', 'x' => 52, 'y' => 18, 'w' => 44,  'h' => 24,  'req' => true,  'ph' => 'Titlu principal'],
            ['role' => 'contact_text',     'type' => 'textbox', 'x' => 5,  'y' => 90, 'w' => 68,  'h' => 7,   'req' => true,  'ph' => 'www.malinco.ro  |  0359 444 999'],
            ['role' => 'highlight_panel',  'type' => 'rect',    'x' => 48, 'y' => 14, 'w' => 52,  'h' => 72,  'req' => false],
            ['role' => 'partner_logo',     'type' => 'image',   'x' => 52, 'y' => 4,  'w' => 20,  'h' => 9,   'req' => false],
            ['role' => 'subtitle',         'type' => 'textbox', 'x' => 52, 'y' => 45, 'w' => 44,  'h' => 14,  'req' => false, 'ph' => 'Subtitlu produs', 'excl' => ['bullet_list']],
            ['role' => 'bullet_list',      'type' => 'textbox', 'x' => 52, 'y' => 45, 'w' => 44,  'h' => 24,  'req' => false, 'excl' => ['subtitle']],
            ['role' => 'badge',            'type' => 'rect',    'x' => 52, 'y' => 62, 'w' => 22,  'h' => 10,  'req' => false],
            ['role' => 'CTA_text',         'type' => 'textbox', 'x' => 52, 'y' => 74, 'w' => 38,  'h' => 8,   'req' => false, 'ph' => 'malinco.ro →'],
            ['role' => 'separator',        'type' => 'rect',    'x' => 0,  'y' => 87, 'w' => 100, 'h' => 1,   'req' => false],
        ],

        // ── Centered product ──────────────────────────────────────────────────
        'centered_product' => [
            ['role' => 'background_shape', 'type' => 'rect',    'x' => 0,  'y' => 0,  'w' => 100, 'h' => 100, 'req' => true],
            ['role' => 'footer_bar',       'type' => 'rect',    'x' => 0,  'y' => 88, 'w' => 100, 'h' => 12,  'req' => true],
            ['role' => 'brand_logo',       'type' => 'image',   'x' => 5,  'y' => 4,  'w' => 22,  'h' => 9,   'req' => true],
            ['role' => 'title',            'type' => 'textbox', 'x' => 5,  'y' => 16, 'w' => 90,  'h' => 20,  'req' => true,  'ph' => 'Titlu principal'],
            ['role' => 'product_image',    'type' => 'image',   'x' => 15, 'y' => 40, 'w' => 70,  'h' => 46,  'req' => true],
            ['role' => 'contact_text',     'type' => 'textbox', 'x' => 5,  'y' => 90, 'w' => 68,  'h' => 7,   'req' => true,  'ph' => 'www.malinco.ro  |  0359 444 999'],
            ['role' => 'highlight_panel',  'type' => 'rect',    'x' => 0,  'y' => 13, 'w' => 100, 'h' => 24,  'req' => false],
            ['role' => 'partner_logo',     'type' => 'image',   'x' => 73, 'y' => 4,  'w' => 22,  'h' => 9,   'req' => false],
            ['role' => 'subtitle',         'type' => 'textbox', 'x' => 5,  'y' => 37, 'w' => 60,  'h' => 8,   'req' => false, 'ph' => 'Subtitlu produs', 'excl' => ['bullet_list']],
            ['role' => 'bullet_list',      'type' => 'textbox', 'x' => 5,  'y' => 37, 'w' => 90,  'h' => 8,   'req' => false, 'excl' => ['subtitle']],
            ['role' => 'badge',            'type' => 'rect',    'x' => 15, 'y' => 40, 'w' => 22,  'h' => 10,  'req' => false],
            ['role' => 'CTA_text',         'type' => 'textbox', 'x' => 65, 'y' => 37, 'w' => 28,  'h' => 8,   'req' => false, 'ph' => 'malinco.ro →'],
            ['role' => 'separator',        'type' => 'rect',    'x' => 5,  'y' => 87, 'w' => 90,  'h' => 1,   'req' => false],
        ],

        // ── Partner campaign (dual brand) ─────────────────────────────────────
        'partner_campaign' => [
            ['role' => 'background_shape', 'type' => 'rect',    'x' => 0,  'y' => 0,  'w' => 100, 'h' => 100, 'req' => true],
            ['role' => 'footer_bar',       'type' => 'rect',    'x' => 0,  'y' => 88, 'w' => 100, 'h' => 12,  'req' => true],
            ['role' => 'brand_logo',       'type' => 'image',   'x' => 5,  'y' => 4,  'w' => 22,  'h' => 9,   'req' => true],
            ['role' => 'partner_logo',     'type' => 'image',   'x' => 73, 'y' => 4,  'w' => 22,  'h' => 9,   'req' => true],
            ['role' => 'separator',        'type' => 'rect',    'x' => 49, 'y' => 5,  'w' => 2,   'h' => 7,   'req' => true],
            ['role' => 'title',            'type' => 'textbox', 'x' => 5,  'y' => 18, 'w' => 90,  'h' => 20,  'req' => true,  'ph' => 'Titlu campanie'],
            ['role' => 'product_image',    'type' => 'image',   'x' => 15, 'y' => 40, 'w' => 70,  'h' => 44,  'req' => true],
            ['role' => 'contact_text',     'type' => 'textbox', 'x' => 5,  'y' => 90, 'w' => 68,  'h' => 7,   'req' => true,  'ph' => 'www.malinco.ro  |  0359 444 999'],
            ['role' => 'highlight_panel',  'type' => 'rect',    'x' => 0,  'y' => 13, 'w' => 100, 'h' => 26,  'req' => false],
            ['role' => 'subtitle',         'type' => 'textbox', 'x' => 5,  'y' => 40, 'w' => 90,  'h' => 8,   'req' => false, 'ph' => 'Subtitlu campanie'],
            ['role' => 'badge',            'type' => 'rect',    'x' => 15, 'y' => 40, 'w' => 22,  'h' => 10,  'req' => false],
            ['role' => 'CTA_text',         'type' => 'textbox', 'x' => 30, 'y' => 80, 'w' => 40,  'h' => 7,   'req' => false, 'ph' => 'malinco.ro →'],
        ],

        // ── Text announcement (no product) ────────────────────────────────────
        'text_announcement' => [
            ['role' => 'background_shape', 'type' => 'rect',    'x' => 0,  'y' => 0,  'w' => 100, 'h' => 100, 'req' => true],
            ['role' => 'footer_bar',       'type' => 'rect',    'x' => 0,  'y' => 88, 'w' => 100, 'h' => 12,  'req' => true],
            ['role' => 'brand_logo',       'type' => 'image',   'x' => 5,  'y' => 4,  'w' => 22,  'h' => 9,   'req' => true],
            ['role' => 'title',            'type' => 'textbox', 'x' => 8,  'y' => 22, 'w' => 84,  'h' => 30,  'req' => true,  'ph' => 'Titlu anunț'],
            ['role' => 'contact_text',     'type' => 'textbox', 'x' => 5,  'y' => 90, 'w' => 68,  'h' => 7,   'req' => true,  'ph' => 'www.malinco.ro  |  0359 444 999'],
            ['role' => 'highlight_panel',  'type' => 'rect',    'x' => 0,  'y' => 18, 'w' => 100, 'h' => 36,  'req' => false],
            ['role' => 'subtitle',         'type' => 'textbox', 'x' => 8,  'y' => 55, 'w' => 84,  'h' => 16,  'req' => false, 'ph' => 'Subtitlu anunț', 'excl' => ['bullet_list']],
            ['role' => 'bullet_list',      'type' => 'textbox', 'x' => 8,  'y' => 55, 'w' => 84,  'h' => 24,  'req' => false, 'excl' => ['subtitle']],
            ['role' => 'simple_text',      'type' => 'textbox', 'x' => 8,  'y' => 72, 'w' => 60,  'h' => 11,  'req' => false, 'ph' => 'Text informativ'],
            ['role' => 'CTA_text',         'type' => 'textbox', 'x' => 8,  'y' => 81, 'w' => 38,  'h' => 7,   'req' => false, 'ph' => 'malinco.ro →'],
            ['role' => 'partner_logo',     'type' => 'image',   'x' => 73, 'y' => 4,  'w' => 22,  'h' => 9,   'req' => false],
            ['role' => 'separator',        'type' => 'rect',    'x' => 8,  'y' => 53, 'w' => 84,  'h' => 1,   'req' => false],
        ],

        // ── Promo badge (prominent discount label) ────────────────────────────
        'promo_badge' => [
            ['role' => 'background_shape', 'type' => 'rect',    'x' => 0,  'y' => 0,  'w' => 100, 'h' => 100, 'req' => true],
            ['role' => 'footer_bar',       'type' => 'rect',    'x' => 0,  'y' => 88, 'w' => 100, 'h' => 12,  'req' => true],
            ['role' => 'brand_logo',       'type' => 'image',   'x' => 5,  'y' => 4,  'w' => 22,  'h' => 9,   'req' => true],
            ['role' => 'product_image',    'type' => 'image',   'x' => 52, 'y' => 10, 'w' => 44,  'h' => 76,  'req' => true],
            ['role' => 'badge',            'type' => 'rect',    'x' => 4,  'y' => 14, 'w' => 34,  'h' => 18,  'req' => true],
            ['role' => 'title',            'type' => 'textbox', 'x' => 5,  'y' => 36, 'w' => 44,  'h' => 22,  'req' => true,  'ph' => 'Titlu promo'],
            ['role' => 'contact_text',     'type' => 'textbox', 'x' => 5,  'y' => 90, 'w' => 68,  'h' => 7,   'req' => true,  'ph' => 'www.malinco.ro  |  0359 444 999'],
            ['role' => 'highlight_panel',  'type' => 'rect',    'x' => 0,  'y' => 12, 'w' => 52,  'h' => 74,  'req' => false],
            ['role' => 'partner_logo',     'type' => 'image',   'x' => 28, 'y' => 4,  'w' => 20,  'h' => 9,   'req' => false],
            ['role' => 'subtitle',         'type' => 'textbox', 'x' => 5,  'y' => 60, 'w' => 44,  'h' => 12,  'req' => false, 'ph' => 'Subtitlu promo', 'excl' => ['bullet_list']],
            ['role' => 'bullet_list',      'type' => 'textbox', 'x' => 5,  'y' => 60, 'w' => 44,  'h' => 20,  'req' => false, 'excl' => ['subtitle']],
            ['role' => 'CTA_text',         'type' => 'textbox', 'x' => 5,  'y' => 74, 'w' => 38,  'h' => 8,   'req' => false, 'ph' => 'malinco.ro →'],
            ['role' => 'separator',        'type' => 'rect',    'x' => 0,  'y' => 87, 'w' => 100, 'h' => 1,   'req' => false],
        ],
    ];

    // ─── Public entry point ───────────────────────────────────────────────────

    /**
     * @param  string[] $imagePaths  — căi relative în disk public
     * @return GraphicTemplate[]
     */
    public function analyzeAndGenerate(array $imagePaths): array
    {
        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY);
        if (blank($apiKey)) {
            throw new \RuntimeException('API key Anthropic lipsă. Configurează-l în Setări ERP.');
        }

        $imagePaths = array_slice($imagePaths, 0, self::MAX_IMAGES);
        if (empty($imagePaths)) {
            throw new \RuntimeException('Nicio imagine selectată.');
        }

        $content   = $this->buildImageContent($imagePaths);
        $content[] = ['type' => 'text', 'text' => $this->buildPrompt(count($imagePaths))];

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
            'model'      => self::model(),
            'max_tokens' => 4096,
            'messages'   => [['role' => 'user', 'content' => $content]],
        ]);

        if (! $response->successful()) {
            Log::error('TemplateGeneratorService: API error ' . $response->status() . ': ' . $response->body());
            throw new \RuntimeException('Eroare API Claude: ' . $response->status());
        }

        AiUsageLog::record(
            'template_generation',
            self::model(),
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            ['image_count' => count($imagePaths)]
        );

        $rawText  = $response->json('content.0.text', '');
        $patterns = $this->parseResponse($rawText);

        if (empty($patterns)) {
            throw new \RuntimeException('Niciun pattern detectat. Verifică imaginile și încearcă din nou.');
        }

        return $this->persistTemplates($patterns);
    }

    // ─── Image content ────────────────────────────────────────────────────────

    private function buildImageContent(array $paths): array
    {
        $content = [];
        foreach ($paths as $i => $path) {
            $data = Storage::disk('public')->get($path);
            if (! $data) {
                Log::warning("TemplateGeneratorService: Nu pot citi {$path}");
                continue;
            }
            $content[] = ['type' => 'text', 'text' => 'Reference image ' . ($i + 1) . ':'];
            $content[] = [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                        'png'  => 'image/png',
                        'webp' => 'image/webp',
                        default => 'image/jpeg',
                    },
                    'data' => base64_encode($data),
                ],
            ];
        }
        return $content;
    }

    // ─── Prompt ───────────────────────────────────────────────────────────────
    // Claude classifies structure, measures real zones, and detects decorative elements.
    // layout_overrides let Claude correct recipe defaults with what it actually sees.
    // decorative_elements capture diagonal panels, splits, accent bars — critical for fidelity.

    private function buildPrompt(int $count): string
    {
        return <<<PROMPT
You are a visual layout analyst. You are looking at {$count} social media graphic(s) made by Malinco, a Romanian building materials company.

YOUR MISSION: Reconstruct each layout as faithfully as possible from the reference images.
This is NOT categorization. This IS structural reconstruction.

For each distinct composition pattern you see, you must:
1. Identify the composition FAMILY
2. Measure the ACTUAL ZONES — where elements are and how large they are
3. Detect ALL DECORATIVE GRAPHIC ELEMENTS (diagonals, color panels, splits, overlays)
4. Extract BRAND COLORS
5. Report VISUAL STYLE signals

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 1 — COMPOSITION FAMILY (pick the best match):
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- product_right_text_left   → product photo occupies the right half; logo + title + text on left
- product_left_text_right   → product photo on left half; logo + title + text on right
- centered_product          → product centered; title above; optional text below
- partner_campaign          → two brand logos visible (Malinco + partner); product centered below
- text_announcement         → no product photo; large text-dominant composition
- promo_badge               → product photo with a prominent discount badge/promo label overlaid

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 2 — ZONE MEASUREMENTS (layout_overrides):
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
For key elements, estimate their position as a percentage of the 1080×1080 canvas:
  x = left edge position as % of canvas width (0 = far left, 100 = far right)
  y = top edge position as % of canvas height (0 = top, 100 = bottom)
  w = element width as % of canvas width
  h = element height as % of canvas height

Measure these elements if they differ meaningfully from the composition default:
- product_image: where is the product? How wide/tall is its zone?
- title: where does the title block start? How tall is its text zone?
- brand_logo: is the logo in a non-standard position or size?
- footer_bar: how tall is the footer bar? (report y and h)
- highlight_panel: if there is a colored panel behind text, estimate its zone

Only include an element in layout_overrides if its position or size clearly differs from the standard recipe for this composition family. Skip elements that look standard.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 3 — DECORATIVE GRAPHIC ELEMENTS (decorative_elements):
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
These are structural graphic elements that create the visual identity of the design beyond content zones. They are the MOST IMPORTANT differentiator between templates. Detect them with precision.

TYPE: diagonal_panel
  A large background rectangle rotated at an angle — creates a diagonal visual split.
  Example: a dark rotated panel covering the left 55% of the canvas at a 8-degree angle.
  Fields: x (%), y (%), w (%), h (%), angle (degrees — positive = clockwise tilt), color_role
  color_role: "primary" | "secondary" | "background" | or an exact hex like "#1f2a44"
  Note: x/y can be negative if the panel bleeds off-canvas edge intentionally.

TYPE: background_split
  The canvas is divided into two distinct flat color zones (no rotation).
  Example: left 45% is dark navy, right 55% is white.
  Fields: split_direction ("vertical" | "horizontal"), split_point (% where split occurs),
          left_color and right_color (hex) for vertical, or top_color and bottom_color for horizontal.

TYPE: color_accent_bar
  A horizontal or vertical band of solid color — NOT the footer. Often used as a header band,
  a mid-canvas separator stripe, or a color band behind the logo zone.
  Fields: x (%), y (%), w (%), h (%), color_role or hex.

TYPE: corner_overlay
  A triangular or rectangular color shape in one corner of the canvas.
  Fields: corner ("top_left" | "top_right" | "bottom_left" | "bottom_right"),
          size_pct (approximate size as % of canvas width), color_role or hex.

If none of these decorative elements are visible, return an empty array [].
If multiple decorative elements are present in a single design, report all of them.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 4 — OPTIONAL CONTENT ELEMENTS:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
List only those clearly visible in the image:
- subtitle        → secondary text block below the main title
- badge           → promo label / discount tag / "NOU" / "OFERTĂ" sticker
- partner_logo    → a second brand logo (not Malinco)
- highlight_panel → a colored semi-transparent rectangle behind a text block
- separator       → a thin horizontal or vertical line between zones
- CTA_text        → call-to-action line ("malinco.ro →", phone, website)
- bullet_list     → vertical list of product features or benefits
- simple_text     → an additional body text block

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 5 — VISUAL STYLE SIGNALS:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
title_size:        "large" (dominates) | "medium" | "small"
title_weight:      "extrabold" (Montserrat Black feel) | "bold" | "normal"
logo_size:         "large" (~12% canvas h) | "medium" (~9%) | "small" (~6%)
product_dominance: "high" (hero image) | "medium" | "low"
footer_prominence: "strong" (thick solid bar) | "medium" | "subtle"
spacing_feel:      "airy" | "balanced" | "dense"
accent_style:      "rich" (many accents) | "moderate" | "minimal"

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
GROUPING RULES:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- Group similar images into one pattern — generate 2–5 distinct patterns total
- If two images share the same composition family but have different decorative elements, they are DIFFERENT patterns
- Be precise about zone measurements — report what you actually see, not what feels "standard"

Return ONLY this JSON (no text before or after, no markdown code fences):
{
  "patterns": [
    {
      "composition": "product_right_text_left",
      "name": "Promo Produs — Produs dreapta",
      "optional_roles": ["subtitle", "CTA_text"],
      "layout_overrides": {
        "product_image": {"x": 54, "y": 8, "w": 43, "h": 78},
        "title": {"x": 5, "y": 17, "w": 46, "h": 28},
        "footer_bar": {"y": 87, "h": 13}
      },
      "decorative_elements": [
        {
          "type": "diagonal_panel",
          "x": -8, "y": 0, "w": 58, "h": 95,
          "angle": 7,
          "color_role": "secondary"
        }
      ],
      "colors": {
        "primary":    "#b11f3a",
        "secondary":  "#1f2a44",
        "background": "#f5f5f5",
        "text":       "#1f2a44"
      },
      "style": {
        "title_size":        "large",
        "title_weight":      "extrabold",
        "logo_size":         "medium",
        "product_dominance": "high",
        "footer_prominence": "strong",
        "spacing_feel":      "balanced",
        "accent_style":      "moderate"
      }
    }
  ]
}
PROMPT;
    }

    // ─── Response parsing ─────────────────────────────────────────────────────

    private function parseResponse(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $text, $m)) {
            $text = $m[1];
        }

        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($decoded['patterns'])) {
            Log::error('TemplateGeneratorService: JSON invalid: ' . substr($text, 0, 500));
            return [];
        }

        return $decoded['patterns'];
    }

    // ─── Persist templates ────────────────────────────────────────────────────

    /** @return GraphicTemplate[] */
    private function persistTemplates(array $patterns): array
    {
        $created = [];

        foreach ($patterns as $pattern) {
            $composition       = $pattern['composition']         ?? 'centered_product';
            $name              = $pattern['name']                ?? 'Template AI';
            $optionalRoles     = $pattern['optional_roles']      ?? [];
            $rawColors         = $pattern['colors']              ?? [];
            $rawStyle          = $pattern['style']               ?? [];
            $layoutOverrides   = $pattern['layout_overrides']    ?? [];
            $decorativeRaw     = $pattern['decorative_elements'] ?? [];

            // Normalize inputs
            $colors = $this->normalizeColors($rawColors);
            $style  = $this->normalizeStyleProfile($rawStyle);

            // STEP B: Apply recipe — filter by detected optional roles
            $elements = $this->applyRecipe($composition, $optionalRoles);

            // STEP B.5: Apply layout overrides — Claude's measured zone corrections
            $elements = $this->applyLayoutOverrides($elements, $layoutOverrides);

            // STEP B.6: Inject decorative elements — diagonal panels, splits, accent bars
            $decorative = $this->buildDecorativeElements($decorativeRaw, $colors);
            $elements   = array_merge($decorative, $elements);

            // STEP C: Style refinement — adjust proportions to match reference feel
            $elements = $this->refineTemplateStyle($elements, $style);

            // STEP D: Layout normalization — hard constraints, must run last
            $elements = $this->normalizeLayout($elements, $colors, $style);

            // Sort by z-index
            usort($elements, fn ($a, $b) =>
                (self::ROLE_ZINDEX[$a['role'] ?? ''] ?? 5) <=>
                (self::ROLE_ZINDEX[$b['role'] ?? ''] ?? 5)
            );

            $fabricJson = $this->buildFabricJson($elements, $colors, $style);

            $baseSlug = 'ai-' . Str::slug($composition);
            $slug     = $baseSlug;
            $n        = 2;
            while (GraphicTemplate::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $n++;
            }

            $template = GraphicTemplate::create([
                'name'   => $name,
                'slug'   => $slug,
                'layout' => 'product',
                'config' => [
                    'canvas_json'    => $fabricJson,
                    'canvas'         => ['width' => self::CANVAS_SIZE, 'height' => self::CANVAS_SIZE, 'preset' => 'square_post'],
                    'ai_generated'   => true,
                    'layout_family'  => $composition,
                    'primary_color'  => $colors['primary'],
                    'style_profile'  => $style,
                    'bottom_text'    => 'ASIGURĂM TRANSPORT ȘI DESCĂRCARE CU MACARA',
                    'bottom_subtext' => 'Sântandrei, Nr. 311  |  www.malinco.ro  |  0359 444 999',
                ],
                'is_active' => true,
            ]);

            $created[] = $template;
        }

        return $created;
    }

    // ─── STEP B: Apply recipe ─────────────────────────────────────────────────

    private function applyRecipe(string $composition, array $optionalRoles): array
    {
        $recipe = self::RECIPES[$composition] ?? self::RECIPES['centered_product'];

        // Build exclusion map
        $excludedRoles = [];
        foreach ($recipe as $slot) {
            if (empty($slot['excl'])) {
                continue;
            }
            $role     = $slot['role'];
            $included = $slot['req'] || in_array($role, $optionalRoles, true);
            if ($included) {
                foreach ($slot['excl'] as $rival) {
                    $excludedRoles[] = $rival;
                }
            }
        }

        $elements = [];
        foreach ($recipe as $slot) {
            $role     = $slot['role'];
            $included = $slot['req'] || in_array($role, $optionalRoles, true);

            if (! $included || in_array($role, $excludedRoles, true)) {
                continue;
            }

            $elements[] = [
                'role'        => $role,
                'type'        => $slot['type'],
                'x'           => $slot['x'],
                'y'           => $slot['y'],
                'w'           => $slot['w'],
                'h'           => $slot['h'],
                'placeholder' => $slot['ph'] ?? '',
            ];
        }

        return $elements;
    }

    // ─── STEP B.5: Apply layout overrides ────────────────────────────────────
    //
    // Claude's measured zone data overrides the recipe's hardcoded positions.
    // Only dimensions explicitly provided by Claude are changed — others stay as-is.

    private function applyLayoutOverrides(array $elements, array $overrides): array
    {
        if (empty($overrides)) {
            return $elements;
        }

        foreach ($elements as &$el) {
            $role = $el['role'];
            if (! isset($overrides[$role])) {
                continue;
            }
            $o = $overrides[$role];
            foreach (['x', 'y', 'w', 'h'] as $dim) {
                if (isset($o[$dim]) && is_numeric($o[$dim])) {
                    $el[$dim] = (int) round((float) $o[$dim]);
                }
            }
        }
        unset($el);

        return $elements;
    }

    // ─── STEP B.6: Build decorative elements ─────────────────────────────────
    //
    // Converts Claude's decorative_elements JSON into the internal element format.
    // These elements are prepended (low z-index) so they sit behind content.

    private function buildDecorativeElements(array $decorativeRaw, array $colors): array
    {
        $elements = [];

        foreach ($decorativeRaw as $dec) {
            $type = $dec['type'] ?? '';

            // Resolve color_role → actual hex
            $resolveColor = function (string $role) use ($colors): string {
                return match ($role) {
                    'primary'    => $colors['primary'],
                    'secondary'  => $colors['secondary'],
                    'background' => $colors['background'],
                    default      => preg_match('/^#[0-9a-fA-F]{6}$/', $role) ? $role : $colors['secondary'],
                };
            };

            switch ($type) {
                case 'diagonal_panel':
                    $colorRole = $dec['color_role'] ?? 'secondary';
                    $elements[] = [
                        'role'        => 'diagonal_panel',
                        'type'        => 'rect',
                        'x'           => (int) round((float) ($dec['x'] ?? 0)),
                        'y'           => (int) round((float) ($dec['y'] ?? 0)),
                        'w'           => (int) round((float) ($dec['w'] ?? 50)),
                        'h'           => (int) round((float) ($dec['h'] ?? 90)),
                        'angle'       => (float) ($dec['angle'] ?? 0),
                        'color'       => $resolveColor($colorRole),
                        'placeholder' => '',
                    ];
                    break;

                case 'background_split':
                    $dir   = $dec['split_direction'] ?? 'vertical';
                    $split = (int) round((float) ($dec['split_point'] ?? 50));
                    if ($dir === 'vertical') {
                        $hex = $dec['left_color'] ?? $colors['secondary'];
                        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                            $hex = $colors['secondary'];
                        }
                        $elements[] = [
                            'role' => 'background_split', 'type' => 'rect',
                            'x' => 0, 'y' => 0, 'w' => $split, 'h' => 88,
                            'angle' => 0, 'color' => $hex, 'placeholder' => '',
                        ];
                    } else {
                        $hex = $dec['top_color'] ?? $colors['secondary'];
                        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                            $hex = $colors['secondary'];
                        }
                        $elements[] = [
                            'role' => 'background_split', 'type' => 'rect',
                            'x' => 0, 'y' => 0, 'w' => 100, 'h' => $split,
                            'angle' => 0, 'color' => $hex, 'placeholder' => '',
                        ];
                    }
                    break;

                case 'color_accent_bar':
                case 'text_highlight_band':
                    $colorRole = $dec['color_role'] ?? ($dec['hex'] ?? 'primary');
                    $elements[] = [
                        'role'        => 'color_accent_bar',
                        'type'        => 'rect',
                        'x'           => (int) round((float) ($dec['x'] ?? 0)),
                        'y'           => (int) round((float) ($dec['y'] ?? 0)),
                        'w'           => (int) round((float) ($dec['w'] ?? 100)),
                        'h'           => (int) round((float) ($dec['h'] ?? 10)),
                        'angle'       => 0,
                        'color'       => $resolveColor($colorRole),
                        'placeholder' => '',
                    ];
                    break;

                case 'corner_overlay':
                    $corner  = $dec['corner'] ?? 'top_left';
                    $sizePct = (int) round((float) ($dec['size_pct'] ?? 20));
                    $colorRole = $dec['color_role'] ?? ($dec['hex'] ?? 'primary');
                    [$cx, $cy] = match ($corner) {
                        'top_right'    => [100 - $sizePct, 0],
                        'bottom_left'  => [0, 100 - $sizePct],
                        'bottom_right' => [100 - $sizePct, 100 - $sizePct],
                        default        => [0, 0],  // top_left
                    };
                    $elements[] = [
                        'role'        => 'color_accent_bar',
                        'type'        => 'rect',
                        'x'           => $cx,
                        'y'           => $cy,
                        'w'           => $sizePct,
                        'h'           => $sizePct,
                        'angle'       => 0,
                        'color'       => $resolveColor($colorRole),
                        'placeholder' => '',
                    ];
                    break;
            }
        }

        return $elements;
    }

    // ─── STEP C: Style refinement ─────────────────────────────────────────────
    //
    // Adjusts element sizes and positions based on visual style signals inferred
    // from the reference images. This pass runs BEFORE normalization so that any
    // adjustments that push into unsafe territory are caught and fixed by normalize.

    private function refineTemplateStyle(array $elements, array $style): array
    {
        // Compute dynamic footer zone from style signal
        $footerH = match ($style['footer_prominence']) {
            'strong' => 13,
            'subtle' => 10,
            default  => 12,
        };
        $footerY = 100 - $footerH;

        // Logo dimensions from style signal
        $logoH = match ($style['logo_size']) {
            'large' => 11,
            'small' => 7,
            default => 9,
        };
        $logoW = (int) round($logoH * 2.75);  // typical logo landscape aspect

        // Product dominance multiplier
        $productMult = match ($style['product_dominance']) {
            'high' => 1.08,
            'low'  => 0.88,
            default => 1.0,
        };

        // Title height boost from style signal
        $titleBoost = match ($style['title_size']) {
            'large' => 5,
            'small' => -3,
            default => 0,
        };

        // Spacing offset for text blocks when composition feels "airy"
        $spacingPush = ($style['spacing_feel'] === 'airy') ? 3 : 0;

        foreach ($elements as &$el) {
            $role = $el['role'];

            // Decorative elements are placed exactly as Claude described — skip style adjustments
            if (in_array($role, self::DECORATIVE_ROLES, true)) {
                continue;
            }

            switch ($role) {
                case 'footer_bar':
                    // Stronger footer = thicker bar, pinned to bottom
                    $el['y'] = $footerY;
                    $el['h'] = $footerH;
                    break;

                case 'brand_logo':
                case 'partner_logo':
                    // Scale logo to match reference prominence
                    $el['h'] = $logoH;
                    $el['w'] = min($el['w'] + 4, $logoW);
                    // Keep logo anchored to same corner — only resize, don't reposition x
                    break;

                case 'product_image':
                    // Scale product image area based on dominance signal
                    $newH = (int) round($el['h'] * $productMult);
                    $newW = (int) round($el['w'] * $productMult);
                    // Cap: product cannot extend into footer and cannot exceed canvas
                    $el['h'] = min($newH, $footerY - $el['y'] - 2);
                    $el['w'] = min($newW, 100 - $el['x'] - 1);
                    $el['h'] = max(35, $el['h']);
                    $el['w'] = max(35, $el['w']);
                    break;

                case 'title':
                    // Larger title signal → taller text block (implies larger font)
                    $el['h'] = max(16, $el['h'] + $titleBoost);
                    if ($spacingPush > 0) {
                        $el['y'] = min($el['y'] + $spacingPush, $footerY - $el['h'] - 6);
                    }
                    break;

                case 'subtitle':
                case 'bullet_list':
                case 'simple_text':
                    if ($spacingPush > 0) {
                        $el['y'] = min($el['y'] + $spacingPush, $footerY - $el['h'] - 4);
                    }
                    break;

                case 'highlight_panel':
                    // More accents = slightly higher opacity handled in Fabric builder.
                    // Panel height tracks product_dominance: high → panel needs to cover wider area.
                    if ($style['product_dominance'] === 'high') {
                        $el['h'] = min($el['h'] + 4, $footerY - $el['y'] - 2);
                    }
                    break;

                case 'badge':
                    // Promo badge: slightly larger when accent style is "rich"
                    if ($style['accent_style'] === 'rich') {
                        $el['w'] = min($el['w'] + 4, 40);
                        $el['h'] = min($el['h'] + 2, 20);
                    }
                    break;
            }
        }
        unset($el);

        return $elements;
    }

    // ─── STEP D: Layout normalization ─────────────────────────────────────────
    //
    // Enforces hard constraints regardless of recipe or style output.
    // Always runs last in the position pipeline — guarantees structural safety.

    private function normalizeLayout(array $elements, array $colors, array $style): array
    {
        // Derive actual footer zone (matches style refinement output)
        $footerH = match ($style['footer_prominence']) {
            'strong' => 13,
            'subtle' => 10,
            default  => 12,
        };
        $footerY = 100 - $footerH;
        $safeX   = 4;   // minimum left/right margin (%)
        $safeTop = 3;   // minimum top margin (%)

        foreach ($elements as &$el) {
            $role = $el['role'];

            // Decorative elements are intentionally positioned by Claude — bypass normalization.
            // They may legitimately extend off-canvas (e.g. diagonal panels bleeding edges).
            if (in_array($role, self::DECORATIVE_ROLES, true)) {
                continue;
            }

            switch ($role) {
                case 'background_shape':
                    $el = array_merge($el, ['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100]);
                    break;

                case 'footer_bar':
                    $el = array_merge($el, ['x' => 0, 'y' => $footerY, 'w' => 100, 'h' => $footerH]);
                    break;

                case 'contact_text':
                    // Must sit inside footer zone with internal padding
                    $el['y'] = $footerY + 2;
                    $el['h'] = min($el['h'], $footerH - 4);
                    $el['x'] = max($safeX, $el['x']);
                    $el['w'] = min($el['w'], 92 - $el['x']);
                    break;

                case 'brand_logo':
                case 'partner_logo':
                    // Logo must stay in header zone
                    $el['y'] = max($safeTop, min($el['y'], 12));
                    $el['h'] = min($el['h'], 12);
                    $el['w'] = min($el['w'], 28);
                    break;

                case 'product_image':
                    // Must not extend into footer; maintain minimum 35% size
                    if ($el['y'] + $el['h'] > $footerY - 1) {
                        $el['h'] = $footerY - 1 - $el['y'];
                    }
                    $el['w'] = max(35, min($el['w'], 100 - $el['x']));
                    $el['h'] = max(35, $el['h']);
                    break;

                case 'title':
                    $el['x'] = max($safeX, $el['x']);
                    $el['w'] = max(35, min($el['w'], 96 - $el['x']));
                    if ($el['y'] + $el['h'] > $footerY - 6) {
                        $el['h'] = max(14, $footerY - 6 - $el['y']);
                    }
                    break;

                case 'highlight_panel':
                    if ($el['y'] + $el['h'] > $footerY - 1) {
                        $el['h'] = $footerY - 1 - $el['y'];
                    }
                    break;

                default:
                    if (! in_array($role, ['separator', 'footer_bar', 'background_shape', 'contact_text'], true)) {
                        $el['x'] = max($safeX, $el['x']);
                        $el['w'] = min($el['w'], 96 - $el['x']);
                        if ($el['y'] + $el['h'] > $footerY - 2) {
                            $el['h'] = max(4, $footerY - 2 - $el['y']);
                        }
                    }
                    break;
            }

            // Universal clamp
            $el['x'] = max(0, min(100, $el['x']));
            $el['y'] = max(0, min(100, $el['y']));
            $el['w'] = max(1, min(100 - $el['x'], $el['w']));
            $el['h'] = max(1, min(100 - $el['y'], $el['h']));
        }
        unset($el);

        return $elements;
    }

    // ─── Style profile helpers ────────────────────────────────────────────────

    /**
     * Validates and fills a raw style profile from Claude, applying brand defaults
     * for any missing or invalid values.
     */
    private function normalizeStyleProfile(array $raw): array
    {
        $valid = [
            'title_size'        => ['large', 'medium', 'small'],
            'title_weight'      => ['extrabold', 'bold', 'normal'],
            'logo_size'         => ['large', 'medium', 'small'],
            'product_dominance' => ['high', 'medium', 'low'],
            'footer_prominence' => ['strong', 'medium', 'subtle'],
            'spacing_feel'      => ['airy', 'balanced', 'dense'],
            'accent_style'      => ['rich', 'moderate', 'minimal'],
        ];

        $profile = [];
        foreach ($valid as $key => $allowed) {
            $value = $raw[$key] ?? null;
            $profile[$key] = in_array($value, $allowed, true)
                ? $value
                : self::DEFAULT_STYLE_PROFILE[$key];
        }

        return $profile;
    }

    // ─── Color helpers ────────────────────────────────────────────────────────

    private function normalizeColors(array $colors): array
    {
        $defaults = [
            'primary'    => '#b11f3a',
            'secondary'  => '#1f2a44',
            'background' => '#1f2a44',
            'text'       => '#ffffff',
        ];

        foreach ($defaults as $key => $fallback) {
            if (empty($colors[$key]) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $colors[$key])) {
                $colors[$key] = $fallback;
            }
        }

        $colors['text'] = $this->isColorDark($colors['background']) ? '#ffffff' : '#1f2a44';

        return $colors;
    }

    private function isColorDark(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return true;
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 < 0.5;
    }

    // ─── Fabric.js JSON builder ───────────────────────────────────────────────

    private function buildFabricJson(array $elements, array $colors, array $style): string
    {
        $cw      = self::CANVAS_SIZE;
        $ch      = self::CANVAS_SIZE;
        $objects = [];
        $zIndex  = 0;

        foreach ($elements as $el) {
            $role   = $el['role'] ?? '';
            $elType = $el['type'] ?? '';
            if (blank($role)) {
                continue;
            }

            // Enforce type overrides
            if (in_array($role, self::ALWAYS_RECT_ROLES, true)) {
                $elType = 'rect';
            } elseif (in_array($role, self::ALWAYS_IMAGE_ROLES, true)) {
                $elType = 'image';
            } elseif (blank($elType)) {
                $elType = 'textbox';
            }

            // Convert percentage → pixels (decorative elements may have negative coords — allow it)
            $x     = (int) round($el['x'] / 100 * $cw);
            $y     = (int) round($el['y'] / 100 * $ch);
            $w     = (int) round($el['w'] / 100 * $cw);
            $h     = (int) round($el['h'] / 100 * $ch);
            $ph    = $el['placeholder'] ?? '';
            $angle = (float) ($el['angle'] ?? 0);
            $colorOverride = $el['color'] ?? null;  // pre-resolved hex for decorative elements

            $w = max(40, $w);
            $h = max($elType === 'rect' ? 4 : 20, $h);

            $obj = $this->makeFabricObject($role, $elType, $x, $y, $w, $h, $ph, $colors, $style, $zIndex, $angle, $colorOverride);
            if ($obj !== null) {
                $objects[] = $obj;
                $zIndex++;
            }
        }

        return json_encode([
            'version'    => '5.3.1',
            'objects'    => $objects,
            'background' => $colors['background'],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function makeFabricObject(
        string $role, string $elType,
        int $x, int $y, int $w, int $h,
        string $placeholder, array $colors, array $style,
        int $zIndex,
        float $angle = 0.0,
        ?string $colorOverride = null
    ): ?array {
        $id             = (string) Str::uuid();
        $primaryColor   = $colors['primary'];
        $secondaryColor = $colors['secondary'];
        $textColor      = $colors['text'];

        // Derive per-object typography adjustments from style profile
        $titleSizeMult = match ($style['title_size'] ?? 'medium') {
            'large' => 1.18,
            'small' => 0.82,
            default => 1.0,
        };
        $titleWeight = match ($style['title_weight'] ?? 'bold') {
            'extrabold' => '800',
            'bold'      => 'bold',
            default     => 'normal',
        };
        $accentOpacity = match ($style['accent_style'] ?? 'moderate') {
            'rich'    => 0.20,
            'minimal' => 0.07,
            default   => 0.12,
        };
        $separatorOpacity = match ($style['accent_style'] ?? 'moderate') {
            'rich'    => 0.80,
            'minimal' => 0.25,
            default   => 0.50,
        };

        $meta = [
            'id'         => $id,
            'role'       => $this->editorRole($role),
            'name'       => $this->roleName($role),
            'editable'   => self::ROLE_EDITABLE[$role] ?? true,
            'aiMappable' => in_array($role, self::AI_MAPPABLE_ROLES, true),
            'aiRole'     => $role,
            'locked'     => false,
            'visible'    => true,
            'zIndex'     => $zIndex,
        ];

        $base = [
            'scaleX' => 1, 'scaleY' => 1, 'angle' => 0, 'opacity' => 1,
            'selectable' => true, 'evented' => true,
            'lockMovementX' => false, 'lockMovementY' => false,
            'lockRotation' => false, 'lockScalingX' => false, 'lockScalingY' => false,
        ];

        // ── IMAGE PLACEHOLDER ─────────────────────────────────────────────────
        if ($elType === 'image') {
            [$fill, $strokeW, $strokeColor] = match ($role) {
                'brand_logo'   => ['#eff6ff', 1, '#bfdbfe'],
                'partner_logo' => ['#f0fdf4', 1, '#bbf7d0'],
                default        => ['#f1f5f9', 2, '#94a3b8'],  // product: slightly warmer grey
            };
            return array_merge($base, [
                'type'            => 'rect',
                'left'            => $x, 'top' => $y,
                'width'           => $w, 'height' => $h,
                'fill'            => $fill,
                'stroke'          => $strokeColor,
                'strokeWidth'     => $strokeW,
                'strokeDashArray' => [16, 8],
                'rx' => 4, 'ry' => 4,
                'data'            => $meta,
            ]);
        }

        // ── TEXTBOX ───────────────────────────────────────────────────────────
        if ($elType === 'textbox') {
            $defaultText = match ($role) {
                'title'        => filled($placeholder) ? $placeholder : 'Titlu principal',
                'subtitle'     => filled($placeholder) ? $placeholder : 'Subtitlu / descriere scurtă',
                'contact_text' => filled($placeholder) ? $placeholder : 'www.malinco.ro  |  0359 444 999',
                'CTA_text'     => filled($placeholder) ? $placeholder : 'malinco.ro  →',
                'bullet_list'  => "• Beneficiu 1\n• Beneficiu 2\n• Beneficiu 3",
                default        => filled($placeholder) ? $placeholder : 'Text',
            };

            $fontFamily = match ($role) {
                'title', 'CTA_text', 'subtitle' => 'Montserrat',
                default                          => 'Open Sans',
            };

            $fontWeight = match ($role) {
                'title'    => $titleWeight,  // driven by style profile
                'CTA_text' => 'bold',
                'subtitle' => 'bold',
                default    => 'normal',
            };

            $textAlign  = $role === 'CTA_text' ? 'center' : 'left';

            // Line height: titles are tight, lists are spacious
            $lineHeight = match ($role) {
                'title'      => 1.15,
                'bullet_list'=> 1.65,
                'subtitle'   => 1.30,
                default      => 1.40,
            };

            // Letter spacing: titles benefit from slight tracking when extrabold
            $charSpacing = ($role === 'title' && ($style['title_weight'] ?? '') === 'extrabold') ? 25 : 0;

            $fontSize = match ($role) {
                'title'    => $this->fontSize($h, 0.38 * $titleSizeMult, 52, 130),
                'subtitle' => $this->fontSize($h, 0.32, 28, 68),
                'CTA_text' => $this->fontSize($h, 0.52, 26, 56),
                'contact_text' => $this->fontSize($h, 0.55, 18, 32),
                default    => $this->fontSize($h, 0.38, 20, 42),
            };

            // contact_text: always white (sits on colored footer)
            $fill = $role === 'contact_text' ? '#ffffff' : $textColor;

            if ($role === 'cta_button') {
                return $this->makeCtaButton($id, $meta, $x, $y, $w, $h, $placeholder, $primaryColor);
            }

            return array_merge($base, [
                'type'        => 'textbox',
                'left'        => $x, 'top' => $y, 'width' => $w,
                'text'        => $defaultText,
                'fontFamily'  => $fontFamily,
                'fontSize'    => $fontSize,
                'fontWeight'  => $fontWeight,
                'fill'        => $fill,
                'textAlign'   => $textAlign,
                'lineHeight'  => $lineHeight,
                'charSpacing' => $charSpacing,
                'data'        => $meta,
            ]);
        }

        // ── RECT / SHAPE ──────────────────────────────────────────────────────
        [$fill, $rx, $opacity] = match ($role) {
            'background_shape' => [$colors['background'], 0, 1.0],
            'footer_bar'       => [$primaryColor,         0, 1.0],
            'badge'            => [$primaryColor,         6, 1.0],
            'separator'        => [$primaryColor,         0, $separatorOpacity],
            'highlight_panel'  => [$secondaryColor,       4, $accentOpacity],
            'color_block'      => [$primaryColor,         0, 1.0],
            // Decorative elements always use their pre-resolved color
            'diagonal_panel',
            'background_split',
            'color_accent_bar'  => [$colorOverride ?? $secondaryColor, 0, 1.0],
            default             => [$secondaryColor, 0, 1.0],
        };

        // colorOverride supersedes the match result for any role when explicitly set
        if ($colorOverride !== null) {
            $fill = $colorOverride;
        }

        $obj = array_merge($base, [
            'type'    => 'rect',
            'left'    => $x, 'top' => $y,
            'width'   => $w, 'height' => $h,
            'fill'    => $fill,
            'rx'      => $rx, 'ry' => $rx,
            'opacity' => $opacity,
            'data'    => $meta,
        ]);

        // Apply rotation for decorative elements (diagonal panels, etc.)
        if ($angle !== 0.0) {
            $obj['angle'] = $angle;
            // Fabric.js rotates around the object center, so we shift origin to center
            $obj['originX'] = 'center';
            $obj['originY'] = 'center';
            // Recalculate left/top to be the center of the element
            $obj['left'] = $x + (int) round($w / 2);
            $obj['top']  = $y + (int) round($h / 2);
        }

        return $obj;
    }

    // ─── CTA Button (Fabric Group) ────────────────────────────────────────────

    private function makeCtaButton(string $id, array $meta, int $x, int $y, int $w, int $h, string $label, string $bgColor): array
    {
        $text     = filled($label) ? $label : 'Comandă acum';
        $fontSize = 32; $paddingH = 40; $paddingV = 20; $radius = 8;
        $btnW     = max(200, $w);
        $btnH     = $fontSize + $paddingV * 2;
        $textW    = $btnW - $paddingH * 2;

        return [
            'type' => 'group', 'left' => $x, 'top' => $y,
            'width' => $btnW, 'height' => $btnH,
            'scaleX' => 1, 'scaleY' => 1, 'angle' => 0, 'opacity' => 1,
            'objects' => [
                ['type' => 'rect', 'originX' => 'center', 'originY' => 'center', 'left' => 0, 'top' => 0,
                 'width' => $btnW, 'height' => $btnH, 'fill' => $bgColor, 'rx' => $radius, 'ry' => $radius,
                 'scaleX' => 1, 'scaleY' => 1, 'angle' => 0, 'opacity' => 1, 'selectable' => false, 'evented' => false],
                ['type' => 'textbox', 'originX' => 'center', 'originY' => 'center', 'left' => 0, 'top' => 0,
                 'width' => $textW, 'text' => $text, 'fontFamily' => 'Montserrat', 'fontSize' => $fontSize,
                 'fontWeight' => '800', 'fill' => '#ffffff', 'textAlign' => 'center', 'lineHeight' => 1.2,
                 'charSpacing' => 20,
                 'scaleX' => 1, 'scaleY' => 1, 'angle' => 0, 'opacity' => 1, 'selectable' => false, 'evented' => false],
            ],
            'selectable' => true, 'evented' => true,
            'lockMovementX' => false, 'lockMovementY' => false, 'lockRotation' => false,
            'lockScalingX' => true, 'lockScalingY' => true,
            'data' => array_merge($meta, ['ctaProps' => [
                'text' => $text, 'fontSize' => $fontSize, 'fontFamily' => 'Montserrat',
                'fontWeight' => '800', 'textColor' => '#ffffff', 'bgColor' => $bgColor,
                'paddingH' => $paddingH, 'paddingV' => $paddingV, 'borderRadius' => $radius,
            ]]),
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function fontSize(int $regionH, float $ratio, int $min, int $max): int
    {
        return max($min, min($max, (int) round($regionH * $ratio)));
    }

    private function editorRole(string $role): string
    {
        return match ($role) {
            'footer_bar', 'separator', 'highlight_panel', 'color_block',
            'diagonal_panel', 'background_split', 'color_accent_bar' => 'background_shape',
            'contact_text' => 'simple_text',
            'partner_logo' => 'brand_logo',
            default        => $role,
        };
    }

    private function roleName(string $role): string
    {
        return match ($role) {
            'background_shape' => 'Fundal',
            'diagonal_panel'   => 'Panou diagonal',
            'background_split' => 'Zonă culoare',
            'color_accent_bar' => 'Bandă accent',
            'footer_bar'       => 'Bară jos',
            'separator'        => 'Separator',
            'highlight_panel'  => 'Panou evidențiere',
            'color_block'      => 'Bloc culoare',
            'brand_logo'       => 'Logo Malinco',
            'partner_logo'     => 'Logo partener',
            'product_image'    => 'Imagine produs',
            'badge'            => 'Badge promo',
            'title'            => 'Titlu',
            'subtitle'         => 'Subtitlu',
            'simple_text'      => 'Text simplu',
            'contact_text'     => 'Contact',
            'bullet_list'      => 'Listă beneficii',
            'CTA_text'         => 'Text CTA',
            'cta_button'       => 'Buton CTA',
            default            => ucfirst(str_replace('_', ' ', $role)),
        };
    }
}
