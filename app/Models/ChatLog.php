<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatLog extends Model
{
    protected $fillable = [
        'session_id',
        'ip_address',
        'role',
        'content',
        'has_products',
        'input_tokens',
        'output_tokens',
    ];

    protected $casts = [
        'has_products' => 'boolean',
    ];

    /**
     * Loghează un mesaj (user sau assistant).
     * Tokenii se stochează doar pe rândul 'assistant'.
     */
    public static function log(
        string $sessionId,
        string $role,
        string $content,
        ?string $ip = null,
        bool $hasProducts = false,
        int $inputTokens = 0,
        int $outputTokens = 0,
    ): void {
        try {
            static::create([
                'session_id'    => $sessionId,
                'ip_address'    => $ip,
                'role'          => $role,
                'content'       => $content,
                'has_products'  => $hasProducts,
                'input_tokens'  => $inputTokens  ?: null,
                'output_tokens' => $outputTokens ?: null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ChatLog: nu am putut salva', ['error' => $e->getMessage()]);
        }
    }
}
