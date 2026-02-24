<?php

namespace App\Models;

use App\Concerns\HasLocationScope;
use App\Services\CompanyData\OpenApiCompanyLookupService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasLocationScope;

    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_COMPANY = 'company';

    protected $fillable = [
        'location_id',
        'type',
        'name',
        'representative_name',
        'phone',
        'email',
        'cui',
        'is_vat_payer',
        'registration_number',
        'address',
        'city',
        'county',
        'postal_code',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'is_vat_payer' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $customer): void {
            $customer->type = $customer->type ?: self::TYPE_INDIVIDUAL;
            $customer->name = trim((string) $customer->name);
            $customer->email = filled($customer->email) ? strtolower(trim((string) $customer->email)) : null;
            $customer->phone = filled($customer->phone) ? trim((string) $customer->phone) : null;
            $customer->cui = filled($customer->cui)
                ? OpenApiCompanyLookupService::normalizeCui((string) $customer->cui)
                : null;
            $customer->registration_number = filled($customer->registration_number)
                ? trim((string) $customer->registration_number)
                : null;
            $customer->postal_code = filled($customer->postal_code) ? trim((string) $customer->postal_code) : null;

            if ($customer->type === self::TYPE_INDIVIDUAL) {
                $customer->cui = null;
                $customer->is_vat_payer = null;
                $customer->registration_number = null;
                $customer->representative_name = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_INDIVIDUAL => 'Persoană fizică',
            self::TYPE_COMPANY => 'Persoană juridică',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function deliveryAddresses(): HasMany
    {
        return $this->hasMany(CustomerDeliveryAddress::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function isCompany(): bool
    {
        return $this->type === self::TYPE_COMPANY;
    }
}
