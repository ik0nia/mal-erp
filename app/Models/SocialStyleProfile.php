<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialStyleProfile extends Model
{
    protected $fillable = [
        'social_account_id',
        'posts_analyzed',
        'tone',
        'vocabulary',
        'hashtag_patterns',
        'caption_structure',
        'visual_style',
        'raw_analysis',
        'is_active',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'generated_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /**
     * Returnează un context formatat pentru injectat în promptul Claude.
     */
    public function toPromptContext(): string
    {
        $parts = [];

        if (filled($this->tone)) {
            $parts[] = "TON: {$this->tone}";
        }
        if (filled($this->vocabulary)) {
            $parts[] = "VOCABULAR ȘI STIL: {$this->vocabulary}";
        }
        if (filled($this->hashtag_patterns)) {
            $parts[] = "HASHTAG-URI UZUALE: {$this->hashtag_patterns}";
        }
        if (filled($this->caption_structure)) {
            $parts[] = "STRUCTURA CAPTION: {$this->caption_structure}";
        }
        if (filled($this->visual_style)) {
            $parts[] = "STIL VIZUAL (pentru generarea imaginilor): {$this->visual_style}";
        }

        return implode("\n\n", $parts);
    }
}
