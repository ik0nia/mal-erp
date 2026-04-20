<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Horizon metrics snapshot — la fiecare 5 minute (grafice throughput în dashboard).
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Snapshot de închidere zilnică la 17:30 (closing stock pentru rapoarte).
// Snapshot-urile intra-zi sunt preluate direct de winmentor:sync-stock-bridge (la 5 minute).
Schedule::command('stock:snapshot-daily-metrics')
    ->dailyAt('17:30')
    ->days([1, 2, 3, 4, 5, 6])
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping()
    ->runInBackground();

// Runs every minute and dispatches due WinMentor imports based on each connection settings.
Schedule::command('stock:dispatch-scheduled-winmentor')
    ->everyMinute()
    ->withoutOverlapping();

// Fetch emailuri IMAP — la fiecare 5 minute, read-only (peek mode).
Schedule::job(new \App\Jobs\FetchEmailsJob())
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Parsare documente furnizori (PDF/XLSX) — zilnic la 02:00, prinde emailurile ratate.
Schedule::command('email:parse-supplier-docs --no-report')
    ->dailyAt('02:00')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping()
    ->runInBackground();

// Procesare AI emailuri neprocesate — DEZACTIVAT temporar (consum API).
// Schedule::command('email:process-ai --limit=100')
//     ->everyThirtyMinutes()
//     ->withoutOverlapping()
//     ->runInBackground();

// Redescoperire contacte furnizori — DEZACTIVAT temporar (consum API).
// Schedule::command('supplier:discover-contacts')
//     ->dailyAt('03:00')
//     ->withoutOverlapping()
//     ->runInBackground();

// Social Media — publică postările programate la timp.
Schedule::command('social:publish-scheduled')
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

// Curățare sync_runs mai vechi de 30 de zile — zilnic la 00:15.
Schedule::call(function () {
    \DB::table('sync_runs')
        ->where('created_at', '<', now()->subDays(30))
        ->delete();
})->dailyAt('00:15')->timezone('Europe/Bucharest');

// BI data layer — zilnic la 00:30 (Europe/Bucharest).
// Procesează ziua de ieri (complet înghețată după miezul nopții).
// Ordinea internă: KPI → Velocity → Alerts.
Schedule::command('bi:compute-daily')
    ->dailyAt('00:30')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping()
    ->runInBackground();

// Watchdog BI — zilnic la 09:00 (Europe/Bucharest).
// Dacă bi:compute-daily nu a rulat (sau a eșuat) noaptea trecută, îl rulează acum.
// Auto-healing: detectează și repară singur dacă lipsesc date din ziua anterioară.
Schedule::command('bi:health-check')
    ->dailyAt('09:00')
    ->timezone('Europe/Bucharest');

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

// Toya — sync prețuri achiziție + alerte modificări semnificative (zilnic la 07:00).
Schedule::command('toya:sync-prices')
    ->dailyAt('07:00')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping()
    ->runInBackground();

// Alerte prețuri achiziție — zilnic la 08:30 (Europe/Bucharest).
// Detectează anomalii nealertate (spike/drop) unde prețul de vânzare nu a fost actualizat
// și produse cu marjă sub 10%. Trimite notificări la buyers + manageri.
Schedule::command('erp:alert-price-changes')
    ->dailyAt('08:30')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping();

// Clasificare ABC/XYZ produse — zilnic la 01:00 (Europe/Bucharest).
// Calculează consum mediu zilnic, clasificare ABC/XYZ și reorder_qty.
Schedule::command('erp:compute-abc-classification')
    ->dailyAt('01:00')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping()
    ->runInBackground();

// WinMentor — asociere PO-uri cu recepții contabile (la fiecare 30 minute).
Schedule::command('winmentor:match-po-receptie --firma=MAL2019')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// WinMentor — arhivare loguri mai vechi de 30 de zile (1 ale lunii la 02:00).
Schedule::command('winmentor:archive-logs')
    ->monthlyOn(1, '02:00')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping();

// Health check workeri — la fiecare 30 de minute.
// Verifică failed_jobs noi și workers cu SyncRun stale → alertă e-mail la codrut@ikonia.ro.
Schedule::command('erp:workers-health-check')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// WinMentor Bridge — sync stoc + preț de vânzare (la fiecare 5 minute).
// Doar clasa 1, gestiunea MP, luni–sâmbătă 08:00–17:30.
// Înlocuiește conexiunea CSV pentru stoc și preț de vânzare.
Schedule::command('winmentor:sync-stock-bridge')
    ->everyFiveMinutes()
    ->timezone('Europe/Bucharest')
    ->days([1, 2, 3, 4, 5, 6])
    ->between('08:00', '17:30')
    ->withoutOverlapping()
    ->runInBackground();

// WinMentor Bridge — detectare modificări SKU/denumire articole (la fiecare 5 minute).
// Când detectează o modificare, actualizează ERP + WooCommerce și trimite e-mail.
Schedule::command('winmentor:detect-article-changes')
    ->everyFiveMinutes()
    ->timezone('Europe/Bucharest')
    ->days([1, 2, 3, 4, 5, 6])
    ->between('08:00', '17:30')
    ->withoutOverlapping()
    ->runInBackground();

// WinMentor — detectare intrări noi și procesare automată (la fiecare 15 minute).
// Rulează doar luni–sâmbătă între 08:00–17:30 (Europe/Bucharest).
// Se oprește singur dacă COM nu e conectat (WinMentor închis).
Schedule::command('winmentor:watch-intrari --firma=MAL2019')
    ->everyFifteenMinutes()
    ->timezone('Europe/Bucharest')
    ->days([1, 2, 3, 4, 5, 6])
    ->between('08:00', '17:30')
    ->withoutOverlapping()
    ->runInBackground();

// WinMentor — detectare vânzări noi (la fiecare 15 minute).
// Același program ca intrările — luni–sâmbătă 08:00–17:30.
// Nu rulează concurent cu fetch-ul de backfill (blocat prin Cache lock).
Schedule::command('winmentor:watch-vanzari --firma=MAL2019')
    ->everyFifteenMinutes()
    ->timezone('Europe/Bucharest')
    ->days([1, 2, 3, 4, 5, 6])
    ->between('08:00', '17:30')
    ->withoutOverlapping()
    ->runInBackground();

