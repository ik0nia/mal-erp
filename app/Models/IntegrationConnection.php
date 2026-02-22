<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IntegrationConnection extends Model
{
    public const PROVIDER_WOOCOMMERCE = 'woocommerce';
    public const PROVIDER_WINMENTOR_CSV = 'winmentor_csv';

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

            if ($connection->provider === self::PROVIDER_WINMENTOR_CSV) {
                $csvUrl = trim((string) data_get($connection->settings, 'csv_url', $connection->base_url));
                $connection->base_url = $csvUrl;
                $connection->consumer_key = '';
                $connection->consumer_secret = '';
            } else {
                $connection->base_url = rtrim((string) $connection->base_url, '/');
            }
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

    public function latestSyncRun(): HasOne
    {
        return $this->hasOne(SyncRun::class, 'connection_id')->latestOfMany('started_at');
    }

    public function wooCategories(): HasMany
    {
        return $this->hasMany(WooCategory::class, 'connection_id');
    }

    public function wooProducts(): HasMany
    {
        return $this->hasMany(WooProduct::class, 'connection_id');
    }

    public function resolvePerPage(int $default = 50): int
    {
        $value = (int) data_get($this->settings, 'per_page', $default);

        return max(1, min(100, $value));
    }

    public function resolveTimeoutSeconds(int $default = 30): int
    {
        $value = (int) data_get($this->settings, 'timeout', $default);

        return max(5, $value);
    }

    public function isWooCommerce(): bool
    {
        return $this->provider === self::PROVIDER_WOOCOMMERCE;
    }

    public function isWinmentorCsv(): bool
    {
        return $this->provider === self::PROVIDER_WINMENTOR_CSV;
    }

    public function csvUrl(): string
    {
        return trim((string) data_get($this->settings, 'csv_url', $this->base_url));
    }

    public function shouldPushPriceToSite(bool $default = true): bool
    {
        return (bool) data_get($this->settings, 'push_price_to_site', $default);
    }

    /**
     * @return array<string, string>
     */
    public static function providerOptions(): array
    {
        return [
            self::PROVIDER_WOOCOMMERCE => 'WooCommerce',
            self::PROVIDER_WINMENTOR_CSV => 'WinMentor CSV',
        ];
    }
}
