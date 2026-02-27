<?php

namespace App\Models;

use App\Concerns\HasLocationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WooOrder extends Model
{
    use HasLocationScope;

    public const STATUS_LABELS = [
        'pending'    => 'În așteptare',
        'processing' => 'În procesare',
        'on-hold'    => 'În hold',
        'completed'  => 'Finalizat',
        'cancelled'  => 'Anulat',
        'refunded'   => 'Rambursat',
        'failed'     => 'Eșuat',
    ];

    public const STATUS_COLORS = [
        'pending'    => 'warning',
        'processing' => 'info',
        'on-hold'    => 'gray',
        'completed'  => 'success',
        'cancelled'  => 'danger',
        'refunded'   => 'danger',
        'failed'     => 'danger',
    ];

    protected $fillable = [
        'connection_id',
        'location_id',
        'woo_id',
        'number',
        'status',
        'currency',
        'customer_note',
        'billing',
        'shipping',
        'payment_method',
        'payment_method_title',
        'subtotal',
        'shipping_total',
        'discount_total',
        'fee_total',
        'tax_total',
        'total',
        'date_paid',
        'date_completed',
        'order_date',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'connection_id'  => 'integer',
            'location_id'    => 'integer',
            'woo_id'         => 'integer',
            'billing'        => 'array',
            'shipping'       => 'array',
            'data'           => 'array',
            'subtotal'       => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'fee_total'      => 'decimal:2',
            'tax_total'      => 'decimal:2',
            'total'          => 'decimal:2',
            'date_paid'      => 'datetime',
            'date_completed' => 'datetime',
            'order_date'     => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WooOrderItem::class, 'order_id');
    }

    public function samedayAwbs(): HasMany
    {
        return $this->hasMany(SamedayAwb::class, 'woo_order_id');
    }

    public function getCustomerNameAttribute(): string
    {
        $first = (string) data_get($this->billing, 'first_name', '');
        $last  = (string) data_get($this->billing, 'last_name', '');

        return trim($first.' '.$last);
    }

    public function getCustomerEmailAttribute(): string
    {
        return (string) data_get($this->billing, 'email', '');
    }

    public function getCustomerPhoneAttribute(): string
    {
        return (string) data_get($this->billing, 'phone', '');
    }
}
