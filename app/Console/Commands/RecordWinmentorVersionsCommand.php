<?php

namespace App\Console\Commands;

use App\Models\WinmentorVersionHistory;
use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;

/**
 * Verifică versiunile curente (WinMentor, DocImpServer, MentorAPI) și înregistrează
 * în winmentor_version_history orice SCHIMBARE față de ultima valoare cunoscută.
 * Astfel avem un istoric al momentelor când s-a făcut update pe server.
 */
class RecordWinmentorVersionsCommand extends Command
{
    protected $signature = 'winmentor:record-versions';

    protected $description = 'Detectează și înregistrează schimbările de versiune WinMentor/DocImpServer/MentorAPI';

    public function handle(): int
    {
        $client = app(WinmentorBridgeClient::class);

        try {
            $health   = $client->health();
            if (($health['success'] ?? false) !== true) {
                $this->warn('MentorAPI indisponibil — nu verific versiunile acum.');
                return self::SUCCESS; // nu e eroare, doar server oprit
            }
            $mentorApi = $health['data']['version'] ?? null;

            $versiuni = $client->getVersiuni();
            $mentor   = WinmentorBridgeClient::formatVersiuneWinmentor($versiuni['verMentor'] ?? null);
            $server   = WinmentorBridgeClient::formatVersiuneWinmentor($versiuni['verServer'] ?? null);
        } catch (\Throwable $e) {
            $this->warn('Eroare la citirea versiunilor: ' . $e->getMessage());
            return self::SUCCESS;
        }

        $components = [
            'mentor'       => $mentor,
            'docimpserver' => $server,
            'mentorapi'    => $mentorApi ? 'v' . ltrim($mentorApi, 'v') : null,
        ];

        $changes = 0;
        foreach ($components as $component => $version) {
            if (! $version) {
                continue;
            }

            $last = WinmentorVersionHistory::where('component', $component)
                ->latest('detected_at')
                ->first();

            if ($last && $last->version === $version) {
                continue; // neschimbat
            }

            WinmentorVersionHistory::create([
                'component'        => $component,
                'version'          => $version,
                'previous_version' => $last?->version,
                'detected_at'      => now(),
            ]);

            $changes++;
            $label = WinmentorVersionHistory::COMPONENTS[$component] ?? $component;
            if ($last) {
                $this->info("{$label}: {$last->version} → {$version} (update detectat)");
                \Log::info("[WinMentor] Update detectat {$label}", ['from' => $last->version, 'to' => $version]);
            } else {
                $this->info("{$label}: {$version} (baseline)");
            }
        }

        if ($changes === 0) {
            $this->line('Nicio schimbare de versiune.');
        }

        return self::SUCCESS;
    }
}
