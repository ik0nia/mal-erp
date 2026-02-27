<?php

namespace App\Console\Commands;

use Anthropic\Client as AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caută pe internet informații suplimentare pentru produsele WinMentor
 * al căror SKU nu începe cu cifra 9 (sunt coduri EAN internaționale).
 *
 * Actualizează: descriere, brand (furnizor), atribute tehnice.
 * Nu suprascrie titlul dacă produsul a fost deja reformat ([titlu-reformat]).
 */
class EnrichProductsFromWebCommand extends Command
{
    protected $signature = 'products:enrich-from-web
                            {--dry-run     : Afișează ce ar fi actualizat fără să salveze}
                            {--limit=      : Max produse de procesat}
                            {--batch-size=5 : Produse per apel Claude (1-10)}
                            {--regenerate  : Re-procesează și produsele deja îmbogățite}
                            {--sku=        : Procesează un singur SKU (pentru test)}
                            {--worker=1    : Worker index (1-based)}
                            {--workers=1   : Total number of parallel workers}';

    protected $description = 'Îmbogățește produsele WinMentor cu informații căutate pe internet (EAN lookup via web search)';

    private AnthropicClient $claude;
    private string $model = 'claude-haiku-4-5-20251001';

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY nu este setat în .env');
            return self::FAILURE;
        }

        $this->claude    = new AnthropicClient(apiKey: $apiKey);
        $dryRun          = (bool) $this->option('dry-run');
        $limit           = $this->option('limit') ? (int) $this->option('limit') : null;
        $batchSize       = max(1, min(10, (int) $this->option('batch-size')));
        $regenerate      = (bool) $this->option('regenerate');
        $singleSku       = $this->option('sku');
        $worker          = max(1, (int) $this->option('worker'));
        $workers         = max(1, (int) $this->option('workers'));

        $workerNote = $workers > 1 ? ", worker: {$worker}/{$workers}" : '';
        $this->info('Îmbogățire produse din web — model: ' . $this->model . ($dryRun ? ' [DRY RUN]' : '') . $workerNote);

        $query = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->whereRaw("sku NOT LIKE '9%'")   // SKU-uri EAN (nu coduri interne WinMentor)
            ->select('id', 'sku', 'name', 'description', 'erp_notes');

        if ($singleSku) {
            $query->where('sku', $singleSku);
        } elseif (! $regenerate) {
            // Sărăm produsele care au deja marcajul de îmbogățire web
            $query->where(fn ($q) => $q
                ->whereNull('erp_notes')
                ->orWhereRaw("erp_notes NOT LIKE '%[web-enriched]%'")
            );
        }

        if ($workers > 1) {
            $query->whereRaw('(id % ?) = ?', [$workers, $worker - 1]);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->get();
        $total    = $products->count();

        if ($total === 0) {
            $this->info('Niciun produs de procesat.');
            return self::SUCCESS;
        }

        $this->info("Produse de procesat: {$total}");
        $this->line("Cost estimat web search: ~\$" . number_format($total * 0.01, 2) . " (10$/1000 căutări)");

        $processed = 0;
        $enriched  = 0;
        $notFound  = 0;
        $errors    = 0;

        foreach ($products->chunk($batchSize) as $batch) {
            $batch = $batch->values();
            $from  = $processed + 1;
            $to    = $processed + $batch->count();

            $this->info("[{$from}–{$to} / {$total}] Căutare web pentru {$batch->count()} produse...");

            try {
                $results = $this->enrichBatch($batch->toArray());

                foreach ($batch as $product) {
                    $result = $results[$product->id] ?? null;

                    if (! $result || ! ($result['found'] ?? false)) {
                        $notFound++;
                        $this->line("  #{$product->id} <fg=gray>{$product->sku}</> → negăsit");
                        $processed++;
                        continue;
                    }

                    $this->line("  #{$product->id} <fg=green>{$product->sku}</> → găsit: " . ($result['title_ro'] ?? $product->name));

                    if (! $dryRun) {
                        $this->saveEnrichment($product, $result);
                    } else {
                        $this->showDryRunDiff($product, $result);
                    }

                    $enriched++;
                    $processed++;
                }
            } catch (\Throwable $e) {
                $this->warn("  Batch eșuat: " . $e->getMessage());
                Log::warning('EnrichProductsFromWeb batch failed: ' . $e->getMessage());
                $errors    += $batch->count();
                $processed += $batch->count();

                if (str_contains($e->getMessage(), '529') || str_contains($e->getMessage(), 'overloaded')) {
                    $this->warn('  API supraîncărcat — aștept 60s...');
                    sleep(60);
                } elseif (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                    $this->warn('  Rate limited — aștept 30s...');
                    sleep(30);
                }
            }

            usleep(500_000); // 0.5s între batch-uri
        }

        $this->newLine();
        $this->info("Gata. Îmbogățite: {$enriched} | Negăsite: {$notFound} | Erori: {$errors}" . ($dryRun ? ' [DRY RUN]' : ''));

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function enrichBatch(array $products): array
    {
        $lines = '';
        foreach ($products as $p) {
            $lines .= "  ID:{$p->id} | EAN:{$p->sku} | Denumire curentă: {$p->name}\n";
        }

        $prompt = <<<PROMPT
Ești un expert în produse de bricolaj, construcții și hardware din România.

## Sarcina

Pentru fiecare produs de mai jos, caută pe internet informații despre el folosind codul EAN sau denumirea.
Verifică OBLIGATORIU dacă EAN-ul corespunde exact produsului descris în denumire.

{$lines}

## Ce trebuie să returnezi (JSON)

```json
{
  "PRODUCT_ID": {
    "found": true,
    "confidence": "high|medium|low",
    "title_ro": "Titlu corect în română, Title Case, max 70 caractere",
    "brand": "Numele brandului/producătorului dacă l-ai găsit",
    "description_ro": "Descriere în română, 2-4 propoziții, tehnic și util pentru client, fără prețuri",
    "attributes": {
      "Putere": "8W",
      "Culoare lumină": "3000K Caldă",
      "Dulie": "E27"
    }
  }
}
```

## Reguli STRICTE

1. **found: false** dacă nu ești sigur că e același produs sau dacă nu găsești informații clare
2. **confidence: "high"** numai dacă ai găsit produsul exact pe un site de comerț sau producător
3. **confidence: "medium"** dacă denumirea se potrivește dar nu ai confirmare EAN
4. **confidence: "low"** → setează found: false (nu salvăm informații incerte)
5. Titlul în română, Title Case: "Bec LED 8W E27 3000K V-TAC" nu "BEC LED 8W E27"
6. Descrierea: fără prețuri, fără "produs de calitate", concretă și utilă
7. Atributele: doar ce ai găsit sigur, în format cheie: valoare, în română
8. Răspunde NUMAI cu JSON valid, fără text în afara JSON-ului

Caută fiecare EAN/produs și completează JSON-ul.
PROMPT;

        $response = $this->claude->messages->create(
            maxTokens: 4000,
            model:     $this->model,
            messages:  [['role' => 'user', 'content' => $prompt]],
            tools:     [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => count($products) * 2]],
        );

        $text = '';
        foreach ($response->content as $block) {
            if (isset($block->text)) {
                $text .= $block->text;
            }
        }

        return $this->parseResponse($text, $products);
    }

    private function parseResponse(string $text, array $products): array
    {
        // Extrage JSON din răspuns (poate fi în ```json ... ``` sau direct)
        $text    = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $text);
        $validIds = array_map(fn ($p) => $p->id, $products);
        $result  = [];

        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $data = json_decode($m[0], true);

            if (is_array($data)) {
                foreach ($data as $idStr => $info) {
                    $id = (int) $idStr;
                    if (! in_array($id, $validIds, true)) {
                        continue;
                    }
                    if (! is_array($info)) {
                        continue;
                    }

                    // Respingem automat produsele cu confidence: "low"
                    if (($info['confidence'] ?? '') === 'low') {
                        $info['found'] = false;
                    }

                    $result[$id] = $info;
                }
            }
        }

        return $result;
    }

    private function saveEnrichment(object $product, array $result): void
    {
        $updates = [];

        // Titlu: actualizăm NUMAI dacă nu a fost deja reformat și noul titlu e mai bun
        $alreadyReformatted = str_contains((string) ($product->erp_notes ?? ''), '[titlu-reformat]');
        $newTitle = $result['title_ro'] ?? null;

        if ($newTitle && ! $alreadyReformatted && $newTitle !== $product->name) {
            $updates['name'] = $newTitle;
        }

        // Descriere: actualizăm dacă e goală sau mai scurtă de 50 caractere
        $newDescription = $result['description_ro'] ?? null;
        if ($newDescription && (empty($product->description) || mb_strlen($product->description) < 50)) {
            $updates['description'] = $newDescription;
        }

        // Marcăm că a fost îmbogățit din web
        $notes = $product->erp_notes ?? '';
        if (! str_contains($notes, '[web-enriched]')) {
            $notes = trim($notes . ' [web-enriched]');
        }
        $updates['erp_notes'] = $notes;

        if ($updates !== []) {
            $updates['updated_at'] = now();
            DB::table('woo_products')->where('id', $product->id)->update($updates);
        }

        // Brand → furnizor (dacă am găsit brandul și există în suppliers)
        $brand = $result['brand'] ?? null;
        if ($brand) {
            $supplier = DB::table('suppliers')
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($brand)])
                ->first(['id']);

            if ($supplier) {
                DB::table('product_suppliers')->upsert(
                    [
                        'woo_product_id' => $product->id,
                        'supplier_id'    => $supplier->id,
                        'is_preferred'   => false,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ],
                    ['woo_product_id', 'supplier_id'],
                    ['updated_at']
                );
            }
        }

        // Atribute: adaugă sau actualizează
        $attributes = $result['attributes'] ?? [];
        if (! empty($attributes)) {
            $existing = DB::table('woo_product_attributes')
                ->where('woo_product_id', $product->id)
                ->get(['id', 'name'])
                ->keyBy(fn ($a) => mb_strtolower($a->name));

            $inserts = [];
            $now     = now();

            foreach ($attributes as $attrName => $attrValue) {
                if (empty($attrName) || empty($attrValue)) {
                    continue;
                }

                $key = mb_strtolower($attrName);

                if (isset($existing[$key])) {
                    // Actualizăm dacă valoarea s-a schimbat
                    DB::table('woo_product_attributes')
                        ->where('id', $existing[$key]->id)
                        ->update(['value' => (string) $attrValue, 'updated_at' => $now]);
                } else {
                    $inserts[] = [
                        'woo_product_id' => $product->id,
                        'name'           => $attrName,
                        'value'          => (string) $attrValue,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];
                }
            }

            if (! empty($inserts)) {
                DB::table('woo_product_attributes')->insert($inserts);
            }
        }
    }

    private function showDryRunDiff(object $product, array $result): void
    {
        if (! empty($result['title_ro']) && $result['title_ro'] !== $product->name) {
            $this->line("    Titlu: <fg=red>{$product->name}</> → <fg=green>{$result['title_ro']}</>");
        }
        if (! empty($result['brand'])) {
            $this->line("    Brand: {$result['brand']}");
        }
        if (! empty($result['description_ro'])) {
            $desc = substr($result['description_ro'], 0, 100) . '...';
            $this->line("    Descriere: {$desc}");
        }
        if (! empty($result['attributes'])) {
            $attrs = collect($result['attributes'])->map(fn ($v, $k) => "{$k}: {$v}")->implode(', ');
            $this->line("    Atribute: {$attrs}");
        }
    }
}
