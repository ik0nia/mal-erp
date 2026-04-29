<?php

namespace App\Console\Commands;

use App\Models\WooProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScrapeFischerSiteCommand extends Command
{
    protected $signature = 'fischer:scrape-site
                            {--sku= : SKU specific de testat}
                            {--limit= : Limita produse}
                            {--only-empty : Doar produse fara descriere}
                            {--dry-run : Nu salva, doar afiseaza}
                            {--sleep=2 : Secunde intre cereri}';

    protected $description = 'Scrape fischer.com.ro și actualizează denumiri, descrieri, imagini montaj și documente';

    public function handle(): int
    {
        $skuFilter  = $this->option('sku');
        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;
        $onlyEmpty  = $this->option('only-empty');
        $dryRun     = $this->option('dry-run');
        $sleep      = (int) $this->option('sleep');

        if ($skuFilter) {
            $data = $this->scrape($skuFilter);
            if (!$data) {
                $this->error("Nu am putut scrapa SKU: $skuFilter");
                return self::FAILURE;
            }
            $this->info("=== RESULT ===");
            $this->line("Name: " . ($data['name'] ?? ''));
            $this->line("Texts: " . count($data['texts'] ?? []));
            $this->line("Assembly strips: " . count($data['assembly_strips'] ?? []));
            $this->line("Documents: " . count($data['documents'] ?? []));
            $this->line("Product images: " . count($data['product_images'] ?? []));
            if (!$dryRun) $this->saveToFile($skuFilter, $data);
            return self::SUCCESS;
        }

        // Batch mode - preia produsele Fischer din ERP
        $query = DB::table('woo_products as wp')
            ->join('product_suppliers as ps', 'ps.woo_product_id', '=', 'wp.id')
            ->where('ps.supplier_id', 75)
            ->whereNotNull('wp.woo_id')
            ->select('wp.woo_id', 'wp.id', 'wp.name', 'wp.description', 'ps.supplier_sku');

        if ($onlyEmpty) {
            $query->where(function ($q) {
                $q->whereNull('wp.description')->orWhere('wp.description', '');
            });
        }

        if ($limit) $query->limit($limit);

        $products = $query->get();
        $this->info("Produse de scrapat: {$products->count()}");

        $results = [];
        $ok = 0; $fail = 0;

        foreach ($products as $product) {
            $sku = $product->supplier_sku;
            $this->line("Scraping SKU {$sku} | {$product->name}...");

            $data = $this->scrape($sku);
            if (!$data) {
                $this->warn("  FAIL: nu am putut accesa pagina");
                $fail++;
                sleep($sleep);
                continue;
            }

            $results[$product->woo_id] = array_merge($data, [
                'erp_id'    => $product->id,
                'woo_id'    => $product->woo_id,
                'old_name'  => $product->name,
            ]);

            $this->line("  OK: " . ($data['name'] ?? '?') .
                " | texts=" . count($data['texts']) .
                " | assembly=" . count($data['assembly_strips']) .
                " | docs=" . count($data['documents']) .
                " | imgs=" . count($data['product_images']));
            $ok++;

            if ($sleep > 0) sleep($sleep);
        }

        // Salvează JSON pentru WP-CLI updater
        $jsonPath = '/tmp/fischer-site-data.json';
        file_put_contents($jsonPath, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info("\nSalvat: $jsonPath ({$ok} produse, {$fail} eșecuri)");

        return self::SUCCESS;
    }

    private function scrape(string $sku): ?array
    {
        $url = "https://www.fischer.com.ro/ro-ro/products/{$sku}";

        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0\r\nAccept-Language: ro-RO,ro;q=0.9\r\nAccept: text/html,*/*\r\n",
            'timeout'         => 20,
            'follow_location' => 1,
            'max_redirects'   => 5,
        ]]);

        $html = @file_get_contents($url, false, $ctx);
        if (!$html || strlen($html) < 10000) return null;

        // Extract Apollo state
        preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $html, $scripts);
        $apollo = null;
        foreach ($scripts[1] as $s) {
            if (strpos($s, 'ROOT_QUERY') !== false && strpos($s, 'getCatalogProductDataById') !== false) {
                $apollo = json_decode($s, true);
                break;
            }
        }
        if (!$apollo) return null;

        // Find product key
        $productKey = null;
        foreach (array_keys($apollo['ROOT_QUERY'] ?? []) as $k) {
            if (strpos($k, 'getCatalogProductDataById') !== false) { $productKey = $k; break; }
        }
        if (!$productKey) return null;

        $prod = $apollo['ROOT_QUERY'][$productKey];

        // === TEXTE ===
        $texts = [];
        $textMap = [
            'BEZEICHNUNG'       => 'name_full',
            'ECOMLANG'          => 'name_short',
            'DOKBSTMARKCLAIM'   => 'claim',
            'DOKBSTMARKDESCR'   => 'description',
            'KAT_VORTEILNUTZEN' => 'advantages',
            'KAT_FUNKTION'      => 'function',
            'KAT_ANWENDUNG_GEN' => 'applications',
            'KAT_BAUSTOFF_GEN'  => 'materials',
        ];
        foreach ($prod['TextList'] ?? [] as $t) {
            $key = $t['Key'] ?? '';
            if (isset($textMap[$key])) {
                $texts[$textMap[$key]] = $t['Value'] ?? '';
            }
        }

        // === IMAGINI PRODUS (tip PP) ===
        $productImages = [];
        $appImages = [];
        foreach ($prod['Media'] ?? [] as $m) {
            $mediaType = $m['MediaType'] ?? '';
            // Cauta URL in orice cheie filteredMedia
            foreach ($m as $k => $v) {
                if (is_array($v) && !empty($v)) {
                    foreach ($v as $item) {
                        if (isset($item['Url'])) {
                            if (in_array($mediaType, ['PP', 'PR'])) {
                                $productImages[] = $item['Url'];
                            } elseif ($mediaType === 'IS') {
                                $appImages[] = $item['Url'];
                            }
                        }
                    }
                }
            }
        }

        // Fallback - extrage direct din HTML dacă Media e goală
        if (empty($productImages)) {
            preg_match_all('/https:\/\/media\.fischer\.group\/[^"\'>\s]+W1_P_[^"\'>\s]+\.jpg/i', $html, $imgs);
            $productImages = array_unique($imgs[0]);
        }

        // Imagini aplicații (W1_A_I) - întotdeauna din HTML (MediaType IS nu se prinde din Apollo)
        preg_match_all('/https:\/\/media\.fischer\.group\/[^"\'>\s]+W1_A_I[^"\'>\s]+\.jpg/i', $html, $aiImgs);
        $appImages = array_unique($aiImgs[0]);

        // === ASSEMBLY STRIPS ===
        $assemblyStrips = [];
        foreach ($prod['AssemblyMedia'] ?? [] as $strip) {
            $stripName = $strip['Name'] ?? '';
            $images = [];
            foreach ($strip as $k => $v) {
                if (strpos($k, 'filteredMedia') !== false && is_array($v)) {
                    foreach ($v as $item) {
                        if (isset($item['Url'])) $images[] = $item['Url'];
                    }
                }
            }
            if ($images) {
                $assemblyStrips[] = ['name' => $stripName, 'images' => $images];
            }
        }

        // === DOCUMENTE ===
        $documents = [];
        $docs = $prod['Documents'] ?? [];
        $docCats = [
            'SafetyDataSheets'        => 'SDS',
            'InstallationInstructions'=> 'Installation',
            'TechnicalDatasheets'     => 'Technical',
            'AdditionalDocuments'     => 'Additional',
        ];
        foreach ($docCats as $cat => $label) {
            foreach ($docs[$cat] ?? [] as $d) {
                if (!empty($d['Url'])) {
                    $documents[] = [
                        'category' => $label,
                        'name'     => $d['Name'] ?? $label,
                        'url'      => $d['Url'],
                        'size'     => $d['FileSize'] ?? null,
                    ];
                }
            }
        }

        // === YOUTUBE ===
        preg_match('/youtu\.be\/([A-Za-z0-9_-]{11})|youtube\.com\/watch\?v=([A-Za-z0-9_-]{11})/', $html, $yt);
        $youtube = $yt[1] ?? $yt[2] ?? null;

        return [
            'name'            => $texts['name_full'] ?? $prod['ProductName'] ?? null,
            'name_short'      => $texts['name_short'] ?? null,
            'texts'           => $texts,
            'product_images'  => array_unique($productImages),
            'app_images'      => array_unique($appImages),
            'assembly_strips' => $assemblyStrips,
            'documents'       => $documents,
            'youtube'         => $youtube,
            'scraped_url'     => $url,
        ];
    }

    private function saveToFile(string $sku, array $data): void
    {
        $path = "/tmp/fischer-scrape-{$sku}.json";
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Salvat: $path");
    }
}
