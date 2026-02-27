<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs every minute and dispatches due WinMentor imports based on each connection settings.
Schedule::command('stock:dispatch-scheduled-winmentor')
    ->everyMinute()
    ->withoutOverlapping();

// Fallback sync comenzi — prinde orice a ratat webhook-ul.
Schedule::command('woo:sync-orders')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Sync categorii WooCommerce — categoriile nu au webhook, le aducem periodic.
Schedule::command('woo:sync-categories')
    ->everySixHours()
    ->withoutOverlapping();

// BI data layer — zilnic la 00:30 (Europe/Bucharest).
// Procesează ziua de ieri (complet înghețată după miezul nopții).
// Ordinea internă: KPI → Velocity → Alerts.
Schedule::command('bi:compute-daily')
    ->dailyAt('00:30')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping()
    ->runInBackground();

// Raport săptămânal BI — în fiecare duminică la 05:00 (Europe/Bucharest).
// Acoperă ultimele 7 zile complete (luni–sâmbătă).
// Folosește exclusiv tabelele BI pre-calculate (nu daily_stock_metrics).
Schedule::command('bi:generate-weekly-report')
    ->weeklyOn(0, '05:00')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping()
    ->runInBackground();

// Raport lunar BI — în data de 1 a fiecărei luni la 11:00 (Europe/Bucharest).
// Acoperă ultimele 30 de zile, grupat săptămânal.
// Include toate rapoartele zilnice/săptămânale din perioadă ca și context pentru Claude.
Schedule::command('bi:generate-monthly-report')
    ->monthlyOn(1, '11:00')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping()
    ->runInBackground();

