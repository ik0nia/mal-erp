<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class SmAccount extends Model
{
    protected $table = 'sm_accounts';

    protected $fillable = [
        'name',
        'platform',
        'page_id',
        'instagram_business_id',
        'access_token',
        'token_expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'        => 'boolean',
            'token_expires_at' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SmPost::class, 'sm_account_id');
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        if (! $value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function isFacebook(): bool
    {
        return $this->platform === 'facebook';
    }

    public function isInstagram(): bool
    {
        return $this->platform === 'instagram';
    }
}
