<?php
/**
 * Plugin Name: Malinco — Etichetă livrare SameDay
 * Description: Redenumește serviciul SameDay „24H" în „Livrare Sameday" la coș/checkout/email/admin. Rezistent la re-sync (nu modifică datele pluginului).
 * Version: 1.0.0
 * Author: Malinco
 */

if (!defined('ABSPATH')) { exit; }

add_filter('woocommerce_package_rates', function ($rates) {
    if (!is_array($rates)) { return $rates; }
    foreach ($rates as $rate) {
        if (!is_object($rate) || !method_exists($rate, 'get_method_id')) { continue; }
        if (strpos((string) $rate->get_method_id(), 'samedaycourier') !== 0) { continue; }
        $label = trim(wp_strip_all_tags((string) $rate->get_label()));
        if ($label === '24H' || strcasecmp($label, '24h') === 0) {
            $rate->label = 'Livrare Sameday';
        }
    }
    return $rates;
}, 20);
