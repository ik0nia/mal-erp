<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    /**
     * V1 este single-company. În viitor putem introduce multi-tenant/franciză adăugând tenant_id
     * pe locations și pe toate entitățile operaționale.
     */
    protected $fillable = [
        'name',
        'type',
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
}
