<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\SocialMedia\MetaGraphClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublishSocialPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(public readonly int $postId)
    {
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $post = SocialPost::with('account')->lockForUpdate()->find($this->postId);

            if (! $post) {
                return;
            }

            // Protecție împotriva dublei publicări
            if ($post->status === SocialPost::STATUS_PUBLISHED) {
                return;
            }

            if ($post->status !== SocialPost::STATUS_SCHEDULED) {
                Log::warning("PublishSocialPostJob: post #{$this->postId} nu e în status scheduled (e {$post->status})");
                return;
            }

            $post->update(['status' => SocialPost::STATUS_PUBLISHING]);

            $account = $post->account;
            $token   = $account?->getAccessToken();

            if (blank($token)) {
                throw new \RuntimeException("Token Meta lipsă pentru contul #{$account?->id}");
            }

            $client  = new MetaGraphClient($token);
            $message = $post->getFullCaption();
            $imageUrl = $post->getImageUrl();

            $result = $client->publishPost($account->account_id, $message, $imageUrl);

            $postId  = $result['post_id'] ?? $result['id'] ?? null;
            $pageId  = $account->account_id;
            $url     = $postId ? "https://www.facebook.com/{$pageId}/posts/{$postId}" : null;

            $post->update([
                'status'           => SocialPost::STATUS_PUBLISHED,
                'platform_post_id' => $postId,
                'platform_url'     => $url,
                'published_at'     => now(),
                'error_message'    => null,
            ]);

            Log::info("PublishSocialPostJob: post #{$this->postId} publicat cu succes. Post ID: {$postId}");
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("PublishSocialPostJob: post #{$this->postId} eșuat definitiv: " . $exception->getMessage());

        SocialPost::where('id', $this->postId)->update([
            'status'        => SocialPost::STATUS_FAILED,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
