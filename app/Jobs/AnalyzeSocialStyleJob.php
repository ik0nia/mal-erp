<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SocialFetchedPost;
use App\Services\SocialMedia\StyleAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeSocialStyleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;
    public int $tries   = 1;

    public function __construct(public readonly int $accountId)
    {
    }

    public function handle(): void
    {
        $account = SocialAccount::find($this->accountId);

        if (! $account) {
            return;
        }

        $posts = SocialFetchedPost::where('social_account_id', $account->id)
            ->whereNotNull('message')
            ->orderByDesc('created_time')
            ->limit(50)
            ->get()
            ->map(fn ($p) => ['id' => $p->platform_post_id, 'message' => $p->message])
            ->all();

        if (empty($posts)) {
            Log::info("AnalyzeSocialStyleJob: nicio postare cu text pentru account #{$this->accountId}");
            return;
        }

        try {
            $service = new StyleAnalysisService();
            $profile = $service->analyzeAndSave($account, $posts);
            Log::info("AnalyzeSocialStyleJob: profil stil generat (#{$profile->id}) pentru account #{$this->accountId}");
        } catch (\Throwable $e) {
            Log::error("AnalyzeSocialStyleJob: {$e->getMessage()} pentru account #{$this->accountId}");
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(class_basename(static::class) . ' failed', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }
}
