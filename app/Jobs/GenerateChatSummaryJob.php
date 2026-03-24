<?php

namespace App\Jobs;

use App\Models\AiUsageLog;
use App\Models\AppSetting;
use App\Models\ChatContact;
use App\Models\ChatLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateChatSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 30;

    public function __construct(private readonly string $sessionId) {}

    public function handle(): void
    {
        $contact = ChatContact::where('session_id', $this->sessionId)->first();
        if (! $contact) {
            return;
        }

        // Încarcă ultimele 30 mesaje din conversație
        $messages = ChatLog::where('session_id', $this->sessionId)
            ->orderBy('created_at')
            ->limit(30)
            ->get(['role', 'content']);

        if ($messages->count() < 2) {
            return;
        }

        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY) ?? '';
        if (blank($apiKey)) {
            return;
        }

        // Formatează conversația (truncat pentru cost minim)
        $conversation = $messages->map(function ($m) {
            $prefix  = $m->role === 'user' ? 'Client' : 'Bot';
            $content = mb_substr(strip_tags($m->content), 0, 250);
            return "{$prefix}: {$content}";
        })->implode("\n");

        $prompt = <<<PROMPT
Analizează această conversație de chat de la un magazin de materiale de construcții și extrage:

1. Produse/categorii de interes (max 3, specific cu cantitate dacă s-a menționat)
2. Rezumat scurt al nevoii clientului (1-2 propoziții, în română)

Conversație:
{$conversation}

Răspunde STRICT în JSON (fără text suplimentar):
{"interested_in": "ex: 100 buc BISON, vată minerală Knauf 10cm", "summary": "ex: Client interesat de izolație termică pentru casă. Dorește ofertă pentru 100 buc spumă poliuretanică BISON."}
PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(25)->post('https://api.anthropic.com/v1/messages', [
                'model'      => config('app.malinco.ai.models.haiku', 'claude-haiku-4-5-20251001'),
                'max_tokens' => 200,
                'system'     => 'Ești un asistent care extrage date structurate din conversații. Răspunzi DOAR cu JSON valid, fără markdown.',
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

            if (! $response->successful()) {
                Log::warning('GenerateChatSummaryJob: API error', ['status' => $response->status()]);
                return;
            }

            AiUsageLog::record('chat_summary', config('app.malinco.ai.models.haiku', 'claude-haiku-4-5-20251001'),
                (int) $response->json('usage.input_tokens', 0),
                (int) $response->json('usage.output_tokens', 0),
                ['contact_id' => $contact->id]
            );

            $text = $response->json('content.0.text', '');
            // Curăță eventualul markdown ```json ... ```
            $text = preg_replace('/^```json\s*|\s*```$/s', '', trim($text));
            $data = json_decode($text, true);

            if (! is_array($data)) {
                return;
            }

            $contact->update([
                'summary'      => $data['summary']      ?? null,
                'interested_in' => $data['interested_in'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::warning('GenerateChatSummaryJob: eroare', ['error' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(class_basename(static::class) . ' failed', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }
}
