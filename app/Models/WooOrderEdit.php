<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WooOrderEdit extends Model
{
    protected $fillable = [
        'woo_order_id', 'user_email', 'action', 'label',
        'before', 'after', 'reverted_at', 'reverted_by',
    ];

    protected function casts(): array
    {
        return [
            'before'      => 'array',
            'after'       => 'array',
            'reverted_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WooOrder::class, 'woo_order_id');
    }

    public function isRevertible(): bool
    {
        return $this->reverted_at === null
            && in_array($this->action, ['remove_item', 'add_item', 'edit_items', 'edit_address'], true);
    }
}
