<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialFetchedPost extends Model
{
    protected $fillable = [
        'social_account_id',
        'platform_post_id',
        'message',
        'created_time',
        'likes_count',
        'comments_count',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'created_time' => 'datetime',
            'raw_data'     => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
