<?php

namespace App\Services\SocialMedia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Compune imaginea finală 1080×1350 (4:5 portrait) pentru postări social media.
 *
 * Template Malinco:
 *  - Fundal alb curat
 *  - Imaginea produsului centrată, ocupând ~75% din înălțime
 *  - Bandă diagonală roșu-burgundy (#AB2223) în stânga jos, ca accent grafic
 *  - Logo Malinco în colțul dreapta sus
 *  - Opțional: logo brand partener în colțul dreapta jos
 */
class SocialImageComposer
{
    private const W = 1080;
    private const H = 1350;

    // Roșu-burgundy Malinco
    private const BRAND_R = 171;
    private const BRAND_G = 34;
    private const BRAND_B = 35;

    private string $malincoLogoPath;

    public function __construct()
    {
        $this->malincoLogoPath = Storage::disk('public')->path('malinco-logo.png');
    }

    /**
     * Construiește imaginea din URL-ul produsului WooCommerce.
     * Returnează filename relativ (ex: social/1_abc123.jpg) sau null dacă eșuează.
     */
    public function composeFromProductUrl(
        string $imageUrl,
        string $postId,
        ?string $brandLogoPath = null
    ): ?string {
        try {
            $response = Http::timeout(20)->get($imageUrl);
            if (! $response->successful()) {
                return null;
            }

            $src = @imagecreatefromstring($response->body());
            if ($src === false) {
                return null;
            }

            $canvas = $this->buildCanvas($src, $brandLogoPath);
            imagedestroy($src);

            return $this->saveCanvas($canvas, $postId);
        } catch (\Throwable $e) {
            Log::warning('SocialImageComposer: eroare compose produs: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Procesează date binare deja generate (de Gemini),
     * aplică crop 4:5 și overlay brand.
     */
    public function composeFromBinary(
        string $binaryData,
        string $postId,
        ?string $brandLogoPath = null
    ): string {
        $src = @imagecreatefromstring($binaryData);

        if ($src === false) {
            Storage::disk('public')->makeDirectory('social');
            $filename = 'social/' . $postId . '_' . Str::random(8) . '.jpg';
            Storage::disk('public')->put($filename, $binaryData);
            return $filename;
        }

        // Pentru imagini Gemini facem doar crop + logo brand (fără template Malinco complet)
        $canvas = $this->cropToPortrait($src);
        imagedestroy($src);

        $this->applyMalincoLogo($canvas);

        if ($brandLogoPath && file_exists($brandLogoPath)) {
            $this->applyBrandLogo($canvas, $brandLogoPath);
        }

        return $this->saveCanvas($canvas, $postId);
    }

    /**
     * Construiește canvas-ul complet cu template Malinco din imaginea sursă.
     */
    private function buildCanvas(\GdImage $productSrc, ?string $brandLogoPath): \GdImage
    {
        $canvas = imagecreatetruecolor(self::W, self::H);

        // Fundal alb
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        // Zona produsului: centrată, cu padding 60px pe lateral și 80px sus
        $prodAreaX = 60;
        $prodAreaY = 80;
        $prodAreaW = self::W - 120;
        $prodAreaH = (int) round(self::H * 0.72); // 72% din înălțime

        $this->drawProductCentered($canvas, $productSrc, $prodAreaX, $prodAreaY, $prodAreaW, $prodAreaH);

        // Bandă diagonală burgundy în colțul stânga jos
        $this->drawDiagonalAccent($canvas);

        // Logo Malinco dreapta sus
        $this->applyMalincoLogo($canvas);

        // Logo brand partener dreapta jos (opțional)
        if ($brandLogoPath && file_exists($brandLogoPath)) {
            $this->applyBrandLogo($canvas, $brandLogoPath);
        }

        return $canvas;
    }

    /**
     * Desenează produsul centrat în zona dată, păstrând aspect ratio-ul original.
     */
    private function drawProductCentered(
        \GdImage $canvas,
        \GdImage $src,
        int $areaX, int $areaY, int $areaW, int $areaH
    ): void {
        $srcW  = imagesx($src);
        $srcH  = imagesy($src);
        $ratio = min($areaW / $srcW, $areaH / $srcH);

        $dstW  = (int) round($srcW * $ratio);
        $dstH  = (int) round($srcH * $ratio);
        $dstX  = $areaX + (int) round(($areaW - $dstW) / 2);
        $dstY  = $areaY + (int) round(($areaH - $dstH) / 2);

        imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
    }

    /**
     * Desenează banda diagonală roșu-burgundy în colțul stânga jos.
     * Formă: triunghi în colțul inferior stâng cu linie diagonală spre dreapta.
     */
    private function drawDiagonalAccent(\GdImage $canvas): void
    {
        $burgundy = imagecolorallocate($canvas, self::BRAND_R, self::BRAND_G, self::BRAND_B);

        $h = self::H;
        $w = self::W;

        // Triunghi mare în colțul stânga-jos
        // Vârfuri: (0, H), (0, H-300), (420, H)
        $points = [
            0,       $h,        // colț stânga jos
            0,       $h - 280,  // sus pe marginea stângă
            400,     $h,        // dreapta pe marginea jos
        ];
        imagefilledpolygon($canvas, $points, $burgundy);

        // Linie diagonală mai subțire deasupra, ca accent secundar
        // Vârfuri: (0, H-280), (0, H-320), (480, H), (400, H)
        $stripe = [
            0,    $h - 280,
            0,    $h - 330,
            460,  $h,
            400,  $h,
        ];
        $burgundyLight = imagecolorallocate($canvas, 196, 55, 56); // burgundy mai deschis
        imagefilledpolygon($canvas, $stripe, $burgundyLight);
    }

    /**
     * Aplică logo-ul Malinco în colțul dreapta sus.
     */
    private function applyMalincoLogo(\GdImage $canvas): void
    {
        if (! file_exists($this->malincoLogoPath)) {
            return;
        }

        $logo = @imagecreatefrompng($this->malincoLogoPath);
        if ($logo === false) {
            return;
        }

        $logoW = imagesx($logo);
        $logoH = imagesy($logo);

        // Scalăm la 240px lățime
        $targetW = 240;
        $targetH = (int) round($logoH * $targetW / $logoW);

        $scaled = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefill($scaled, 0, 0, $transparent);
        imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $targetW, $targetH, $logoW, $logoH);
        imagedestroy($logo);

        // Poziție: colț dreapta sus, cu padding 30px
        $padding = 30;
        $dstX    = self::W - $targetW - $padding;
        $dstY    = $padding;

        // Fundal alb semi-transparent sub logo
        $bg = imagecreatetruecolor($targetW + 20, $targetH + 14);
        $white = imagecolorallocate($bg, 255, 255, 255);
        imagefill($bg, 0, 0, $white);
        imagecopymerge($canvas, $bg, $dstX - 10, $dstY - 7, 0, 0, $targetW + 20, $targetH + 14, 85);
        imagedestroy($bg);

        imagealphablending($canvas, true);
        imagecopy($canvas, $scaled, $dstX, $dstY, 0, 0, $targetW, $targetH);
        imagedestroy($scaled);
    }

    /**
     * Aplică logo-ul brandului partener în colțul dreapta jos.
     */
    private function applyBrandLogo(\GdImage $canvas, string $logoPath): void
    {
        $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $logo = match ($ext) {
            'png'          => @imagecreatefrompng($logoPath),
            'jpg', 'jpeg'  => @imagecreatefromjpeg($logoPath),
            'webp'         => @imagecreatefromwebp($logoPath),
            default        => false,
        };

        if ($logo === false) {
            return;
        }

        $logoW = imagesx($logo);
        $logoH = imagesy($logo);

        // Scalăm la max 200px lățime
        $targetW = min(200, $logoW);
        $targetH = (int) round($logoH * $targetW / $logoW);

        $scaled = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefill($scaled, 0, 0, $transparent);
        imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $targetW, $targetH, $logoW, $logoH);
        imagedestroy($logo);

        // Poziție: colț dreapta jos, deasupra benzii diagonale
        $padding = 35;
        $dstX    = self::W - $targetW - $padding;
        $dstY    = self::H - $targetH - $padding - 80; // 80px deasupra marginii jos

        // Fundal alb sub logo
        $bg = imagecreatetruecolor($targetW + 20, $targetH + 14);
        $white = imagecolorallocate($bg, 255, 255, 255);
        imagefill($bg, 0, 0, $white);
        imagecopymerge($canvas, $bg, $dstX - 10, $dstY - 7, 0, 0, $targetW + 20, $targetH + 14, 85);
        imagedestroy($bg);

        imagealphablending($canvas, true);
        imagecopy($canvas, $scaled, $dstX, $dstY, 0, 0, $targetW, $targetH);
        imagedestroy($scaled);
    }

    /**
     * Cropează imaginea la 4:5 portrait centrată.
     */
    private function cropToPortrait(\GdImage $src): \GdImage
    {
        $srcW        = imagesx($src);
        $srcH        = imagesy($src);
        $targetRatio = self::W / self::H;
        $srcRatio    = $srcW / $srcH;

        if ($srcRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
            $cropX = (int) round(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $cropX = 0;
            $cropY = (int) round(($srcH - $cropH) / 2);
        }

        $dst = imagecreatetruecolor(self::W, self::H);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, self::W, self::H, $cropW, $cropH);
        return $dst;
    }

    private function saveCanvas(\GdImage $canvas, string $postId): string
    {
        Storage::disk('public')->makeDirectory('social');
        $filename = 'social/' . $postId . '_' . Str::random(8) . '.jpg';
        $path     = Storage::disk('public')->path($filename);
        imagejpeg($canvas, $path, 92);
        imagedestroy($canvas);
        return $filename;
    }
}
