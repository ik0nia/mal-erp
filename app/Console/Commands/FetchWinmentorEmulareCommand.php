<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fetch bonuri de casă din emularea casei de marcat (GetVanzariEmulare),
 * lună cu lună, în winmentor_emulare_raw.
 *
 * READ-ONLY pe WinMentor. Folosește ACELAȘI lock ca winmentor:fetch-vanzari,
 * astfel încât watch-vanzari (care schimbă și el luna de lucru) să nu ruleze
 * concurent cu noi.
 *
 * Semantica câmpurilor emulare (verificată empiric, 2026-07-12):
 *  - cantVanduta = cantitatea reală vândută (NU câmpul "cantitate", care e poziția)
 *  - valoare     = totalul BONULUI, repetat pe fiecare linie
 *  - pret        = preț unitar
 */
class FetchWinmentorEmulareCommand extends Command
{
    protected $signature = 'winmentor:fetch-emulare
                            {--firma=MAL2019 : Firma WinMentor}
                            {--delay=20 : Secunde pauză între luni (minim 15)}
                            {--force : Re-fetch luni deja importate}
                            {--an= : Procesează doar anul specificat}
                            {--luna= : Procesează doar luna specificată (necesită --an)}
                            {--de-la=2025-01 : Prima lună procesată (YYYY-MM), când nu se dă --an}
                            {--curenta : Doar luna curentă, cu re-fetch forțat (pentru cron)}';

    protected $description = 'Fetch bonuri emulare casă din WinMentor lună cu lună (read-only)';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma = $this->option('firma');
        $delay = max(15, (int) $this->option('delay'));

        // Același lock ca fetch-vanzari: serializare cu backfill-ul și cu watch-vanzari
        $lockKey = "winmentor_fetch_vanzari_{$firma}";
        if (Cache::has($lockKey)) {
            $this->warn('Un alt fetch/emulare rulează deja — ieșire.');
            return self::SUCCESS;
        }
        Cache::put($lockKey, true, now()->addHours(3));

        try {
            return $this->doFetch($bridge, $firma, $delay);
        } finally {
            // Repune luna de lucru curentă indiferent de rezultat
            try {
                $bridge->selectFirmaForMonth(now()->year, now()->month, $firma);
            } catch (\Throwable $e) {
                Log::channel('winmentor_sync')->error("[Emulare] Restore lună curentă eșuat: {$e->getMessage()}");
            }
            Cache::forget($lockKey);
        }
    }

    private function doFetch(WinmentorBridgeClient $bridge, string $firma, int $delay): int
    {
        $health = $bridge->health();
        if (! ($health['data']['comConnected'] ?? false)) {
            $this->error('WinMentor Bridge COM nu este conectat.');
            return self::FAILURE;
        }

        $luni = $this->buildMonthList();
        $this->info("Firma: {$firma} — " . count($luni) . ' luni de procesat, delay ' . $delay . 's');

        $first = true;
        foreach ($luni as [$an, $luna]) {
            $exists = DB::table('winmentor_emulare_raw')
                ->where(['firma' => $firma, 'an' => $an, 'luna' => $luna])
                ->exists();
            if ($exists && ! $this->option('force') && ! $this->option('curenta')) {
                $this->line("  [{$luna}/{$an}] deja importat — skip");
                continue;
            }

            if (! $first) sleep($delay);
            $first = false;

            try {
                $bridge->selectFirmaForMonth($an, $luna, $firma);
                sleep(3);
                $rows = $bridge->getVanzariEmulare();
            } catch (\Throwable $e) {
                $this->error("  [{$luna}/{$an}] eroare fetch: {$e->getMessage()}");
                Log::channel('winmentor_sync')->warning("[Emulare] {$luna}/{$an}: {$e->getMessage()}");
                continue;
            }

            $saved = $this->saveMonth($firma, $an, $luna, $rows);
            $this->info("  [{$luna}/{$an}] " . count($rows) . " linii primite, {$saved} salvate");
        }

        return self::SUCCESS;
    }

    /** @return array<array{0:int,1:int}> */
    private function buildMonthList(): array
    {
        if ($this->option('curenta')) {
            return [[now()->year, now()->month]];
        }

        if ($this->option('an')) {
            $an = (int) $this->option('an');
            if ($this->option('luna')) {
                return [[$an, (int) $this->option('luna')]];
            }
            $end = $an === now()->year ? now()->month : 12;
            return array_map(fn ($l) => [$an, $l], range(1, $end));
        }

        $start = Carbon::createFromFormat('Y-m', $this->option('de-la'))->startOfMonth();
        $luni = [];
        for ($c = $start->copy(); $c->lessThanOrEqualTo(now()->startOfMonth()); $c->addMonth()) {
            $luni[] = [$c->year, $c->month];
        }

        return $luni;
    }

    private function saveMonth(string $firma, int $an, int $luna, array $rows): int
    {
        $toNum = function ($v): ?float {
            $v = str_replace(',', '.', trim((string) $v));
            return is_numeric($v) ? (float) $v : null;
        };

        $now     = now();
        $records = [];

        foreach ($rows as $row) {
            if (! is_array($row)) continue;

            $dataBon = null;
            $zi      = null;
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($row['data'] ?? ''), $m)) {
                $dataBon = "{$m[3]}-{$m[2]}-{$m[1]}";
                $zi      = (int) $m[1];
            }

            $records[] = [
                'firma'           => $firma,
                'an'              => $an,
                'luna'            => $luna,
                'zi'              => $zi,
                'id_bon'          => trim($row['idBon'] ?? '') ?: null,
                'pozitie'         => trim($row['pozitie'] ?? '') ?: null,
                'data_bon'        => $dataBon,
                'valoare_bon'     => $toNum($row['valoare'] ?? null),
                'nume_client'     => trim($row['numeClient'] ?? '') ?: null,
                'cantitate'       => $toNum($row['cantVanduta'] ?? null),
                'pret'            => $toNum($row['pret'] ?? null),
                'den_articol'     => mb_substr(trim($row['denArticol'] ?? ''), 0, 255) ?: null,
                'cod_articol'     => trim($row['codArticol'] ?? '') ?: null,
                'cod_extern'      => trim($row['codExtern'] ?? '') ?: null,
                'nr_casa'         => trim($row['nrComanda'] ?? '') ?: null,
                'den_gestiune'    => trim($row['denGestiune'] ?? '') ?: null,
                'simbol_gestiune' => trim($row['simbolGestiune'] ?? '') ?: null,
                'raw_row'         => json_encode($row, JSON_UNESCAPED_UNICODE),
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        return DB::transaction(function () use ($firma, $an, $luna, $records) {
            DB::table('winmentor_emulare_raw')
                ->where(['firma' => $firma, 'an' => $an, 'luna' => $luna])
                ->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('winmentor_emulare_raw')->insert($chunk);
            }

            return count($records);
        });
    }
}
