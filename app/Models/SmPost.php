<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SmPost extends Model
{
    protected $table = 'sm_posts';

    const TYPE_PRODUCT  = 'product';
    const TYPE_CATEGORY = 'category';
    const TYPE_BRAND    = 'brand';

    const STATUS_DRAFT      = 'draft';
    const STATUS_GENERATING = 'generating';
    const STATUS_READY      = 'ready';
    const STATUS_APPROVED   = 'approved';
    const STATUS_SCHEDULED  = 'scheduled';
    const STATUS_PUBLISHING = 'publishing';
    const STATUS_PUBLISHED  = 'published';
    const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'type',
        'sourceable_type',
        'sourceable_id',
        'status',
        'caption',
        'hashtags',
        'image_path',
        'image_prompt',
        'graphic_texts',
        'canvas_json',
        'platforms',
        'scheduled_at',
        'published_at',
        'fb_post_id',
        'ig_post_id',
        'error_message',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'hashtags'      => 'array',
            'graphic_texts' => 'array',
            'canvas_json'   => 'array',
            'platforms'     => 'array',
            'scheduled_at'  => 'datetime',
            'published_at'  => 'datetime',
            'approved_at'   => 'datetime',
        ];
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_READY, self::STATUS_FAILED]);
    }

    public function isApprovable(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isSchedulable(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function getImageUrl(): ?string
    {
        if (! $this->image_path) return null;
        return asset('storage/' . $this->image_path);
    }

    public function getSourceNameAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_PRODUCT  => $this->sourceable?->name ?? '—',
            self::TYPE_CATEGORY => $this->sourceable?->name ?? '—',
            self::TYPE_BRAND    => $this->sourceable?->name ?? '—',
            default             => '—',
        };
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_PRODUCT  => 'Produs',
            self::TYPE_CATEGORY => 'Categorie',
            self::TYPE_BRAND    => 'Brand',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT      => 'Schiță',
            self::STATUS_GENERATING => 'Se generează...',
            self::STATUS_READY      => 'Gata de revizuire',
            self::STATUS_APPROVED   => 'Aprobat',
            self::STATUS_SCHEDULED  => 'Programat',
            self::STATUS_PUBLISHING => 'Se publică...',
            self::STATUS_PUBLISHED  => 'Publicat',
            self::STATUS_FAILED     => 'Eșuat',
        ];
    }

    public static function statusColors(): array
    {
        return [
            self::STATUS_DRAFT      => 'gray',
            self::STATUS_GENERATING => 'info',
            self::STATUS_READY      => 'warning',
            self::STATUS_APPROVED   => 'success',
            self::STATUS_SCHEDULED  => 'primary',
            self::STATUS_PUBLISHING => 'info',
            self::STATUS_PUBLISHED  => 'success',
            self::STATUS_FAILED     => 'danger',
        ];
    }
}
