<?php

namespace App\Console\Commands;

use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Parcurge produsele WinMentor placeholder și asociază furnizorul
 * când numele acestuia apare în denumirea produsului.
 *
 * Exemplu:
 *   "BEC LED PHILIPS 8W E27"   → Philips (#9)
 *   "DRISCA CAUCIUC HARDY"     → Hardy (#15)
 *   "SIG MOELLER C16"          → Eaton Moeller (#17)
 */
class AssignProductSuppliersCommand extends Command
{
    protected $signature = 'products:assign-suppliers
                            {--dry-run  : Afișează potrivirile fără să le salveze}
                            {--force    : Re-procesează și produsele care au deja un furnizor}
                            {--limit=   : Limitează numărul de produse procesate}';

    protected $description = 'Asociază furnizori la produsele WinMentor pe baza denumirii';

    /**
     * Alias-uri: cuvinte cheie din denumire → numele exact din tabela suppliers.
     * Folositoare când numele furnizorului din DB diferă de ce apare în CSV.
     */
    protected array $supplierAliases = [
        'moeller'       => 'Eaton Moeller',
        'eaton'         => 'Eaton Moeller',
        'knauf insul'   => 'Knauf Insulation',
        'lahti'         => 'Lahti PRO',
        'schuller'      => 'Schuller Eh\'klar',
        'eh\'klar'      => 'Schuller Eh\'klar',
        'ehklar'        => 'Schuller Eh\'klar',
        'lumytools'     => 'LumyTools',
        'lumy'          => 'LumyTools',
        'termopasty'    => 'TermoPasty',
        'poxipol'       => 'Poxipol',
        'romprofix'     => 'Romprofix',
        'procema'       => 'Procema',
        'romdaniel'     => 'Romdaniel',
        'maxcl'         => 'maxCL',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');
        $limit  = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Asociere furnizori pe baza denumirii' . ($dryRun ? ' [DRY RUN]' : ''));

        // Încarcă toți furnizorii activi, sortați descrescător după lungime
        // (pentru a prefera "Knauf Insulation" față de "Knauf" la potrivire)
        $suppliers = DB::table('suppliers')
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->sortByDesc(fn ($s) => mb_strlen($s->name))
            ->values();

        $this->line("Furnizori activi: {$suppliers->count()}");

        // Produse WinMentor fără furnizor (sau toate dacă --force)
        $query = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->select('id', 'name');

        if (! $force) {
            $query->whereNotIn('id', DB::table('product_suppliers')->select('woo_product_id'));
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->get();
        $total    = $products->count();

        $this->line("Produse de procesat: {$total}");

        if ($total === 0) {
            $this->info('Nimic de procesat.');
            return self::SUCCESS;
        }

        $assigned = 0;
        $skipped  = 0;
        $inserts  = [];

        foreach ($products as $product) {
            $supplierId = $this->detectSupplier($product->name, $suppliers);

            if ($supplierId === null) {
                $skipped++;
                continue;
            }

            $supplierName = $suppliers->firstWhere('id', $supplierId)?->name ?? "#{$supplierId}";
            $this->line("  #{$product->id} <fg=green>{$product->name}</> → <fg=yellow>{$supplierName}</>");

            if (! $dryRun) {
                $inserts[] = [
                    'woo_product_id' => $product->id,
                    'supplier_id'    => $supplierId,
                    'is_preferred'   => false,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }

            $assigned++;
        }

        if (! $dryRun && $inserts !== []) {
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('product_suppliers')->upsert(
                    $chunk,
                    ['woo_product_id', 'supplier_id'],
                    ['updated_at']
                );
            }
        }

        $this->newLine();
        $this->info("Asociate: {$assigned} | Fără potrivire: {$skipped}" . ($dryRun ? ' [DRY RUN — nimic salvat]' : ''));

        return self::SUCCESS;
    }

    /**
     * Caută furnizorul în denumirea produsului.
     * Returnează supplier_id sau null dacă nu găsește.
     */
    private function detectSupplier(string $productName, Collection $suppliers): ?int
    {
        // 1. Verifică alias-urile mai întâi (word-boundary match)
        foreach ($this->supplierAliases as $keyword => $supplierName) {
            if ($this->matchesWord($productName, $keyword)) {
                $supplier = $suppliers->firstWhere('name', $supplierName);
                if ($supplier) {
                    return (int) $supplier->id;
                }
            }
        }

        // 2. Potrivire directă după numele furnizorului (sortați desc după lungime)
        foreach ($suppliers as $supplier) {
            // Ignoră furnizori cu nume prea scurte/generice (sub 4 caractere)
            if (mb_strlen($supplier->name) < 4) {
                continue;
            }

            if ($this->matchesWord($productName, $supplier->name)) {
                return (int) $supplier->id;
            }
        }

        return null;
    }

    /**
     * Verifică dacă $needle apare în $haystack ca cuvânt de sine stătător
     * (nu ca parte a altui cuvânt). Case-insensitive, Unicode-aware.
     */
    private function matchesWord(string $haystack, string $needle): bool
    {
        $pattern = '/(?<![A-Za-z\pL])' . preg_quote($needle, '/') . '(?![A-Za-z\pL])/iu';

        return (bool) preg_match($pattern, $haystack);
    }
}
