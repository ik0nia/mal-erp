<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhPushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'p256dh', 'auth', 'user_agent'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
