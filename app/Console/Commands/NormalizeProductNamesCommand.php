<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Normalizează denumirile produselor WinMentor placeholder:
 * - Expandează abrevierile cunoscute (CU→Cupru, PEX→Pexal, PRE.→Prelungitor etc.)
 * - Convertește din MAJUSCULE în Title Case inteligent
 * - Salvează denumirea originală în coloana erp_notes dacă nu există deja
 *
 * Rulează DUPĂ ce glosarul a fost configurat în config/product_glossary.php
 */
class NormalizeProductNamesCommand extends Command
{
    protected $signature = 'products:normalize-names
                            {--dry-run : Arată modificările fără să le aplice}
                            {--limit= : Procesează maxim N produse}';

    protected $description = 'Normalizează denumirile produselor WinMentor (expandează abrevieri, Title Case)';

    /** Cuvinte care rămân cu majusculă specifică sau lowercase în Title Case */
    private array $keepUppercase = [
        'PPR', 'PEX', 'PVC', 'OSB', 'BCA', 'LED', 'UV', 'INOX', 'AL',
        'HSS', 'SDS', 'RAL', 'WC', 'TV', 'IP', 'IK', 'AC', 'DC',
        'MAXCL', 'MRC', 'MRT', 'FPB', 'FP', 'XP', 'NV', 'CN', 'FN',
        'V-TAC', 'BISON', 'KNAUF', 'PROX', 'NORTON', 'ADEPLAST', 'STARKE',
        'AUSTROTHERM', 'MOELLER', 'FISCHER', 'DUOPOWER', 'RAIN BIRD',
        'MM', 'CM', 'ML', 'KG', 'BUC', 'TAC',
        'XXL', 'XL', 'XS', 'UD', 'UW', 'CW', 'CD', // mărimi & coduri profile
    ];

    private array $keepLowercase = ['și', 'sau', 'cu', 'de', 'din', 'pe', 'la', 'în', 'pt', 'pt.', 'pentru'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = $this->option('limit') ? (int) $this->option('limit') : null;

        $expansions  = config('product_glossary.title_expansions', []);
        $doNotChange = config('product_glossary.do_not_change', []);

        $query = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->select('id', 'name', 'erp_notes');

        if ($limit) {
            $query->limit($limit);
        }

        $products = $query->get();
        $total    = $products->count();

        $this->info("Normalizare denumiri — {$total} produse" . ($dryRun ? ' [DRY RUN]' : ''));

        $changed  = 0;
        $skipped  = 0;

        foreach ($products as $product) {
            $original    = $product->name;
            $normalized  = $this->normalize($original, $expansions, $doNotChange);

            if ($normalized === $original) {
                $skipped++;
                continue;
            }

            $changed++;
            $this->line("  #{$product->id}");
            $this->line("    ÎNAINTE: {$original}");
            $this->line("    DUPĂ:    {$normalized}");

            if (! $dryRun) {
                // Salvăm originalul în erp_notes dacă e gol
                $notes = $product->erp_notes;
                if (empty($notes)) {
                    $notes = "Denumire originală WinMentor: {$original}";
                }

                DB::table('woo_products')->where('id', $product->id)->update([
                    'name'      => $normalized,
                    'erp_notes' => $notes,
                    'updated_at' => now(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Modificate: {$changed} | Neschimbate: {$skipped}" . ($dryRun ? ' [DRY RUN — nimic salvat]' : ''));

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function normalize(string $name, array $expansions, array $doNotChange): string
    {
        // 1. Aplică expansiunile de abrevieri (regex replacements)
        foreach ($expansions as $rule) {
            $name = preg_replace($rule['pattern'], $rule['replacement'], $name);
        }

        // 2. Convertește în Title Case inteligent
        $name = $this->toTitleCase($name, $doNotChange);

        // 3. Curăță spații multiple
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    private function toTitleCase(string $name, array $doNotChange): string
    {
        $keepUpper = array_map('strtoupper', array_merge($this->keepUppercase, $doNotChange));

        // Separă pe spații, menținând spațiile ca separatori
        $words  = preg_split('/(\s+)/', $name, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = [];
        $isFirst = true;

        foreach ($words as $word) {
            // Separator de spații — păstrează
            if (preg_match('/^\s+$/', $word)) {
                $result[] = $word;
                continue;
            }

            // Cod de dimensiuni / unitate măsură: conține cifre și litere/separatori
            // ex: 48MMX90M, 125X2, 280X130MM, 9W, 28W, 3/4, 0.6KG, 4.5W, 3000K, 6M(185-600CM)
            if (preg_match('/^\d[\dxXa-zA-Z*\/.,\-()\[\]]+$|^\d+$/', $word)) {
                $result[] = strtoupper($word);
                $isFirst  = false;
                continue;
            }

            // Cod tehnic alfanumeric: conține atât litere cât și cifre (ex: DT300UT, PZ0X75MM, E27)
            if (preg_match('/^[a-zA-Z\d]+$/', $word) && preg_match('/[a-zA-Z]/', $word) && preg_match('/\d/', $word)) {
                $result[] = strtoupper($word);
                $isFirst  = false;
                continue;
            }

            // Cuvânt cu cratimă — procesează fiecare parte separat
            if (str_contains($word, '-') && ! preg_match('/^\d/', $word)) {
                $subParts = explode('-', $word);
                $titled   = array_map(function ($sub) use ($keepUpper) {
                    if ($sub === '') return '';
                    $upper = strtoupper($sub);
                    if (in_array($upper, $keepUpper, true)) return $upper;
                    if (preg_match('/^\d/', $sub)) return $sub;
                    // Sub-cod tehnic alfanumeric (ex: FN3 din FN3-10MP)
                    if (preg_match('/[a-zA-Z]/', $sub) && preg_match('/\d/', $sub)) return strtoupper($sub);
                    return mb_strtoupper(mb_substr($sub, 0, 1, 'UTF-8'), 'UTF-8')
                         . mb_strtolower(mb_substr($sub, 1, null, 'UTF-8'), 'UTF-8');
                }, $subParts);
                $result[] = implode('-', $titled);
                $isFirst  = false;
                continue;
            }

            $upper = strtoupper($word);

            // Token care rămâne uppercase (branduri, coduri, unități)
            if (in_array($upper, $keepUpper, true)) {
                $result[] = $upper;
                $isFirst  = false;
                continue;
            }

            // Prepoziții/articole lowercase (nu primul cuvânt)
            $lower = mb_strtolower($word, 'UTF-8');
            if (! $isFirst && in_array($lower, $this->keepLowercase, true)) {
                $result[] = $lower;
                continue;
            }

            // Default: Title Case
            $result[] = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
                      . mb_strtolower(mb_substr($word, 1, null, 'UTF-8'), 'UTF-8');
            $isFirst  = false;
        }

        return implode('', $result);
    }
}
