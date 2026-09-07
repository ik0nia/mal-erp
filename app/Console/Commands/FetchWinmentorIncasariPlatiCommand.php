<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fetch încasări clienți + plăți furnizori din WinMentor (READ-ONLY: GetIncasariLuna/GetPlatiLuna).
 *
 * Fără argumente: luna de lucru curentă (safe pentru rulare schedulată — nu comută luna COM
 * dacă e deja pe curentă). Cu --an/--luna: backfill istoric — de rulat DOAR în afara
 * ferestrei 08:00–17:30 (comută luna de lucru pe obiectul COM global).
 */
class FetchWinmentorIncasariPlatiCommand extends Command
{
    protected $signature = 'winmentor:fetch-incasari-plati
                            {--firma=MAL2019 : Firma WinMentor}
                            {--an= : Doar anul specificat (altfel luna curentă)}
                            {--luna= : Doar luna specificată (necesită --an)}
                            {--delay=15 : Secunde pauză între luni la backfill}';

    protected $description = 'Sincronizează încasările de la clienți și plățile către furnizori din WinMentor';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma = $this->option('firma');

        if (! ($bridge->health()['data']['comConnected'] ?? false)) {
            return self::SUCCESS; // WinMentor închis — normal noaptea
        }

        // Nu ne suprapunem cu fetch-urile mari (vanzari/emulare/istoric)
        if (Cache::has("winmentor_fetch_vanzari_{$firma}")) {
            $this->warn('Fetch vanzari/istoric în curs — ieșire.');
            return self::SUCCESS;
        }

        $lock = Cache::lock("winmentor_fetch_incasari_{$firma}", 240);
        if (! $lock->get()) {
            $this->warn('Alt fetch de încasări rulează (lock activ) — ieșire.');
            return self::SUCCESS;
        }

        try {
            $conn = \App\Models\IntegrationConnection::find(5);

            if ($this->option('an')) {
                $an   = (int) $this->option('an');
                $luni = $this->option('luna') ? [(int) $this->option('luna')] : range(12, 1);

                foreach ($luni as $luna) {
                    $this->fetchMonth($bridge, $firma, $an, $luna);
                    sleep(max(5, (int) $this->option('delay')));
                }

                // Restaurăm luna de lucru curentă
                $bridge->selectFirmaForMonth($conn->bridgeAn(), $conn->bridgeLuna(), $firma);
            } else {
                $this->fetchMonth($bridge, $firma, $conn->bridgeAn(), $conn->bridgeLuna());
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function fetchMonth(WinmentorBridgeClient $bridge, string $firma, int $an, int $luna): void
    {
        try {
            $bridge->selectFirmaForMonth($an, $luna, $firma);
            sleep(2);

            $incasari = $bridge->getIncasariLuna();
            $plati    = $bridge->getPlatiLuna();
        } catch (\Throwable $e) {
            $this->warn("  [{$luna}/{$an}] EROARE fetch: {$e->getMessage()} — luna rămâne neatinsă");
            Log::channel('winmentor_sync')->warning("[FetchIncasariPlati] {$luna}/{$an}: {$e->getMessage()}");
            return;
        }

        $nIncasari = $this->saveRows('winmentor_incasari_raw', $firma, $an, $luna, $incasari, 'dataIncasare');
        $nPlati    = $this->saveRows('winmentor_plati_raw', $firma, $an, $luna, $plati, 'data');

        $this->info("  [{$luna}/{$an}] ✓ încasări: {$nIncasari}, plăți: {$nPlati}");
        Log::channel('winmentor_sync')->info("[FetchIncasariPlati] {$luna}/{$an}: incasari={$nIncasari} plati={$nPlati}");
    }

    /**
     * Înlocuiește atomic luna. Fetch gol → luna rămâne neatinsă (nu ștergem pe o eroare mută).
     */
    private function saveRows(string $table, string $firma, int $an, int $luna, array $rows, string $dateKey): int
    {
        if (empty($rows)) {
            return 0;
        }

        $now    = now();
        $mapped = [];

        foreach ($rows as $row) {
            if (! is_array($row)) continue;

            $data = null;
            $raw  = trim($row[$dateKey] ?? '');
            if ($raw !== '') {
                try {
                    $data = Carbon::createFromFormat('d.m.Y', $raw)->toDateString();
                } catch (\Throwable) {
                    // dată neparsabilă — păstrăm rândul, fără dată
                }
            }

            $suma = str_replace(',', '.', trim($row['suma'] ?? ''));

            $mapped[] = [
                'firma'        => $firma,
                'an'           => $an,
                'luna'         => $luna,
                'data'         => $data,
                'pozitie'      => trim($row['pozitie'] ?? '') ?: null,
                'document_ref' => trim($row['documentRef'] ?? '') ?: null,
                'part_id'      => trim($row['idPartener'] ?? '') ?: null,
                'suma'         => $suma !== '' && is_numeric($suma) ? (float) $suma : null,
                'raw_row'      => json_encode($row, JSON_UNESCAPED_UNICODE),
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (empty($mapped)) {
            return 0;
        }

        DB::transaction(function () use ($table, $firma, $an, $luna, $mapped) {
            DB::table($table)->where('firma', $firma)->where('an', $an)->where('luna', $luna)->delete();
            collect($mapped)->chunk(500)->each(fn ($c) => DB::table($table)->insert($c->all()));
        });

        return count($mapped);
    }
}
