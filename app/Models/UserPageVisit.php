<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O accesare de pagină de către un utilizator (jurnal de navigare cronologic).
 * Alimentat de middleware-ul UpdateLastActivity::logPageVisit().
 */
class UserPageVisit extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'method', 'path', 'route_name', 'title', 'visited_at'];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLabelAttribute(): string
    {
        return static::labelFor($this->route_name, ltrim((string) $this->path, '/'));
    }

    /** Etichetă lizibilă dintr-un route_name Filament / cale. */
    public static function labelFor(?string $routeName, string $path): string
    {
        if ($routeName && str_contains($routeName, '.resources.')) {
            $parts = explode('.', $routeName);
            $res   = $parts[array_search('resources', $parts, true) + 1] ?? null;
            $page  = end($parts);
            if ($res) {
                return ucfirst(str_replace('-', ' ', $res)).($page && $page !== 'index' ? ' · '.$page : '');
            }
        }
        if ($routeName && str_contains($routeName, '.pages.')) {
            $parts = explode('.', $routeName);

            return 'Pagină · '.ucfirst(str_replace('-', ' ', (string) end($parts)));
        }

        return '/'.$path;
    }
}
