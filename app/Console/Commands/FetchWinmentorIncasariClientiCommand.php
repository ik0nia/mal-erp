<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Istoricul complet de încasări bancare per client (GetIncasariClienti).
 *
 * GetIncasariLuna (importul orar) omite majoritatea încasărilor — constatat
 * 2026-09-11 pe Mivinia: 3 din 25 încasări. Apelul per partener + interval
 * întoarce istoricul complet, deci îl folosim ca sursă pentru fișa clientului.
 *
 * Zilnic: doar partenerii cu vânzări recente (--zile=10 implicit).
 * Săptămânal: --toti (partenerii cu vânzări din 2023 încoace, ~1-2h).
 */
class FetchWinmentorIncasariClientiCommand extends Command
{
    protected $signature = 'winmentor:fetch-incasari-clienti
                            {--zile=10 : Doar partenerii cu vânzări în ultimele N zile}
                            {--toti : Toți partenerii cu vânzări din 2023 încoace}
                            {--part= : Un singur partener (wm_id)}
                            {--firma=MAL2019 : Firma WinMentor}';

    protected $description = 'Istoric complet încasări bancare per client (GetIncasariClienti) → winmentor_incasari_clienti';

    public function handle(WinmentorBridgeClient $bridge): int
    {
        $firma = $this->option('firma');

        if (! ($bridge->health()['data']['comConnected'] ?? false)) {
            $this->error('WinMentor Bridge COM nu este conectat.');
            return self::FAILURE;
        }

        if ($this->option('part')) {
            $partIds = [(string) $this->option('part')];
        } elseif ($this->option('toti')) {
            $partIds = DB::table('winmentor_vanzari_raw')
                ->where('firma', $firma)->where('an', '>=', 2023)
                ->whereNotNull('part_id')->distinct()->pluck('part_id')->all();
        } else {
            $zile = max(1, (int) $this->option('zile'));
            $de = now()->subDays($zile);
            $partIds = DB::table('winmentor_vanzari_raw')
                ->where('firma', $firma)
                ->whereRaw('(an*10000 + luna*100 + zi) >= ?', [(int) $de->format('Ymd')])
                ->whereNotNull('part_id')->distinct()->pluck('part_id')->all();
        }

        $this->info(count($partIds) . ' parteneri de procesat');

        $an2 = (int) now()->year;
        $luna2 = (int) now()->month;
        $ok = 0;
        $erori = 0;
        $now = now();

        foreach ($partIds as $i => $partId) {
            try {
                $incasari = $bridge->getIncasariClient((string) $partId, 2019, 1, $an2, $luna2);
            } catch (\Throwable $e) {
                $erori++;
                if ($erori <= 5) $this->warn("  {$partId}: {$e->getMessage()}");
                usleep(300000);
                continue;
            }

            $rows = array_map(fn ($r) => [
                'firma'           => $firma,
                'part_id'         => (string) $partId,
                'data'            => self::parseData($r['data'] ?? ''),
                'document_ref'    => mb_substr(trim($r['documentRef'] ?? ''), 0, 60) ?: null,
                'suma'            => (float) str_replace(',', '.', (string) ($r['suma'] ?? 0)),
                'detalii_facturi' => trim($r['detaliiFacturi'] ?? '') ?: null,
                'fetched_at'      => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ], $incasari);

            DB::transaction(function () use ($firma, $partId, $rows) {
                DB::table('winmentor_incasari_clienti')
                    ->where('firma', $firma)->where('part_id', (string) $partId)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('winmentor_incasari_clienti')->insert($chunk);
                }
            });

            $ok++;
            if (($i + 1) % 200 === 0) $this->line('  ' . ($i + 1) . '/' . count($partIds));
            usleep(200000); // menajăm COM-ul
        }

        $this->info("Gata: {$ok} parteneri actualizați, {$erori} erori.");
        Log::channel('winmentor_sync')->info("[IncasariClienti] {$ok} parteneri, {$erori} erori");

        return self::SUCCESS;
    }

    private static function parseData(string $d): ?string
    {
        return preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($d), $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : null;
    }
}
