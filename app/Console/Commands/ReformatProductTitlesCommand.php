<?php

namespace App\Console\Commands;

use Anthropic\Client as AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reformatează titlurile produselor WinMentor pentru o ordine logică a parametrilor.
 *
 * Normalizarea anterioară a rezolvat: ALLCAPS → Title Case, abrevieri expandate.
 * Această comandă rezolvă: ordinea parametrilor în titlu, per tip de produs.
 *
 * Exemplu:
 *  ÎNAINTE: Bec LED 4.5W V-TAC E14 Lustra Cald 3000K
 *  DUPĂ:    Bec LED 4.5W E14 3000K V-TAC
 */
class ReformatProductTitlesCommand extends Command
{
    protected $signature = 'products:reformat-titles
                            {--dry-run   : Afișează modificările fără să le aplice}
                            {--limit=    : Max produse de procesat}
                            {--batch-size=20 : Produse per apel Claude}
                            {--regenerate : Re-procesează și produsele deja reformatate}';

    protected $description = 'Reformatează ordinea parametrilor în titlurile produselor WinMentor';

    private AnthropicClient $claude;
    private string $model;

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            $this->error('ANTHROPIC_API_KEY nu este setat în .env');
            return self::FAILURE;
        }

        $this->claude  = new AnthropicClient(apiKey: $apiKey);
        $this->model   = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
        $dryRun        = (bool) $this->option('dry-run');
        $limit         = $this->option('limit') ? (int) $this->option('limit') : null;
        $batchSize     = max(1, min(20, (int) $this->option('batch-size')));
        $regenerate    = (bool) $this->option('regenerate');

        $this->info("Reformatare titluri — model: {$this->model}" . ($dryRun ? ' [DRY RUN]' : ''));

        $categoryMap = $this->buildCategoryMap();

        $query = DB::table('woo_products')
            ->where('is_placeholder', true)
            ->where('source', 'winmentor_csv')
            ->select('id', 'name', 'erp_notes');

        if (! $regenerate) {
            // Procesăm doar produsele care nu au fost încă reformatate
            // (erp_notes conține originalul → dacă există erp_notes, normalizarea a rulat)
            // Adăugăm un marker în erp_notes după reformatare pentru a nu reprocesa
            $query->whereRaw("erp_notes NOT LIKE '%[titlu-reformat]%' OR erp_notes IS NULL");
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products  = $query->get();
        $total     = $products->count();

        if ($total === 0) {
            $this->info('Niciun produs de procesat.');
            return self::SUCCESS;
        }

        $this->info("Produse de procesat: {$total}");

        $processed = 0;
        $changed   = 0;
        $skipped   = 0;

        foreach ($products->chunk($batchSize) as $batch) {
            $batch = $batch->values();
            $from  = $processed + 1;
            $to    = $processed + $batch->count();

            $this->info("[{$from}–{$to} / {$total}] Reformatare...");

            try {
                $results = $this->reformatBatch($batch->toArray(), $categoryMap);

                foreach ($batch as $product) {
                    $newTitle = $results[$product->id] ?? null;

                    if (! $newTitle || $newTitle === $product->name) {
                        $skipped++;
                        $processed++;
                        continue;
                    }

                    $changed++;
                    $this->line("  #{$product->id}");
                    $this->line("    ÎNAINTE: {$product->name}");
                    $this->line("    DUPĂ:    {$newTitle}");

                    if (! $dryRun) {
                        // Marchează că a fost reformat în erp_notes
                        $notes = $product->erp_notes ?? '';
                        if (! str_contains((string) $notes, '[titlu-reformat]')) {
                            $notes = trim($notes . ' [titlu-reformat]');
                        }

                        DB::table('woo_products')->where('id', $product->id)->update([
                            'name'       => $newTitle,
                            'erp_notes'  => $notes,
                            'updated_at' => now(),
                        ]);
                    }

                    $processed++;
                }
            } catch (\Throwable $e) {
                $this->warn("  Batch eșuat: " . $e->getMessage());
                Log::warning('ReformatProductTitles batch failed: ' . $e->getMessage());

                $processed += $batch->count();
                $skipped   += $batch->count();

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'rate')) {
                    $this->warn('  Rate limited — aștept 30s...');
                    sleep(30);
                }
            }

            usleep(200_000);
        }

        $this->newLine();
        $this->info("Gata. Modificate: {$changed} | Neschimbate: {$skipped}" . ($dryRun ? ' [DRY RUN — nimic salvat]' : ''));

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function reformatBatch(array $products, array $categoryMap): array
    {
        $prompt  = $this->buildPrompt($products, $categoryMap);
        $message = $this->claude->messages->create(
            maxTokens: 4000,
            messages:  [['role' => 'user', 'content' => $prompt]],
            model:     $this->model,
        );

        $text = '';
        foreach ($message->content as $block) {
            if (isset($block->text)) $text .= $block->text;
        }

        return $this->parseResponse($text, $products);
    }

    private function buildPrompt(array $products, array $categoryMap): string
    {
        $lines = '';
        foreach ($products as $p) {
            $category = $categoryMap[$p->id] ?? null;
            $catStr   = $category ? " [{$category}]" : '';
            $lines   .= "  {$p->id}: {$p->name}{$catStr}\n";
        }

        return <<<PROMPT
Ești un specialist în catalogarea produselor pentru un magazin online de materiale de construcții din România.

## Sarcina

Reformatează titlurile produselor de mai jos pentru o ordine logică și corectă a parametrilor.
Titlurile sunt deja în Title Case. Schimbă ORDINEA parametrilor și adaugă diacritice românești lipsă.

{$lines}

## Regula PRINCIPALĂ — ordinea obligatorie:
[Tip produs] [Material dacă există] [Dimensiuni/Specificații tehnice] [Brand dacă există]

Materialul (Cupru, Alamă, Zinc, PPR, PVC, INOX, Aluminiu etc.) vine ÎNTOTDEAUNA după tipul de produs.
Greșit: "Zinc Mufă Redusă 1×1/2" → Corect: "Mufă Redusă Zinc 1×1/2"
Greșit: "Cupru Cot 20mm" → Corect: "Cot Cupru 20mm"

## Diacritice și expansiuni obligatorii
Adaugă diacritice lipsă și expandează abrevieri:
- Mufa → Mufă, Reductie → Reducție, Teava → Țeavă, Surub → Șurub
- Surubelnita → Șurubelniță, Drisca → Driscă, Gletiera → Gletieră
- Filet interior/exterior: Int-Ext → Interior-Exterior, Int-Int → Interior-Interior, Ext-Ext → Exterior-Exterior
- Int standalone (la final) → Interior, Ext standalone → Exterior

## Reguli per categorie

**Becuri:** Bec [Tip LED/Halogen] [Putere W] [Dulie E14/E27] [Temperatura K] [Brand]
Ex: "Bec LED 4.5W E14 3000K V-TAC", "Bec Halogen 28W E14"

**Siguranțe automate:** Siguranță [Brand] [Curba][Curent A]
Ex: "Siguranță Moeller C10", "Siguranță Moeller C16"

**Tablouri electrice:** Tablou Siguranțe [Tip montaj] [N Module]
Ex: "Tablou Siguranțe Ingropat 12 Module"

**Fitinguri (Mufă, Teu, Cot, Niplu, Reducție, Olandez):**
[Tip fitng] [Material] [Diametru/Filet]
Ex: "Mufă Cupru 20mm", "Teu PPR 25mm", "Reducție Alamă 1″-1/2″ Ext-Int"

**Discuri:** Disc [Tip: Diamantat/Abraziv/Metal] [Diametru] [Brand]
Ex: "Disc Diamantat 125mm", "Disc Abraziv Metal 115mm"

**Cabluri:** Cablu [Tip] [Secțiune mm²] [Culoare]
Ex: "Cablu MYF 2.5mm² Maro"

**Profile:** Profil [Tip UD/CW] [Dimensiuni]
Ex: "Profil UD 4m", "Profil CW 50mm"

**Vopsele/emailuri/lacuri:** [Tip] [Brand] [Culoare] [Volum L/Greutate kg]
Ex: "Email Sobe 0.75L", "Grund Roșu 0.75L"

**Scule:** [Tip unealtă] [Specificații] [Brand]
Ex: "Ciocan Dulgher 0.6kg"

**Altele:** [Tip] [Material] [Dimensiuni] [Brand]

## Reguli stricte
- Păstrează TOATE specificațiile tehnice existente — nu elimina dimensiuni sau specificații
- NU schimba tipul de produs (Mufă rămâne Mufă, Niplu rămâne Niplu etc.)
- Maxim 70 de caractere
- Nu adăuga informații inexistente

## Format răspuns

JSON valid, fără text înainte sau după:
{
  "PRODUCT_ID": "Titlu reformat"
}
PROMPT;
    }

    private function parseResponse(string $text, array $products): array
    {
        $text     = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $text);
        $validIds = array_map(fn ($p) => $p->id, $products);
        $result   = [];

        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $data = json_decode($m[0], true);

            if (is_array($data)) {
                foreach ($data as $idStr => $title) {
                    $id = (int) $idStr;
                    if (in_array($id, $validIds, true) && is_string($title) && ! empty(trim($title))) {
                        $result[$id] = trim($title);
                    }
                }
            }
        }

        return $result;
    }

    private function buildCategoryMap(): array
    {
        $rows = DB::table('woo_product_category as pc')
            ->join('woo_categories as wc', 'wc.id', '=', 'pc.woo_category_id')
            ->leftJoin('woo_categories as parent', 'parent.id', '=', 'wc.parent_id')
            ->leftJoin('woo_categories as gp', 'gp.id', '=', 'parent.parent_id')
            ->select('pc.woo_product_id', 'wc.name as cat', 'parent.name as parent', 'gp.name as gp')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $parts = array_filter([$row->gp, $row->parent, $row->cat]);
            $map[(int) $row->woo_product_id] = implode(' > ', $parts);
        }

        return $map;
    }
}
