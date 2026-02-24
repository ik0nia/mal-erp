<?php

namespace App\Filament\App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait EnforcesLocationScope
{
    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected static function applyLocationFilter(Builder $query): Builder
    {
        $user = static::currentUser();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->visibleToUser($user);
    }

    protected static function canAccessRecord(Model $record): bool
    {
        $user = static::currentUser();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $locationIds = $user->operationalLocationIds();

        return in_array((int) $record->location_id, $locationIds, true);
    }
}
