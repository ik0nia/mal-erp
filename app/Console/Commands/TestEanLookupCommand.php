<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Testează acoperirea serviciilor EAN lookup pe un sample de produse.
 *
 * Servicii testate:
 *  - UPCitemdb  (gratuit, fără cheie, 100/zi)
 *  - go-upc.com (necesită GO_UPC_API_KEY în .env, 100 gratuit/zi)
 *  - Open EAN DB / opengtindb.org (necesită OPENGTINDB_UID în .env)
 */
class TestEanLookupCommand extends Command
{
    protected $signature = 'ean:test-lookup
                            {--sample=50 : Număr produse de testat}
                            {--service=all : Serviciu: upcitemdb, goupc, opengtindb, all}';

    protected $description = 'Testează acoperirea serviciilor EAN lookup pe produsele placeholder';

    public function handle(): int
    {
        $sample  = (int) $this->option('sample');
        $service = $this->option('service');

        $products = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->whereRaw("sku REGEXP '^[0-9]{13}$'")
            ->inRandomOrder()
            ->limit($sample)
            ->get(['id', 'name', 'sku']);

        $total = $products->count();
        $this->info("Testing {$total} EAN-13 products...");
        $this->newLine();

        $services = [];

        if (in_array($service, ['all', 'upcitemdb'])) {
            $services['UPCitemdb'] = fn($ean) => $this->lookupUpcItemDb($ean);
        }
        if (in_array($service, ['all', 'goupc']) && env('GO_UPC_API_KEY')) {
            $services['go-upc.com'] = fn($ean) => $this->lookupGoUpc($ean);
        }
        if (in_array($service, ['all', 'opengtindb']) && env('OPENGTINDB_UID')) {
            $services['OpenGTINdb'] = fn($ean) => $this->lookupOpenGtinDb($ean);
        }

        if (empty($services)) {
            $this->warn('Niciun serviciu activ. Adaugă GO_UPC_API_KEY sau OPENGTINDB_UID în .env pentru servicii suplimentare.');
            $this->info('Testez doar UPCitemdb (gratuit, fără cheie)...');
            $services['UPCitemdb'] = fn($ean) => $this->lookupUpcItemDb($ean);
        }

        // Rezultate per serviciu
        $stats = [];
        foreach (array_keys($services) as $svc) {
            $stats[$svc] = ['found' => 0, 'with_image' => 0, 'not_found' => 0, 'error' => 0, 'hits' => []];
        }

        $rows = [];

        foreach ($products as $i => $product) {
            $num = $i + 1;
            $this->line("[{$num}/{$total}] {$product->sku} — {$product->name}");

            $rowData = ['ean' => $product->sku, 'name' => $product->name];

            foreach ($services as $svcName => $lookup) {
                try {
                    $result = $lookup($product->sku);

                    if ($result === null) {
                        $stats[$svcName]['not_found']++;
                        $this->line("  [{$svcName}] NOT FOUND");
                        $rowData[$svcName] = 'not_found';
                    } else {
                        $stats[$svcName]['found']++;
                        $hasImage = ! empty($result['image']);
                        if ($hasImage) {
                            $stats[$svcName]['with_image']++;
                            $stats[$svcName]['hits'][] = [
                                'ean'   => $product->sku,
                                'name'  => $product->name,
                                'title' => $result['title'] ?? '',
                                'image' => $result['image'],
                            ];
                        }
                        $icon = $hasImage ? '✓ cu imagine' : '~ fără imagine';
                        $this->line("  [{$svcName}] {$icon}: " . ($result['title'] ?? ''));
                        $rowData[$svcName] = $hasImage ? 'image' : 'found_no_image';
                    }
                } catch (\Throwable $e) {
                    $stats[$svcName]['error']++;
                    $this->warn("  [{$svcName}] ERROR: " . $e->getMessage());
                    $rowData[$svcName] = 'error';
                }

                usleep(300_000); // 0.3s între cereri per serviciu
            }

            $rows[] = $rowData;
            usleep(500_000); // 0.5s între produse
        }

        // ── Sumar ──
        $this->newLine();
        $this->info('══════════════════ REZULTATE ══════════════════');

        foreach ($stats as $svcName => $s) {
            $foundPct    = $total > 0 ? round($s['found']    / $total * 100) : 0;
            $imagePct    = $total > 0 ? round($s['with_image'] / $total * 100) : 0;
            $this->newLine();
            $this->info("── {$svcName} ──");
            $this->line("  Găsite:       {$s['found']} / {$total} ({$foundPct}%)");
            $this->line("  Cu imagine:   {$s['with_image']} / {$total} ({$imagePct}%)");
            $this->line("  Negăsite:     {$s['not_found']}");
            $this->line("  Erori:        {$s['error']}");

            if (! empty($s['hits'])) {
                $this->newLine();
                $this->line("  Exemple imagini găsite:");
                foreach (array_slice($s['hits'], 0, 5) as $hit) {
                    $this->line("    [{$hit['ean']}] {$hit['name']}");
                    $this->line("      → {$hit['image']}");
                }
            }
        }

        // ── Detaliu per prefix ──
        $this->newLine();
        $this->info('── Acoperire per prefix EAN ──');

        $byPrefix = collect($rows)->groupBy(fn($r) => substr($r['ean'], 0, 3));
        foreach ($byPrefix->sortKeys() as $prefix => $group) {
            $found = $group->filter(fn($r) => in_array(array_values(array_diff_key($r, ['ean'=>1,'name'=>1]))[0] ?? '', ['found_no_image','image']))->count();
            $imgs  = $group->filter(fn($r) => in_array(array_values(array_diff_key($r, ['ean'=>1,'name'=>1]))[0] ?? '', ['image']))->count();
            $cnt   = $group->count();
            $this->line("  {$prefix}xxx : {$cnt} produse | găsite: {$found} | cu imagine: {$imgs}");
        }

        $this->newLine();
        $this->info('Test finalizat.');

        return self::SUCCESS;
    }

