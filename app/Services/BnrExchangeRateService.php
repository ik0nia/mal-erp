<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Serviciu pentru cursuri de schimb BNR (Banca Nationala a Romaniei).
 *
 * Surse:
 *  - Curent:   https://www.bnr.ro/nbrfxrates.xml
 *  - Istoric:  https://www.bnr.ro/files/xml/years/nbrfxrates{YYYY}.xml
 *
 * Cursurile sunt cached 24h pentru ziua curentă și permanent pentru zile trecute.
 * BNR publică cursul zilei bancare (luni-vineri). Pentru weekend/sărbători
 * se folosește cursul zilei bancare anterioare.
 */
class BnrExchangeRateService
{
    private const BNR_CURRENT_URL  = 'https://www.bnr.ro/nbrfxrates.xml';
    private const BNR_HISTORY_URL  = 'https://www.bnr.ro/files/xml/years/nbrfxrates%d.xml';
    private const CACHE_TTL_TODAY  = 3600 * 6;   // 6h pentru ziua curentă
    private const CACHE_TTL_PAST   = 3600 * 24 * 365; // 1 an pentru zile trecute

    /**
     * Returnează cursul EUR/RON pentru o dată dată.
     * Dacă e zi nebancară (weekend/sărbătoare), merge înapoi până găsește.
     *
     * @param  string|\DateTimeInterface  $date  format 'Y-m-d' sau Carbon
     * @return float|null  Cursul EUR în RON (ex: 4.9700) sau null dacă nu se poate obține
     */
    public function getEurRate(string|\DateTimeInterface $date): ?float
    {
        return $this->getRate('EUR', $date);
    }

    /**
     * Returnează cursul unei valute față de RON pentru o dată dată.
     */
    public function getRate(string $currency, string|\DateTimeInterface $date): ?float
    {
        $carbon = $date instanceof \DateTimeInterface
            ? Carbon::instance($date)
            : Carbon::parse($date);

        $dateStr = $carbon->format('Y-m-d');
        $cacheKey = "bnr_rate_{$currency}_{$dateStr}";

        $isToday = $carbon->isToday();
        $ttl = $isToday ? self::CACHE_TTL_TODAY : self::CACHE_TTL_PAST;

        return Cache::remember($cacheKey, $ttl, function () use ($currency, $carbon, $dateStr) {
            return $this->fetchRate($currency, $carbon);
        });
    }

    /**
     * Convertește o valoare din valuta dată în RON.
     * Dacă moneda e RON sau nu se găsește cursul, returnează valoarea nemodificată.
     */
    public function toRon(float $amount, string $currency, string|\DateTimeInterface $date): float
    {
        if (strtoupper($currency) === 'RON') {
            return $amount;
        }

        $rate = $this->getRate($currency, $date);
        if ($rate === null) {
            Log::warning("[BNR] Nu s-a putut obține cursul {$currency} pentru {$date} — se returnează valoarea neconvertită");
            return $amount;
        }

        return round($amount * $rate, 4);
    }

    // ─── Fetch ────────────────────────────────────────────────────────────────────

    private function fetchRate(string $currency, Carbon $date): ?float
    {
        // Încearcă până la 7 zile în urmă (weekend + sărbători)
        for ($i = 0; $i <= 7; $i++) {
            $try = $date->copy()->subDays($i);

            $rates = $this->fetchDayRates($try);
            if ($rates === null) continue;

            $rate = $rates[strtoupper($currency)] ?? null;
            if ($rate !== null) {
                return (float) $rate;
            }
        }

        // Fallback ECB — site-ul BNR blochează uneori accesul din datacenter (302 → homepage).
        // Cursul de referință ECB pentru RON diferă nesemnificativ de cel BNR.
        $ecb = $this->fetchEcbRate($currency, $date);
        if ($ecb !== null) {
            Log::info("[BNR] Curs {$currency}/{$date->format('Y-m-d')} obținut din fallback ECB: {$ecb}");
            return $ecb;
        }

        Log::warning("[BNR] Nu s-a găsit cursul {$currency} pentru {$date->format('Y-m-d')} (nici în ultimele 7 zile, nici la ECB)");
        return null;
    }

