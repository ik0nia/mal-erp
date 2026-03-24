<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierFeed extends Model
{
    public const PROVIDER_TOYA_API    = 'toya_api';
    public const PROVIDER_CSV_URL     = 'csv_url';
    public const PROVIDER_XLSX_UPLOAD = 'xlsx_upload';
    public const PROVIDER_MANUAL      = 'manual';

    protected $fillable = [
        'supplier_id',
        'provider',
        'label',
        'is_active',
        'settings',
        'last_sync_at',
        'last_sync_status',
        'last_sync_summary',
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'settings'     => 'array',
            'last_sync_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public static function providerOptions(): array
    {
        return [
            self::PROVIDER_TOYA_API    => 'Toya API (Pimcore)',
            self::PROVIDER_CSV_URL     => 'Feed CSV (URL)',
            self::PROVIDER_XLSX_UPLOAD => 'Excel / XLSX (upload manual)',
            self::PROVIDER_MANUAL      => 'Manual (fără feed automat)',
        ];
    }

    public function getDiscount(): float
    {
        return (float) data_get($this->settings, 'discount', 0);
    }

    public function getMarkup(): float
    {
        return (float) data_get($this->settings, 'markup', 0);
    }

    public function getVat(): float
    {
        return (float) data_get($this->settings, 'vat', 21);
    }

    public function getApiKey(): string
    {
        return (string) data_get($this->settings, 'api_key', '');
    }

    public function getCsvUrl(): string
    {
        return (string) data_get($this->settings, 'url', '');
    }
}
