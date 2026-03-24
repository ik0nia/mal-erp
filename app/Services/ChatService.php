<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\AppSetting;
use App\Models\ChatContact;
use App\Models\Location;
use App\Models\ProductStock;
use App\Models\WooOrder;
use App\Models\WooProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Serviciu chatbot AI pentru site-ul Malinco.
 *
 * Returnează: ['reply' => string, 'products' => array]
 *  - products: card-uri cu imagine + link pentru afișare în widget
 */
class ChatService
{
    private const MAX_CONTEXT_MESSAGES  = 6;   // mesaje recente trimise la Claude
    private const COST_PREFIX          = 'chat_cost:';
    private const MAX_TOOL_ITERATIONS  = 5;

    private static function maxHistoryMessages(): int
    {
        return (int) config('app.malinco.chat.max_history', 20);
    }

    private static function cacheTtl(): int
    {
        return (int) config('app.malinco.chat.session_ttl', 900);
    }

    private static function cachePrefix(): string
    {
        return config('app.malinco.cache.chat_prefix', 'chat:');
    }

    private static function statePrefix(): string
    {
        return config('app.malinco.cache.chat_state', 'chat_state:');
    }

    private static function siteUrl(): string
    {
        return rtrim(config('app.malinco.site_url', 'https://malinco.ro'), '/');
    }

    private static function modelHaiku(): string
    {
        return config('app.malinco.ai.models.haiku', 'claude-haiku-4-5-20251001');
    }

    private string $apiKey;

    /** Prețuri Claude Haiku (USD per milion tokeni) */
    private const PRICE_INPUT_PER_M  = 0.80;
    private const PRICE_OUTPUT_PER_M = 4.00;

    /** Produse colectate în timpul execuției tool-urilor (per request) */
    private array $foundProducts = [];

    /** Tokeni acumulați per request (toate iterațiile tool-use) */
    private int $totalInputTokens  = 0;
    private int $totalOutputTokens = 0;

    /** Session ID curent (necesar pentru tool-ul de colectare contact) */
    private string $currentSessionId = '';

    /** Pagina curentă a utilizatorului (URL + titlu), trimis din widget */
    private ?array $pageContext = null;

    /** Rezumatul conversației anterioare (generat când history > MAX_CONTEXT_MESSAGES) */
    private string $conversationSummary = '';

    /** Starea conversației (salvată în cache, trimisă la Claude) */
    private array $conversationState = [];

    /** Istoricul paginilor vizitate de client (trimis din widget) */
    private ?array $pageHistory = null;

    /** Flag: Claude a cerut afișarea formularului de contact */
    private bool   $showContactForm    = false;
    private string $contactFormMessage = '';

