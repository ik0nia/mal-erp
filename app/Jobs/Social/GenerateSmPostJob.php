<?php

namespace App\Jobs\Social;

use App\Models\SmPost;
use App\Services\Social\OpenAiImageService;
use App\Services\Social\SocialCopyService;
use App\Services\Social\SocialDesignService;
use App\Services\Social\SocialRendererService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateSmPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(public SmPost $post) {}

    public function handle(
        SocialCopyService    $copyService,
        SocialDesignService  $designService,
        SocialRendererService $rendererService,
        OpenAiImageService   $imageService,
    ): void {
        Log::info('[Social] Start generare postare', ['post_id' => $this->post->id]);

        $this->post->update(['status' => SmPost::STATUS_GENERATING, 'error_message' => null]);

        try {
            // 1. Generează copy (caption, hashtags, graphic_texts, style_variant, image_prompt)
            $copy = $copyService->generate($this->post);

            $this->post->update([
                'caption'       => $copy['caption'],
                'hashtags'      => $copy['hashtags'],
                'graphic_texts' => $copy['graphic_texts'],
                'image_prompt'  => $copy['image_prompt'],
            ]);

            // 2. gpt-image-2 generează DOAR fundalul (dramatic, atmospheric, cinematographic)
            $bgPrompt   = $this->buildBackgroundPrompt($copy);
            $bgPath     = $imageService->generateBackground($bgPrompt);

            // 3. Claude generează Fabric.js JSON (overlay text + elemente grafice peste fundal)
            $canvasJson = $designService->generateCanvasJson($this->post, $copy);

            // 4. Salvăm canvas_json pe post (pentru editor vizual ulterior)
            $this->post->update(['canvas_json' => $canvasJson]);

            // 5. Randăm: fundal AI + canvas_json overlay + poza reală produs
            $imagePath = $rendererService->render($this->post, $canvasJson, $bgPath, $copy['style_variant'] ?? null);

            $this->post->update([
                'image_path' => $imagePath,
                'status'     => SmPost::STATUS_READY,
            ]);

            Log::info('[Social] Postare generată cu succes', ['post_id' => $this->post->id]);

        } catch (\Throwable $e) {
            Log::error('[Social] Eroare generare postare', [
                'post_id' => $this->post->id,
                'error'   => $e->getMessage(),
            ]);

            $this->post->update([
                'status'        => SmPost::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Construiește promptul pentru fundalul AI — FĂRĂ produs, FĂRĂ text.
     * gpt-image-2 e excelent la atmosferă și iluminat.
     */
    private function buildBackgroundPrompt(array $copy): string
    {
        $style      = $copy['style_variant'] ?? 'dark_premium';
        $imageHint  = $copy['image_prompt']  ?? '';

        $styleDescriptions = [
            'minimal_light'  => 'Clean white studio photography backdrop, soft diffused lighting, very subtle warm shadows on the floor, minimalist and elegant. Pure empty scene.',
            'split_layout'   => 'Modern abstract background with dramatic lighting contrast. Left half deep charcoal dark, right half with a bright studio spotlight beam. Sharp tonal transition. Empty scene.',
            'dark_premium'   => 'Dark cinematic studio background. Deep charcoal textured walls and concrete floor, dramatic single-source spotlight creating a pool of light, deep red color accent glow from one side, smoke/haze atmosphere. Cinematic, premium, high-end. Empty scene.',
            'bottom_band'    => 'Clean professional photography background. Warm light gray gradient, soft even studio lighting, subtle floor reflection, very neutral and elegant. Empty scene.',
            'geometric'      => 'Abstract modern background with large geometric color blocks. Bold red and dark gray shapes, sharp edges, graphic design aesthetic, dramatic lighting. Empty scene.',
            'editorial'      => 'High-end editorial photography backdrop. Crisp white walls with subtle texture, precise sharp shadows, clean grid lines on the floor, luxury studio aesthetic. Empty scene.',
        ];

        $stylePrompt = $styleDescriptions[$style] ?? $styleDescriptions['dark_premium'];

        return "{$stylePrompt} {$imageHint} No products, no people, no text, no logos whatsoever. Square 1:1 format. Professional commercial photography quality.";
    }

    /**
     * Returnează URL-ul imaginii reale a sursei (dacă există).
     */
    private function getSourceImageUrl(): ?string
    {
        $source = $this->post->sourceable;
        if (! $source) return null;

        return match ($this->post->type) {
            'product'  => $source->main_image_url ?? null,
            'brand'    => $source->logo_url ?? null,
            default    => null,
        };
    }

    private function buildImagePrompt(array $copy): string
    {
        $basePrompt    = $copy['image_prompt'] ?? '';
        $title         = data_get($copy, 'graphic_texts.title', '');
        $subtitle      = data_get($copy, 'graphic_texts.subtitle', '');
        $cta           = data_get($copy, 'graphic_texts.cta', '');
        $advantages    = data_get($copy, 'graphic_texts.advantages', []);
        $styleVariant  = $copy['style_variant'] ?? null;

        $hasRealImage = (bool) $this->getSourceImageUrl();

        return $hasRealImage
            ? $this->buildEditPrompt($title, $subtitle, $cta, $advantages, $basePrompt, $styleVariant)
            : $this->buildGeneratePrompt($title, $subtitle, $cta, $advantages, $basePrompt, $styleVariant);
    }

    private function buildEditPrompt(string $title, string $subtitle, string $cta, array $advantages, string $base, ?string $styleVariant = null): string
    {
        $advLines = collect($advantages)->take(3)->map(fn ($a) => "· {$a}")->implode('  ');
        $style    = $this->resolveStyleVariant($styleVariant);

        $preserve = 'IMPORTANT: Keep the original product exactly as it is — do NOT modify packaging, labels, colors or shape of the product. The product is the hero. ';
        $text     = '';
        if ($title)    $text .= "Title: \"{$title}\". ";
        if ($subtitle) $text .= "Subtitle: \"{$subtitle}\". ";
        if ($advLines) $text .= "Advantages as bullet list: {$advLines}. ";
        if ($cta)      $text .= "CTA: \"{$cta}\". ";
        $footer = 'Square 1:1 format. Do NOT add any logo or branding watermark — branding is added separately outside the image. ';

        return $preserve . $style . $text . $footer . $base;
    }

    private function buildGeneratePrompt(string $title, string $subtitle, string $cta, array $advantages, string $base, ?string $styleVariant = null): string
    {
        $advLines = collect($advantages)->take(3)->map(fn ($a) => "· {$a}")->implode('  ');
        $style    = $this->resolveStyleVariant($styleVariant);

        $text = '';
        if ($title)    $text .= "Title: \"{$title}\". ";
        if ($subtitle) $text .= "Subtitle: \"{$subtitle}\". ";
        if ($advLines) $text .= "Advantages as bullet list: {$advLines}. ";
        if ($cta)      $text .= "CTA: \"{$cta}\". ";
        $footer = 'Square 1:1 format. Do NOT add any logo or branding watermark — branding is added separately outside the image. ';

        return $style . $text . $footer . $base;
    }

    /**
     * Rezolvă stilul vizual — folosește ce a ales Claude, cu fallback aleatoriu.
     */
    private function resolveStyleVariant(?string $key): string
    {
        $map = $this->styleVariantMap();
        if ($key && isset($map[$key])) {
            return $map[$key];
        }
        // fallback: aleatoriu dacă Claude nu a specificat
        return $map[array_rand($map)];
    }

    private function styleVariantMap(): array
    {
        return [
            'minimal_light' =>
                'Studio product photography feel. Pure white background, dramatic soft-box lighting that makes the product pop with subtle drop shadow. Malinco red #C41E3A used as a single bold horizontal accent stripe. Typography is large, confident, dark. Lots of breathing room. Think IKEA catalogue meets premium hardware brand. ',

            'split_layout' =>
                'Dynamic diagonal split composition. Left side: product hero shot on crisp white with dramatic lighting and long shadow. Right side: deep Malinco red #C41E3A with bold white typography and elegant bullet points. Sharp diagonal cut between the two halves creates energy and movement. Premium trade magazine quality. ',

            'dark_premium' =>
                'Cinematic dark studio shot. Very deep charcoal background (#111111), product lit with a sharp spotlight creating dramatic highlights and rich shadows. Malinco red #C41E3A glows as a thin neon-like accent line under the product. White typography floats cleanly. Feels like a BMW or Bosch campaign. ',

            'bottom_band' =>
                'Clean architectural layout. Soft warm white or very light gray background, product large and centered with professional lighting and realistic shadow beneath it. Bold Malinco red #C41E3A band occupies the bottom quarter with confident white text. Simple, powerful, premium. Like a professional product launch announcement. ',

            'geometric' =>
                'Modern graphic design poster. Product centered and sharp. Behind it: large overlapping geometric circles and rectangles in very subtle tones of red and gray creating depth layers. Malinco red #C41E3A pops on key text elements. Dynamic, contemporary, design-studio aesthetic. Feels crafted, not generic. ',

            'editorial' =>
                'High-end trade publication layout. Crisp white background. Product positioned with confidence, slightly angled for dynamism. Oversized bold typography in dark #1A1A1A with a single Malinco red #C41E3A word as accent. Clean thin rules and grid lines. Bullet points in elegant small caps. Feels like a feature in a premium B2B magazine. ',
        ];
    }

    /** @deprecated use resolveStyleVariant */
    private function pickStyleVariant(): string
    {
        $variants = [
            // 1. Luminos, minimalist
            'Clean minimal design. Very light background (#F5F5F5 or white), generous whitespace. Malinco red #C41E3A used only as thin accent line or small label. Dark #1A1A1A typography. Think premium B2B: confident, uncluttered, breathing. ',

            // 2. Split layout — jumătate produs / jumătate text
            'Split composition: product occupies left half on a soft light background, right half has a clean panel in Malinco red #C41E3A with white text overlays. Strong contrast between the two halves. Professional editorial feel. ',

            // 3. Dark premium — dar subtil, nu agresiv
            'Deep dark background (#1A1A1A), product lit dramatically in center with subtle spotlight. Malinco red #C41E3A accent elements: thin border, small label tag, underline. White typography. Elegant and confident, not heavy. ',

            // 4. Fundal neutru cu bandă roșie în partea de jos
            'Neutral off-white background, large product centered. Strong Malinco red #C41E3A horizontal band at the bottom third with white text. Clean sans-serif typography throughout. Modern and structured. ',

            // 5. Geometric — forme abstracte subtile
            'Light background with subtle large geometric shapes (circles or rectangles) in very pale red or gray behind the product. Malinco red as sharp accent on text labels. Layered depth without clutter. Contemporary design studio feel. ',

            // 6. Editorial — stil revistă B2B
            'Editorial magazine layout. Clean white background, product slightly off-center. Bold oversized title text in dark or red beside the product. Small elegant bullet points. Thin grid lines as design elements. High-end trade publication style. ',
        ];

        return $variants[array_rand($variants)];
    }
}
