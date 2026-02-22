<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDeliveryAddress extends Model
{
    protected $fillable = [
        'customer_id',
        'label',
        'contact_name',
        'contact_phone',
        'address',
        'city',
        'county',
        'postal_code',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $address): void {
            $address->label = filled($address->label) ? trim((string) $address->label) : null;
            $address->contact_name = filled($address->contact_name) ? trim((string) $address->contact_name) : null;
            $address->contact_phone = filled($address->contact_phone) ? trim((string) $address->contact_phone) : null;
            $address->postal_code = filled($address->postal_code) ? trim((string) $address->postal_code) : null;
            $address->position = max(0, (int) $address->position);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
