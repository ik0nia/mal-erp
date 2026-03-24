<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialPostJob;
use App\Models\SocialPost;
use Illuminate\Console\Command;

class SocialPublishScheduledCommand extends Command
{
    protected $signature   = 'social:publish-scheduled';
    protected $description = 'Publică postările Social Media programate pentru acum sau în trecut.';

    public function handle(): void
    {
        $posts = SocialPost::where('status', SocialPost::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($posts->isEmpty()) {
            return;
        }

        foreach ($posts as $post) {
            PublishSocialPostJob::dispatch($post->id);
            $this->line("Dispatched PublishSocialPostJob pentru post #{$post->id}");
        }

        $this->info("Total: {$posts->count()} postări trimise la publicare.");
    }
}
