<?php

namespace App\Jobs;

use App\Models\ProductSubstitutionProposal;
use App\Models\WooProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProposeProductSubstitutionJob extends BaseAiJob
{
    public int $tries   = 2;
    public int $timeout = 120;

    /**
     * @param array<int> $sourceProductIds  ID-uri produse non-Toya de analizat (max 5)
     */
    public function __construct(private readonly array $sourceProductIds) {}

    public function handle(): void
    {
        $apiKey = $this->getApiKey();

        $products = WooProduct::whereIn('id', $this->sourceProductIds)
            ->where('source', '!=', WooProduct::SOURCE_TOYA_API)
            ->get(['id', 'name', 'sku', 'brand', 'description']);

        if ($products->isEmpty()) {
            return;
        }

        // Construim lista de candidați Toya pentru fiecare produs
        // prin keyword search pe nume
        $items = [];
        foreach ($products as $product) {
            $candidates = $this->findToyaCandidates($product->name);
            if ($candidates->isEmpty()) {
                // Fără candidați — marcăm direct no_match
                ProductSubstitutionProposal::updateOrCreate(
                    ['source_product_id' => $product->id],
                    ['status' => 'no_match', 'reasoning' => 'Niciun candidat Toya găsit prin keyword search.']
                );
                continue;
            }

            $items[] = [
                'id'         => $product->id,
                'name'       => $product->name,
                'sku'        => $product->sku,
                'brand'      => $product->brand ?? '',
                'candidates' => $candidates->map(fn ($c) => [
                    'id'   => $c->id,
                    'name' => $c->name,
                    'sku'  => $c->sku,
                    'brand'=> $c->brand ?? '',
                ])->values()->all(),
            ];
        }

        if (empty($items)) {
            return;
        }

        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $systemPrompt = <<<'PROMPT'
Ești un specialist în scule, unelte și echipamente tehnice. Analizezi produse dintr-un catalog existent și găsești echivalentul potrivit din catalogul Toya.

Reguli:
1. Returnezi DOAR JSON valid, fără markdown, fără text în afara JSON-ului
2. Pentru fiecare produs analizat returnezi cel mai bun candidat Toya sau null dacă nu există potrivire
3. Potrivirea se bazează pe: funcție identică sau echivalentă, dimensiuni similare, tip de produs
4. confidence: 0.0-1.0 (0.9+ = potrivire clară, 0.7-0.9 = probabil, sub 0.7 = incert)
5. Dacă niciun candidat nu se potrivește rezonabil, returnezi proposed_toya_id: null și status: "no_match"
6. Nu forța potriviri între produse fundamental diferite
PROMPT;

        $userPrompt = "Analizează aceste produse și găsește cel mai bun echivalent Toya din lista de candidați:\n\n{$itemsJson}\n\n"
            . "Răspunde cu JSON array:\n"
            . '[{"id":123,"proposed_toya_id":456,"confidence":0.85,"reasoning":"Motiv scurt"},...] '
            . '(sau proposed_toya_id: null dacă nu există potrivire)';

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout(90)
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 4096,
                'system'     => $systemPrompt,
                'messages'   => [['role' => 'user', 'content' => $userPrompt]],
            ]);

        if (! $response->successful()) {
            Log::error('ProposeProductSubstitutionJob: API error', ['status' => $response->status()]);
            $this->fail(new \RuntimeException('Claude API error: ' . $response->status()));
            return;
        }

        $body         = $response->json();
        $inputTokens  = $body['usage']['input_tokens'] ?? 0;
        $outputTokens = $body['usage']['output_tokens'] ?? 0;

        $this->recordUsage(
            'product_substitution',
            'claude-haiku-4-5-20251001',
            $inputTokens,
            $outputTokens,
            ['count' => count($this->sourceProductIds)]
        );

        $rawText = $body['content'][0]['text'] ?? '';
        $rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
        $rawText = preg_replace('/\s*```\s*$/m', '', $rawText);
        $rawText = trim($rawText);

        if (preg_match('/\[.*\]/s', $rawText, $m)) {
            $rawText = $m[0];
        }

        $results = json_decode($rawText, true);

        if (! is_array($results)) {
            Log::warning('ProposeProductSubstitutionJob: invalid JSON', ['raw' => substr($rawText, 0, 300)]);
            return;
        }

        foreach ($results as $result) {
            $id             = (int) ($result['id'] ?? 0);
            $proposedToyaId = $result['proposed_toya_id'] ? (int) $result['proposed_toya_id'] : null;
            $confidence     = isset($result['confidence']) ? (float) $result['confidence'] : null;
            $reasoning      = trim($result['reasoning'] ?? '');

            if (! $id) {
                continue;
            }

            // Validăm că proposed_toya_id există și e Toya
            if ($proposedToyaId && ! WooProduct::where('id', $proposedToyaId)->where('source', WooProduct::SOURCE_TOYA_API)->exists()) {
                $proposedToyaId = null;
            }

            ProductSubstitutionProposal::updateOrCreate(
                ['source_product_id' => $id],
                [
                    'proposed_toya_id' => $proposedToyaId,
                    'confidence'       => $confidence,
                    'reasoning'        => $reasoning,
                    'status'           => $proposedToyaId ? 'pending' : 'no_match',
                ]
            );
        }
    }

    /**
     * Caută candidați Toya prin keyword search pe numele produsului.
     * Extrage cuvintele cheie relevante și face FULLTEXT / LIKE search.
     */
    private function findToyaCandidates(string $name): \Illuminate\Support\Collection
    {
        // Curățăm și luăm primele 3 cuvinte semnificative (>= 3 litere)
        $words = array_values(array_filter(
            explode(' ', preg_replace('/[^a-zA-ZăâîșțĂÂÎȘȚ0-9\s]/u', ' ', $name)),
            fn ($w) => mb_strlen($w) >= 3
        ));

        if (empty($words)) {
            return collect();
        }

        // Căutăm cu primele 3 cuvinte cheie
        $keywords = array_slice($words, 0, 3);

        $query = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
            ->whereNotNull('name');

        foreach ($keywords as $kw) {
            $query->where('name', 'like', "%{$kw}%");
        }

        $results = $query->limit(15)->get(['id', 'name', 'sku', 'brand']);

        // Dacă prea puțini cu toate cuvintele, relaxăm la primul cuvânt
        if ($results->count() < 3 && count($keywords) > 1) {
            $results = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)
                ->where('name', 'like', "%{$keywords[0]}%")
                ->limit(15)
                ->get(['id', 'name', 'sku', 'brand']);
        }

        return $results;
    }
}
