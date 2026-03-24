<?php

namespace App\Models;

use App\Enums\HasStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SocialPost extends Model
{
    use HasStatusEnum;
    protected $fillable = [
        'social_account_id',
        'woo_product_id',
        'brand_id',
        'created_by',
        'status',
        'brief_type',
        'brief_direction',
        'caption',
        'hashtags',
        'image_path',
        'template',
        'image_prompt',
        'platform_post_id',
        'platform_url',
        'scheduled_at',
        'published_at',
        'error_message',
        'graphic_title',
        'graphic_subtitle',
        'graphic_label',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    const STATUS_DRAFT      = 'draft';
    const STATUS_GENERATING = 'generating';
    const STATUS_READY      = 'ready';
    const STATUS_SCHEDULED  = 'scheduled';
    const STATUS_PUBLISHING = 'publishing';
    const STATUS_PUBLISHED  = 'published';
    const STATUS_FAILED     = 'failed';

    const TYPE_PRODUCT = 'product';
    const TYPE_BRAND   = 'brand';
    const TYPE_PROMO   = 'promo';
    const TYPE_GENERAL = 'general';

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT      => 'Draft',
            self::STATUS_GENERATING => 'Se generează...',
            self::STATUS_READY      => 'Gata de programare',
            self::STATUS_SCHEDULED  => 'Programată',
            self::STATUS_PUBLISHING => 'Se publică...',
            self::STATUS_PUBLISHED  => 'Publicată',
            self::STATUS_FAILED     => 'Eșuată',
        ];
    }

    public static function statusColorMap(): array
    {
        return [
            self::STATUS_DRAFT      => 'gray',
            self::STATUS_GENERATING => 'warning',
            self::STATUS_READY      => 'info',
            self::STATUS_SCHEDULED  => 'primary',
            self::STATUS_PUBLISHING => 'warning',
            self::STATUS_PUBLISHED  => 'success',
            self::STATUS_FAILED     => 'danger',
        ];
    }

    public static function typeOptions(): array
    {
        return [
            self::TYPE_PRODUCT => 'Produs',
            self::TYPE_BRAND   => 'Brand',
            self::TYPE_PROMO   => 'Promoție',
            self::TYPE_GENERAL => 'General',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WooProduct::class, 'woo_product_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_READY, self::STATUS_FAILED]);
    }

    public function getImageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }
        return Storage::disk('public')->url($this->image_path);
    }

    public function getFullCaption(): string
    {
        $parts = [];
        if (filled($this->caption)) {
            $parts[] = $this->caption;
        }
        if (filled($this->hashtags)) {
            $parts[] = $this->hashtags;
        }
        return implode("\n\n", $parts);
    }

    protected static function booted(): void
    {
        static::creating(function (self $post) {
            if (blank($post->created_by) && auth()->check()) {
                $post->created_by = auth()->id();
            }
        });
    }
}
