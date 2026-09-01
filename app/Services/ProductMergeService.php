<?php

namespace App\Services;

use App\Models\WooProduct;
use Illuminate\Support\Facades\DB;

/**
 * Mută istoricul unei fișe de produs duplicat (metrici stoc, loguri prețuri,
 * furnizori, linii de comenzi etc.) pe fișa păstrată, apoi „parchează" duplicatul
 * (SKU prefixat DUP-, winmentor_name golit) ca să nu mai intre în potrivirile sync-ului.
 *
 * Coloanele care referențiază woo_products sunt descoperite dinamic din
 * information_schema — acoperă și tabelele fără foreign key declarat.
 */
class ProductMergeService
{
    /** Coloane care referențiază woo_products dar nu urmează convenția de nume. */
    private const EXTRA_COLUMNS = [
        ['product_substitution_proposals', 'proposed_toya_id'],
        ['woo_products', 'substituted_by_id'],
    ];

    /** @return array<array{0: string, 1: string}> perechi [tabel, coloană] */
    public function discoverReferenceColumns(): array
    {
        $cols = DB::select(
            "SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND COLUMN_NAME IN ('woo_product_id', 'reference_product_id')
               AND TABLE_NAME != 'woo_products'
             ORDER BY TABLE_NAME"
        );

        return array_merge(
            array_map(fn ($r) => [$r->t, $r->c], $cols),
            self::EXTRA_COLUMNS,
        );
    }

    /**
     * Mută toate referințele de pe $from pe $to.
     *
     * UPDATE IGNORE sare peste rândurile care ar încălca o cheie unică pe produsul
     * țintă (ex. metrici pe aceeași zi există deja pe ambele fișe) — acelea rămân
     * pe fișa parcată și apar în statistici la "ramase".
     *
     * @return array<string, array{total: int, mutate: int, ramase: int}>
     */
    public function mergeHistory(WooProduct $from, WooProduct $to, bool $dryRun = true): array
    {
        $stats = [];

        foreach ($this->discoverReferenceColumns() as [$table, $col]) {
            $total = DB::table($table)->where($col, $from->id)->count();
            if (! $total) {
                continue;
            }

            $moved = $total;
            if (! $dryRun) {
                $moved = DB::update(
                    "UPDATE IGNORE `{$table}` SET `{$col}` = ? WHERE `{$col}` = ?",
                    [$to->id, $from->id]
                );
            }

            $stats["{$table}.{$col}"] = [
                'total'  => $total,
                'mutate' => $moved,
                'ramase' => $dryRun ? 0 : $total - $moved,
            ];
        }

        return $stats;
    }

    /**
     * Parchează fișa duplicat: eliberează SKU-ul (prefix DUP-) și golește
     * winmentor_name ca să nu mai fie luată în calcul la potrivirea pe denumire.
     * Statusul rămâne neschimbat (oglindește site-ul).
     */
    public function parkDuplicate(WooProduct $from, bool $dryRun = true): string
    {
        $parkedSku = str_starts_with((string) $from->sku, 'DUP-')
            ? $from->sku
            : "DUP-{$from->id}-{$from->sku}";

        if (! $dryRun) {
            $from->update(['sku' => $parkedSku, 'winmentor_name' => null]);
        }

        return $parkedSku;
    }
}
