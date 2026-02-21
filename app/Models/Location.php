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
        'company_registration_number',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'store_id' => 'integer',
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
                $location->company_registration_number = null;
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
}
