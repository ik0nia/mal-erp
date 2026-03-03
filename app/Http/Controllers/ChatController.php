<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\ChatLog;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chatService)
    {
    }

    public function preflight(): Response
    {
        return $this->corsResponse(response('', 204));
    }

    public function config(): JsonResponse
    {
        return $this->corsResponse(response()->json([
            'primary_color' => AppSetting::get(AppSetting::KEY_CHAT_PRIMARY_COLOR, '#e65100'),
            'bot_name'      => AppSetting::get(AppSetting::KEY_CHAT_BOT_NAME,      'Malinco'),
            'subtitle'      => AppSetting::get(AppSetting::KEY_CHAT_SUBTITLE,      'Asistent virtual'),
            'welcome_msg'   => AppSetting::get(AppSetting::KEY_CHAT_WELCOME_MSG,   'Bună ziua! Cu ce vă pot ajuta? Puteți căuta un produs, verifica o comandă sau afla detalii despre livrare.'),
            'enabled'       => AppSetting::get(AppSetting::KEY_CHAT_ENABLED, '1') === '1',
        ], 200, ['Cache-Control' => 'public, max-age=300']));
    }

    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message'    => ['required', 'string', 'max:500'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'page_url'   => ['nullable', 'string', 'max:500'],
            'page_title' => ['nullable', 'string', 'max:200'],
        ]);

        $sessionId = filled($validated['session_id'] ?? '')
            ? $validated['session_id']
            : Str::uuid()->toString();

        $ip = $request->ip();

        try {
            // Log mesajul clientului
            ChatLog::log($sessionId, 'user', $validated['message'], $ip);

            // Apelăm serviciul AI
            $pageContext = filled($validated['page_url'] ?? '') ? [
                'url'   => $validated['page_url'],
                'title' => $validated['page_title'] ?? '',
            ] : null;
            $result       = $this->chatService->chat($sessionId, trim($validated['message']), $pageContext);
            $reply        = $result['reply'];
            $products     = $result['products'];
            $inputTokens  = $result['input_tokens']  ?? 0;
            $outputTokens = $result['output_tokens'] ?? 0;

            // Log răspunsul botului (cu tokeni pentru cost tracking)
            ChatLog::log($sessionId, 'assistant', $reply, $ip, count($products) > 0, $inputTokens, $outputTokens);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ChatController: eroare neașteptată', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
            $reply    = 'Îmi pare rău, am întâmpinat o problemă tehnică. Vă rugăm să reîncercați.';
            $products = [];
        }

        $response = response()->json([
            'reply'                => $reply,
            'session_id'           => $sessionId,
            'products'             => $products,
            'contact_form'         => $result['show_contact_form']    ?? false,
            'contact_form_message' => $result['contact_form_message'] ?? '',
        ]);

        return $this->corsResponse($response);
    }

    public function contact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id'       => ['required', 'string', 'max:64'],
            'email'            => ['nullable', 'email', 'max:200'],
            'phone'            => ['nullable', 'string', 'max:30'],
            'wants_specialist' => ['nullable', 'boolean'],
        ]);

        if (blank($validated['email'] ?? '') && blank($validated['phone'] ?? '')) {
            return $this->corsResponse(response()->json(['saved' => false, 'error' => 'Completați cel puțin un câmp.'], 422));
        }

        \App\Models\ChatContact::collect(
            sessionId:       $validated['session_id'],
            email:           filled($validated['email'] ?? '')  ? $validated['email']  : null,
            phone:           filled($validated['phone'] ?? '')  ? $validated['phone']  : null,
            wantsSpecialist: (bool) ($validated['wants_specialist'] ?? false),
        );

        // Generează rezumat AI, apoi trimite notificare Telegram (cu delay ca să aibă rezumatul)
        \App\Jobs\GenerateChatSummaryJob::dispatch($validated['session_id'])->afterResponse();
        \App\Jobs\SendTelegramLeadJob::dispatch($validated['session_id'])
            ->afterResponse()
            ->delay(now()->addSeconds(35)); // după ce GenerateChatSummaryJob termină (~25s)

        return $this->corsResponse(response()->json(['saved' => true]));
    }

    /** @param \Illuminate\Http\Response|\Illuminate\Http\JsonResponse $response */
    private function corsResponse($response)
    {
        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
    }
}
