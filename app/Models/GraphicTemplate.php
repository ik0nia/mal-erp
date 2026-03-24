<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GraphicTemplate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'layout',
        'config',
        'preview_image',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config'    => 'array',
            'is_active' => 'boolean',
        ];
    }

    public static function default(): ?self
    {
        return static::where('slug', 'malinco-default')->first()
            ?? static::where('is_active', true)->first();
    }
}