    // ─── UPCitemdb ─────────────────────────────────────────────────────────────

    private function lookupUpcItemDb(string $ean): ?array
    {
        $url  = "https://api.upcitemdb.com/prod/trial/lookup?upc={$ean}";
        $body = $this->httpGet($url, [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0',
        ]);

        $data  = json_decode($body, true);
        $items = $data['items'] ?? [];

        if (empty($items)) {
            return null;
        }

        $item  = $items[0];
        $image = $item['images'][0] ?? null;

        return [
            'title' => $item['title'] ?? null,
            'brand' => $item['brand'] ?? null,
            'image' => $image,
        ];
    }

    // ─── go-upc.com ────────────────────────────────────────────────────────────

    private function lookupGoUpc(string $ean): ?array
    {
        $key  = env('GO_UPC_API_KEY');
        $url  = "https://go-upc.com/api/v1/code/{$ean}";
        $body = $this->httpGet($url, [
            "Authorization: Bearer {$key}",
            'Accept: application/json',
        ]);

        $data = json_decode($body, true);

        if (empty($data['product'])) {
            return null;
        }

        $p = $data['product'];

        return [
            'title' => $p['name'] ?? null,
            'brand' => $p['brand'] ?? null,
            'image' => $p['imageUrl'] ?? null,
        ];
    }

    // ─── opengtindb.org ────────────────────────────────────────────────────────

    private function lookupOpenGtinDb(string $ean): ?array
    {
        $uid  = env('OPENGTINDB_UID');
        $url  = "https://opengtindb.org/?ean={$ean}&cmd=query&lang=en&uid={$uid}";
        $body = $this->httpGet($url, ['Accept: text/plain']);

        // Răspuns text, linii key=value
        $lines = array_filter(explode("\n", trim($body)));
        $data  = [];
        foreach ($lines as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $data[trim($k)] = trim($v);
            }
        }

        if (($data['error'] ?? '') !== '0') {
            return null;
        }

        return [
            'title' => $data['detailname'] ?? $data['name'] ?? null,
            'brand' => $data['vendor'] ?? null,
            'image' => null, // opengtindb nu returnează imagini
        ];
    }

    // ─── HTTP helper ───────────────────────────────────────────────────────────

    private function httpGet(string $url, array $headers = []): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("cURL error: {$error}");
        }
        if ($status === 429) {
            throw new \RuntimeException("Rate limited (429)");
        }
        if ($status >= 400) {
            throw new \RuntimeException("HTTP {$status}");
        }

        return $body ?: '';
    }
}
