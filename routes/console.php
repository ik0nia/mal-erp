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

// Fetch emailuri IMAP — la fiecare 5 minute, read-only (peek mode).
Schedule::job(new \App\Jobs\FetchEmailsJob())
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Procesare AI emailuri neprocesate — la fiecare 30 minute, batch de 100.
// Necesită cheia Anthropic API configurată în Setări aplicație.
Schedule::command('email:process-ai --limit=100')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Redescoperire contacte furnizori — zilnic la 03:00.
// Actualizează statistici și descoperă contacte noi din emailurile importate.
Schedule::command('supplier:discover-contacts')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

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

// Raport trimestrial BI — 1 ian, 1 apr, 1 iul, 1 oct la 06:00 (Europe/Bucharest).
// Acoperă ultimele 90 de zile, grupat săptămânal.
// Context: rapoartele lunare + săptămânale din perioadă.
Schedule::command('bi:generate-period-report --type=quarterly')
    ->monthlyOn(1, '06:00')
    ->timezone('Europe/Bucharest')
    ->when(fn () => in_array(now()->setTimezone('Europe/Bucharest')->month, [1, 4, 7, 10]))
    ->withoutOverlapping()
    ->runInBackground();

// Raport semestrial BI — 1 ian și 1 iul la 08:00 (Europe/Bucharest).
// Acoperă ultimele 180 de zile, grupat lunar.
// Context: rapoartele trimestriale + lunare din perioadă.
Schedule::command('bi:generate-period-report --type=semiannual')
    ->monthlyOn(1, '08:00')
    ->timezone('Europe/Bucharest')
    ->when(fn () => in_array(now()->setTimezone('Europe/Bucharest')->month, [1, 7]))
    ->withoutOverlapping()
    ->runInBackground();

// Raport anual BI — 1 ianuarie la 10:00 (Europe/Bucharest).
// Acoperă ultimele 365 de zile, grupat lunar (12 rânduri KPI).
// Context: rapoartele semestriale + trimestriale + lunare din an.
Schedule::command('bi:generate-period-report --type=annual')
    ->yearlyOn(1, 1, '10:00')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping()
    ->runInBackground();

