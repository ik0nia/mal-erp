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

class RegenerateImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(public readonly int $postId) {}

    public function handle(): void
    {
        $post = SocialPost::with(['product', 'brand'])->find($this->postId);
        if (! $post) return;

        $post->update(['status' => SocialPost::STATUS_GENERATING, 'error_message' => null]);

        $label = filled($post->graphic_label) ? $post->graphic_label : $this->resolveLabel($post);

        // Dacă nu avem titlu grafică — generăm cu Claude, cu fallback la numele brandului/produsului
        if (blank($post->graphic_title)) {
            try {
                $generated = (new SocialCaptionService())->generateGraphicTextsOnly($post);
                $title    = filled($generated['graphic_title'])    ? $generated['graphic_title']    : $this->resolveTitle($post);
                $subtitle = filled($generated['graphic_subtitle']) ? $generated['graphic_subtitle'] : null;
            } catch (\Throwable $e) {
                Log::warning("RegenerateImageJob: Claude graphic texts failed: " . $e->getMessage());
                $title    = $this->resolveTitle($post);
                $subtitle = null;
            }

            $post->update([
                'graphic_title'    => $title,
                'graphic_subtitle' => $subtitle,
                'graphic_label'    => $label,
            ]);
        } else {
            $title    = $post->graphic_title;
            $subtitle = filled($post->graphic_subtitle) ? $post->graphic_subtitle : null;
        }

        // Dacă tot n-avem titlu, fallback la brand/produs
        if (blank($title)) {
            $title = $this->resolveTitle($post);
        }

        try {
            $brandLogoPath = null;
            if ($post->brand && filled($post->brand->logo_url)) {
                $local = Storage::disk('public')->path('brand-logos/' . basename(parse_url($post->brand->logo_url, PHP_URL_PATH)));
                if (file_exists($local)) $brandLogoPath = $local;
            }

            $imagePath = (new NodeImageRenderer())->render(
                (string) $post->id,
                filled($post->product?->main_image_url) ? $post->product->main_image_url : null,
                $brandLogoPath,
                $title,
                $subtitle,
                [],
                $label,
                $this->resolveTemplateConfig($post)
            );

            $post->update([
                'status'     => SocialPost::STATUS_READY,
                'image_path' => $imagePath ?? $post->image_path,
            ]);

            Log::info("RegenerateImageJob: postare #{$this->postId} re-randată. title=[{$title}]");

        } catch (\Throwable $e) {
            Log::error("RegenerateImageJob: eroare post #{$this->postId}: " . $e->getMessage());
            $post->update(['status' => SocialPost::STATUS_FAILED, 'error_message' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(class_basename(static::class) . ' failed', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
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

    private function resolveTitle(SocialPost $post): ?string
    {
        if ($post->brand && filled($post->brand->name)) {
            return $post->brand->name;
        }
        if ($post->product && filled($post->product->name)) {
            return preg_replace('/\s+\d[\d\/\-\.]+$/', '', trim($post->product->name));
        }
        return null;
    }

    private function resolveLabel(SocialPost $post): string
    {
        return match ($post->brief_type) {
            'promo'   => 'OFERTĂ SPECIALĂ',
            'product' => 'DIN CATALOGUL NOSTRU',
            default   => '',
        };
    }
}
