<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\WooCommerce\WooClient;
use App\Models\IntegrationConnection;

class SetTemadBrandsCommand extends Command
{
    protected $signature = 'temad:set-brands
        {--dry-run : Afișează ce s-ar face fără a modifica nimic}';

    protected $description = 'Setează taxonomia product_brand pe toate produsele Temad din WooCommerce';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) $this->warn('[DRY RUN]');

        $connection = IntegrationConnection::where('provider', 'woocommerce')->first();
        if (!$connection) {
            $this->error('Nu am găsit conexiune WooCommerce!');
            return self::FAILURE;
        }
        $woo     = new WooClient($connection);
        $apiBase = rtrim($connection->base_url, '/') . '/wp-json/wc/v3';
        $http    = Http::acceptJson()->asJson()
            ->withBasicAuth($connection->consumer_key, $connection->consumer_secret)
            ->withOptions(['verify' => $connection->verify_ssl])
            ->timeout(30);

        // ── 1. Construim maparea EAN/cod → brand din fișierele Excel ──────────

        $this->info('Citesc fișierele Excel pentru maping brand...');
        $brandMap = $this->buildBrandMap();
        $uniqueBrands = array_unique(array_values($brandMap));
        sort($uniqueBrands);
        $this->info('  → ' . count($brandMap) . ' produse cu brand din Excel (' . count($uniqueBrands) . ' branduri unice)');

        // ── 2. Creăm / obținem termenii de brand din WooCommerce ──────────────

        $this->info('Sincronizez termeni de brand în WooCommerce...');
        $brandIds = $this->ensureBrandTerms($http, $apiBase, $uniqueBrands, $dryRun);
        $this->info('  → ' . count($brandIds) . ' termeni de brand disponibili');

        // ── 3. Preluăm toate produsele Temad cu woo_id ────────────────────────

        $temadProducts = DB::table('product_suppliers as ps')
            ->where('ps.supplier_id', 5)
            ->join('woo_products as wp', 'ps.woo_product_id', '=', 'wp.id')
            ->whereNotNull('wp.woo_id')
            ->select('wp.id as local_id', 'wp.woo_id', 'wp.sku', 'wp.name', 'ps.supplier_sku')
            ->get();

        $this->info('  → ' . count($temadProducts) . ' produse Temad cu woo_id');

        // ── 4. Mapăm fiecare produs la brand ─────────────────────────────────

        $toUpdate  = [];
        $noBrand   = [];

        foreach ($temadProducts as $product) {
            $brand = null;

            // Caută după EAN (SKU)
            if ($product->sku && isset($brandMap[$product->sku])) {
                $brand = $brandMap[$product->sku];
            }
            // Caută după supplier_sku (cod Temad)
            if (!$brand && $product->supplier_sku && isset($brandMap['cod_' . $product->supplier_sku])) {
                $brand = $brandMap['cod_' . $product->supplier_sku];
            }
            // Fallback: extrage din numele produsului (primul cuvânt)
            if (!$brand) {
                $firstWord = strtoupper(explode(' ', trim($product->name ?? ''))[0]);
                if ($firstWord && isset($brandIds[$firstWord])) {
                    $brand = $firstWord;
                }
            }

            if ($brand && isset($brandIds[$brand])) {
                $toUpdate[] = [
                    'id'     => $product->woo_id,
                    'brands' => [['id' => $brandIds[$brand]]],
                ];
            } else {
                $noBrand[] = $product->woo_id . ' - ' . $product->name;
            }
        }

        $this->info('  → ' . count($toUpdate) . ' produse cu brand identificat, ' . count($noBrand) . ' fără brand');

        if ($dryRun) {
            $brandCount = [];
            foreach ($toUpdate as $u) {
                $id = $u['brands'][0]['id'];
                $name = array_search($id, $brandIds);
                $brandCount[$name] = ($brandCount[$name] ?? 0) + 1;
            }
            $this->table(['Brand', 'Produse'], array_map(fn($k,$v) => [$k, $v], array_keys($brandCount), array_values($brandCount)));
            return self::SUCCESS;
        }

        // ── 5. Update WooCommerce în batch-uri de 10 ─────────────────────────

        $batches = array_chunk($toUpdate, 10);
        $bar     = $this->output->createProgressBar(count($toUpdate));
        $bar->start();
        $errors  = 0;

        foreach ($batches as $batch) {
            try {
                $woo->updateProductsBatch($batch);
            } catch (\Exception $e) {
                $this->error("\nEroare batch: " . $e->getMessage());
                $errors++;
            }
            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->newLine();

        if (!empty($noBrand)) {
            $this->warn('Produse fără brand (' . count($noBrand) . '):');
            foreach (array_slice($noBrand, 0, 10) as $line) {
                $this->line("  $line");
            }
        }

        $this->info("Gata! {$errors} erori.");
        return self::SUCCESS;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Construiește mapa EAN/cod → brand din ambele fișiere Excel
    // ────────────────────────────────────────────────────────────────────────

    private function buildBrandMap(): array
    {
        $map = [];

        // Din Baza: col A=Cod, I=Grupa (brand), K=EAN
        $bazaPath = storage_path('furnizori/temad/Baza 06.04.26 (1).xlsx');
        $reader   = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $sheet    = $reader->load($bazaPath)->getActiveSheet();
        $maxRow   = $sheet->getHighestRow();

        for ($row = 2; $row <= $maxRow; $row++) {
            $cod   = trim((string) $sheet->getCell('A' . $row)->getValue());
            $grupa = strtoupper(trim((string) $sheet->getCell('I' . $row)->getValue()));
            $ean   = rtrim(trim((string) $sheet->getCell('K' . $row)->getValue()), '.0');

            if (empty($cod) || empty($grupa)) continue;

            $brand = $this->normalizeBrand($grupa);

            if ($ean && preg_match('/^\d{8,14}$/', $ean)) {
                $map[$ean] = $brand;
            }
            $map['cod_' . $cod] = $brand;
        }

        // Din lista: col A=Cod, J=EAN (pentru VITEX)
        $listaPath = storage_path('furnizori/temad/malinco lista 04.03.26.xlsx');
        $sheet2    = $reader->load($listaPath)->getActiveSheet();
        $maxRow2   = $sheet2->getHighestRow();

        for ($row = 2; $row <= $maxRow2; $row++) {
            $cod  = trim((string) $sheet2->getCell('A' . $row)->getValue());
            $ean  = rtrim(trim((string) $sheet2->getCell('J' . $row)->getValue()), '.0');
            $grup = strtoupper(trim((string) $sheet2->getCell('H' . $row)->getValue()));

            if (empty($cod)) continue;

            // Lista are doar produse VITEX
            $brand = !empty($grup) ? $this->normalizeBrand($grup) : 'VITEX';

            if ($ean && preg_match('/^\d{8,14}$/', $ean) && !isset($map[$ean])) {
                $map[$ean] = $brand;
            }
            if (!isset($map['cod_' . $cod])) {
                $map['cod_' . $cod] = $brand;
            }
        }

        return $map;
    }

    private function normalizeBrand(string $grupa): string
    {
        // Normalizări specifice
        $aliases = [
            'VITEX'         => 'VITEX',
            'BISON'         => 'BISON',
            'DUPLICOLOR'    => 'DUPLICOLOR',
            'OREGON'        => 'OREGON',
            'KLINGSPOR'     => 'KLINGSPOR',
            'RINO'          => 'RINO',
            'TERMICO'       => 'TERMICO',
            'KARRO'         => 'KARRO',
            'KAPRO'         => 'KAPRO',
            'MELLERUD'      => 'MELLERUD',
            'UHU'           => 'UHU',
            'GRIFFON'       => 'GRIFFON',
            'ELLEN FLEX'    => 'ELLEN',
            'WD-40'         => 'WD-40',
            'SPRAY WD-40'   => 'WD-40',
            'QUICKLOADER'   => 'QUICKLOADER',
            'DUCTIL'        => 'DUCTIL',
            'WINNERFLEX'    => 'WINNERFLEX',
            'REVCO'         => 'REVCO',
            'BERGDECK'      => 'BERGDECK',
            'COSMOS SPRAY'  => 'COSMOS',
            'STARKBOHRER'   => 'STARKBOHRER',
            'BURGHIE STARKBOHRER' => 'STARKBOHRER',
            'ZIKA ELECTROZI' => 'ZIKA',
            'SWORDFLEX - SAINT GOBAIN' => 'SAINT-GOBAIN',
        ];

        if (isset($aliases[$grupa])) return $aliases[$grupa];

        // Încearcă match parțial
        foreach ($aliases as $key => $val) {
            if (str_contains($grupa, $key)) return $val;
        }

        return $grupa;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Crează termeni de brand dacă nu există, returnează name → id
    // ────────────────────────────────────────────────────────────────────────

    private function ensureBrandTerms($http, string $apiBase, array $brandNames, bool $dryRun): array
    {
        // Preluăm termenii existenți
        $existing = [];
        $page = 1;
        do {
            $resp = $http->get("$apiBase/products/brands", ['per_page' => 100, 'page' => $page]);
            $items = $resp->json() ?? [];
            foreach ($items as $item) {
                $existing[strtoupper($item['name'])] = $item['id'];
            }
            $page++;
        } while (count($items) === 100);

        $brandIds = [];

        foreach ($brandNames as $name) {
            $upper = strtoupper($name);
            if (isset($existing[$upper])) {
                $brandIds[$name] = $existing[$upper];
                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY] Creez brand: $name");
                $brandIds[$name] = -1;
                continue;
            }

            // Crează termenul
            $resp = $http->post("$apiBase/products/brands", [
                'name' => ucwords(strtolower($name)),
                'slug' => strtolower(str_replace([' ', '/'], ['-', '-'], $name)),
            ]);

            if ($resp->successful()) {
                $data = $resp->json();
                $brandIds[$name] = $data['id'];
                $this->line("  + Brand creat: {$name} (id={$data['id']})");
            } else {
                $this->warn("  ! Nu am putut crea brand: $name — " . $resp->body());
            }
        }

        return $brandIds;
    }
}
