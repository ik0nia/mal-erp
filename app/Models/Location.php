<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    /**
     * V1 este single-company. În viitor putem introduce multi-tenant/franciză adăugând tenant_id
     * pe locations și pe toate entitățile operaționale.
     */
    protected $fillable = [
        'name',
        'type',
        'store_id',
        'address',
        'city',
        'county',
        'company_name',
        'company_vat_number',
        'company_is_vat_payer',
        'company_registration_number',
        'company_postal_code',
        'company_phone',
        'company_bank',
        'company_bank_account',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'store_id' => 'integer',
        'company_is_vat_payer' => 'boolean',
    ];

    public const TYPE_STORE = 'store';
    public const TYPE_WAREHOUSE = 'warehouse';
    public const TYPE_OFFICE = 'office';

    public static function typeOptions(): array
    {
        return [
            self::TYPE_STORE => 'store',
            self::TYPE_WAREHOUSE => 'warehouse',
            self::TYPE_OFFICE => 'office',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $location): void {
            if ($location->type !== self::TYPE_WAREHOUSE) {
                $location->store_id = null;
            }

            if ($location->type === self::TYPE_WAREHOUSE) {
                $location->company_name = null;
                $location->company_vat_number = null;
                $location->company_is_vat_payer = null;
                $location->company_registration_number = null;
                $location->company_postal_code = null;
                $location->company_phone = null;
                $location->company_bank = null;
                $location->company_bank_account = null;
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(self::class, 'store_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(self::class, 'store_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function productPriceLogs(): HasMany
    {
        return $this->hasMany(ProductPriceLog::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function samedayAwbs(): HasMany
    {
        return $this->hasMany(SamedayAwb::class);
    }
}
