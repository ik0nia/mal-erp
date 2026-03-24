<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    protected $fillable = [
        'name',
        'platform',
        'account_id',
        'access_token',
        'token_expires_at',
        'is_active',
        'style_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at'  => 'datetime',
            'style_analyzed_at' => 'datetime',
            'is_active'         => 'boolean',
        ];
    }

    const PLATFORM_FACEBOOK  = 'facebook';
    const PLATFORM_INSTAGRAM = 'instagram';

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function fetchedPosts(): HasMany
    {
        return $this->hasMany(SocialFetchedPost::class);
    }

    public function styleProfiles(): HasMany
    {
        return $this->hasMany(SocialStyleProfile::class);
    }

    public function activeStyleProfile(): ?SocialStyleProfile
    {
        return $this->styleProfiles()->where('is_active', true)->latest('generated_at')->first();
    }

    public function getAccessToken(): ?string
    {
        if (blank($this->access_token)) {
            return null;
        }
        try {
            return decrypt($this->access_token);
        } catch (\Throwable) {
            return $this->access_token;
        }
    }

    public function setAccessToken(string $token): void
    {
        $this->access_token = encrypt($token);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function isTokenExpiringSoon(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->diffInDays(now()) <= 7;
    }

    public function getPlatformLabel(): string
    {
        return match ($this->platform) {
            self::PLATFORM_FACEBOOK  => 'Facebook',
            self::PLATFORM_INSTAGRAM => 'Instagram',
            default                  => ucfirst($this->platform),
        };
    }
}
