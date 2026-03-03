<?php

namespace App\Services;

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
    private const MAX_HISTORY_MESSAGES = 20;
    private const CACHE_TTL            = 900;  // 15 min
    private const CACHE_PREFIX         = 'chat:';
    private const MAX_TOOL_ITERATIONS  = 5;
    private const SITE_URL             = 'https://malinco.ro';

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
    public function chat(string $sessionId, string $userMessage, ?array $pageContext = null): array
    {
        $this->foundProducts      = []; // reset per request
        $this->totalInputTokens   = 0;
        $this->totalOutputTokens  = 0;
        $this->currentSessionId   = $sessionId;
        $this->pageContext         = $pageContext;
        $this->showContactForm    = false;
        $this->contactFormMessage = '';

        if (blank($this->apiKey)) {
            Log::warning('ChatService: API key Anthropic lipsă');

            return ['reply' => 'Serviciul de chat nu este disponibil momentan.', 'products' => []];
        }

        $history   = $this->loadHistory($sessionId);
        $history[] = ['role' => 'user', 'content' => $userMessage];

        $reply = $this->callClaude($history);

        $history[] = ['role' => 'assistant', 'content' => $reply];

        if (count($history) > self::MAX_HISTORY_MESSAGES) {
            $history = array_slice($history, count($history) - self::MAX_HISTORY_MESSAGES);
        }

        $this->saveHistory($sessionId, $history);

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
        $messages = $history;
        $tools    = $this->getToolDefinitions();

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            try {
                $response = Http::withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                    'model'      => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 768,
                    'system'     => $this->getSystemPrompt($history),
                    'tools'      => $tools,
                    'messages'   => $messages,
                ]);
            } catch (\Throwable $e) {
                Log::error('ChatService: timeout/rețea', ['error' => $e->getMessage()]);

                return $this->fallbackMessage();
            }

            if (! $response->successful()) {
                Log::warning('ChatService: API error ' . $response->status(), ['body' => $response->body()]);

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
            $permalink = self::SITE_URL . '/produs/' . $product->slug;
        }
        if (blank($permalink)) {
            $permalink = self::SITE_URL . '/?p=' . $product->woo_id;
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
Ești Alex, asistentul virtual al Malinco — magazin de materiale de construcții, finisaje și renovări din Sântandrei, județul Bihor, înființat în 1997. Catalog: peste 7.500 de produse.

PERSONALITATE: Ești prietenos, cald și entuziast — ca un coleg expert care chiar vrea să ajute. Folosești un limbaj natural, relaxat, dar profesional. Ești direct și concis, nu birocratul. Când nu găsești ceva, nu te scuzi excesiv — oferi imediat alternative.

CUNOȘTINȚE EXTINSE: Pe lângă catalogul Malinco, folosește-ți cunoștințele generale despre materiale de construcții pentru a oferi sfaturi utile. Dacă un client întreabă de un produs și ai informații relevante (proprietăți, utilizare, comparații, sfaturi de montaj), oferă-le pe scurt chiar dacă nu sunt în tool results. Scopul e să fii un consultant real, nu doar un motor de căutare.

MISIUNE: Ajuți clienții să găsească produse, să înțeleagă ce li se potrivește și să afle informații despre magazin.

REGULI (respectă-le întotdeauna):

1. Comunică EXCLUSIV în română. Tonul e prietenos și natural.

2. STOC: Nu dezvălui niciodată cantități exacte. Răspunsuri permise: "avem în stoc", "stoc limitat", "nu avem momentan la magazin".

3. CANTITATE CERUTĂ: Dacă clientul întreabă dacă ai X bucăți/mp/kg, folosește checkQuantityAvailability și răspunde:
   - available_at_store=true → "Da, avem la magazin, poți veni sau comanda oricând."
   - available_at_store=false + available_total=true → "La magazin nu avem toată cantitatea, dar o aducem din depozit în 1-5 zile — fără probleme."
   - available_total=false → "Momentan e mai greu cu această cantitate, dar scrie-ne sau sună ca să găsim o soluție."

4. FLOW RECOMANDARE PRODUSE — urmează exact această ordine:

   Pasul 1 — Branduri disponibile: Când clientul cere un tip de produs, apelează ÎNTÂI getAvailableBrands.
   - 2+ branduri găsite → listează-le scurt, întreabă preferința. Ex: "Avem Vitex, Kober și Baumit. Preferați vreunul?"
   - 1 brand găsit → menționează-l, întreabă dacă e ok. Ex: "Avem în principal VOX. Vă interesează?"
   - 0 branduri / no_brand_info=true → sari direct la Pasul 3.

   Pasul 2 — Confirmare afișare: DUPĂ ce clientul și-a spus preferința de brand, întreabă scurt:
   "Vreți să vă arăt câteva produse [brand]?" sau "Vă arăt câteva opțiuni?"
   NU apela searchProducts până clientul nu confirmă că vrea să vadă produse.

   Pasul 3 — Afișare produse: Când clientul confirmă (da / arată / vreu), apelează searchProducts.
   - Scrie DOAR 1 rând scurt înaintea produselor: "Iată ce am găsit:" sau "Am găsit acestea:"
   - Produsele apar automat ca imagini în chat — nu le descrie în text.
   - "Nu contează brandul" → caută fără filtru brand.

5. PRODUS NEGĂSIT: Încearcă cu un termen mai scurt. Dacă tot nu găsești, spune că poate fi în showroom.

6. TELEFON (0359 444 999): Doar pentru reclamații, proiecte mari, garanție/retur. NU pentru întrebări simple. Program: L-V 08-17, Sâm 08-14.

7. ALTERNATIVE: Produs indisponibil (stock_available=false) → caută similar cu searchProducts, prezintă scurt.

8. SFATURI TEHNICE: La întrebări "cât îmi trebuie", "ce e mai bun pentru X", "cum se montează" — răspunde din cunoștințele tale generale, scurt.

9. CONTEXT PRODUS: Folosește atributele și descrierile din tool results pentru răspunsuri precise.

10. FORMATARE — IMPORTANT:
    - Răspunsuri SCURTE: maxim 2-3 propoziții per mesaj, exceptând cazurile când oferi sfaturi tehnice detaliate.
    - Text simplu, fără markdown, fără emoji.
    - Când urmează să afișezi produse, textul de intro e 1 rând maxim.

11. PREȚURI: Prețurile produselor sunt disponibile în catalogul nostru și le poți vedea prin searchProducts. Când cineva întreabă de prețul unui produs, caută-l cu searchProducts și arată prețul din rezultate. NICIODATĂ nu spune că "nu pot vedea prețurile" sau că "nu am acces la prețuri" — ai acces, folosește tool-ul.

12. Nu discuta despre prețuri de achiziție de la furnizori sau date interne.

13. COLECTARE DATE CONTACT — OBLIGATORIU prin formular grafic:

La al 2-lea mesaj al clientului sau după ce i-ai recomandat produse, apelează showContactForm cu un mesaj scurt și prietenos.
NU cere contactul în text — apelează TOOL-UL showContactForm care afișează un formular grafic elegant în chat.
NU sări dacă ai ajutat cu produse sau clientul are un proiect.
NU repeta dacă ai afișat deja formularul sau dacă clientul a refuzat.
NU afișa formularul dacă conversația e doar despre program/adresă fără interes de achiziție.

Exemple de message pentru showContactForm (scurt, 1 propoziție, prietenos):
- "Vă pot pune în legătură cu un specialist Malinco pentru o ofertă personalizată!"
- "Dacă aveți un proiect, un coleg vă poate oferi consultanță gratuită."
- "Lăsați un contact și revenim cu detalii complete!"

CÂND clientul scrie email/telefon DIRECT ÎN CHAT (fără să fi completat formularul):
- Apelează collectContactInfo imediat, confirmă că ai notat.

CÂND clientul REFUZĂ formularul: acceptă scurt ("Nicio problemă! Suntem aici dacă aveți întrebări."), nu mai afișa formularul.
PROMPT;

        // Numără mesajele clientului din conversație (include mesajul curent)
        $userMsgCount = count(array_filter($history, fn ($m) => $m['role'] === 'user'));

        // Verifică dacă s-a colectat deja contactul (cel mai sigur indicator: există în DB)
        $contactCollected = filled($this->currentSessionId)
            && \App\Models\ChatContact::where('session_id', $this->currentSessionId)->exists();

        // Verifică dacă s-a propus deja contactul (caută fraza specifică în răspunsuri)
        $alreadyAsked = $contactCollected;
        if (! $alreadyAsked) {
            foreach ($history as $m) {
                if ($m['role'] === 'assistant' && is_string($m['content'])) {
                    $lower = mb_strtolower($m['content']);
                    // Caută fraze specifice de invitare la contact (nu orice mențiune a cuvântului)
                    if (str_contains($lower, 'lăsați un email') ||
                        str_contains($lower, 'lăsați un număr') ||
                        str_contains($lower, 'lăsați-mi un') ||
                        str_contains($lower, 'puteți lăsa un contact') ||
                        str_contains($lower, 'date de contact') ||
                        str_contains($lower, 'vă pot pune în legătură cu un specialist') ||
                        str_contains($lower, 'vreți să lăsați')) {
                        $alreadyAsked = true;
                        break;
                    }
                }
            }
        }

        // Injectează reminder urgent dacă sunt 2+ mesaje și nu s-a întrebat încă
        if ($userMsgCount >= 2 && ! $alreadyAsked) {
            $base .= "\n\n⚠️ REMINDER OBLIGATORIU: Ai schimbat deja {$userMsgCount} mesaje cu clientul și NU ai afișat formularul de contact. Apelează ACUM tool-ul showContactForm cu un mesaj scurt și prietenos (regula 13). NU scrie în text — apelează tool-ul.";
        }

        // Injectează context pagina curentă dacă e disponibil
        if ($this->pageContext) {
            $url   = $this->pageContext['url']   ?? '';
            $title = $this->pageContext['title'] ?? '';

            $base .= "\n\nCONTEXT PAGINA CURENTĂ: Clientul se află pe pagina \"{$title}\" ({$url}).";

            // Detectează pagina de produs — URL-urile Malinco sunt /p/slug/ sau /produs/slug/
            if (preg_match('#/(p|produs)/[^/?]+#', $url)) {
                // Elimină sufixul temei din titlu (ex: "Vata Minerala Knauf – Malinco" → "Vata Minerala Knauf")
                $productName = trim(preg_replace('/\s*[–—\-]+\s*(Malinco|Magazin|Shop).*$/ui', '', $title));
                if (blank($productName)) {
                    $productName = $title;
                }
                $base .= "\n⚡ PAGINA DE PRODUS CURENTĂ: \"{$productName}\".";
                $base .= "\n- Indiferent de ce s-a discutat anterior în conversație, ACUM clientul se află pe pagina acestui produs.";
                $base .= "\n- Dacă clientul întreabă despre \"acest produs\", \"cel de aici\", \"cât costă\", \"e în stoc\" etc. — se referă STRICT la \"{$productName}\", nu la produse din mesajele anterioare.";
                $base .= "\n- Caută-l IMEDIAT cu searchProducts(\"{$productName}\") fără să întrebi despre care produs e vorba.";
            } else {
                // Nu e pagina de produs — anulează orice asociere implicită cu un produs
                $base .= "\n- Clientul NU se află pe o pagină de produs specific. Referințele vagi (\"acest produs\") nu au context — roagă clientul să specifice.";
            }
        }

        return $base;
    }

    // ──────────────────────────────────────────────────────────
    // Cache
    // ──────────────────────────────────────────────────────────

    private function loadHistory(string $sessionId): array
    {
        try {
            $cached = Cache::get(self::CACHE_PREFIX . $sessionId);

            return is_array($cached) ? $cached : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function saveHistory(string $sessionId, array $history): void
    {
        try {
            Cache::put(self::CACHE_PREFIX . $sessionId, $history, self::CACHE_TTL);
        } catch (\Throwable $e) {
            Log::warning('ChatService: nu am putut salva istoricul', ['error' => $e->getMessage()]);
        }
    }

    private function fallbackMessage(): string
    {
        return 'Am întâmpinat o problemă tehnică. Vă rugăm să reîncercați.';
    }
}
