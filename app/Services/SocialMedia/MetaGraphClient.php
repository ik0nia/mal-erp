<?php

namespace App\Services\SocialMedia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaGraphClient
{
    private string $baseUrl;

    public function __construct(
        private string $accessToken,
        private string $apiVersion = 'v21.0'
    ) {
        $this->baseUrl = "https://graph.facebook.com/{$this->apiVersion}";
    }

    /**
     * Fetch postări recente de pe pagina FB (cursor-based pagination).
     * Returnează array cu postările, maxim $maxPosts în total.
     */
    public function fetchPagePosts(string $pageId, int $maxPosts = 200): array
    {
        $posts  = [];
        $url    = "{$this->baseUrl}/{$pageId}/posts";
        $params = [
            'fields'       => 'id,message,created_time,full_picture,attachments{media_type,media,description}',
            'limit'        => 100,
            'access_token' => $this->accessToken,
        ];

        do {
            $response = Http::timeout(30)->get($url, $params);

            if (! $response->successful()) {
                Log::warning("MetaGraphClient: fetchPagePosts error {$response->status()}: " . $response->body());
                break;
            }

            $data  = $response->json();
            $batch = $data['data'] ?? [];
            $posts = array_merge($posts, $batch);

            // Paginare cursor
            $nextUrl = $data['paging']['next'] ?? null;
            if ($nextUrl && count($posts) < $maxPosts) {
                $url    = $nextUrl;
                $params = []; // parametrii sunt deja în URL-ul next
            } else {
                break;
            }
        } while (true);

        return array_slice($posts, 0, $maxPosts);
    }

    /**
     * Publică o postare cu imagine pe pagina Facebook.
     * Dacă imageUrl e null, publică doar text.
     */
    public function publishPost(string $pageId, string $message, ?string $imageUrl = null): array
    {
        if ($imageUrl) {
            // Postare cu imagine
            $response = Http::timeout(30)->post("{$this->baseUrl}/{$pageId}/photos", [
                'url'          => $imageUrl,
                'message'      => $message,
                'access_token' => $this->accessToken,
            ]);
        } else {
            // Postare text
            $response = Http::timeout(30)->post("{$this->baseUrl}/{$pageId}/feed", [
                'message'      => $message,
                'access_token' => $this->accessToken,
            ]);
        }

        if (! $response->successful()) {
            $error = $response->json('error.message', $response->body());
            throw new \RuntimeException("Meta Graph API error: {$error}");
        }

        return $response->json();
    }

    /**
     * Obține informații despre pagină (name, fan_count, etc.)
     */
    public function getPageInfo(string $pageId): array
    {
        $response = Http::timeout(15)->get("{$this->baseUrl}/{$pageId}", [
            'fields'       => 'id,name,fan_count,link',
            'access_token' => $this->accessToken,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Meta Graph API error: " . $response->json('error.message', '?'));
        }

        return $response->json();
    }
}
