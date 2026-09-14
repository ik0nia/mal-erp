<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorEvent extends Model
{
    protected $fillable = [
        'fingerprint', 'exception_class', 'message', 'file', 'line', 'trace',
        'url', 'method', 'context', 'user_id', 'count', 'status',
        'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at'  => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Numele scurt al clasei (fără namespace). */
    public function getShortClassAttribute(): string
    {
        return class_basename($this->exception_class);
    }
}
