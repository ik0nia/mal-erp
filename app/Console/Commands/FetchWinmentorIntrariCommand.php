<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\BnrExchangeRateService;
use App\Services\Winmentor\WinmentorBridgeClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchWinmentorIntrariCommand extends Command
{
    protected $signature = 'winmentor:fetch-intrari
                            {--firma=MAL2019 : Firma WinMentor din care să importe}
                            {--delay=30 : Secunde pauză între luni}
                            {--force : Re-fetch luni deja importate}';

    protected $description = 'Fetch intrări de marfă din WinMentor Bridge, lună cu lună, și salvează raw în DB';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma   = $this->option('firma');
        $delay   = max(5, (int) $this->option('delay'));
        $force   = (bool) $this->option('force');

        // Verifică COM
        $health = $bridge->health();
        if (! ($health['data']['comConnected'] ?? false)) {
            $this->error('WinMentor Bridge COM nu este conectat — pornește WinMentor mai întâi.');
            return self::FAILURE;
        }

        // Obține lunile disponibile
        $luni = $this->getLuniDisponibile($bridge, $firma);
        if (empty($luni)) {
            $this->error("Nu am putut obține lunile disponibile pentru firma [{$firma}].");
            return self::FAILURE;
        }

        $luni = $luni->reverse()->values(); // cele mai recente primele

        $this->info("Firma: {$firma} — {$luni->count()} luni disponibile ({$luni->last()} → {$luni->first()})");
        $this->info("Delay între luni: {$delay}s");
        $this->line(str_repeat('─', 60));

        $totalSaved = 0;
        $totalSkipped = 0;

        foreach ($luni as $lunaStr) {
            [$an, $luna] = explode('_', $lunaStr);
            $an   = (int) $an;
            $luna = (int) $luna;

            // Sari dacă deja importată (și nu --force)
            if (! $force && $this->alreadyFetched($firma, $an, $luna)) {
                $this->line("  [{$luna}/{$an}] deja importat — skip (folosește --force pentru re-fetch)");
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

            $this->line("  [{$luna}/{$an}] getReceptii...");

            try {
                $intrari = $bridge->getReceptii();
            } catch (\Throwable $e) {
                $this->warn("  [{$luna}/{$an}] Eroare getReceptii: {$e->getMessage()}");
                $this->waitWithCountdown($delay);
                continue;
            }

            $saved = $this->saveIntrari($firma, $an, $luna, $intrari);
            $totalSaved += $saved;

            $this->info("  [{$luna}/{$an}] ✓ {$saved} rânduri salvate");

            // Marchează luna ca importată
            DB::table('winmentor_intrari_sync')->upsert([
                'firma'       => $firma,
                'an'          => $an,
                'luna'        => $luna,
                'rows_fetched'=> $saved,
                'fetched_at'  => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ], ['firma', 'an', 'luna'], ['rows_fetched', 'fetched_at', 'updated_at']);

            // Pauză între luni
            $this->waitWithCountdown($delay);
        }

        $this->line(str_repeat('─', 60));
        $this->info("Total salvat: {$totalSaved} rânduri, {$totalSkipped} luni sărite (deja importate).");

        Log::channel('winmentor_sync')->info("[WinMentor FetchIntrari] Finalizat firma={$firma}", [
            'saved'   => $totalSaved,
            'skipped' => $totalSkipped,
        ]);

        return self::SUCCESS;
    }

    private function getLuniDisponibile(WinmentorBridgeClient $bridge, string $firma): \Illuminate\Support\Collection
    {
        $conn    = \App\Models\IntegrationConnection::find(5);
        $baseUrl = $conn->bridgeUrl();
        $apiKey  = $conn->bridgeApiKey();

        try {
            $response = Http::timeout(15)
                ->withoutVerifying()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get("{$baseUrl}/api/firme/{$firma}/luni");

            return collect($response->json()['data'] ?? [])->sort()->values();
        } catch (\Throwable $e) {
            $this->warn("Eroare la obținerea lunilor: {$e->getMessage()}");
            return collect();
        }
    }

    private function alreadyFetched(string $firma, int $an, int $luna): bool
    {
        return DB::table('winmentor_intrari_sync')
            ->where('firma', $firma)
            ->where('an', $an)
            ->where('luna', $luna)
            ->whereNotNull('fetched_at')
            ->exists();
    }

    private function saveIntrari(string $firma, int $an, int $luna, array $intrari): int
    {
        if (empty($intrari)) return 0;

        // Șterge rândurile vechi pentru luna asta dacă există (re-fetch)
        DB::table('winmentor_intrari_raw')
            ->where('firma', $firma)
            ->where('an', $an)
            ->where('luna', $luna)
            ->delete();

        // Index furnizori EUR (winmentor_id → true)
        $eurSuppliers = Supplier::where('default_currency', 'EUR')
            ->whereNotNull('winmentor_id')
            ->pluck('default_currency', 'winmentor_id')
            ->map(fn ($c) => strtoupper($c) === 'EUR')
            ->all();

        $bnr     = app(BnrExchangeRateService::class);
        $rateCache = []; // date → rate (evităm apeluri repetate BNR pentru aceeași dată)
        $rows = [];
        $now  = now();

        foreach ($intrari as $row) {
            if (! is_array($row) || count($row) < 14) continue;

            // Receptii: [5]=part_id, [6]=nr_factura, [7]=data_factura, [8]=sku,
            //           [13]=cant_receptionata, [11]=uom, [14]=pret_intrare
            $partId      = trim($row[5] ?? '') ?: null;
            $dateStr     = trim($row[7] ?? '');
            $pretStr     = trim($row[14] ?? '');
            $cantStr     = trim($row[13] ?? '');
            $pretVanzStr = trim($row[15] ?? '');

            $date = null;
            try {
                $date = \Carbon\Carbon::createFromFormat('d.m.Y', $dateStr)->toDateString();
            } catch (\Throwable) {}

            // Detectare monedă furnizor
            $isEur   = $partId && ($eurSuppliers[$partId] ?? false);
            $moneda  = $isEur ? 'EUR' : 'RON';
            $cursRon = null;

            if ($isEur && $date) {
                if (! isset($rateCache[$date])) {
                    $rateCache[$date] = $bnr->getEurRate($date);
                }
                $cursRon = $rateCache[$date];
            }

            $rows[] = [
                'firma'         => $firma,
                'an'            => $an,
                'luna'          => $luna,
                'part_id'       => $partId,
                'den_furnizor'  => trim($row[4] ?? '') ?: null,
                'data_intrare'  => $date,
                'nr_doc'        => trim($row[6] ?? '') ?: null,
                'nr_receptie'   => trim($row[2] ?? '') ?: null,
                'sku'           => trim($row[8] ?? '') ?: null,
                'den_articol'   => trim($row[9] ?? '') ?: null,
                'cantitate'     => $cantStr !== '' ? (float) str_replace(',', '.', $cantStr) : null,
                'uom'           => trim($row[11] ?? '') ?: null,
                'pret'          => $pretStr !== '' ? (float) str_replace(',', '.', $pretStr) : null,
                'pret_vanzare'  => $pretVanzStr !== '' ? (float) str_replace(',', '.', $pretVanzStr) : null,
                'moneda'        => $moneda,
                'curs_bnr'      => $cursRon,
                'den_gestiune'  => trim($row[1] ?? '') ?: null,
                'id_comanda_wm' => trim($row[17] ?? '') ?: null,
                'raw_row'       => json_encode($row),
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (empty($rows)) return 0;

        // Insert în batch-uri de 500
        collect($rows)->chunk(500)->each(fn ($chunk) => DB::table('winmentor_intrari_raw')->insert($chunk->all()));

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
