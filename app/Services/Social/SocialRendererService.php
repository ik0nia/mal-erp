<?php

namespace App\Services\Social;

use App\Models\SmPost;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Apelează node-renderer/render-html.js (Puppeteer) pentru a genera JPEG final.
 * Layout: cinematic poster — produs hero mare, text la bottom pe gradient.
 */
class SocialRendererService
{
    private string $rendererPath;

    public function __construct()
    {
        $this->rendererPath = base_path('node-renderer/render-html.js');
    }

    /**
     * Randează postarea și salvează imaginea în storage.
     * Returnează calea relativă (pentru image_path pe SmPost).
     */
    public function render(
        SmPost  $post,
        array   $canvasJson,
        ?string $backgroundImagePath = null,
        ?string $styleVariant        = null,
    ): string {
        $outFilename = 'social/' . Str::uuid() . '.jpg';
        $outPath     = Storage::disk('public')->path($outFilename);

        $productImagePath = $this->resolveProductImagePath($post);
        $malincoLogoPath  = public_path('malinco-logo-white.png');

        $graphicTexts = $post->graphic_texts ?? [];
        $advantages   = data_get($graphicTexts, 'advantages', []);

        $config = [
            'style_variant' => $styleVariant ?? 'dark_premium',
            'output'        => $outPath,
            'title'         => data_get($graphicTexts, 'title', ''),
            'subtitle'      => data_get($graphicTexts, 'subtitle', ''),
            'label'         => data_get($graphicTexts, 'cta', ''),
            'advantages'    => is_array($advantages) ? array_values($advantages) : [],
        ];

        if ($backgroundImagePath && file_exists($backgroundImagePath)) {
            $config['background_image'] = $backgroundImagePath;
        }
        if ($productImagePath) {
            $config['product_image'] = $productImagePath;
        }
        if (file_exists($malincoLogoPath)) {
            $config['malinco_logo'] = $malincoLogoPath;
        }

        $json    = json_encode($config);
        $escaped = escapeshellarg($json);

        $cmd    = "node {$this->rendererPath} {$escaped} 2>&1";
        $output = [];
        $code   = 0;
        exec($cmd, $output, $code);

        $outText = implode("\n", $output);

        if ($code !== 0 || ! file_exists($outPath)) {
            Log::error('[Social] Renderer node.js a eșuat', [
                'post_id' => $post->id,
                'code'    => $code,
                'output'  => $outText,
            ]);
            throw new \RuntimeException("Node renderer failed (exit {$code}): {$outText}");
        }

        Log::info('[Social] Imagine randată cu succes', ['post_id' => $post->id, 'path' => $outFilename]);

        return $outFilename;
    }

    /**
     * Rezolvă calea locală a imaginii produsului.
     * Descarcă URL-uri externe într-un fișier temporar.
     */
    private function resolveProductImagePath(SmPost $post): ?string
    {
        $source = $post->sourceable;
        if (! $source) return null;

        $url = match ($post->type) {
            'product' => $source->main_image_url ?? null,
            default   => null,
        };

        if (! $url) return null;

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            try {
                $data = file_get_contents($url, false, stream_context_create([
                    'http' => ['timeout' => 15],
                ]));
                if (! $data) return null;

                $ext     = $this->guessExtension($url);
                $tmpPath = sys_get_temp_dir() . '/sm_prod_' . Str::uuid() . '.' . $ext;
                file_put_contents($tmpPath, $data);
                return $tmpPath;
            } catch (\Throwable) {
                return null;
            }
        }

        return file_exists($url) ? $url : null;
    }

    private function guessExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) ? $ext : 'jpg';
    }
}
