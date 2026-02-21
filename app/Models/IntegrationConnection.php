<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationConnection extends Model
{
    public const PROVIDER_WOOCOMMERCE = 'woocommerce';

    protected $fillable = [
        'location_id',
        'provider',
        'name',
        'base_url',
        'consumer_key',
        'consumer_secret',
        'verify_ssl',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'verify_ssl' => 'boolean',
            'is_active' => 'boolean',
            'settings' => 'array',
            'consumer_key' => 'encrypted',
            'consumer_secret' => 'encrypted',
            'location_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $connection): void {
            $connection->provider = $connection->provider ?: self::PROVIDER_WOOCOMMERCE;
            $connection->base_url = rtrim((string) $connection->base_url, '/');
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class, 'connection_id');
    }

    public function wooCategories(): HasMany
    {
        return $this->hasMany(WooCategory::class, 'connection_id');
    }

    public function wooProducts(): HasMany
    {
        return $this->hasMany(WooProduct::class, 'connection_id');
    }

    public function resolvePerPage(int $default = 100): int
    {
        $value = (int) data_get($this->settings, 'per_page', $default);

        return max(1, min(100, $value));
    }

    public function resolveTimeoutSeconds(int $default = 30): int
    {
        $value = (int) data_get($this->settings, 'timeout', $default);

        return max(5, $value);
    }
}
