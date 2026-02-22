<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyApiSetting extends Model
{
    public const PROVIDER_OPENAPI = 'openapi';

    protected $fillable = [
        'provider',
        'name',
        'base_url',
        'api_key',
        'timeout',
        'verify_ssl',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'timeout' => 'integer',
            'verify_ssl' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            $setting->provider = $setting->provider ?: self::PROVIDER_OPENAPI;
            $setting->base_url = rtrim((string) $setting->base_url, '/');
            $setting->timeout = max(5, (int) $setting->timeout);
        });
    }

    public static function activeOpenApi(): ?self
    {
        return static::query()
            ->where('provider', self::PROVIDER_OPENAPI)
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    public function resolvedBaseUrl(): string
    {
        return rtrim((string) $this->base_url, '/');
    }

    public function resolveTimeoutSeconds(int $default = 30): int
    {
        return max(5, (int) ($this->timeout ?: $default));
    }
}
