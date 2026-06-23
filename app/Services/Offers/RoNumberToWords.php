<?php

namespace App\Services\Offers;

/**
 * Convertește o sumă în litere, în limba română (lei + bani).
 * Ex: 1345.68 → „o mie trei sute patruzeci și cinci lei și 68 bani".
 */
class RoNumberToWords
{
    private const UNITS   = ['', 'unu', 'doi', 'trei', 'patru', 'cinci', 'șase', 'șapte', 'opt', 'nouă'];
    private const UNITS_F = ['', 'una', 'două', 'trei', 'patru', 'cinci', 'șase', 'șapte', 'opt', 'nouă'];
    private const TEENS   = ['zece', 'unsprezece', 'doisprezece', 'treisprezece', 'paisprezece', 'cincisprezece', 'șaisprezece', 'șaptesprezece', 'optsprezece', 'nouăsprezece'];
    private const TENS    = ['', '', 'douăzeci', 'treizeci', 'patruzeci', 'cincizeci', 'șaizeci', 'șaptezeci', 'optzeci', 'nouăzeci'];

    public static function money(float $amount): string
    {
        $amount = round($amount, 2);
        $lei = (int) floor($amount + 1e-9);
        $bani = (int) round(($amount - $lei) * 100);

        if ($bani >= 100) {
            $lei += 1;
            $bani -= 100;
        }

        $leiPart = $lei === 1 ? 'un leu' : self::words($lei) . ' ' . self::leiNoun($lei);

        return ucfirst($leiPart) . ' și ' . $bani . ' ' . ($bani === 1 ? 'ban' : 'bani');
    }

    private static function leiNoun(int $lei): string
    {
        $lastTwo = $lei % 100;
        $needsDe = ! ($lastTwo >= 1 && $lastTwo <= 19);

        return ($needsDe ? 'de ' : '') . 'lei';
    }

    public static function words(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }

        $parts = [];

        $millions = intdiv($n, 1000000);
        $n %= 1000000;
        $thousands = intdiv($n, 1000);
        $rest = $n % 1000;

        if ($millions) {
            $parts[] = $millions === 1 ? 'un milion' : self::words($millions) . ' milioane';
        }

        if ($thousands) {
            $parts[] = match (true) {
                $thousands === 1 => 'o mie',
                $thousands === 2 => 'două mii',
                default          => self::below1000($thousands, true) . ' mii',
            };
        }

        if ($rest) {
            $parts[] = self::below1000($rest, false);
        }

        return trim(implode(' ', $parts));
    }

    private static function below1000(int $n, bool $fem): string
    {
        $parts = [];
        $h = intdiv($n, 100);
        $r = $n % 100;

        if ($h) {
            $parts[] = match (true) {
                $h === 1 => 'o sută',
                $h === 2 => 'două sute',
                default  => self::UNITS_F[$h] . ' sute',
            };
        }

        if ($r) {
            $parts[] = self::below100($r, $fem);
        }

        return implode(' ', $parts);
    }

    private static function below100(int $n, bool $fem): string
    {
        if ($n < 10) {
            return $fem ? self::UNITS_F[$n] : self::UNITS[$n];
        }

        if ($n < 20) {
            return self::TEENS[$n - 10];
        }

        $t = intdiv($n, 10);
        $u = $n % 10;

        return self::TENS[$t] . ($u ? ' și ' . ($fem ? self::UNITS_F[$u] : self::UNITS[$u]) : '');
    }
}
