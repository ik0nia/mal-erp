<?php

namespace App\Console\Commands;

use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetToyaProductDimensionsCommand extends Command
{
    protected $signature = 'toya:set-dimensions
                            {--dry-run : Afișează ce s-ar seta fără a salva}
                            {--force  : Suprascrie dimensiunile deja completate}';

    protected $description = 'Extrage atributele de dimensiuni din produsele Toya și le salvează în dim_length/width/height/weight';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force  = $this->option('force');

        if ($dryRun) {
            $this->warn('--- MOD DRY-RUN: nu se salvează nimic ---');
        }

        // Încărcăm toate atributele de dimensiuni ale produselor Toya dintr-o singură interogare
        $attrs = DB::table('woo_product_attributes')
            ->where('source', 'toya_api')
            ->whereIn('name', ['Lungime', 'Lăţime', 'Înălţime', 'Masa', 'Dimensiuni', 'Lungime totală', 'Grosime'])
            ->select('woo_product_id', 'name', 'value')
            ->get()
            ->groupBy('woo_product_id');

        $this->info('Produse cu atribute de dimensiuni: ' . $attrs->count());

        $bar   = $this->output->createProgressBar($attrs->count());
        $stats = ['updated' => 0, 'skipped' => 0, 'no_data' => 0];

        foreach ($attrs as $productId => $productAttrs) {
            $product = WooProduct::find($productId);
            if (! $product) {
                $bar->advance();
                continue;
            }

            // Dacă are deja dimensiuni și nu forțăm, sărim
            if (! $force && ($product->dim_length || $product->dim_width || $product->dim_height || $product->weight)) {
                $stats['skipped']++;
                $bar->advance();
                continue;
            }

            $attrMap = $productAttrs->pluck('value', 'name');

            $length = null;
            $width  = null;
            $height = null;
            $weight = null;

            // 1. Încearcă "Dimensiuni" (LxWxH unit sau LxW unit)
            if ($attrMap->has('Dimensiuni')) {
                [$length, $width, $height] = $this->parseDimensiuni($attrMap->get('Dimensiuni'));
            }

            // 2. Fallback pe atribute individuale (suprascriu dacă există)
            if ($attrMap->has('Lungime') && ! $length) {
                $length = $this->parseLinear($attrMap->get('Lungime'));
            }
            if ($attrMap->has('Lungime totală') && ! $length) {
                $length = $this->parseLinear($attrMap->get('Lungime totală'));
            }
            if ($attrMap->has('Lăţime') && ! $width) {
                $width = $this->parseLinear($attrMap->get('Lăţime'));
            }
            if ($attrMap->has('Înălţime') && ! $height) {
                $height = $this->parseLinear($attrMap->get('Înălţime'));
            }
            if ($attrMap->has('Grosime') && ! $height) {
                // Grosimea merge pe height dacă nu avem altceva
                $height = $this->parseLinear($attrMap->get('Grosime'));
            }

            // 3. Masă
            if ($attrMap->has('Masa')) {
                $weight = $this->parseMasa($attrMap->get('Masa'));
            }

            // Dacă nu am extras nimic util, sărim
            if (! $length && ! $width && ! $height && ! $weight) {
                $stats['no_data']++;
                $bar->advance();
                continue;
            }

            if ($dryRun) {
                $this->newLine();
                $this->line("  [{$product->sku}] L={$length} W={$width} H={$height} W_kg={$weight}");
                $stats['updated']++;
                $bar->advance();
                continue;
            }

            $update = [];
            if ($length !== null) {
                $update['dim_length'] = $length;
            }
            if ($width !== null) {
                $update['dim_width'] = $width;
            }
            if ($height !== null) {
                $update['dim_height'] = $height;
            }
            if ($weight !== null) {
                $update['weight'] = $weight;
            }

            if (! empty($update)) {
                $product->update($update);
                $stats['updated']++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Statistică', 'Valoare'],
            [
                ['Actualizate',                      $stats['updated']],
                ['Sărite (aveau deja dimensiuni)',    $stats['skipped']],
                ['Fără date utile',                  $stats['no_data']],
            ]
        );

        return self::SUCCESS;
    }

    // ----------------------------------------------------------------
    // Parsare "Dimensiuni": "385x175x41 mm", "60x100 cm", "226x115 mm"
    // Returnează [length_cm, width_cm, height_cm]
    // ----------------------------------------------------------------
    private function parseDimensiuni(string $value): array
    {
        // Extragem unit: mm, cm, m
        $unit = 'mm';
        if (preg_match('/\b(mm|cm|m)\b/i', $value, $um)) {
            $unit = strtolower($um[1]);
        }

        // Extragem primul set de numere LxW sau LxWxH (ignorăm valorile cu paranteze de descriere)
        // Ex: "40x40x30 (clip)|94x22x16 (în formă de cupă) mm" → luăm primul set
        $clean = preg_replace('/\(.*?\)/', '', $value); // scoatem paranteze
        $clean = preg_replace('/\|.*/', '', $clean);     // luăm primul segment din | separated

        if (! preg_match('/([\d.]+)\s*[xX×]\s*([\d.]+)(?:\s*[xX×]\s*([\d.]+))?/', $clean, $m)) {
            return [null, null, null];
        }

        $l = isset($m[1]) ? (float) $m[1] : null;
        $w = isset($m[2]) ? (float) $m[2] : null;
        $h = isset($m[3]) && $m[3] !== '' ? (float) $m[3] : null;

        return [
            $l !== null ? $this->toCm($l, $unit) : null,
            $w !== null ? $this->toCm($w, $unit) : null,
            $h !== null ? $this->toCm($h, $unit) : null,
        ];
    }

    // ----------------------------------------------------------------
    // Parsare valoare liniară: "100 mm", "5 cm", "1.2 m", "850+100 mm"
    // Returnează cm (float sau null)
    // ----------------------------------------------------------------
    private function parseLinear(string $value): ?float
    {
        // Ignorăm valori imposibil de parsesat simplu (range cu -, valori multiple)
        if (preg_match('/^\s*mm\s*$/', $value)) {
            return null; // valoare goală " mm"
        }

        // Extragem unit
        $unit = 'mm';
        if (preg_match('/\b(mm|cm|m)\b/i', $value, $um)) {
            $unit = strtolower($um[1]);
        }

        // Extragem primul număr (ignorăm range-uri: "850+100", "2010-2070")
        if (! preg_match('/([\d]+(?:[.,][\d]+)?)/', $value, $m)) {
            return null;
        }

        $num = (float) str_replace(',', '.', $m[1]);

        if ($num <= 0) {
            return null;
        }

        return $this->toCm($num, $unit);
    }

    // ----------------------------------------------------------------
    // Parsare masă: "1.5 kg", "600 g", "1000 g", "0,63 (carcasă) kg"
    // Returnează kg (float sau null)
    // ----------------------------------------------------------------
    private function parseMasa(string $value): ?float
    {
        // Extragem primul număr
        if (! preg_match('/([\d]+(?:[.,][\d]+)?)/', $value, $m)) {
            return null;
        }

        $num = (float) str_replace(',', '.', $m[1]);

        if ($num <= 0) {
            return null;
        }

        // Detectăm unitatea
        if (preg_match('/\bg\b/i', $value) && ! preg_match('/\bkg\b/i', $value)) {
            // grame → kg
            return round($num / 1000, 4);
        }

        // implicit kg
        return round($num, 4);
    }

    // ----------------------------------------------------------------
    // Conversie la cm
    // ----------------------------------------------------------------
    private function toCm(float $value, string $unit): float
    {
        return match ($unit) {
            'mm'    => round($value / 10, 2),
            'cm'    => round($value, 2),
            'm'     => round($value * 100, 2),
            default => round($value / 10, 2),
        };
    }
}
