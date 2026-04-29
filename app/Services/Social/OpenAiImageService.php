<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OpenAiImageService
{
    private string $apiKey;
    private string $model = 'gpt-image-2';
    private string $size  = '1024x1024';

    public function __construct()
    {
        $this->apiKey = config('services.openai_image.key');
    }

    /**
     * Editează o imagine de produs existentă — adaugă design grafic în jur
     * fără să modifice produsul, ambalajul sau eticheta.
     * Returnează calea relativă salvată în storage.
     */
    public function editWithProductImage(string $productImageUrl, string $prompt): string
    {
        Log::info('[Social] Edit imagine produs OpenAI', ['url' => Str::limit($productImageUrl, 80)]);

        // Descărcăm imaginea produsului
        $imageData = Http::timeout(30)->get($productImageUrl)->body();
        if (! $imageData) {
            throw new \RuntimeException('Nu am putut descărca imaginea produsului.');
        }

        // Convertim la PNG pătrat 1024x1024 (cerință API)
        $tmpPath = $this->prepareImageForEdit($imageData);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(120)
                ->asMultipart()
                ->post('https://api.openai.com/v1/images/edits', [
                    [
                        'name'     => 'model',
                        'contents' => $this->model,
                    ],
                    [
                        'name'     => 'prompt',
                        'contents' => $prompt,
                    ],
                    [
                        'name'     => 'n',
                        'contents' => '1',
                    ],
                    [
                        'name'     => 'size',
                        'contents' => $this->size,
                    ],
                    [
                        'name'     => 'image[]',
                        'contents' => fopen($tmpPath, 'r'),
                        'filename' => 'product.png',
                        'headers'  => ['Content-Type' => 'image/png'],
                    ],
                ]);

            if ($response->failed()) {
                $error = $response->json('error.message', 'Eroare necunoscută OpenAI');
                Log::error('[Social] Eroare edit imagine', ['error' => $error]);
                throw new \RuntimeException("OpenAI image edit failed: {$error}");
            }

            $b64 = $response->json('data.0.b64_json');
            if ($b64) {
                return $this->saveFromBase64($b64);
            }

            $imageUrl = $response->json('data.0.url');
            if ($imageUrl) {
                return $this->saveFromUrl($imageUrl);
            }

            throw new \RuntimeException('OpenAI nu a returnat imagine editată.');

        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * Pregătește imaginea produsului pentru API edit:
     * - convertită la PNG cu canal alpha
     * - redimensionată la 1024x1024 (cu padding, fără distorsiune)
     */
    private function prepareImageForEdit(string $imageData): string
    {
        $src = @imagecreatefromstring($imageData);
        if (! $src) {
            throw new \RuntimeException('Format imagine produs nesuportat.');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $size = 1024;

        // Creăm canvas pătrat transparent
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagealphablending($canvas, true);

        // Calculăm dimensiunile pentru fit (păstrăm aspect ratio, centrăm)
        $ratio  = min($size / $srcW, $size / $srcH);
        $dstW   = (int)($srcW * $ratio);
        $dstH   = (int)($srcH * $ratio);
        $dstX   = (int)(($size - $dstW) / 2);
        $dstY   = (int)(($size - $dstH) / 2);

        imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);

        $tmpPath = sys_get_temp_dir() . '/sm_edit_' . Str::uuid() . '.png';
        imagepng($canvas, $tmpPath, 9);

        imagedestroy($src);
        imagedestroy($canvas);

        return $tmpPath;
    }

    /**
     * Generează o imagine și o salvează în storage.
     * Returnează calea relativă (pentru image_path pe SmPost).
     */
    public function generate(string $prompt): string
    {
        Log::info('[Social] Generare imagine OpenAI', ['prompt_preview' => Str::limit($prompt, 100)]);

        $response = Http::withToken($this->apiKey)
            ->timeout(120)
            ->post('https://api.openai.com/v1/images/generations', [
                'model'           => $this->model,
                'prompt'          => $prompt,
                'n'               => 1,
                'size'            => $this->size,
                'output_format'   => 'png',
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Eroare necunoscută OpenAI');
            Log::error('[Social] Eroare generare imagine', ['error' => $error]);
            throw new \RuntimeException("OpenAI image generation failed: {$error}");
        }

        $b64 = $response->json('data.0.b64_json');
        if ($b64) {
            return $this->saveFromBase64($b64);
        }

        // fallback url dacă există
        $imageUrl = $response->json('data.0.url');
        if ($imageUrl) {
            return $this->saveFromUrl($imageUrl);
        }

        throw new \RuntimeException('OpenAI nu a returnat imagine (nici b64_json, nici url).');
    }

    private function saveFromUrl(string $url): string
    {
        $imageData = Http::timeout(60)->get($url)->body();
        return $this->saveImageData($imageData, 'jpg');
    }

    private function saveFromBase64(string $b64): string
    {
        $imageData = base64_decode($b64);
        return $this->saveImageData($imageData, 'png');
    }

    private function saveImageData(string $data, string $ext): string
    {
        $filename = 'social/' . Str::uuid() . '.' . $ext;
        Storage::disk('public')->put($filename, $data);

        $fullPath = Storage::disk('public')->path($filename);
        $this->compositeLogoOnImage($fullPath);

        Log::info('[Social] Imagine salvată cu logo', ['path' => $filename]);
        return $filename;
    }

    /**
     * Generează DOAR un fundal (background) fără niciun logo sau bar Malinco.
     * Folosit în pipeline-ul canvas_json — textul și logo-ul se adaugă ulterior.
     * Returnează calea locală completă (nu relativă) a imaginii salvate.
     */
    public function generateBackground(string $prompt): string
    {
        Log::info('[Social] Generare fundal AI', ['prompt_preview' => Str::limit($prompt, 100)]);

        $response = Http::withToken($this->apiKey)
            ->timeout(120)
            ->post('https://api.openai.com/v1/images/generations', [
                'model'         => $this->model,
                'prompt'        => $prompt,
                'n'             => 1,
                'size'          => $this->size,
                'output_format' => 'png',
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Eroare necunoscută OpenAI');
            throw new \RuntimeException("OpenAI background generation failed: {$error}");
        }

        $b64  = $response->json('data.0.b64_json');
        $data = base64_decode($b64);

        $filename = 'social/bg_' . Str::uuid() . '.png';
        Storage::disk('public')->put($filename, $data);

        return Storage::disk('public')->path($filename);
    }

    /**
     * Generează un background pentru brand post, apoi compozitează logo-ul brandului.
     */
    public function generateBrandPost(string $backgroundPrompt, string $brandLogoPath, string $brandName): string
    {
        Log::info('[Social] Generare brand post', ['brand' => $brandName]);

        // 1. Generăm fundalul fără niciun logo
        $response = Http::withToken($this->apiKey)
            ->timeout(120)
            ->post('https://api.openai.com/v1/images/generations', [
                'model'         => $this->model,
                'prompt'        => $backgroundPrompt,
                'n'             => 1,
                'size'          => $this->size,
                'output_format' => 'png',
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Eroare OpenAI');
            throw new \RuntimeException("OpenAI brand background failed: {$error}");
        }

        $b64  = $response->json('data.0.b64_json');
        $data = base64_decode($b64);

        $filename = 'social/' . Str::uuid() . '.png';
        Storage::disk('public')->put($filename, $data);
        $fullPath = Storage::disk('public')->path($filename);

        // 2. Compozităm logo-ul real al brandului centrat în imagine
        $this->compositeBrandLogo($fullPath, $brandLogoPath);

        // 3. Banner Malinco la bază
        $this->compositeLogoOnImage($fullPath);

        return $filename;
    }

    /**
     * Lipește logo-ul brandului partener centrat în treimea superioară a imaginii.
     */
    private function compositeBrandLogo(string $imagePath, string $brandLogoPath): void
    {
        if (! file_exists($brandLogoPath)) return;

        try {
            $base = imagecreatefrompng($imagePath);
            if (! $base) return;

            $baseW = imagesx($base);
            $baseH = imagesy($base);

            // Detectăm formatul logo-ului brandului
            $ext  = strtolower(pathinfo($brandLogoPath, PATHINFO_EXTENSION));
            $logo = match ($ext) {
                'jpg', 'jpeg' => imagecreatefromjpeg($brandLogoPath),
                'webp'        => imagecreatefromwebp($brandLogoPath),
                default       => imagecreatefrompng($brandLogoPath),
            };
            if (! $logo) return;

            // Scalăm la 45% din lățimea imaginii, menținând aspect ratio
            $targetW    = (int)($baseW * 0.45);
            $logoScaled = imagescale($logo, $targetW, -1, IMG_BILINEAR_FIXED);
            $targetH    = imagesy($logoScaled);

            // Centrat orizontal, în treimea superioară (y = 25% din înălțime)
            $dstX = (int)(($baseW - $targetW) / 2);
            $dstY = (int)($baseH * 0.25) - (int)($targetH / 2);
            $dstY = max($dstY, (int)($baseH * 0.08));

            imagealphablending($base, true);
            imagecopy($base, $logoScaled, $dstX, $dstY, 0, 0, $targetW, $targetH);

            imagepng($base, $imagePath, 9);

            imagedestroy($base);
            imagedestroy($logo);
            imagedestroy($logoScaled);

        } catch (\Throwable $e) {
            Log::warning('[Social] Compositing brand logo eșuat', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Adaugă un banner Malinco roșu (#C41E3A) la baza imaginii cu logo-ul alb.
     * Banner-ul e separat de imagine — logo-ul nu ajunge niciodată peste produs.
     */
    private function compositeLogoOnImage(string $imagePath): void
    {
        try {
            $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

            $base = match ($ext) {
                'jpg', 'jpeg' => imagecreatefromjpeg($imagePath),
                'webp'        => imagecreatefromwebp($imagePath),
                default       => imagecreatefrompng($imagePath),
            };

            if (! $base) return;

            $baseW      = imagesx($base);
            $baseH      = imagesy($base);
            $bannerH    = (int)($baseH * 0.09); // banner = 9% din înălțime

            // Canvas nou = imaginea originală + banner jos
            $canvas = imagecreatetruecolor($baseW, $baseH + $bannerH);

            // Copiem imaginea originală în partea de sus
            imagecopy($canvas, $base, 0, 0, 0, 0, $baseW, $baseH);

            // Umplem banner-ul cu roșu Malinco #C41E3A
            $red = imagecolorallocate($canvas, 0xC4, 0x1E, 0x3A);
            imagefilledrectangle($canvas, 0, $baseH, $baseW, $baseH + $bannerH, $red);

            // Încărcăm logo-ul alb și îl scalăm să încapă în banner cu padding
            $logo = imagecreatefrompng(public_path('malinco-logo-white.png'));
            if (! $logo) {
                imagedestroy($base);
                imagedestroy($canvas);
                return;
            }

            $padding    = (int)($bannerH * 0.20);
            $logoH      = $bannerH - $padding * 2;
            $logoScaled = imagescale($logo, -1, $logoH, IMG_BILINEAR_FIXED);
            $logoW      = imagesx($logoScaled);

            // Centrat orizontal în banner
            $dstX = (int)(($baseW - $logoW) / 2);
            $dstY = $baseH + $padding;

            imagealphablending($canvas, true);
            imagecopy($canvas, $logoScaled, $dstX, $dstY, 0, 0, $logoW, $logoH);

            // Salvăm
            match ($ext) {
                'jpg', 'jpeg' => imagejpeg($canvas, $imagePath, 95),
                'webp'        => imagewebp($canvas, $imagePath, 90),
                default       => imagepng($canvas, $imagePath, 9),
            };

            imagedestroy($base);
            imagedestroy($canvas);
            imagedestroy($logo);
            imagedestroy($logoScaled);

        } catch (\Throwable $e) {
            Log::warning('[Social] Compositing logo eșuat', ['error' => $e->getMessage()]);
        }
    }
}
