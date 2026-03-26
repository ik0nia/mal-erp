<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class RolePermission extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (self $model) => static::clearCache($model->role));
        static::deleted(fn (self $model) => static::clearCache($model->role));
    }

    protected $fillable = [
        'role',
        'resource',
        'can_access',
        'can_create',
        'can_edit',
        'can_delete',
        'can_view',
    ];

    protected function casts(): array
    {
        return [
            'can_access' => 'boolean',
            'can_create' => 'boolean',
            'can_edit'   => 'boolean',
            'can_delete' => 'boolean',
            'can_view'   => 'boolean',
        ];
    }

    // Cheie cache per rol
    public static function cacheKey(string $role): string
    {
        return "role_permissions:{$role}";
    }

    // Returnează toate permisiunile pentru un rol, indexate după resource
    public static function forRole(string $role): array
    {
        return Cache::remember(static::cacheKey($role), 300, function () use ($role): array {
            return static::where('role', $role)
                ->get()
                ->keyBy('resource')
                ->map(fn ($p) => [
                    'can_access' => $p->can_access,
                    'can_create' => $p->can_create,
                    'can_edit'   => $p->can_edit,
                    'can_delete' => $p->can_delete,
                    'can_view'   => $p->can_view,
                ])
                ->all();
        });
    }

    public static function clearCache(string $role): void
    {
        Cache::forget(static::cacheKey($role));
    }

    // Verifică o permisiune specifică pentru userul curent
    public static function check(string $resource, string $permission = 'can_access'): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        // Doar super_admin are acces la tot; admin respectă permisiunile din roluri
        if ($user->isSuperAdmin()) {
            return true;
        }

        $role = $user->role;
        if (! $role) {
            return false;
        }

        $permissions = static::forRole($role);

        // Dacă nu există o regulă explicit definită → acces refuzat implicit (default-deny)
        if (! isset($permissions[$resource])) {
            return false;
        }

        return (bool) ($permissions[$resource][$permission] ?? false);
    }
}
