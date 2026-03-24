<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SocialFetchedPost;
use App\Services\SocialMedia\MetaGraphClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchSocialPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;
    public int $backoff = 30;

    public function __construct(public readonly int $accountId)
    {
    }

    public function handle(): void
    {
        $account = SocialAccount::find($this->accountId);

        if (! $account || ! $account->is_active) {
            return;
        }

        $token = $account->getAccessToken();
        if (blank($token)) {
            Log::warning("FetchSocialPostsJob: token lipsă pentru account #{$this->accountId}");
            return;
        }

        $client = new MetaGraphClient($token);
        $posts  = $client->fetchPagePosts($account->account_id, 200);

        if (empty($posts)) {
            Log::info("FetchSocialPostsJob: nicio postare găsită pentru account #{$this->accountId}");
            return;
        }

        foreach ($posts as $post) {
            SocialFetchedPost::updateOrCreate(
                [
                    'social_account_id' => $account->id,
                    'platform_post_id'  => $post['id'],
                ],
                [
                    'message'        => $post['message'] ?? null,
                    'created_time'   => isset($post['created_time']) ? \Carbon\Carbon::parse($post['created_time']) : null,
                    'likes_count'    => $post['likes']['summary']['total_count'] ?? 0,
                    'comments_count' => $post['comments']['summary']['total_count'] ?? 0,
                    'raw_data'       => $post,
                ]
            );
        }

        Log::info("FetchSocialPostsJob: {$posts[0]['id']} — {" . count($posts) . "} postări fetchate pentru account #{$this->accountId}");

        // Dacă avem destule postări, dispatch analiză stil
        $total = SocialFetchedPost::where('social_account_id', $account->id)
            ->whereNotNull('message')
            ->count();

        if ($total >= 10) {
            AnalyzeSocialStyleJob::dispatch($account->id);
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error(class_basename(static::class) . ' failed', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }
}
