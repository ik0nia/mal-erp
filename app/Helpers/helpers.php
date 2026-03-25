<?php

if (! function_exists('formatQty')) {
    /**
     * Format a quantity: no decimals if integer, 2 decimals otherwise.
     * Never uses comma as thousands separator.
     */
    function formatQty($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $value = (float) $value;

        if (floor($value) == $value) {
            return number_format($value, 0, '.', '');
        }

        return number_format($value, 2, '.', '');
    }
}
