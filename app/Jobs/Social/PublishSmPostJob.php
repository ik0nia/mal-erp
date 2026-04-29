<?php

namespace App\Jobs\Social;

use App\Models\SmAccount;
use App\Models\SmPost;
use App\Services\Social\MetaSocialClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PublishSmPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 3;

    public function __construct(public SmPost $post) {}

    public function handle(MetaSocialClient $meta): void
    {
        Log::info('[Social] Start publicare postare', ['post_id' => $this->post->id]);

        if ($this->post->status !== SmPost::STATUS_SCHEDULED) {
            Log::warning('[Social] Postare nu e în status SCHEDULED, skip', ['post_id' => $this->post->id]);
            return;
        }

        $this->post->update(['status' => SmPost::STATUS_PUBLISHING]);

        try {
            $imageUrl = $this->post->getImageUrl();
            if (! $imageUrl) {
                throw new \RuntimeException('Postarea nu are imagine generată.');
            }

            $caption  = $this->buildCaption();
            $fbPostId = null;
            $igPostId = null;

            // Publică pe Facebook dacă există cont activ
            $fbAccount = SmAccount::where('platform', 'facebook')->where('is_active', true)->first();
            if ($fbAccount) {
                $fbPostId = $meta->publishToFacebook($fbAccount, $imageUrl, $caption);
            }

            // Publică pe Instagram dacă există cont activ
            $igAccount = SmAccount::where('platform', 'instagram')->where('is_active', true)->first();
            if ($igAccount) {
                $igPostId = $meta->publishToInstagram($igAccount, $imageUrl, $caption);
            }

            if (! $fbAccount && ! $igAccount) {
                throw new \RuntimeException('Nu există conturi Meta active configurate.');
            }

            $this->post->update([
                'status'       => SmPost::STATUS_PUBLISHED,
                'published_at' => now(),
                'fb_post_id'   => $fbPostId,
                'ig_post_id'   => $igPostId,
            ]);

            Log::info('[Social] Postare publicată cu succes', ['post_id' => $this->post->id]);

        } catch (\Throwable $e) {
            Log::error('[Social] Eroare publicare', [
                'post_id' => $this->post->id,
                'error'   => $e->getMessage(),
            ]);

            $this->post->update([
                'status'        => SmPost::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function buildCaption(): string
    {
        $hashtags = collect($this->post->hashtags ?? [])
            ->map(fn ($h) => str_starts_with($h, '#') ? $h : "#{$h}")
            ->implode(' ');

        return trim($this->post->caption . "\n\n" . $hashtags);
    }
}
