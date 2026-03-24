<?php

namespace App\Jobs;

use App\Models\ToyaCategoryProposal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProposeToyaCategoryMappingJob extends BaseAiJob
{
    public int $tries   = 3;
    public int $timeout = 300;

    /**
     * @param array<string, int>  $paths        ['toya path' => product_count]
     * @param array<int, string>  $wooCategories [id => 'Name > Parent > Grandparent']
     * @param int                 $chunkIndex
     */
    public function __construct(
        private readonly array $paths,
        private readonly array $wooCategories,
        private readonly int   $chunkIndex = 0,
    ) {}

    public function handle(): void
    {
        $apiKey = $this->getApiKey();

        // Construim lista WooCommerce pentru prompt
        $wooCatList = collect($this->wooCategories)
            ->map(fn ($name, $id) => "  [{$id}] {$name}")
            ->join("\n");

        // Construim lista Toya pentru prompt
        $toyaList = collect($this->paths)
            ->map(fn ($count, $path) => "  - {$path} ({$count} produse)")
            ->join("\n");

        $systemPrompt = <<<'PROMPT'
Ești un expert în clasificarea produselor de tip unelte, scule, materiale de construcții și accesorii de grădină.
Vei primi o listă de categorii de produse din feedul furnizorului Toya (producător polonez de unelte YATO, VOREL, FLO, STHOR)
și o listă de categorii disponibile în WooCommerce.

Sarcina ta: pentru fiecare categorie Toya, propune cea mai potrivită categorie din WooCommerce.

Reguli stricte:
1. Returnezi DOAR JSON valid, fără text în afara JSON-ului
2. Dacă nu există nicio potrivire rezonabilă, setezi proposed_id la null și status la "no_match"
3. confidence: 0.0-1.0 (1.0 = potrivire perfectă, 0.5 = potrivire parțială, sub 0.4 = no_match)
4. Dacă confidence < 0.4, setezi proposed_id null și status "no_match"
5. alternatives: array de max 2 ID-uri alternative (goale dacă nu sunt)
PROMPT;

        $userPrompt = "CATEGORII WOOCOMMERCE DISPONIBILE:\n{$wooCatList}\n\nCATEGORII TOYA DE MAPAT:\n{$toyaList}\n\nRăspunde cu JSON array:\n[\n  {\n    \"toya_path\": \"exact path-ul Toya\",\n    \"proposed_id\": 42,\n    \"alternatives\": [15, 88],\n    \"confidence\": 0.85,\n    \"reasoning\": \"scurtă explicație în română\",\n    \"status\": \"pending\"\n  },\n  ...\n]";

        $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->timeout(240)
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 8192,
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if (! $response->successful()) {
            Log::error('ProposeToyaCategoryMappingJob: API error', [
                'chunk'  => $this->chunkIndex,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $this->fail(new \RuntimeException('Claude API error: ' . $response->status()));
            return;
        }

        $body        = $response->json();
        $inputTokens = $body['usage']['input_tokens'] ?? 0;
        $outputTokens = $body['usage']['output_tokens'] ?? 0;

        $this->recordUsage(
            'toya_category_mapping',
            'claude-haiku-4-5-20251001',
            $inputTokens,
            $outputTokens,
            ['chunk' => $this->chunkIndex, 'paths_count' => count($this->paths)]
        );

        $rawText = $body['content'][0]['text'] ?? '';

        // Eliminăm markdown code blocks (```json ... ```)
        $rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
        $rawText = preg_replace('/\s*```\s*$/m', '', $rawText);
        $rawText = trim($rawText);

        // Extragem JSON array din răspuns
        if (preg_match('/\[.*\]/s', $rawText, $m)) {
            $rawText = $m[0];
        }

        $proposals = json_decode($rawText, true);

        if (! is_array($proposals)) {
            Log::warning('ProposeToyaCategoryMappingJob: invalid JSON response', [
                'chunk' => $this->chunkIndex,
                'raw'   => substr($rawText, 0, 500),
            ]);
            return;
        }

        foreach ($proposals as $proposal) {
            $toyaPath = $proposal['toya_path'] ?? null;
            if (! $toyaPath) {
                continue;
            }

            $confidence  = isset($proposal['confidence']) ? (float) $proposal['confidence'] : null;
            $proposedId  = ($confidence !== null && $confidence >= 0.4)
                ? ($proposal['proposed_id'] ?: null)
                : null;
            $status      = $proposal['status'] ?? ($proposedId ? 'pending' : 'no_match');
            $productCount = $this->paths[$toyaPath] ?? 0;

            ToyaCategoryProposal::updateOrCreate(
                ['toya_path' => $toyaPath],
                [
                    'product_count'              => $productCount,
                    'proposed_woo_category_id'   => $proposedId,
                    'alternative_category_ids'   => $proposal['alternatives'] ?? [],
                    'confidence'                 => $confidence,
                    'reasoning'                  => $proposal['reasoning'] ?? null,
                    'status'                     => $proposedId ? 'pending' : 'no_match',
                ]
            );
        }

        Log::info("ProposeToyaCategoryMappingJob: chunk {$this->chunkIndex} done", [
            'paths'     => count($this->paths),
            'proposals' => count($proposals),
        ]);
    }
}
