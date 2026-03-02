<?php

namespace App\Filament\App\Concerns;

use App\Models\AppSetting;

trait HasDynamicNavSort
{
    public static function getNavigationSort(): ?int
    {
        $key     = 'nav_item_sort_' . str_replace('\\', '_', static::class);
        $stored  = AppSetting::get($key);

        return $stored !== null ? (int) $stored : static::$navigationSort;
    }
}
