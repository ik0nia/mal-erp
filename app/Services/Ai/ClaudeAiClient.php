<?php

namespace App\Services\Ai;

use Anthropic\Client as AnthropicClient;
use App\Models\AppSetting;

class ClaudeAiClient
{
    public static function modelSonnet(): string
    {
        return config('app.malinco.ai.models.sonnet', 'claude-sonnet-4-6');
    }

    public static function modelHaiku(): string
    {
        return config('app.malinco.ai.models.haiku', 'claude-haiku-4-5-20251001');
    }

    // Kept as class constants for backward-compat (used in static contexts before DI)
    public const MODEL_SONNET = 'claude-sonnet-4-6';
    public const MODEL_HAIKU  = 'claude-haiku-4-5-20251001';

    private const PRICING = [
        self::MODEL_SONNET => ['input' => 3.0,  'output' => 15.0],
        self::MODEL_HAIKU  => ['input' => 0.80, 'output' => 4.0],
    ];

    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey
            ?? AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY)
            ?? config('services.anthropic.api_key', env('ANTHROPIC_API_KEY', ''));
    }

    /**
     * Returnează un client Anthropic brut (pentru cazuri cu tool-use, streaming etc.)
     */
    public function rawClient(): AnthropicClient
    {
        return new AnthropicClient(apiKey: $this->apiKey);
    }

    /**
     * Trimite un mesaj simplu și returnează răspunsul cu tokeni și cost.
     *
     * @return array{content: string, tokens: array{input: int, output: int}, cost_usd: float}
     */
    public function complete(
        string $prompt,
        ?string $system = null,
        string $model = self::MODEL_HAIKU,
        int $maxTokens = 1024,
    ): array {
        $client = $this->rawClient();

        $params = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];

        if ($system !== null) {
            $params['system'] = $system;
        }

        $response = $client->messages->create(...$params);

        $inputTokens  = $response->usage->inputTokens ?? 0;
        $outputTokens = $response->usage->outputTokens ?? 0;
        $content      = '';

        foreach ($response->content as $block) {
            if (isset($block->text)) {
                $content .= $block->text;
            }
        }

        $costUsd = self::calculateCost($model, $inputTokens, $outputTokens);

        return [
            'content'  => $content,
            'tokens'   => ['input' => $inputTokens, 'output' => $outputTokens],
            'cost_usd' => $costUsd,
        ];
    }

    /**
     * Calculează costul USD pentru tokeni dați.
     */
    public static function calculateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = self::PRICING[$model] ?? self::PRICING[self::MODEL_HAIKU];

        return round(
            $inputTokens  / 1_000_000 * $pricing['input'] +
            $outputTokens / 1_000_000 * $pricing['output'],
            6
        );
    }

    /**
     * Returnează tabelul de prețuri pentru un model dat.
     *
     * @return array{input: float, output: float}
     */
    public static function pricing(string $model): array
    {
        return self::PRICING[$model] ?? self::PRICING[self::MODEL_HAIKU];
    }
}
