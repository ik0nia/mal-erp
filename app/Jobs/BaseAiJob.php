<?php

namespace App\Jobs;

use App\Models\AiUsageLog;
use App\Models\AppSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

abstract class BaseAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;
    public int $backoff = 30;

    /**
     * Returnează API key-ul Anthropic.
     * Aruncă exception dacă nu e configurat.
     */
    protected function getApiKey(): string
    {
        $key = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY)
            ?? config('anthropic.api_key', env('ANTHROPIC_API_KEY', ''));

        if (blank($key)) {
            throw new \RuntimeException('Anthropic API key not configured');
        }

        return $key;
    }

    /**
     * Înregistrează utilizarea AI în AiUsageLog.
     *
     * @param  string  $type     Sursa apelului (ex: 'bi_daily', 'chat_summary')
     * @param  string  $model    Modelul Claude folosit
     * @param  int     $inputTokens
     * @param  int     $outputTokens
     * @param  array   $context  Metadata opțională (ex: ['analysis_id' => 1])
     */
    protected function recordUsage(
        string $type,
        string $model,
        int $inputTokens,
        int $outputTokens,
        array $context = []
    ): void {
        try {
            AiUsageLog::record($type, $model, $inputTokens, $outputTokens, $context);
        } catch (\Throwable $e) {
            Log::warning('BaseAiJob: nu s-a putut înregistra AiUsageLog', [
                'error' => $e->getMessage(),
                'type'  => $type,
            ]);
        }
    }

    /**
     * Handler generic pentru erori — poate fi suprascris în subclase.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error(class_basename(static::class) . ' failed permanently', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }
}
