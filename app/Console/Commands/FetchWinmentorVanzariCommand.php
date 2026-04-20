<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchWinmentorVanzariCommand extends Command
{
    protected $signature = 'winmentor:fetch-vanzari
                            {--firma=MAL2019 : Firma WinMentor}
                            {--delay=20 : Secunde pauză între luni (minim 15)}
                            {--force : Re-fetch luni deja importate}
                            {--an= : Procesează doar anul specificat}
                            {--luna= : Procesează doar luna specificată (necesită --an)}';

    protected $description = 'Fetch vânzări din WinMentor Bridge lună cu lună, cu delay generos între apeluri';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma      = $this->option('firma');
        $delay      = max(15, (int) $this->option('delay'));
        $force      = (bool) $this->option('force');
        $anFiltru   = $this->option('an')   ? (int) $this->option('an')   : null;
        $lunaFiltru = $this->option('luna') ? (int) $this->option('luna') : null;

        // Previne rulări concurente
        $lockKey = "winmentor_fetch_vanzari_{$firma}";
        if (Cache::has($lockKey)) {
            $this->warn('Un alt fetch vanzari rulează deja — ieșire.');
            return self::SUCCESS;
        }
        Cache::put($lockKey, true, now()->addHours(3));

        try {
            return $this->doFetch($bridge, $firma, $delay, $force, $anFiltru, $lunaFiltru);
        } finally {
            Cache::forget($lockKey);
        }
    }

    private function doFetch(WinmentorBridgeClient $bridge, string $firma, int $delay, bool $force, ?int $anFiltru, ?int $lunaFiltru): int
    {
        $health = $bridge->health();
        if (! ($health['data']['comConnected'] ?? false)) {
            $this->error('WinMentor Bridge COM nu este conectat.');
            return self::FAILURE;
        }

        $luni = $this->getLuniDisponibile($bridge, $firma);
        if (empty($luni)) {
            $this->error("Nu am putut obține lunile disponibile pentru firma [{$firma}].");
            return self::FAILURE;
        }

        $luni = collect($luni)->sort()->reverse()->values(); // cele mai recente primele

        if ($anFiltru) $luni = $luni->filter(fn($l) => (int) explode('_', $l)[0] === $anFiltru);
        if ($lunaFiltru) $luni = $luni->filter(fn($l) => (int) explode('_', $l)[1] === $lunaFiltru);
        $luni = $luni->values();

        $this->info("Firma: {$firma} — {$luni->count()} luni de procesat");
        $this->info("Delay între luni: {$delay}s (minim 15s pentru stabilitatea Bridge-ului)");
        $this->line(str_repeat('─', 60));

        $totalSaved   = 0;
        $totalSkipped = 0;

        foreach ($luni as $lunaStr) {
            [$an, $luna] = explode('_', $lunaStr);
            $an   = (int) $an;
            $luna = (int) $luna;

            if (! $force && $this->alreadyFetched($firma, $an, $luna)) {
                $this->line("  [{$luna}/{$an}] deja importat — skip");
                $totalSkipped++;
                continue;
            }

            $this->line("  [{$luna}/{$an}] selectFirma...");

            try {
                $bridge->selectFirmaForMonth($an, $luna, $firma);
            } catch (\Throwable $e) {
                $this->warn("  [{$luna}/{$an}] Eroare selectFirma: {$e->getMessage()}");
                $this->waitWithCountdown($delay);
                continue;
            }

            // Pauză după selectFirma — Bridge-ul are nevoie de timp să comute contextul
            sleep(3);

            $this->line("  [{$luna}/{$an}] getVanzari...");

            try {
                $vanzari = $bridge->getVanzari();
            } catch (\Throwable $e) {
                $this->warn("  [{$luna}/{$an}] Eroare getVanzari: {$e->getMessage()}");
                $this->waitWithCountdown($delay);
                continue;
            }

            $saved = $this->saveVanzari($firma, $an, $luna, $vanzari);
            $totalSaved += $saved;

            $this->info("  [{$luna}/{$an}] ✓ {$saved} rânduri salvate");

            DB::table('winmentor_vanzari_sync')->upsert([
                'firma'        => $firma,
                'an'           => $an,
                'luna'         => $luna,
                'rows_fetched' => $saved,
                'fetched_at'   => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ], ['firma', 'an', 'luna'], ['rows_fetched', 'fetched_at', 'updated_at']);

            $this->waitWithCountdown($delay);
        }

        $this->line(str_repeat('─', 60));
        $this->info("Total salvat: {$totalSaved} rânduri, {$totalSkipped} luni sărite.");

        Log::channel('winmentor_sync')->info("[WinMentor FetchVanzari] Finalizat firma={$firma}", [
            'saved'   => $totalSaved,
            'skipped' => $totalSkipped,
        ]);

        return self::SUCCESS;
    }

    private function getLuniDisponibile(WinmentorBridgeClient $bridge, string $firma): array
    {
        $conn = \App\Models\IntegrationConnection::find(5);
        try {
            $r = Http::timeout(15)
                ->withoutVerifying()
                ->withHeaders(['X-API-Key' => $conn->bridgeApiKey()])
                ->get($conn->bridgeUrl() . "/api/firme/{$firma}/luni");
            return $r->json()['data'] ?? [];
        } catch (\Throwable $e) {
            $this->warn("Eroare la obținerea lunilor: {$e->getMessage()}");
            return [];
        }
    }

    private function alreadyFetched(string $firma, int $an, int $luna): bool
    {
        return DB::table('winmentor_vanzari_sync')
            ->where('firma', $firma)->where('an', $an)->where('luna', $luna)
            ->whereNotNull('fetched_at')
            ->exists();
    }

    private function saveVanzari(string $firma, int $an, int $luna, array $vanzari): int
    {
        if (empty($vanzari)) return 0;

        DB::table('winmentor_vanzari_raw')
            ->where('firma', $firma)->where('an', $an)->where('luna', $luna)
            ->delete();

        $rows = [];
        $now  = now();

        foreach ($vanzari as $row) {
            if (! is_array($row) || count($row) < 16) continue;

            // Mapare reală dedusă empiric (câmpurile sunt shifted față de doc v1.2):
            // label partID    = part_id       ✓
            // label zi        = zi            ✓
            // label prefixDoc = nr_factura
            // label nrDoc     = sku
            // label artID     = cantitate
            // label cant      = uom
            // label denUM     = pret
            // label pret      = den_gestiune
            // label adresa    = cod_fiscal_client
            // label codFiscal = adresa_client
            // label marcaAgent= marca_agent   ✓
            // label valAchizitie = valoare_totala

            $cantStr = trim($row['artID'] ?? '');
            $pretStr = trim($row['denUM'] ?? '');
            $valStr  = trim($row['valAchizitie'] ?? '');

            $rows[] = [
                'firma'             => $firma,
                'an'                => $an,
                'luna'              => $luna,
                'zi'                => is_numeric($row['zi'] ?? '') ? (int) $row['zi'] : null,
                'part_id'           => trim($row['partID'] ?? '') ?: null,
                'nr_factura'        => trim($row['prefixDoc'] ?? '') ?: null,
                'sku'               => trim($row['nrDoc'] ?? '') ?: null,
                'cantitate'         => $cantStr !== '' && is_numeric($cantStr) ? (float) $cantStr : null,
                'uom'               => trim($row['cant'] ?? '') ?: null,
                'pret'              => $pretStr !== '' ? (float) str_replace(',', '.', $pretStr) : null,
                'den_gestiune'      => trim($row['pret'] ?? '') ?: null,
                'cod_fiscal_client' => trim($row['adresa'] ?? '') ?: null,
                'adresa_client'     => trim($row['codFiscal'] ?? '') ?: null,
                'marca_agent'       => trim($row['marcaAgent'] ?? '') ?: null,
                'valoare_totala'    => $valStr !== '' ? (float) str_replace(',', '.', $valStr) : null,
                'clasa_articol'     => trim($row['clasaArticol'] ?? '') ?: null,
                'raw_row'           => json_encode($row),
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        if (empty($rows)) return 0;

        // Deduplicare: elimină rânduri identice (același document, SKU, cantitate, preț, zi)
        $rows = collect($rows)->unique(fn($r) => implode('|', [
            $r['nr_factura'], $r['sku'], $r['part_id'], $r['zi'], $r['cantitate'], $r['pret'],
        ]))->values()->all();

        collect($rows)->chunk(500)->each(fn($chunk) => DB::table('winmentor_vanzari_raw')->insert($chunk->all()));

        return count($rows);
    }

    private function waitWithCountdown(int $seconds): void
    {
        $this->output->write("  Aștept {$seconds}s");
        for ($i = 0; $i < $seconds; $i++) {
            sleep(1);
            $this->output->write('.');
        }
        $this->output->writeln(' gata');
    }
}
