<?php

namespace App\Services\SocialMedia;

use App\Models\AiUsageLog;
use App\Models\AppSetting;
use App\Models\SocialFetchedPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GeminiImageService
{
    private const MODEL      = 'gemini-3.1-flash-image-preview';
    private const LOG_SOURCE = 'social_image_gen';

    // Vertex AI endpoint — fără restricții geografice (spre deosebire de AI Studio)
    private const VERTEX_URL = 'https://aiplatform.googleapis.com/v1/projects/%s/locations/global/publishers/google/models/%s:generateContent';

    private array $serviceAccount;

    public function __construct()
    {
        $saPath = AppSetting::get('google_service_account_path')
            ?? '/var/www/scripts/cegim/service_account.json';

        if (! file_exists($saPath)) {
            throw new \RuntimeException("Service account JSON nu există la: {$saPath}");
        }

        $this->serviceAccount = json_decode(file_get_contents($saPath), true);
    }

    /**
     * Generează o imagine pe baza promptului și o salvează în storage.
     * Returnează calea relativă (ex: social/abc123.png).
     */
    /**
     * Generează imaginea și returnează datele binare brute (fără a salva).
     * Folosit de GenerateSocialPostJob care delegă salvarea la SocialImageComposer.
     */
    public function generateRaw(string $prompt, int $accountId = 1): string
    {
        return $this->generate($prompt, $accountId);
    }

    /** @deprecated Folosiți generateRaw() + SocialImageComposer */
    public function generateAndSave(string $prompt, string $postId, int $accountId = 1, ?string $brandLogoUrl = null): string
    {
        $binaryData = $this->generate($prompt, $accountId);
        $filename   = $this->saveImage($binaryData, $postId);

        if ($brandLogoUrl) {
            $this->overlayLogo($filename, $brandLogoUrl);
        }

        return $filename;
    }

    private function generate(string $prompt, int $accountId): string
    {
        $token     = $this->getAccessToken();
        $projectId = $this->serviceAccount['project_id'];
        $endpoint  = sprintf(self::VERTEX_URL, $projectId, self::MODEL);

        // Luăm O singură imagine reprezentativă ca bază pentru transformare
        $referenceImagePart = $this->getOneReferenceImage($accountId);

        $parts = [];

        if ($referenceImagePart) {
            // Mod image-to-image: Gemini transformă imaginea existentă
            $parts[] = $referenceImagePart;
            $fullPrompt = "Use this existing Malinco Facebook post as a visual style reference. Create a NEW image in the exact same graphic style: same color palette, same layout composition, same background treatment, same overall aesthetic and mood. The new image subject should be: {$prompt}. Keep the same brand visual identity. Do NOT reproduce any text, words, or logos from the reference — keep the graphic style only. Output as a portrait 4:5 image (taller than wide).";
        } else {
            // Fallback: generare pură cu instrucțiuni de stil
            $fullPrompt = "Create a professional branded Facebook post image (portrait 4:5 format, taller than wide) for Malinco, a Romanian building materials company. Use a clean graphic style with brand colors (orange and white), professional composition, no text or logos. Subject: {$prompt}.";
        }

        $parts[] = ['text' => $fullPrompt];

        $response = Http::withHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ])->timeout(300)->post($endpoint, [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        ]);

        if (! $response->successful()) {
            $error = $response->json('error.message', $response->body());
            throw new \RuntimeException("Gemini Vertex AI error: {$error}");
        }

        AiUsageLog::record(self::LOG_SOURCE, self::MODEL, 0, 0, [
            'prompt_chars' => strlen($prompt),
        ]);

        $parts = $response->json('candidates.0.content.parts', []);
        foreach ($parts as $part) {
            $imgData = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if ($imgData && isset($imgData['data'])) {
                return base64_decode($imgData['data']);
            }
        }

        throw new \RuntimeException('Gemini nu a returnat nicio imagine în răspuns.');
    }

    /**
     * Returnează O singură imagine reprezentativă ca bază pentru transformare.
     * Preferă referințele locale încărcate manual (nu expiră).
     * Fallback: imaginile fetchate din Facebook CDN.
     */
    private function getOneReferenceImage(int $accountId): ?array
    {
        // 1. Referințe locale (încărcate manual în storage/public/social/style-references/)
        $localFiles = Storage::disk('public')->files('social/style-references');
        if (! empty($localFiles)) {
            $file = collect($localFiles)->shuffle()->first();
            try {
                $binary   = Storage::disk('public')->get($file);
                $ext      = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $mimeType = match($ext) {
                    'png'  => 'image/png',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
                return [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data'      => base64_encode($binary),
                    ],
                ];
            } catch (\Throwable $e) {
                Log::warning("GeminiImageService: nu am putut citi referința locală: " . $e->getMessage());
            }
        }

        // 2. Fallback: postări fetchate din Facebook CDN
        $url = SocialFetchedPost::where('social_account_id', $accountId)
            ->whereNotNull('raw_data')
            ->get()
            ->filter(fn ($p) => ! empty($p->raw_data['full_picture']))
            ->pluck('raw_data')
            ->map(fn ($d) => $d['full_picture'])
            ->shuffle()
            ->first();

        if (! $url) {
            return null;
        }

        try {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                return null;
            }
            $mimeType = strtok($response->header('Content-Type') ?: 'image/jpeg', ';');
            if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'])) {
                $mimeType = 'image/jpeg';
            }
            return [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data'      => base64_encode($response->body()),
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning("GeminiImageService: nu am putut descărca imaginea de referință: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Salvează imaginea și o cropează/redimensionează la 1080×1350 (4:5 Facebook portrait).
     */
    private function saveImage(string $binaryData, string $postId): string
    {
        $filename = 'social/' . $postId . '_' . Str::random(8) . '.jpg';
        $path     = Storage::disk('public')->path($filename);

        // Asigurăm că directorul există
        Storage::disk('public')->makeDirectory('social');

        // Crop/resize la 1080×1350 (4:5) cu GD
        $src = imagecreatefromstring($binaryData);

        if ($src === false) {
            // GD nu poate procesa — salvăm ca atare
            Storage::disk('public')->put($filename, $binaryData);
            return $filename;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $targetW = 1080;
        $targetH = 1350;
        $targetRatio = $targetW / $targetH; // 0.8
        $srcRatio    = $srcW / $srcH;

        // Calculăm zona de crop centrată
        if ($srcRatio > $targetRatio) {
            // Imaginea e mai lată — crop pe lateral
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
            $cropX = (int) round(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            // Imaginea e mai înaltă — crop pe sus/jos (păstrăm centrul)
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $cropX = 0;
            $cropY = (int) round(($srcH - $cropH) / 2);
        }

        $dst = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);

        imagejpeg($dst, $path, 92);
        imagedestroy($src);
        imagedestroy($dst);

        return $filename;
    }

    /**
     * Suprapune logo-ul brandului în colțul din dreapta jos al imaginii.
     * Logo-ul e scalat la max 30% din lățimea imaginii, cu padding de 40px.
     */
    private function overlayLogo(string $filename, string $logoUrl): void
    {
        try {
            $response = Http::timeout(15)->get($logoUrl);
            if (! $response->successful()) {
                return;
            }

            $logoBinary = $response->body();
            $logo       = @imagecreatefromstring($logoBinary);
            if ($logo === false) {
                return;
            }

            $path = Storage::disk('public')->path($filename);
            $base = @imagecreatefromjpeg($path);
            if ($base === false) {
                imagedestroy($logo);
                return;
            }

            $baseW = imagesx($base);
            $baseH = imagesy($base);
            $logoW = imagesx($logo);
            $logoH = imagesy($logo);

            // Scalăm logo-ul la max 28% din lățimea imaginii
            $maxLogoW = (int) round($baseW * 0.28);
            if ($logoW > $maxLogoW) {
                $scale  = $maxLogoW / $logoW;
                $logoW  = $maxLogoW;
                $logoH  = (int) round($logoH * $scale);
                $scaled = imagecreatetruecolor($logoW, $logoH);
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $logoW, $logoH, imagesx($logo), imagesy($logo));
                imagedestroy($logo);
                $logo = $scaled;
            }

            // Fundal semi-transparent alb în spatele logo-ului (pentru lizibilitate)
            $padding = 20;
            $bgX     = $baseW - $logoW - $padding * 2 - 30;
            $bgY     = $baseH - $logoH - $padding * 2 - 30;
            $bgW     = $logoW + $padding * 2;
            $bgH     = $logoH + $padding * 2;

            // Desenăm dreptunghi alb semi-transparent
            $overlay = imagecreatetruecolor($bgW, $bgH);
            $white   = imagecolorallocatealpha($overlay, 255, 255, 255, 30); // alpha 30/127
            imagefill($overlay, 0, 0, $white);
            imagecopymerge($base, $overlay, $bgX, $bgY, 0, 0, $bgW, $bgH, 70);
            imagedestroy($overlay);

            // Copiem logo-ul cu blending
            imagealphablending($base, true);
            imagecopy($base, $logo, $bgX + $padding, $bgY + $padding, 0, 0, $logoW, $logoH);

            imagejpeg($base, $path, 92);
            imagedestroy($base);
            imagedestroy($logo);
        } catch (\Throwable $e) {
            Log::warning('GeminiImageService: nu am putut suprapune logo-ul brandului: ' . $e->getMessage());
        }
    }

    /**
     * Generează un Bearer token JWT pentru Vertex AI.
     * Același algoritm ca în cegim/wp_context_featured.php.
     */
    private function getAccessToken(): string
    {
        $sa  = $this->serviceAccount;
        $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');

        $header  = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => time() + 3600,
            'iat'   => time(),
        ]));

        openssl_sign($header . '.' . $payload, $sig, $sa['private_key'], 'SHA256');
        $jwt = $header . '.' . $payload . '.' . $b64($sig);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $token = $response->json('access_token');

        if (blank($token)) {
            throw new \RuntimeException('Nu am putut obține access token Google: ' . $response->body());
        }

        return $token;
    }
}