    public function __construct()
    {
        $this->apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY) ?? '';
    }

    /**
     * Procesează un mesaj și returnează răspuns + produse pentru widget.
     *
     * @return array{reply: string, products: array}
     */
    public function chat(string $sessionId, string $userMessage, ?array $pageContext = null, ?array $pageHistory = null): array
    {
        $this->foundProducts      = []; // reset per request
        $this->totalInputTokens   = 0;
        $this->totalOutputTokens  = 0;
        $this->currentSessionId   = $sessionId;
        $this->pageContext         = $pageContext;
        $this->pageHistory         = $pageHistory;
        $this->showContactForm      = false;
        $this->contactFormMessage  = '';
        $this->conversationSummary = '';
        $this->conversationState   = [];

        if (blank($this->apiKey)) {
            Log::warning('ChatService: API key Anthropic lipsă');

            return ['reply' => 'Serviciul de chat nu este disponibil momentan.', 'products' => []];
        }

        // Verifică limita de cost per sesiune ÎNAINTE de orice apel AI
        if ($this->isSessionCostExceeded($sessionId)) {
            Log::info('[ChatService] FALLBACK_COST_LIMIT', [
                'session_id' => $sessionId,
                'message'    => mb_substr($userMessage, 0, 100),
                'cost'       => $this->getSessionCost($sessionId),
            ]);

            return $this->costLimitReply();
        }

        $history   = $this->loadHistory($sessionId);
        $history[] = ['role' => 'user', 'content' => $userMessage];

        // Mesaje triviale — răspuns instant fără apel AI (înainte de loadState pentru eficiență)
        if ($this->isTrivialMessage($userMessage)) {
            Log::info('[ChatService] FALLBACK_TRIVIAL', [
                'session_id' => $sessionId,
                'message'    => mb_substr($userMessage, 0, 100),
            ]);
            $reply     = 'Cu drag! Dacă ai nevoie de materiale sau vrei să verific disponibilitatea unui produs, spune-mi.';
            $history[] = ['role' => 'assistant', 'content' => $reply];
            if (count($history) > self::maxHistoryMessages()) {
                $history = array_slice($history, count($history) - self::maxHistoryMessages());
            }
            $this->saveHistory($sessionId, $history);

            return [
                'reply'                => $reply,
                'products'             => [],
                'input_tokens'         => 0,
                'output_tokens'        => 0,
                'show_contact_form'    => false,
                'contact_form_message' => '',
            ];
        }

        $this->conversationState = $this->loadState($sessionId);

        $reply = $this->callClaude($history);

        $history[] = ['role' => 'assistant', 'content' => $reply];

        if (count($history) > self::maxHistoryMessages()) {
            $history = array_slice($history, count($history) - self::maxHistoryMessages());
        }

        $this->saveHistory($sessionId, $history);

        // Actualizează starea conversației și costul sesiunii
        $this->updateConversationState($userMessage, $reply);
        $this->saveState($sessionId, $this->conversationState);
        $this->addSessionCost($sessionId, $this->totalInputTokens, $this->totalOutputTokens);

        if ($this->totalInputTokens > 0) {
            AiUsageLog::record('chatbot', self::modelHaiku(), $this->totalInputTokens, $this->totalOutputTokens, [
                'session_id' => $sessionId,
            ]);
        }

        return [
            'reply'                => $reply,
            'products'             => $this->foundProducts,
            'input_tokens'         => $this->totalInputTokens,
            'output_tokens'        => $this->totalOutputTokens,
            'show_contact_form'    => $this->showContactForm,
            'contact_form_message' => $this->contactFormMessage,
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Claude API loop
    // ──────────────────────────────────────────────────────────

    private function callClaude(array $history): string
    {
        // Scoatem showContactForm din tool-uri dacă formularul a fost deja afișat —
        // cel mai sigur mod de a preveni apelurile repetate (instrucțiunile din prompt nu sunt suficiente).
        $tools = $this->getToolDefinitions();
        if (! empty($this->conversationState['contact_form_shown'])) {
            $tools = array_values(array_filter($tools, fn ($t) => $t['name'] !== 'showContactForm'));
        }

        $messages     = $this->buildContextMessages($history); // context redus (filtrare + rezumat)
        $systemPrompt = $this->getSystemPrompt($history);      // calculat o singură dată; include rezumatul

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            try {
                $response = Http::withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                    'model'      => self::modelHaiku(),
                    'max_tokens' => 768,
                    'system'     => $systemPrompt,
                    'tools'      => $tools,
                    'messages'   => $messages,
                ]);
            } catch (\Throwable $e) {
                Log::error('[ChatService] FALLBACK_ERROR timeout/rețea', [
                    'session_id' => $this->currentSessionId,
                    'error'      => $e->getMessage(),
                ]);

                return $this->fallbackMessage();
            }

            if (! $response->successful()) {
                Log::warning('[ChatService] FALLBACK_ERROR API', [
                    'session_id' => $this->currentSessionId,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);

                return $this->fallbackMessage();
            }

            $data       = $response->json();
            $stopReason = $data['stop_reason'] ?? 'end_turn';
            $content    = $data['content'] ?? [];

            // Acumulează tokeni din fiecare iterație (inclusiv tool-use)
            $this->totalInputTokens  += (int) ($data['usage']['input_tokens']  ?? 0);
            $this->totalOutputTokens += (int) ($data['usage']['output_tokens'] ?? 0);

            if ($stopReason === 'end_turn' || $stopReason === 'max_tokens') {
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        return trim($block['text']);
                    }
                }

                return $this->fallbackMessage();
            }

            if ($stopReason === 'tool_use') {
                $messages[]  = ['role' => 'assistant', 'content' => $content];
                $toolResults = [];

                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'tool_use') {
                        $result        = $this->executeTool($block['name'], (array) ($block['input'] ?? []));
                        $toolResults[] = [
                            'type'        => 'tool_result',
                            'tool_use_id' => $block['id'],
                            'content'     => is_string($result)
                                ? $result
                                : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                        ];
                    }
                }

                $messages[] = ['role' => 'user', 'content' => $toolResults];
                continue;
            }

            break;
        }

        return $this->fallbackMessage();
    }

    // ──────────────────────────────────────────────────────────
    // Tool execution
    // ──────────────────────────────────────────────────────────

    private function executeTool(string $name, array $input): mixed
    {
        return match ($name) {
            'getAvailableBrands'        => $this->toolGetAvailableBrands($input['query'] ?? '', $input['category'] ?? null),
            'searchProducts'            => $this->toolSearchProducts($input['query'] ?? '', $input['category'] ?? null, $input['brand'] ?? null),
            'checkQuantityAvailability' => $this->toolCheckQuantity($input['product_query'] ?? '', (int) ($input['quantity'] ?? 1)),
            'getOrderStatus'            => $this->toolGetOrderStatus($input['email'] ?? '', $input['order_number'] ?? null),
            'getCompanyInfo'            => $this->toolGetCompanyInfo($input['topic'] ?? 'general'),
            'collectContactInfo'        => $this->toolCollectContactInfo(
                                              $input['email']            ?? null,
                                              $input['phone']            ?? null,
                                              (bool) ($input['wants_specialist'] ?? false),
                                          ),
            'showContactForm'           => $this->toolShowContactForm($input['message'] ?? ''),
            default                     => 'Tool necunoscut: ' . $name,
        };
    }

    /**
     * Returnează brandurile disponibile pentru un tip de produs.
     */
    private function toolGetAvailableBrands(string $query, ?string $category): array
    {
        if (strlen(trim($query)) < 2) {
            return ['brands' => [], 'message' => 'Query prea scurt.'];
        }

        $q = WooProduct::query()
            ->where('status', 'publish')
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                    ->orWhere('short_description', 'LIKE', "%{$query}%");
            });

        if (filled($category)) {
            $q->whereHas('categories', fn ($c) => $c->where('name', 'LIKE', "%{$category}%"));
        }

        $brands = $q->selectRaw('brand, COUNT(*) as cnt')
            ->groupBy('brand')
            ->orderByDesc('cnt')
            ->limit(5)
            ->pluck('cnt', 'brand');

        if ($brands->isEmpty()) {
            return ['brands' => [], 'no_brand_info' => true];
        }

        return [
            'brands' => $brands->map(fn ($cnt, $name) => [
                'name'  => $name,
                'count' => $cnt,
            ])->values()->toArray(),
        ];
    }

    /**
     * Caută produse în catalog. Populează $foundProducts pentru widget.
     */
    private function toolSearchProducts(string $query, ?string $category, ?string $brand = null): array
    {
        if (strlen(trim($query)) < 2) {
            return ['error' => 'Query prea scurt'];
        }

        $q = WooProduct::query()
            ->where('status', 'publish')
            ->where(function ($outer) use ($query) {
                $terms = array_values(array_unique(array_filter(
                    preg_split('/\s+/', trim($query)),
                    fn ($t) => mb_strlen($t) >= 2
                )));
                if (count($terms) > 1) {
                    // Încearcă mai întâi potrivire exactă (fraza completa), apoi AND pe termeni
                    $outer->where('name', 'LIKE', "%{$query}%")
                        ->orWhere('sku', 'LIKE', "%{$query}%")
                        ->orWhere(function ($inner) use ($terms) {
                            foreach ($terms as $term) {
                                $inner->where('name', 'LIKE', "%{$term}%");
                            }
                        });
                } else {
                    $outer->where('name', 'LIKE', "%{$query}%")
                        ->orWhere('sku', 'LIKE', "%{$query}%")
                        ->orWhere('short_description', 'LIKE', "%{$query}%");
                }
            });

        if (filled($category)) {
            $q->whereHas('categories', fn ($c) => $c->where('name', 'LIKE', "%{$category}%"));
        }

        if (filled($brand)) {
            $q->where('brand', 'LIKE', "%{$brand}%");
        }

        $products = $q->with([
            'categories' => fn ($c) => $c->limit(1),
            'attributes' => fn ($a) => $a->where('is_visible', true)->orderBy('position')->limit(6),
        ])->limit(3)->get([
            'id', 'woo_id', 'name', 'price', 'stock_status', 'unit', 'sku',
            'slug', 'main_image_url', 'data', 'brand', 'short_description',
        ]);

        if ($products->isEmpty()) {
            return [
                'found'   => false,
                'message' => "Nu am găsit produse pentru \"{$query}\". Încearcă un termen mai scurt sau un sinonim.",
            ];
        }

        foreach ($products as $p) {
            $this->addFoundProduct($p);
        }

        return [
            'found'    => true,
            'count'    => $products->count(),
            'products' => $products->map(fn ($p) => [
                'name'            => $this->decodeName($p->name),
                'sku'             => $p->sku ?: null,
                'brand'           => $p->brand ?: null,
                'price'           => $p->price ? number_format((float) $p->price, 2, '.', '') . ' RON' : null,
                'stock_available' => $p->stock_status === 'instock',
                'unit'            => $p->unit ?: 'buc',
                'category'        => $p->categories->first()?->name,
                'description'     => $this->stripDesc($p->short_description),
                'attributes'      => $p->attributes->isNotEmpty()
                    ? $p->attributes->map(fn ($a) => $a->name . ': ' . $a->value)->implode('; ')
                    : null,
            ])->values()->toArray(),
        ];
    }

    /**
     * Verifică intern disponibilitatea unei cantități. Nu dezvăluie stocul exact.
     */
    private function toolCheckQuantity(string $productQuery, int $quantity): array
    {
        if (strlen(trim($productQuery)) < 2) {
            return ['error' => 'Denumire produs prea scurtă'];
        }

        $product = WooProduct::where('status', 'publish')
            ->where(function ($outer) use ($productQuery) {
                $terms = array_values(array_unique(array_filter(
                    preg_split('/\s+/', trim($productQuery)),
                    fn ($t) => mb_strlen($t) >= 2
                )));
                if (count($terms) > 1) {
                    $outer->where('name', 'LIKE', "%{$productQuery}%")
                        ->orWhere(function ($inner) use ($terms) {
                            foreach ($terms as $term) {
                                $inner->where('name', 'LIKE', "%{$term}%");
                            }
                        });
                } else {
                    $outer->where('name', 'LIKE', "%{$productQuery}%");
                }
            })
            ->with(['categories' => fn ($c) => $c->limit(1)])
            ->first(['id', 'woo_id', 'name', 'unit', 'stock_status', 'slug', 'main_image_url', 'price', 'data']);

        if (! $product) {
            return [
                'found'   => false,
                'message' => "Produsul \"{$productQuery}\" nu a fost găsit. Încearcă o altă denumire.",
            ];
        }

        $this->addFoundProduct($product);

        $storeStock = (float) ProductStock::where('woo_product_id', $product->id)
            ->whereHas('location', fn ($q) => $q->where('type', Location::TYPE_STORE))
            ->sum('quantity');

        $warehouseStock = (float) ProductStock::where('woo_product_id', $product->id)
            ->whereHas('location', fn ($q) => $q->where('type', Location::TYPE_WAREHOUSE))
            ->sum('quantity');

        return [
            'found'              => true,
            'product_name'       => $this->decodeName($product->name),
            'unit'               => $product->unit ?: 'buc',
            'category'           => $product->categories->first()?->name,
            'available_at_store' => $storeStock >= $quantity,
            'available_total'    => ($storeStock + $warehouseStock) >= $quantity,
            'preorder_possible'  => true,
        ];
    }

    /**
     * Verifică statusul comenzii după email.
     */
    private function toolGetOrderStatus(string $email, ?string $orderNumber): array
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Adresă de email invalidă.'];
        }

        $q = WooOrder::whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(billing, '$.email')) = ?",
            [mb_strtolower(trim($email))]
        );

        if (filled($orderNumber)) {
            $num = preg_replace('/\D/', '', $orderNumber);
            $q->where('number', $num);
        }

        $orders = $q->orderByDesc('order_date')->limit(5)->get();

        if ($orders->isEmpty()) {
            return ['found' => false, 'message' => 'Nu am găsit comenzi pentru această adresă de email.'];
        }

        return [
            'found'  => true,
            'orders' => $orders->map(fn ($o) => [
                'number'      => '#' . $o->number,
                'status'      => WooOrder::STATUS_LABELS[$o->status] ?? $o->status,
                'date'        => $o->order_date?->format('d.m.Y'),
                'items_count' => $o->items()->count(),
            ])->values()->toArray(),
        ];
    }

    /**
     * Informații despre companie (date reale de pe malinco.ro).
     */
    private function toolGetCompanyInfo(string $topic): string
    {
        $topicLower = mb_strtolower($topic);

        $info = [
            'program' => "Program magazin Malinco: Luni-Vineri 08:00-17:00, Sambata 08:00-14:00, Duminica inchis.",

            'adresa' => "Showroom: Santandrei Nr. 311, judetul Bihor. Depozit: Santandrei Nr. 180A, judetul Bihor.",

            'contact' => "Telefon: 0359 444 999. Site: malinco.ro. Showroom: Santandrei Nr. 311, Bihor.",

            'livrare' => "Livrare Malinco: (1) Transport propriu in judetul Bihor - furgon 3,5t (17,85 lei fix sau 5,35 lei/km) sau camion cu macara (11,30 lei/km, min 150 lei + 38,08 lei/palet descarcat). Livrare gratuita posibila in functie de valoarea comenzii. (2) Curier SameDay - livrare rapida in toata tara. (3) Pallex - cost comunicat inainte. (4) Ridicare personala din Santandrei (showroom sau depozit). Preturile includ TVA.",

            'retur' => "Retur Malinco: termen 14 zile calendaristice de la primire. Produsul trebuie sa fie in ambalajul original, nefolosit, cu documentul fiscal. Nu se accepta retur pentru produse desigilate/utilizate, comandate special, vopsite la aparat sau taiate la metru. Modalitati: aduceti la sediu, prin curierat sau ridicare agent. Rambursare in max 14 zile (numerar sau transfer).",

            'garantie' => "Garantie conform legislatiei romane: 2 ani pentru defecte de fabricatie. Pentru reclamatii contactati-ne.",

            'plata' => "Plata acceptata: numerar, card bancar, transfer bancar. Plata cash sau card si la livrare. Factura fiscala disponibila la cerere.",

            'precomanda' => "Produsele indisponibile la magazin pot fi aduse din depozitul central. Termen estimat 1-5 zile lucratoare. Contactati-ne pentru confirmare disponibilitate.",

            'companie' => "MALINCO (SC Malinco Prodex SRL) - infiintata in 1997, specializata in materiale de constructii, finisaje, restaurari si renovari. Peste 7.500 de produse. Santandrei, judetul Bihor.",
        ];

        $mapping = [
            'program'    => ['program', 'orar', 'ore', 'deschis', 'inchis', 'luni', 'sambata', 'duminica'],
            'adresa'     => ['adresa', 'adresă', 'unde', 'locatie', 'showroom', 'depozit', 'santandrei'],
            'contact'    => ['telefon', 'contact', 'suna', 'email', 'mesaj'],
            'livrare'    => ['livra', 'transport', 'curier', 'adus', 'expedit', 'trimit'],
            'retur'      => ['retur', 'inapoi', 'returnare', 'schimb'],
            'garantie'   => ['garant', 'defect', 'reclamat', 'strica'],
            'plata'      => ['plata', 'card', 'numerar', 'factura', 'transfer', 'bon'],
            'precomanda' => ['precomand', 'comand', 'lipsa', 'indisponibil'],
            'companie'   => ['companie', 'firma', 'despre', 'malinco', 'infiintata'],
        ];

        foreach ($mapping as $key => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($topicLower, $keyword)) {
                    return $info[$key];
                }
            }
        }

        return $info['contact'] . ' ' . $info['program'];
    }

    /**
     * Salvează datele de contact furnizate de client.
     */
    private function toolCollectContactInfo(?string $email, ?string $phone, bool $wantsSpecialist): array
    {
        // Validare minimă
        if (filled($email) && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['saved' => false, 'error' => 'Adresa de email nu pare validă. Roagă clientul să o verifice.'];
        }

        if (blank($email) && blank($phone) && ! $wantsSpecialist) {
            return ['saved' => false, 'error' => 'Nicio informație de contact furnizată.'];
        }

        ChatContact::collect(
            sessionId:       $this->currentSessionId,
            email:           filled($email) ? trim($email) : null,
            phone:           filled($phone) ? trim($phone) : null,
            wantsSpecialist: $wantsSpecialist,
        );

        return [
            'saved'            => true,
            'email_saved'      => filled($email),
            'phone_saved'      => filled($phone),
            'wants_specialist' => $wantsSpecialist,
        ];
    }

    /**
     * Afișează formularul grafic de contact în widget.
     * Claude apelează acest tool în loc să ceară contactul în text.
     */
    private function toolShowContactForm(string $message): array
    {
        $this->showContactForm    = true;
        $this->contactFormMessage = filled($message)
            ? $message
            : 'Lăsați datele dvs. și vă vom contacta cât mai curând posibil.';

        return ['status' => 'form_shown'];
    }

    // ──────────────────────────────────────────────────────────
    // Produse pentru widget (imagini + link-uri)
    // ──────────────────────────────────────────────────────────

    private function addFoundProduct(WooProduct $product): void
    {
        // Evităm duplicate
        foreach ($this->foundProducts as $existing) {
            if ($existing['id'] === $product->id) {
                return;
            }
        }

        // URL produs: preferăm permalink-ul din data WooCommerce, fallback slug
        $permalink = data_get($product->data, 'permalink');
        if (blank($permalink) && filled($product->slug)) {
            $permalink = self::siteUrl() . '/produs/' . $product->slug;
        }
        if (blank($permalink)) {
            $permalink = self::siteUrl() . '/?p=' . $product->woo_id;
        }

        $this->foundProducts[] = [
            'id'              => $product->id,
            'woo_id'          => $product->woo_id,
            'name'            => $this->decodeName($product->name),
            'price'           => $product->price ? number_format((float) $product->price, 2, '.', '') . ' RON' : null,
            'image_url'       => filled($product->main_image_url) ? $product->main_image_url : null,
            'url'             => $permalink,
            'stock_available' => $product->stock_status === 'instock',
            'unit'            => $product->unit ?: 'buc',
        ];
    }

    private function decodeName(string $name): string
    {
        return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function stripDesc(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }
        $text = trim(strip_tags($html));

        return mb_strlen($text) > 150 ? mb_substr($text, 0, 150) . '...' : ($text ?: null);
    }

    // ──────────────────────────────────────────────────────────
    // Tool definitions
    // ──────────────────────────────────────────────────────────

    private function getToolDefinitions(): array
    {
        return [
            [
                'name'         => 'getAvailableBrands',
                'description'  => 'Returneaza brandurile disponibile in catalog pentru un tip de produs. Apeleaza INTAI acest tool cand clientul cere un produs, inainte de searchProducts, pentru a afla ce branduri exista si a intreba preferinta clientului.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query'    => ['type' => 'string', 'description' => 'Tipul de produs cautat (ex: "vopsea", "parchet", "caramida")'],
                        'category' => ['type' => 'string', 'description' => 'Categorie optional pentru filtrare mai precisa'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name'         => 'searchProducts',
                'description'  => 'Cauta produse in catalogul Malinco. Foloseste DUPA ce clientul si-a exprimat preferinta de brand. Produsele gasite apar automat ca imagini cu link in widget — spune doar scurt ce ai gasit.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query'    => ['type' => 'string', 'description' => 'Termen de cautare (ex: "caramida", "parchet laminat", "vata minerala")'],
                        'category' => ['type' => 'string', 'description' => 'Filtru optional dupa categorie'],
                        'brand'    => ['type' => 'string', 'description' => 'Filtru optional dupa brand (ex: "Knauf", "Baumit") — foloseste dupa ce clientul si-a exprimat preferinta'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name'         => 'checkQuantityAvailability',
                'description'  => 'Verifica intern daca o cantitate ceruta e disponibila. NU dezvalui stocul exact — raspunde doar "avem disponibil", "stoc limitat" sau "precomanda din depozit". Produsul gasit e afisat automat in widget.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_query' => ['type' => 'string', 'description' => 'Denumirea produsului'],
                        'quantity'      => ['type' => 'number', 'description' => 'Cantitatea solicitata'],
                    ],
                    'required' => ['product_query', 'quantity'],
                ],
            ],
            [
                'name'         => 'getOrderStatus',
                'description'  => 'Verifica statusul unei comenzi plasate pe malinco.ro. Necesita emailul clientului.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'email'        => ['type' => 'string', 'description' => 'Email folosit la comanda'],
                        'order_number' => ['type' => 'string', 'description' => 'Numar comanda (optional)'],
                    ],
                    'required' => ['email'],
                ],
            ],
            [
                'name'         => 'getCompanyInfo',
                'description'  => 'Returneaza informatii despre Malinco: program, adresa, livrare, retur, garantie, plata, precomanda, contact.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'topic' => ['type' => 'string', 'description' => '"program", "adresa", "livrare", "retur", "garantie", "plata", "contact", "precomanda", "companie"'],
                    ],
                    'required' => ['topic'],
                ],
            ],
            [
                'name'         => 'collectContactInfo',
                'description'  => 'Salvează datele de contact furnizate de client în conversație (când clientul scrie direct emailul sau telefonul în chat). Apelează imediat când clientul oferă email sau telefon în text.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'email'            => ['type' => 'string', 'description' => 'Adresa de email furnizată de client (dacă a dat-o)'],
                        'phone'            => ['type' => 'string', 'description' => 'Numărul de telefon furnizat de client (dacă l-a dat)'],
                        'wants_specialist' => ['type' => 'boolean', 'description' => 'True dacă clientul a confirmat explicit că dorește să fie contactat de un specialist'],
                    ],
                ],
            ],
            [
                'name'         => 'showContactForm',
                'description'  => 'Afișează un formular grafic în chat pentru colectarea datelor de contact (email, telefon, dorință specialist). Apelează ACEST tool — NU cere contactul în text — când vrei să inviți clientul să lase datele. Tool-ul va afișa automat un form frumos în interfața de chat.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'message' => ['type' => 'string', 'description' => 'Mesaj scurt afișat deasupra formularului (max 100 caractere). Ex: "Vă pot pune în legătură cu un specialist Malinco!"'],
                    ],
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────
    // System prompt
    // ──────────────────────────────────────────────────────────

    private function getSystemPrompt(array $history = []): string
    {
        $base = <<<'PROMPT'
Ești Alex, asistentul virtual Malinco — magazin materiale de construcții, Sântandrei, Bihor (7.500+ produse, din 1997). Ton: prietenos, expert, concis. Răspunsuri max 2-3 propoziții. FORMAT STRICT: niciodată asteriscuri, bold, italic, liste cu -, bullet points sau emoji — doar text simplu continuu.

STOC: Nu dezvălui niciodată cantități exacte. Folosești: "avem în stoc" / "stoc limitat" / "nu avem momentan". La cantitate cerută, folosește checkQuantityAvailability și răspunde: "avem la magazin" / "aducem din depozit în 1-5 zile" / "sună-ne pentru cantități mari".

TELEFON 0359 444 999: Doar reclamații, proiecte mari, garanție/retur. Program: L-V 08-17, Sâm 08-14.

FLOW PRODUSE (ordine strictă):
1. Cerere vagă ("vreau vopsea", "ce glet aveți") → întreabă clarificări (tip, suprafață, interior/exterior) înainte de orice tool call
2. Cerere clară → getAvailableBrands → listează brandurile, întreabă preferința
3. Client spune brandul → confirmă afișare: "Vă arăt câteva produse [brand]?"
4. Client confirmă → searchProducts → 1 rând intro scurt ("Am găsit:"), produsele apar automat
5. După afișare produse → pune o întrebare scurtă de follow-up: "Doriți să verific disponibilitatea?" sau "Aveți nevoie de o anumită cantitate?"
NU apela searchProducts fără confirmare explicită. Max 2 tool calls per răspuns.

PREȚURI: searchProducts returnează prețuri. Nu spune niciodată că nu ai acces la prețuri.

SPECIFICAȚII TEHNICE: Sfaturi generale despre materiale — ok din cunoștințele tale. Specificații exacte ale unui produs (dimensiuni, compoziție, norme tehnice) — DOAR din tool results, nu inventa niciodată.

CONTACT (obligatoriu prin formular grafic):
- După al 2-lea mesaj al clientului, apelează showContactForm o singură dată cu un mesaj scurt și prietenos
- NU afișa dacă: clientul a refuzat, formularul a fost deja afișat, conversația e doar despre program/adresă fără interes de achiziție
- Când clientul scrie email/telefon direct în chat → apelează collectContactInfo imediat
- La refuz: "Nicio problemă! Suntem aici dacă aveți întrebări." și nu mai afișa
PROMPT;

        if (filled($this->conversationSummary)) {
            $base .= "\n\nCONTEXT CONVERSAȚIE ANTERIOARĂ: {$this->conversationSummary}";
        }

        // State conversație — ajută Claude să evite întrebări redundante
        $state = $this->conversationState;
        if (! empty($state)) {
            $stateLines = [];
            if (filled($state['product_type'] ?? '')) {
                $stateLines[] = "Clientul caută: {$state['product_type']}";
            }
            if (filled($state['brand'] ?? '')) {
                $stateLines[] = "Brand preferat: {$state['brand']}";
            }
            if (! empty($state['products_shown'])) {
                $stateLines[] = 'Produse deja afișate: Da — nu mai afișa aceleași, oferă variante noi sau follow-up';
            }
            if (! empty($state['contact_form_shown'])) {
                $stateLines[] = 'Formular contact: afișat deja — NU mai apela showContactForm';
            }
            if (! empty($stateLines)) {
                $base .= "\n\nSTARE CONVERSAȚIE:\n" . implode("\n", $stateLines);
            }
        }

        // Context navigare — ultimele pagini vizitate de client
        if (! empty($this->pageHistory)) {
            $navLines = [];
            foreach (array_slice($this->pageHistory, 0, 5) as $page) {
                $pageTitle = trim($page['title'] ?? '');
                $pageUrl   = trim($page['url']   ?? '');
                if (blank($pageTitle) && blank($pageUrl)) {
                    continue;
                }
                // Detectează tipul paginii din URL
                $type = '';
                if (preg_match('#/(p|produs)/#', $pageUrl))          { $type = 'produs'; }
                elseif (preg_match('#/categorie/#', $pageUrl))        { $type = 'categorie'; }
                elseif (preg_match('#/cautare|search#i', $pageUrl))   { $type = 'căutare'; }

                $label = $pageTitle ?: $pageUrl;
                $navLines[] = $type ? "{$label} ({$type})" : $label;
            }
            if (! empty($navLines)) {
                $base .= "\n\nCONTEXT NAVIGARE CLIENT:\nClientul a vizitat recent: " . implode(', ', $navLines) . ". Folosește acest context doar dacă întrebarea clientului e ambiguă.";
            }
        }

        $userMsgCount = count(array_filter($history, fn ($m) => $m['role'] === 'user'));

        if ($userMsgCount >= 2 && ! $this->contactAlreadyHandled($history)) {
            $base .= "\n\n⚠️ Ai {$userMsgCount} mesaje cu clientul și NU ai apelat showContactForm. Apelează-l ACUM cu un mesaj scurt (nu în text — apelează tool-ul).";
        }

        if ($this->pageContext) {
            $url   = $this->pageContext['url']   ?? '';
            $title = $this->pageContext['title'] ?? '';

            if (preg_match('#/(p|produs)/[^/?]+#', $url)) {
                $productName = trim(preg_replace('/\s*[–—\-]+\s*(Malinco|Magazin|Shop).*$/ui', '', $title));
                if (blank($productName)) {
                    $productName = $title;
                }
                $base .= "\n\nPAGINĂ PRODUS: Clientul vizualizează \"{$productName}\". Dacă întreabă despre preț, stoc, disponibilitate sau vrea să cumpere — caută cu searchProducts(\"{$productName}\"). La întrebări generale (montaj, sfaturi), răspunde din cunoștințele tale fără să cauți.";
            } else {
                $base .= "\n\nPAGINĂ CURENTĂ: \"{$title}\". Nu e pagină de produs — referințele vagi la \"acest produs\" nu au context, roagă clientul să specifice.";
            }
        }

        return $base;
    }

    /**
     * Verifică dacă formularul de contact a fost deja afișat sau clientul a refuzat.
     */
    private function contactAlreadyHandled(array $history): bool
    {
        // Formularul a fost deja afișat în această sesiune (din state)
        if (! empty($this->conversationState['contact_form_shown'])) {
            return true;
        }

        // Contactul a fost salvat în DB
        if (filled($this->currentSessionId)
            && \App\Models\ChatContact::where('session_id', $this->currentSessionId)->exists()) {
            return true;
        }

        // Clientul a refuzat (Claude a răspuns cu fraza de acceptare a refuzului)
        foreach ($history as $m) {
            if ($m['role'] === 'assistant' && is_string($m['content'])) {
                $lower = mb_strtolower($m['content']);
                if (str_contains($lower, 'nicio problem')) {
                    return true;
                }
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────────────────
    // Context optimization — reducere tokeni
    // ──────────────────────────────────────────────────────────

    /**
     * Construiește array-ul de mesaje trimis la Claude.
     *
     * Dacă istoricul e scurt (≤ MAX_CONTEXT_MESSAGES), îl trimite întreg.
     * Dacă e lung, generează un rezumat al mesajelor vechi și trimite
     * doar ultimele MAX_CONTEXT_MESSAGES mesaje reale.
     * Mesajele triviale (ok, da, bine etc.) sunt filtrate din context.
     */
    private function buildContextMessages(array $history): array
    {
        // Garanție: niciodată array gol (API-ul Claude nu acceptă messages:[])
        if (empty($history)) {
            return [['role' => 'user', 'content' => '...']];
        }

        $filtered = $this->filterTrivialMessages($history);
        $filtered = $this->truncateHistory($filtered); // previne context overflow (>6000 chars)

        // filterTrivialMessages() păstrează întotdeauna ultimul mesaj,
        // deci $filtered nu poate fi gol dacă $history nu era gol.
        if (count($filtered) <= self::MAX_CONTEXT_MESSAGES) {
            return $filtered;
        }

        // Split: mesaje vechi (de rezumat) + mesaje recente (de trimis)
        $older  = array_slice($filtered, 0, -self::MAX_CONTEXT_MESSAGES);
        $recent = array_slice($filtered, -self::MAX_CONTEXT_MESSAGES);

        // API-ul Claude cere ca primul mesaj să fie 'user'.
        // Dacă slice-ul începe cu un mesaj 'assistant', îl mutăm în older.
        while (! empty($recent) && $recent[0]['role'] !== 'user') {
            $older[] = array_shift($recent);
        }

        $this->conversationSummary = $this->summarizeConversation($older);

        // Fallback de siguranță: dacă cumva recent e gol, returnează ultimul mesaj user
        if (empty($recent)) {
            foreach (array_reverse($filtered) as $m) {
                if ($m['role'] === 'user') {
                    return [$m];
                }
            }

            return [['role' => 'user', 'content' => '...']];
        }

        return $recent;
    }


    /**
     * Limitează istoricul la maxim 6000 caractere totale (≈1500 tokeni).
     * Parcurge în ordine inversă și păstrează mesajele recente.
     * Ultimul mesaj (cel curent al utilizatorului) este întotdeauna păstrat.
     */
    private function truncateHistory(array $messages): array
    {
        $maxChars   = 6000; // ~1500 tokens safety limit
        $totalChars = 0;
        $result     = [];

        // Parcurge în ordine inversă (păstrează mesajele recente)
        foreach (array_reverse($messages) as $msg) {
            $chars = strlen($msg['content'] ?? '');
            if ($totalChars + $chars > $maxChars && count($result) > 0) {
                break; // oprește când depășim limita
            }
            $result[]    = $msg;
            $totalChars += $chars;
        }

        return array_reverse($result);
    }

    /**
     * Elimină din array mesajele user fără valoare de context:
     * răspunsuri monosilabice ("ok", "da", "bine", "mhm" etc.).
     */
    private function filterTrivialMessages(array $history): array
    {
        $trivialWords = [
            'ok', 'oki', 'okay', 'da', 'nu', 'bine', 'bun', 'super', 'perfect',
            'mhm', 'hmm', 'aha', 'yep', 'yes', 'no', 'mersi', 'ms',
            'multumesc', 'mulțumesc', 'mulțumesc!', 'mersi!',
        ];

        // Ultimul mesaj (mesajul curent al utilizatorului) nu se filtrează niciodată
        $lastIndex = count($history) - 1;

        return array_values(array_filter($history, function (array $m, int $idx) use ($trivialWords, $lastIndex): bool {
            // Ultimul mesaj — întotdeauna păstrat
            if ($idx === $lastIndex) {
                return true;
            }

            // Mesajele asistentului nu se filtrează niciodată
            if ($m['role'] !== 'user') {
                return true;
            }

            $normalized = $this->normalizeText($m['content'] ?? '');

            // Dacă e suficient de lung, îl păstrăm
            if (mb_strlen($normalized) > 20) {
                return true;
            }

            return ! in_array($normalized, $trivialWords, true);
        }, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Normalizează text pentru comparare: lowercase, elimină punctuație, collapse whitespace.
     * Folosit atât în isTrivialMessage cât și în filterTrivialMessages.
     */
    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        // Elimină orice caracter care nu e literă, cifră sau spațiu (punctuație, emoji etc.)
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        // Collapse whitespace multiplu
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return $text;
    }

    /**
     * Verifică dacă un mesaj e trivial (scurt și fără conținut informațional).
     * Folosit pentru a intercepta mesaje banale înainte de apelul Claude.
     */
    private function isTrivialMessage(string $message): bool
    {
        $normalized = $this->normalizeText($message);

        if (mb_strlen($normalized) > 25) {
            return false;
        }

        $trivialWords = [
            'ok', 'oki', 'okay', 'da', 'nu', 'bine', 'bun', 'super', 'perfect',
            'mhm', 'hmm', 'aha', 'yep', 'yes', 'no', 'mersi', 'ms',
            'multumesc', 'mulțumesc', 'gata', 'am inteles', 'am înțeles',
            'inteleg', 'înțeleg', 'ok mersi', 'ok multumesc', 'ok mulțumesc',
            'super mersi', 'super multumesc', 'perfect mersi',
        ];

        return in_array($normalized, $trivialWords, true);
    }

    /**
     * Creează un rezumat scurt al conversației (fără apel API suplimentar).
     *
     * Extrage: ce a cerut clientul, branduri menționate, dacă s-au afișat produse.
     */
    private function summarizeConversation(array $history): string
    {
        $userIntents    = [];
        $productsShown  = false;
        $brandsMentioned = [];

        // Branduri comune în catalogul Malinco (pentru detecție rapidă)
        $knownBrands = [
            'vitex', 'kober', 'baumit', 'knauf', 'tikkurila', 'dulux', 'caparol',
            'weber', 'mapei', 'cemix', 'vipol', 'vox', 'egger', 'kronospan',
            'quick-mix', 'tytan', 'penosil', 'sika', 'henkel',
        ];

        foreach ($history as $m) {
            $content = is_string($m['content']) ? trim($m['content']) : '';
            if (blank($content)) {
                continue;
            }

            if ($m['role'] === 'user' && mb_strlen($content) > 8) {
                $userIntents[] = mb_substr($content, 0, 100);

                // Detectează branduri în mesajele clientului
                $lower = mb_strtolower($content);
                foreach ($knownBrands as $brand) {
                    if (str_contains($lower, $brand) && ! in_array($brand, $brandsMentioned)) {
                        $brandsMentioned[] = ucfirst($brand);
                    }
                }
            }

            if ($m['role'] === 'assistant') {
                $lower = mb_strtolower($content);
                // Detectează dacă produse au fost deja prezentate
                if (str_contains($content, 'RON') ||
                    str_contains($lower, 'am găsit') ||
                    str_contains($lower, 'iată ce') ||
                    str_contains($lower, 'am găsit acestea')) {
                    $productsShown = true;
                }
                // Detectează branduri menționate de asistent
                foreach ($knownBrands as $brand) {
                    if (str_contains($lower, $brand) && ! in_array(ucfirst($brand), $brandsMentioned)) {
                        $brandsMentioned[] = ucfirst($brand);
                    }
                }
            }
        }

        $parts = [];

        if (! empty($userIntents)) {
            $parts[] = 'Clientul a cerut: ' . implode('; ', array_slice($userIntents, 0, 2));
        }

        if (! empty($brandsMentioned)) {
            $parts[] = 'branduri discutate: ' . implode(', ', $brandsMentioned);
        }

        if ($productsShown) {
            $parts[] = 'produse deja afișate în chat';
        }

        return ! empty($parts) ? implode('. ', $parts) . '.' : '';
    }


    // ──────────────────────────────────────────────────────────
    // Conversation State
    // ──────────────────────────────────────────────────────────

    /**
     * Structura implicită a stării conversației.
     */
    private function defaultState(): array
    {
        return [
            'intent'               => null,  // product_search | order_inquiry | info_request
            'product_type'         => null,  // ex: "vopsea", "glet", "parchet"
            'brand'                => null,  // ex: "Vitex", "Knauf"
            'products_shown'       => false,
            'contact_form_shown'   => false,
        ];
    }

    private function loadState(string $sessionId): array
    {
        try {
            $cached = Cache::get(self::statePrefix() . $sessionId);

            return is_array($cached) ? array_merge($this->defaultState(), $cached) : $this->defaultState();
        } catch (\Throwable) {
            return $this->defaultState();
        }
    }

    private function saveState(string $sessionId, array $state): void
    {
        try {
            Cache::put(self::statePrefix() . $sessionId, $state, self::cacheTtl());
        } catch (\Throwable $e) {
            Log::warning('ChatService: nu am putut salva state-ul', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Actualizează starea pe baza mesajului curent și a răspunsului generat.
     * Fără apel API — analiză locală prin keyword matching.
     */
    private function updateConversationState(string $userMessage, string $reply): void
    {
        $lower = mb_strtolower($userMessage);

        // ── Intent detection ──────────────────────────────────────
        if (blank($this->conversationState['intent'])) {
            if (preg_match('/vreau|caut|aveți|aveti|am nevoie|arătați|aratati|ce .+ aveți/u', $lower)) {
                $this->conversationState['intent'] = 'product_search';
            } elseif (preg_match('/comand|livrare|tracking|colet|expedit/u', $lower)) {
                $this->conversationState['intent'] = 'order_inquiry';
            } elseif (preg_match('/program|adres|telefon|orar|unde|contact/u', $lower)) {
                $this->conversationState['intent'] = 'info_request';
            }
        }

        // ── Product type detection ────────────────────────────────
        if (blank($this->conversationState['product_type'])) {
            $productKeywords = [
                'vopsea' => ['vopsea', 'vopsele', 'lac', 'lacuri', 'email'],
                'glet'   => ['glet', 'gleturi', 'gletuiala'],
                'parchet' => ['parchet', 'laminat', 'pvc', 'podea', 'duşumea'],
                'rigips'  => ['rigips', 'gips-carton', 'carton-gips', 'placa'],
                'caramida' => ['caramida', 'cărămidă', 'cărămizi', 'blocuri ceramice'],
                'izolatie' => ['vata', 'polistiren', 'izolatie', 'izolaţie', 'termoizolatie'],
                'adeziv'  => ['adeziv', 'lipici', 'mortar', 'chit', 'silicon'],
                'profile'  => ['profil', 'profile', 'ghidaj', 'montant', 'cornier'],
                'tencuiala' => ['tencuiala', 'tencuieli', 'finisaj', 'grunduri', 'grund'],
                'tigla'    => ['tigla', 'acoperis', 'acoperiș', 'invelitoare'],
                'faianta'  => ['faianta', 'faianță', 'gresie', 'ceramica', 'placi'],
                'sanitare' => ['cada', 'lavoar', 'vas', 'toaleta', 'robinet', 'baterie', 'dus'],
            ];

            foreach ($productKeywords as $type => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($lower, $keyword)) {
                        $this->conversationState['product_type'] = $type;
                        break 2;
                    }
                }
            }
        }

        // ── Brand detection ───────────────────────────────────────
        if (blank($this->conversationState['brand'])) {
            $knownBrands = [
                'vitex', 'kober', 'baumit', 'knauf', 'tikkurila', 'dulux', 'caparol',
                'weber', 'mapei', 'cemix', 'vipol', 'vox', 'egger', 'kronospan',
                'quick-mix', 'tytan', 'penosil', 'sika', 'henkel', 'oskar', 'hardy',
                'technogip', 'laticrete', 'jub', 'trilak', 'helios',
            ];
            foreach ($knownBrands as $brand) {
                if (str_contains($lower, $brand)) {
                    $this->conversationState['brand'] = ucfirst($brand);
                    break;
                }
            }
        }

        // ── Products shown ────────────────────────────────────────
        if (! $this->conversationState['products_shown'] && ! empty($this->foundProducts)) {
            $this->conversationState['products_shown'] = true;
        }

        // ── Contact form shown ────────────────────────────────────
        if ($this->showContactForm) {
            $this->conversationState['contact_form_shown'] = true;
        }
    }

    // ──────────────────────────────────────────────────────────
    // Cost tracking per sesiune
    // ──────────────────────────────────────────────────────────

    private function getSessionCost(string $sessionId): float
    {
        try {
            return (float) (Cache::get(self::COST_PREFIX . $sessionId, 0.0));
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function addSessionCost(string $sessionId, int $inputTokens, int $outputTokens): void
    {
        if ($inputTokens === 0 && $outputTokens === 0) {
            return;
        }

        $requestCost = ($inputTokens  / 1_000_000) * self::PRICE_INPUT_PER_M
                     + ($outputTokens / 1_000_000) * self::PRICE_OUTPUT_PER_M;

        try {
            $current = $this->getSessionCost($sessionId);
            Cache::put(self::COST_PREFIX . $sessionId, $current + $requestCost, self::cacheTtl());
        } catch (\Throwable $e) {
            Log::warning('ChatService: nu am putut actualiza costul sesiunii', ['error' => $e->getMessage()]);
        }
    }

    private function isSessionCostExceeded(string $sessionId): bool
    {
        $maxCost = (float) AppSetting::get(AppSetting::KEY_CHAT_MAX_COST_PER_SESSION, '0.05');

        // 0 sau negativ = fără limită
        if ($maxCost <= 0) {
            return false;
        }

        return $this->getSessionCost($sessionId) >= $maxCost;
    }

    /**
     * Răspuns fallback când limita de cost per sesiune a fost depășită.
     */
    private function costLimitReply(): array
    {
        return [
            'reply'                => "Pentru mai multe informații vă rugăm să ne contactați direct la telefon:
0359 444 999

Un coleg din magazin vă poate ajuta imediat. Program: L-V 08:00-17:00, Sâm 08:00-14:00.",
            'products'             => [],
            'input_tokens'         => 0,
            'output_tokens'        => 0,
            'show_contact_form'    => false,
            'contact_form_message' => '',
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Cache
    // ──────────────────────────────────────────────────────────

    private function loadHistory(string $sessionId): array
    {
        try {
            $cached = Cache::get(self::cachePrefix() . $sessionId);

            return is_array($cached) ? $cached : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function saveHistory(string $sessionId, array $history): void
    {
        try {
            Cache::put(self::cachePrefix() . $sessionId, $history, self::cacheTtl());
        } catch (\Throwable $e) {
            Log::warning('ChatService: nu am putut salva istoricul', ['error' => $e->getMessage()]);
        }
    }

    private function fallbackMessage(): string
    {
        return "Se pare că situația aceasta mă depășește momentan.\nTe rog să îi contactezi pe colegii mei din magazin la 0359 444 999 și te vor ajuta imediat.";
    }
}
