<?php

namespace App\Services\Social;

use App\Models\SmAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaSocialClient
{
    private string $graphBase = 'https://graph.facebook.com/v19.0';

    /**
     * Publică o imagine cu caption pe Facebook Page.
     * Returnează post_id.
     */
    public function publishToFacebook(SmAccount $account, string $imageUrl, string $caption): string
    {
        Log::info('[Social] Publicare Facebook', ['page_id' => $account->page_id]);

        $response = Http::post("{$this->graphBase}/{$account->page_id}/photos", [
            'url'          => $imageUrl,
            'caption'      => $caption,
            'access_token' => $account->access_token,
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Eroare Meta API');
            Log::error('[Social] Eroare publicare Facebook', ['error' => $error]);
            throw new \RuntimeException("Facebook publish failed: {$error}");
        }

        return $response->json('post_id') ?? $response->json('id') ?? '';
    }

    /**
     * Publică pe Instagram Business via Container API.
     * Returnează media_id.
     */
    public function publishToInstagram(SmAccount $account, string $imageUrl, string $caption): string
    {
        $igId  = $account->instagram_business_id;
        $token = $account->access_token;

        Log::info('[Social] Publicare Instagram', ['ig_id' => $igId]);

        // Step 1: Creează container media
        $containerResp = Http::post("{$this->graphBase}/{$igId}/media", [
            'image_url'    => $imageUrl,
            'caption'      => $caption,
            'access_token' => $token,
        ]);

        if ($containerResp->failed()) {
            $error = $containerResp->json('error.message', 'Eroare Meta API');
            Log::error('[Social] Eroare container Instagram', ['error' => $error]);
            throw new \RuntimeException("Instagram container failed: {$error}");
        }

        $containerId = $containerResp->json('id');

        // Step 2: Publică containerul
        $publishResp = Http::post("{$this->graphBase}/{$igId}/media_publish", [
            'creation_id'  => $containerId,
            'access_token' => $token,
        ]);

        if ($publishResp->failed()) {
            $error = $publishResp->json('error.message', 'Eroare Meta API');
            Log::error('[Social] Eroare publish Instagram', ['error' => $error]);
            throw new \RuntimeException("Instagram publish failed: {$error}");
        }

        return $publishResp->json('id') ?? '';
    }

    /**
     * Verifică dacă token-ul e valid.
     */
    public function verifyToken(string $token): bool
    {
        $resp = Http::get("{$this->graphBase}/me", [
            'access_token' => $token,
            'fields'       => 'id,name',
        ]);
        return $resp->successful();
    }

    /**
     * Returnează paginile disponibile pentru un user token.
     */
    public function getPages(string $userToken): array
    {
        $resp = Http::get("{$this->graphBase}/me/accounts", [
            'access_token' => $userToken,
            'fields'       => 'id,name,access_token,instagram_business_account',
        ]);

        if ($resp->failed()) return [];

        return $resp->json('data', []);
    }
}
