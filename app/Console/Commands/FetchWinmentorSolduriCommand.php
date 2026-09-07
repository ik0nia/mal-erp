<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincronizează scadențarul OFICIAL WinMentor (rest de plată per factură).
 *
 * GetSolduriExt (clienți) + GetSolduriFurn (furnizori) sunt apeluri COM GRELE
 * (citesc tot soldul, pot dura minute) — de rulat nocturn / în afara ferestrei
 * 08:00-17:30. READ-ONLY pe WinMentor; lucrează pe luna curentă (fără comutare).
 */
class FetchWinmentorSolduriCommand extends Command
{
    protected $signature = 'winmentor:fetch-solduri
                            {--firma=MAL2019 : Firma WinMentor}
                            {--directie=ambele : client | furnizor | ambele}';

    protected $description = 'Sincronizează soldurile per factură (scadențar oficial) din WinMentor';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma = $this->option('firma');

        if (! ($bridge->health()['data']['comConnected'] ?? false)) {
            return self::SUCCESS;
        }

        if (Cache::has("winmentor_fetch_vanzari_{$firma}")) {
            $this->warn('Fetch mare în curs — ieșire.');
            return self::SUCCESS;
        }

        $lock = Cache::lock("winmentor_fetch_solduri_{$firma}", 1800);
        if (! $lock->get()) {
            return self::SUCCESS;
        }

        try {
            $directie = $this->option('directie');

            if (in_array($directie, ['client', 'ambele'], true)) {
                $this->fetchDirectie($bridge, $firma, 'client', '/api/solduri/ext');
            }
            if (in_array($directie, ['furnizor', 'ambele'], true)) {
                $this->fetchDirectie($bridge, $firma, 'furnizor', '/api/solduri/furnizori');
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function fetchDirectie(WinmentorBridgeClient $bridge, string $firma, string $directie, string $path): void
    {
        $this->line("Fetch solduri {$directie} (poate dura câteva minute)...");
        $start = microtime(true);

        try {
            $ref = new \ReflectionMethod($bridge, 'get');
            $ref->setAccessible(true);
            // pageSize mare — vrem tot; apelul DLL oricum citește tot soldul
            $result = $ref->invoke($bridge, $path, ['pageSize' => 100000], true, 900);
            $rows   = $result['data'] ?? [];
        } catch (\Throwable $e) {
            $this->error("  {$directie}: EROARE {$e->getMessage()} — datele existente rămân neatinse");
            Log::channel('winmentor_sync')->warning("[FetchSolduri] {$directie}: {$e->getMessage()}");
            return;
        }

        if (empty($rows)) {
            $this->warn("  {$directie}: 0 rânduri — nu șterg datele existente (posibilă eroare mută)");
            return;
        }

        $now    = now();
        $mapped = [];

        foreach ($rows as $row) {
            $mapped[] = [
                'firma'           => $firma,
                'directie'        => $directie,
                'part_id'         => trim($row['idPartener'] ?? '') ?: null,
                'tip_document'    => trim($row['tipDocument'] ?? $row['tipDoc'] ?? '') ?: null,
                'nr_factura'      => trim($row['nrFactura'] ?? $row['nrDocument'] ?? '') ?: null,
                'data_factura'    => $this->parseDate($row['dataFactura'] ?? $row['dataDocument'] ?? null),
                'termen_plata'    => $this->parseDate($row['termenDePlata'] ?? $row['dataScadenta'] ?? null),
                'valoare_factura' => $this->parseNum($row['valoareFactura'] ?? $row['valoareDoc'] ?? null),
                'rest_de_plata'   => $this->parseNum($row['restDePlata'] ?? null),
                'moneda'          => trim($row['moneda'] ?? '') ?: null,
                'locatie'         => trim($row['locatiePartener'] ?? $row['sediu'] ?? '') ?: null,
                'marca_agent'     => trim($row['marcaAgent'] ?? '') ?: null,
                'raw_row'         => json_encode($row, JSON_UNESCAPED_UNICODE),
                'fetched_at'      => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        DB::transaction(function () use ($firma, $directie, $mapped) {
            DB::table('winmentor_solduri_raw')->where('firma', $firma)->where('directie', $directie)->delete();
            collect($mapped)->chunk(500)->each(fn ($c) => DB::table('winmentor_solduri_raw')->insert($c->all()));
        });

        $secs = round(microtime(true) - $start);
        $this->info("  {$directie}: ✓ " . count($mapped) . " rânduri în {$secs}s");
        Log::channel('winmentor_sync')->info("[FetchSolduri] {$directie}: " . count($mapped) . " rânduri ({$secs}s)");
    }

    private function parseDate(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        try {
            return Carbon::createFromFormat('d.m.Y', $v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseNum(?string $v): ?float
    {
        $v = str_replace(',', '.', trim((string) $v));
        return $v !== '' && is_numeric($v) ? (float) $v : null;
    }
}
