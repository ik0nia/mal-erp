<?php

namespace App\Models;

use App\Concerns\HasLocationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SamedayAwb extends Model
{
    use HasLocationScope;

    public const STATUS_CREATED = 'created';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'location_id',
        'user_id',
        'integration_connection_id',
        'provider',
        'status',
        'awb_number',
        'service_id',
        'pickup_point_id',
        'recipient_name',
        'recipient_phone',
        'recipient_email',
        'recipient_county',
        'recipient_city',
        'recipient_address',
        'recipient_postal_code',
        'package_count',
        'package_weight_kg',
        'cod_amount',
        'insured_value',
        'shipping_cost',
        'reference',
        'observation',
        'request_payload',
        'response_payload',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'user_id' => 'integer',
            'integration_connection_id' => 'integer',
            'service_id' => 'integer',
            'pickup_point_id' => 'integer',
            'package_count' => 'integer',
            'package_weight_kg' => 'decimal:3',
            'cod_amount' => 'decimal:2',
            'insured_value' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
