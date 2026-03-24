<?php

namespace App\Jobs;

use App\Models\GraphicTemplate;
use App\Models\SocialPost;
use App\Services\SocialMedia\NodeImageRenderer;
use App\Services\SocialMedia\SocialCaptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateSocialPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 400;
    public int $tries   = 2;
    public int $backoff = 15;

    public function __construct(public readonly int $postId)
    {
    }

    public function handle(): void
    {
        $post = SocialPost::with(['product', 'account', 'brand'])->find($this->postId);

        if (! $post) {
            return;
        }

        $post->update(['status' => SocialPost::STATUS_GENERATING, 'error_message' => null]);

        try {
            // 1. Claude generează caption
            $captionService = new SocialCaptionService();
            $styleProfile   = $post->account?->activeStyleProfile();
            $generated      = $captionService->generate($post, $styleProfile);

            $graphicTitle    = filled($generated['graphic_title'])    ? $generated['graphic_title']    : $this->resolveTitle($post);
            $graphicSubtitle = filled($generated['graphic_subtitle']) ? $generated['graphic_subtitle'] : $this->resolveSubtitle($generated['caption']);
            $graphicLabel    = $this->resolveLabel($post);

            $post->update([
                'caption'          => $generated['caption'],
                'hashtags'         => $generated['hashtags'],
                'image_prompt'     => $generated['image_prompt'],
                'graphic_title'    => $graphicTitle,
                'graphic_subtitle' => $graphicSubtitle,
                'graphic_label'    => $graphicLabel,
            ]);

            // 2. Imagine → Node.js canvas local
            $renderer      = new NodeImageRenderer();
            $brandLogoPath = $this->resolveBrandLogoPath($post);
            $templateConfig = $this->resolveTemplateConfig($post);

            $imagePath = $renderer->render(
                (string) $post->id,
                filled($post->product?->main_image_url) ? $post->product->main_image_url : null,
                $brandLogoPath,
                $graphicTitle,
                $graphicSubtitle,
                [],
                $graphicLabel,
                $templateConfig
            );

            if ($imagePath) {
                $post->update(['image_path' => $imagePath]);
            }

            $post->update(['status' => SocialPost::STATUS_READY]);

            Log::info("GenerateSocialPostJob: postare #{$this->postId} generată cu succes.");

        } catch (\Throwable $e) {
            Log::error("GenerateSocialPostJob: eroare pentru post #{$this->postId}: " . $e->getMessage());

            $post->update([
                'status'        => SocialPost::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Titlul principal — numele produsului sau brandul, maxim 40 caractere per linie.
     */
    private function resolveTitle(SocialPost $post): ?string
    {
        if ($post->product && filled($post->product->name)) {
            // Eliminăm codul de catalog dacă e la sfârșit (ex: "Produs XYZ 123/456")
            $name = preg_replace('/\s+\d[\d\/\-\.]+$/', '', trim($post->product->name));
            return $name;
        }

        if ($post->brand && filled($post->brand->name)) {
            return $post->brand->name;
        }

        return null;
    }

    /**
     * Subtitle — prima linie semnificativă din caption (hook-ul).
     */
    private function resolveSubtitle(string $caption): ?string
    {
        if (blank($caption)) {
            return null;
        }

        $lines = array_filter(
            array_map('trim', explode("\n", $caption)),
            fn ($l) => filled($l)
        );

        $first = array_values($lines)[0] ?? null;

        if (! $first) {
            return null;
        }

        // Eliminăm emoji-ul de la început dacă există
        $first = preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]\s*/u', '', $first);

        // Tăiem dacă e prea lung
        if (mb_strlen($first) > 70) {
            $first = mb_substr($first, 0, 67) . '...';
        }

        return $first;
    }

    private function resolveLabel(SocialPost $post): string
    {
        return match ($post->brief_type) {
            'brand'   => 'BRAND PARTENER',
            'promo'   => 'OFERTĂ SPECIALĂ',
            'product' => 'DIN CATALOGUL NOSTRU',
            default   => 'MATERIALE DE CALITATE',
        };
    }

    private function resolveTemplateConfig(SocialPost $post): array
    {
        $template = null;

        if (filled($post->template) && $post->template !== 'clasic') {
            $template = GraphicTemplate::where('slug', $post->template)->first();
        }

        $template ??= GraphicTemplate::where('layout', $post->brief_type)->where('is_active', true)->first();
        $template ??= GraphicTemplate::default();

        return $template?->config ?? [];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(class_basename(static::class) . ' failed', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }

    private function resolveBrandLogoPath(SocialPost $post): ?string
    {
        if ($post->brand && filled($post->brand->logo_url)) {
            $filename = basename(parse_url($post->brand->logo_url, PHP_URL_PATH));
            $local    = Storage::disk('public')->path('brand-logos/' . $filename);
            if (file_exists($local)) {
                return $local;
            }
        }

        if ($post->product && filled($post->product->brand)) {
            $slug  = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($post->product->brand)));
            $local = Storage::disk('public')->path('brand-logos/' . $slug . '.png');
            if (file_exists($local)) {
                return $local;
            }
        }

        return null;
    }
}
