<?php

namespace App\Jobs;

use App\Models\WooProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateToyaDescriptionsJob extends BaseAiJob
{
    public int $tries   = 2;
    public int $timeout = 120;

    /**
     * @param array<int> $productIds  ID-urile produselor de procesat (max 5)
     */
    public function __construct(private readonly array $productIds) {}

    public function handle(): void
    {
        $apiKey   = $this->getApiKey();
        $products = WooProduct::with('attributes')
            ->whereIn('id', $this->productIds)
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        // Construim payload pentru fiecare produs
        $items = [];
        foreach ($products as $product) {
            $data = $product->data;
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            if (is_string($data)) {
                $data = json_decode($data, true);
            }

            // Atribute (max 20 cele mai relevante)
            $attrs = $product->attributes
                ->take(20)
                ->map(fn ($a) => $a->name . ': ' . $a->value)
                ->join("\n");

            // Categorie Toya (ultimele 2 segmente din path)
            $catPath  = $data['category_ro'] ?? '';
            $segments = array_filter(array_map('trim', explode('/', $catPath)));
            $segments = array_values(array_filter($segments, fn ($s) => ! in_array(mb_strtolower($s), ['produkty', 'products'])));
            $category = count($segments) >= 2
                ? implode(' > ', array_slice($segments, -2))
                : implode(' > ', $segments);

            // Avertizări de siguranță
            $warnings = $data['safety_warnings_ro'] ?? [];

            $items[] = [
                'id'       => $product->id,
                'name'     => $product->name,
                'brand'    => $product->brand ?? '',
                'category' => $category,
                'attrs'    => $attrs,
                'warnings' => $warnings,
            ];
        }

        $productsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $systemPrompt = <<<'PROMPT'
Ești un copywriter specializat în unelte, scule și echipamente profesionale. Scrii descrieri de produse în română pentru un magazin online.

Reguli stricte:
1. Returnezi DOAR JSON valid, fără text în afara JSON-ului, fără markdown
2. Folosești DOAR informațiile furnizate — nu inventa specificații
3. Descrierea lungă (description): 80-150 cuvinte, format HTML cu <p> și opțional <ul> pentru caracteristici cheie
4. Descrierea scurtă (short_description): 1-2 propoziții, simplu <p>, evidențiezi principala utilizare/avantaj
5. Ton: profesional, concis, orientat pe utilitate practică
6. Dacă există avertizări de siguranță, le adaugi la finalul description ca: <p><strong>Avertizări:</strong> text</p>
7. NU repeta denumirea produsului la începutul descrierii
8. Scrie pentru clientul final, nu tehnic
PROMPT;

        $userPrompt = "Generează descrieri pentru aceste produse:\n\n{$productsJson}\n\nRăspunde cu JSON array:\n[{\"id\":123,\"description\":\"<p>...</p>\",\"short_description\":\"<p>...</p>\"},...]";

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout(90)
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 8192,
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if (! $response->successful()) {
            Log::error('GenerateToyaDescriptionsJob: API error', [
                'status' => $response->status(),
                'ids'    => $this->productIds,
            ]);
            $this->fail(new \RuntimeException('Claude API error: ' . $response->status()));
            return;
        }

        $body         = $response->json();
        $inputTokens  = $body['usage']['input_tokens'] ?? 0;
        $outputTokens = $body['usage']['output_tokens'] ?? 0;

        $this->recordUsage(
            'toya_descriptions',
            'claude-haiku-4-5-20251001',
            $inputTokens,
            $outputTokens,
            ['count' => count($this->productIds)]
        );

        $rawText = $body['content'][0]['text'] ?? '';

        // Eliminăm markdown code blocks
        $rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
        $rawText = preg_replace('/\s*```\s*$/m', '', $rawText);
        $rawText = trim($rawText);

        if (preg_match('/\[.*\]/s', $rawText, $m)) {
            $rawText = $m[0];
        }

        $results = json_decode($rawText, true);

        if (! is_array($results)) {
            Log::warning('GenerateToyaDescriptionsJob: invalid JSON', [
                'ids' => $this->productIds,
                'raw' => substr($rawText, 0, 300),
            ]);
            return;
        }

        foreach ($results as $result) {
            $id    = $result['id'] ?? null;
            $desc  = trim($result['description'] ?? '');
            $short = trim($result['short_description'] ?? '');

            if (! $id || (! $desc && ! $short)) {
                continue;
            }

            WooProduct::where('id', $id)->update([
                'description'       => $desc ?: null,
                'short_description' => $short ?: null,
            ]);
        }
    }
}
