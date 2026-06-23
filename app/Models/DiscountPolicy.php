<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountPolicy extends Model
{
    public const SUBJECT_ROLE = 'role';
    public const SUBJECT_USER = 'user';

    public const SCOPE_ALL      = 'all';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_SUPPLIER = 'supplier';

    protected $fillable = [
        'subject_type',
        'role',
        'user_id',
        'scope_type',
        'woo_category_id',
        'supplier_id',
        'max_discount_percent',
        'approval_discount_percent',
        'is_active',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'woo_category_id' => 'integer',
            'supplier_id' => 'integer',
            'max_discount_percent' => 'decimal:2',
            'approval_discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public static function subjectOptions(): array
    {
        return [
            self::SUBJECT_ROLE => 'Rol',
            self::SUBJECT_USER => 'Utilizator (excepție)',
        ];
    }

    public static function scopeOptions(): array
    {
        return [
            self::SCOPE_ALL      => 'Toate produsele',
            self::SCOPE_CATEGORY => 'Categorie produs',
            self::SCOPE_SUPPLIER => 'Furnizor',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WooCategory::class, 'woo_category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Scor de specificitate: cu cât mai mare, cu atât mai prioritar.
     * user (10) > rol (0)  +  categorie/furnizor (1) > toate (0).
     */
    public function specificity(): int
    {
        $score = $this->subject_type === self::SUBJECT_USER ? 10 : 0;
        $score += $this->scope_type === self::SCOPE_ALL ? 0 : 1;

        return $score;
    }
}
