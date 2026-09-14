<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifică ultima versiune WinMENTOR publicată pe portalul oficial (feed RSS)
 * și o cache-uiește, ca widgetul de mentenanță să arate dacă avem update disponibil.
 * Best-effort: dacă feed-ul nu răspunde, nu aruncă (păstrează ultima valoare cache-uită).
 */
class CheckWinmentorLatestVersionCommand extends Command
{
    protected $signature = 'winmentor:check-latest-version';

    protected $description = 'Verifică ultima versiune WinMENTOR publicată (RSS portal) și o cache-uiește';

    public const CACHE_KEY = 'winmentor_latest_version';
    private const FEED_URL  = 'https://portal.winmentor.ro/winmentor/feed/';

    public function handle(): int
    {
        try {
            $resp = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Malinco-ERP/1.0 (+erp.malinco.ro)'])
                ->get(self::FEED_URL);

            if (! $resp->ok()) {
                $this->warn("Feed WinMENTOR a răspuns HTTP {$resp->status()}.");
                return self::FAILURE;
            }

            $xml = $resp->body();

            // Parsează fiecare <item>: reținem versiunea care urmează DUPĂ „WinMENTOR"
            // (ca să NU luăm versiunea DECLARAȚII din titluri combinate).
            $best = null;
            if (preg_match_all('#<item>(.*?)</item>#s', $xml, $items)) {
                foreach ($items[1] as $item) {
                    if (! preg_match('/WinMENTOR\s+(\d{2}\.\d{3}\/\d+)/i', $item, $vm)) {
                        continue;
                    }
                    $version = $vm[1];
                    $score   = self::score($version);

                    if ($best === null || $score > $best['score']) {
                        $title = '';
                        if (preg_match('#<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</title>#s', $item, $tm)) {
                            $title = html_entity_decode(trim($tm[1]));
                        }
                        $date = null;
                        if (preg_match('#<pubDate>(.*?)</pubDate>#s', $item, $dm)) {
                            try { $date = \Illuminate\Support\Carbon::parse(trim($dm[1]))->toDateString(); }
                            catch (\Throwable) { $date = null; }
                        }
                        $best = ['score' => $score, 'version' => $version, 'title' => $title, 'date' => $date];
                    }
                }
            }

            if ($best === null) {
                $this->warn('Nu am găsit nicio versiune WinMENTOR în feed.');
                return self::FAILURE;
            }

            // Persistent în DB (nu cache volatil) — supraviețuiește oricărei goliri de cache.
            \App\Models\AppSetting::set(self::CACHE_KEY, json_encode([
                'version'    => $best['version'],
                'title'      => $best['title'],
                'date'       => $best['date'],
                'checked_at' => now()->toDateTimeString(),
            ], JSON_UNESCAPED_UNICODE));

            $this->info("Ultima versiune WinMENTOR publicată: {$best['version']}" . ($best['date'] ? " ({$best['date']})" : ''));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->warn('Eroare la verificarea versiunii WinMENTOR: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /** „26.071/4” → 26007104, pentru comparație numerică. */
    public static function score(?string $v): int
    {
        if (! $v || ! preg_match('/(\d+)\.(\d+)\/(\d+)/', $v, $p)) {
            return 0;
        }

        return ((int) $p[1]) * 1_000_000 + ((int) $p[2]) * 100 + (int) $p[3];
    }
}
