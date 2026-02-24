<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait HasLocationScope
{
    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        $locationIds = $user->operationalLocationIds();

        if (empty($locationIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('location_id', $locationIds);
    }
}
