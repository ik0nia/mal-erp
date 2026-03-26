<?php

namespace App\Filament\App\Concerns;

use App\Models\RolePermission;
use Illuminate\Database\Eloquent\Model;

trait ChecksRolePermissions
{
    // Cheia unică a resursei — override în fiecare resursă dacă e necesar
    protected static function permissionKey(): string
    {
        return static::class;
    }

    public static function canAccess(): bool
    {
        return RolePermission::check(static::permissionKey(), 'can_access');
    }

    public static function canViewAny(): bool
    {
        return RolePermission::check(static::permissionKey(), 'can_access');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RolePermission::check(static::permissionKey(), 'can_access');
    }

    public static function canCreate(): bool
    {
        return RolePermission::check(static::permissionKey(), 'can_create');
    }

    public static function canEdit(Model $record): bool
    {
        return RolePermission::check(static::permissionKey(), 'can_edit');
    }

    public static function canDelete(Model $record): bool
    {
        return RolePermission::check(static::permissionKey(), 'can_delete');
    }

    public static function canView(Model $record): bool
    {
        return RolePermission::check(static::permissionKey(), 'can_view');
    }
}
