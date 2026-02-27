<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dead stock threshold (RON)
    |--------------------------------------------------------------------------
    | Un produs cu avg_out_30d = 0 și stock_value >= acest prag devine P2.
    | Ajustabil per mediu via BI_DEAD_STOCK_THRESHOLD în .env.
    */
    'dead_stock_value_threshold' => (int) env('BI_DEAD_STOCK_THRESHOLD', 300),

    /*
    |--------------------------------------------------------------------------
    | Price spike threshold (%)
    |--------------------------------------------------------------------------
    | Variație procentuală a prețului (opening → closing, în aceeași zi)
    | care declanșează flag-ul 'price_spike' și urcă produsul la P0.
    | Stocat ca procent întreg (ex: 20 = 20%). Comparat cu abs((new-old)/old*100).
    */
    'alert_price_spike_pct' => (float) env('BI_PRICE_SPIKE_PCT', 20.0),

    /*
    |--------------------------------------------------------------------------
    | P0 / P1 days-left thresholds
    |--------------------------------------------------------------------------
    | days_left_estimate <= p0_days_left  → P0 (critical_stock)
    | p0 < days_left_estimate <= p1_days_left → P1 (low_stock)
    */
    'alert_p0_days_left' => (int) env('BI_P0_DAYS_LEFT', 7),
    'alert_p1_days_left' => (int) env('BI_P1_DAYS_LEFT', 14),

];
