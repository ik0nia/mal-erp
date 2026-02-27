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
    public const PROVIDER_SAMEDAY = 'sameday';

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
        'webhook_secret',
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

    public function samedayAwbs(): HasMany
    {
        return $this->hasMany(SamedayAwb::class, 'integration_connection_id');
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

    public function isSameday(): bool
    {
        return $this->provider === self::PROVIDER_SAMEDAY;
    }

    public function csvUrl(): string
    {
        return trim((string) data_get($this->settings, 'csv_url', $this->base_url));
    }

    public function samedayUsername(): string
    {
        return trim((string) $this->consumer_key);
    }

    public function samedayPassword(): string
    {
        return trim((string) $this->consumer_secret);
    }

    public function samedayApiUrl(): string
    {
        $configured = trim((string) $this->base_url);

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return match (strtolower((string) data_get($this->settings, 'environment', 'production'))) {
            'demo' => 'https://sameday-api.demo.zitec.com',
            default => 'https://api.sameday.ro',
        };
    }

    public function shouldPushPriceToSite(bool $default = true): bool
    {
        return (bool) data_get($this->settings, 'push_price_to_site', $default);
    }

    public function shouldAutoScheduleWinmentorImport(bool $default = false): bool
    {
        if (! $this->isWinmentorCsv() || ! $this->is_active) {
            return false;
        }

        return (bool) data_get($this->settings, 'auto_sync_enabled', $default);
    }

    public function resolveWinmentorSyncIntervalMinutes(int $default = 60): int
    {
        $value = (int) data_get($this->settings, 'sync_interval_minutes', $default);

        return max(5, $value);
    }

    public function webhookUrl(): string
    {
        return secure_url('/webhooks/woo/' . $this->id);
    }

    public static function generateWebhookSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @return array<string, string>
     */
    public static function providerOptions(): array
    {
        return [
            self::PROVIDER_WOOCOMMERCE => 'WooCommerce',
            self::PROVIDER_WINMENTOR_CSV => 'WinMentor CSV',
            self::PROVIDER_SAMEDAY => 'Sameday Courier',
        ];
    }
}
