<?php

namespace App\Enums;

trait HasStatusEnum
{
    /**
     * Returnează lista de status-uri ca array pentru Select Filament.
     * Modelul trebuie să definească statusLabels() și statusConstants().
     */
    public static function statusOptions(): array
    {
        if (method_exists(static::class, 'statusLabels')) {
            return static::statusLabels();
        }
        // Fallback: generează din constante STATUS_*
        $options = [];
        foreach ((new \ReflectionClass(static::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATUS_') && is_string($value)) {
                $options[$value] = ucfirst(str_replace('_', ' ', $value));
            }
        }
        return $options;
    }

    /**
     * Returnează culorile Filament pentru fiecare status.
     */
    public static function statusColors(): array
    {
        if (method_exists(static::class, 'statusColorMap')) {
            return static::statusColorMap();
        }
        // Fallback: toate gri
        $colors = [];
        foreach (array_keys(static::statusOptions()) as $status) {
            $colors[$status] = 'gray';
        }
        return $colors;
    }
}