    /**
     * Fallback: cursul de referință ECB (data-api.ecb.europa.eu).
     * Pentru EUR returnează RON/EUR direct; pentru alte valute derivă prin EUR
     * (ex. USD/RON = RON-per-EUR ÷ USD-per-EUR). Ia ultima zi bancară ≤ data cerută.
     */
    private function fetchEcbRate(string $currency, Carbon $date): ?float
    {
        $currency = strtoupper($currency);
        $start = $date->copy()->subDays(7)->format('Y-m-d');
        $end   = $date->format('Y-m-d');

        $fetch = function (string $cur) use ($start, $end): ?float {
            try {
                $url = "https://data-api.ecb.europa.eu/service/data/EXR/D.{$cur}.EUR.SP00.A"
                    ."?startPeriod={$start}&endPeriod={$end}&format=csvdata";
                $response = Http::timeout(15)->get($url);
                if (! $response->successful()) return null;

                $last = null;
                foreach (explode("\n", trim($response->body())) as $i => $line) {
                    if ($i === 0) continue;
                    $cols = str_getcsv($line);
                    if (isset($cols[7]) && is_numeric($cols[7])) $last = (float) $cols[7];
                }

                return $last;
            } catch (\Throwable $e) {
                Log::warning("[BNR] Eroare fallback ECB {$cur}: {$e->getMessage()}");
                return null;
            }
        };

        $ronPerEur = $fetch('RON');
        if ($ronPerEur === null || $ronPerEur <= 0) return null;
        if ($currency === 'EUR') return round($ronPerEur, 4);

        $curPerEur = $fetch($currency);
        if ($curPerEur === null || $curPerEur <= 0) return null;

        return round($ronPerEur / $curPerEur, 4);
    }

    /**
     * Fetch toate cursurile pentru o zi specifică din XML-ul BNR.
     * Returnează ['EUR' => 4.97, 'USD' => 4.52, ...] sau null dacă ziua nu are date.
     * Pentru anul curent încearcă ambele surse (current + year archive) pentru a acoperi
     * atât datele recente cât și datele din cursul anului care nu sunt în XML-ul curent.
     */
    private function fetchDayRates(Carbon $date): ?array
    {
        $year    = $date->year;
        $dateStr = $date->format('Y-m-d');
        $cacheKey = "bnr_day_{$dateStr}";

        return Cache::remember($cacheKey, self::CACHE_TTL_PAST, function () use ($year, $dateStr) {
            $isCurrentYear = $year === now()->year;

            $xmlSources = $isCurrentYear
                ? [
                    $this->fetchXmlFromUrl(self::BNR_CURRENT_URL, 'bnr_xml_current', self::CACHE_TTL_TODAY),
                    $this->fetchXmlFromUrl(sprintf(self::BNR_HISTORY_URL, $year), "bnr_xml_{$year}", self::CACHE_TTL_TODAY),
                  ]
                : [
                    $this->fetchXmlFromUrl(sprintf(self::BNR_HISTORY_URL, $year), "bnr_xml_{$year}", self::CACHE_TTL_PAST),
                  ];

            foreach ($xmlSources as $xml) {
                if ($xml === null) continue;
                $rates = $this->extractRatesFromXml($xml, $dateStr);
                if ($rates !== null) return $rates;
            }

            return null; // ziua nu există în niciun XML
        });
    }

    private function extractRatesFromXml(\SimpleXMLElement $xml, string $dateStr): ?array
    {
        foreach ($xml->Body->Cube ?? [] as $cube) {
            if ((string) $cube['date'] !== $dateStr) continue;

            $rates = [];
            foreach ($cube->Rate as $rate) {
                $curr = (string) $rate['currency'];
                $mult = (int) ($rate['multiplier'] ?? 1);
                $val  = (float) str_replace(',', '.', (string) $rate);
                $rates[$curr] = $mult > 1 ? $val / $mult : $val;
            }
            return $rates ?: null;
        }
        return null;
    }

    /**
     * Fetch și parsează un XML BNR de la un URL dat, cu cache.
     */
    private function fetchXmlFromUrl(string $url, string $cacheKey, int $ttl): ?\SimpleXMLElement
    {
        $content = Cache::remember($cacheKey, $ttl, function () use ($url) {
            try {
                $response = Http::timeout(15)->get($url);
                if ($response->successful()) {
                    return $response->body();
                }
            } catch (\Throwable $e) {
                Log::warning("[BNR] Eroare fetch XML: {$e->getMessage()}");
            }
            return null;
        });

        if ($content === null) return null;

        try {
            return new \SimpleXMLElement($content);
        } catch (\Throwable) {
            return null;
        }
    }
}
