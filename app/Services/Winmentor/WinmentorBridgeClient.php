<?php

namespace App\Services\Winmentor;

use App\Models\AppSetting;
use App\Models\Supplier;
use App\Models\WinmentorApiLog;
use App\Models\WooProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WinmentorBridgeClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $firma;
    private int $an;
    private int $luna;
    private ?string $logContext = null;

    public function __construct()
    {
        $conn = \App\Models\IntegrationConnection::find(5);

        $this->baseUrl = rtrim($conn?->bridgeUrl() ?? '', '/');
        $this->apiKey  = $conn?->bridgeApiKey() ?? '';
        $this->firma   = $conn?->bridgeFirma() ?? '';
        $this->an      = $conn?->bridgeAn() ?? now()->year;
        $this->luna    = $conn?->bridgeLuna() ?? now()->month;
    }

    // ─── Health ────────────────────────────────────────────────────────────────

    public function health(): array
    {
        return $this->get('/api/health', auth: false);
    }

    public function isReachable(): bool
    {
        try {
            $result = $this->health();
            return ($result['success'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── Firma/Luna ─────────────────────────────────────────────────────────────

    /**
     * Selectează firma și luna. Cached 30 min — Bridge-ul reține starea, dar
     * o re-trimitem la fiecare sesiune nouă / după reconnect COM.
     */
    public function selectFirma(): void
    {
        $cacheKey = "winmentor_firma_selected_{$this->firma}_{$this->an}_{$this->luna}";

        if (Cache::has($cacheKey)) {
            return;
        }

        $this->post('/api/firme/select', [
            'firma' => $this->firma,
            'an'    => $this->an,
            'luna'  => $this->luna,
        ]);

        Cache::put($cacheKey, true, now()->addMinutes(30));
    }

    // ─── Articole / Produse ──────────────────────────────────────────────────────

    /**
     * Caută un articol în WinMentor după SKU (CodExtern).
     * Returnează array-ul articolului sau null dacă nu există.
     */
    public function searchArticolBySku(string $sku): ?array
    {
        $this->selectFirma();

        $result = $this->get('/api/articole', ['search' => $sku]);

        if (! ($result['success'] ?? false)) {
            return null;
        }

        $items = $result['data']['items'] ?? $result['data'] ?? [];

        foreach ($items as $item) {
            $codExtern = $item['codExtern'] ?? $item['codIntern'] ?? '';
            if (strtolower(trim($codExtern)) === strtolower(trim($sku))) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Creează un produs nou în WinMentor din datele produsului ERP.
     * Folosit când un produs nu există în WinMentor și trebuie creat înainte de push.
     * Returnează ['success' => bool, 'error' => string|null].
     *
     * Prerequisite Bridge: selectFirma() + SetIDPartField(CodIntern) — AddProduct
     * folosește MEREU marca CodIntern a producătorului (poz 3 din info string).
     *
     * Format info string AddProduct: 19 câmpuri separate prin ";"
     * Poz 0=CodExtern, 1=Denumire, 2=gol, 3=IDProducator, 4=UM, 5=TipSerie,
     * 6=CotaTVA, 7=gol, 8=gol, 9=CodExternAlt, 10-12=gol, 13=Masa, 14-17=gol, 18=Flag
     */
    public function createArticol(WooProduct $product): array
    {
        if (! $this->writesEnabled()) {
            $this->bridgeLog('warning', "WRITE BLOCAT (WINMENTOR_BRIDGE_WRITES=false): createArticol [{$product->sku}]");
            return ['success' => false, 'error' => 'Scrierile în WinMentor sunt dezactivate (WINMENTOR_BRIDGE_WRITES=false).'];
        }

        $this->selectFirma();
        $this->setIdPartField('CodIntern');

        $fields = [
            $product->sku,                                          // 0  CodExtern
            $product->winmentor_name ?? $product->name,             // 1  Denumire
            '',                                                      // 2  (necunoscut)
            '0',                                                     // 3  IDProducator (0 = fără producător)
            $product->unit ?? 'BUC',                                // 4  UM
            '0',                                                     // 5  TipSerie (0 = fără serie/lot)
            (string) $this->resolveVatCode($product->vat_rate),     // 6  CotaTVA (doar 0,5,9,19,20 valide)
            '',                                                      // 7  (necunoscut)
            '',                                                      // 8  (necunoscut)
            $product->sku,                                          // 9  CodExternAlt
            '',                                                      // 10 (necunoscut)
            '',                                                      // 11 (necunoscut)
            '',                                                      // 12 (necunoscut)
            $product->weight ? (string) $product->weight : '',      // 13 Masa (kg)
            '',                                                      // 14 (necunoscut)
            '',                                                      // 15 (necunoscut)
            '',                                                      // 16 (necunoscut)
            '',                                                      // 17 (necunoscut)
            '',                                                      // 18 Flag
        ];

        try {
            $result = $this->post('/api/produse/add', ['info' => implode(';', $fields)]);

            if ($result['success'] ?? false) {
                Log::channel('winmentor_bridge')->info('[WinMentor] Produs creat', ['sku' => $product->sku, 'name' => $product->name]);
                return ['success' => true, 'error' => null];
            }

            $error = implode(', ', $result['errors'] ?? ['Eroare necunoscută']);
            return ['success' => false, 'error' => $error];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ─── Intrări / Istoric achiziții ─────────────────────────────────────────────

    /**
     * Selectează firma + luna specifică, forțând (fără cache).
     * Folosit la parcurgerea lunilor pentru istoricul achizițiilor.
     */
    public function selectFirmaForMonth(int $an, int $luna, ?string $firma = null): void
    {
        $this->post('/api/firme/select', [
            'firma' => $firma ?? $this->firma,
            'an'    => $an,
            'luna'  => $luna,
        ]);
    }

    /**
     * Returnează toate intrările de marfă din luna de lucru curentă.
     * Format rând: [partID, data, nrDoc, artID(SKU), cant, denUM, pret, denGest, codInternArt, ...]
     */
    public function getArticolePaginated(int $page = 1, int $pageSize = 500): array
    {
        $result = $this->get('/api/articole', ['page' => $page, 'pageSize' => $pageSize]);
        return $result['data'] ?? [];
    }

    /**
     * Returnează toate articolele din WinMentor filtrate după gestiune și clasă,
     * folosind endpoint-ul /api/stocuri (care filtrează corect după simbolClasa și simbolGestiune).
     * Rezultatul este indexat după codExtern (SKU), fără duplicate.
     *
     * @return array<string, array> — [codExtern => article]
     */
    public function getAllArticole(string $gestiune, string $clasa, int $pageSize = 5000): array
    {
        $all  = [];
        $page = 1;

        do {
            $result = $this->get('/api/stocuri', [
                'page'     => $page,
                'pageSize' => $pageSize,
            ]);
            $data       = $result['data'] ?? [];
            $items      = $data['items'] ?? [];
            $hasNext    = $data['hasNextPage'] ?? false;
            $totalPages = (int) ($data['totalPages'] ?? 1);

            foreach ($items as $item) {
                // Filtrăm strict după clasă și gestiune (la fel ca SyncStockFromBridgeCommand)
                if (($item['simbolClasa']    ?? '') !== $clasa)    continue;
                if (($item['simbolGestiune'] ?? '') !== $gestiune) continue;

                $sku = $item['codExtern'] ?? null;
                if ($sku && ! isset($all[$sku])) {
                    $all[$sku] = $item;
                }
            }

            $page++;
        } while ($hasNext && $page <= $totalPages);

        return $all;
    }

    public function getIntrari(): array
    {
        $result = $this->get('/api/intrari');
        return $result['data'] ?? [];
    }

    /**
     * Returnează toate recepțiile din luna de lucru curentă.
     * Față de getIntrari(), are câmpuri suplimentare: nr_receptie, den_furnizor,
     * den_articol, pret_vanzare (cu TVA), id_comanda_wm.
     *
     * Format rând (22 câmpuri):
     * [0]=den_gestiune, [1]=simbol_gestiune, [2]=nr_receptie, [3]=data_receptie,
     * [4]=den_furnizor, [5]=part_id, [6]=nr_factura, [7]=data_factura,
     * [8]=sku, [9]=den_articol, [10]=valoare_totala, [11]=uom,
     * [12]=cant_comandata, [13]=cant_receptionata, [14]=pret_intrare,
     * [15]=pret_vanzare, [16]=tva, [17]=id_comanda_wm, [18-21]=diverse
     */
    public function getReceptii(): array
    {
        $result = $this->get('/api/receptii');
        return $result['data'] ?? [];
    }

    /**
     * Returnează vânzările din luna de lucru curentă (/api/vanzari/ext).
     * NOTĂ: Bridge-ul mapează câmpurile cu etichete greșite față de doc v1.2.
     * Mapare reală (dedusă empiric):
     * prefixDoc=nr_factura, nrDoc=sku, artID=cantitate, cant=uom,
     * denUM=pret, pret=den_gestiune, adresa=cod_fiscal_client,
     * codFiscal=adresa_client, valAchizitie=valoare_totala
     */
    public function getVanzari(): array
    {
        $result = $this->get('/api/vanzari/ext');
        return $result['data'] ?? [];
    }

    /**
     * Caută un partener în WinMentor după ID-ul intern (idPartener / codExtern).
     * Returnează array-ul partenerului sau null dacă nu există.
     */
    /**
     * Returnează toți partenerii din WinMentor, indexați după idPartener.
     * Fetch paginated, toate paginile.
     */
    public function getAllParteneri(): array
    {
        $index = [];
        $page  = 1;

        do {
            $result = $this->get('/api/parteneri', ['pageSize' => 500, 'page' => $page]);
            if (! ($result['success'] ?? false)) break;

            $items      = $result['data']['items'] ?? [];
            $totalPages = (int) ($result['data']['totalPages'] ?? 1);

            foreach ($items as $item) {
                $id = $item['idPartener'] ?? null;
                if ($id) $index[$id] = $item;
            }

            $page++;
        } while ($page <= $totalPages);

        return $index;
    }

    public function searchPartenerById(string $partId): ?array
    {
        $result = $this->get('/api/parteneri', ['search' => $partId]);

        if (! ($result['success'] ?? false)) {
            return null;
        }

        $items = $result['data']['items'] ?? $result['data'] ?? [];

        foreach ($items as $item) {
            if (($item['idPartener'] ?? '') === $partId) {
                return $item;
            }
        }

        return null;
    }

    // ─── Parteneri ──────────────────────────────────────────────────────────────

    /**
     * Caută un partener în WinMentor după CUI/CIF (vat_number).
     * Returnează array-ul partenerului sau null dacă nu există.
     */
    public function searchPartenerByCui(string $cui): ?array
    {
        $this->selectFirma();

        $result = $this->get('/api/parteneri', ['search' => $cui]);

        if (! ($result['success'] ?? false)) {
            return null;
        }

        $items = $result['data']['items'] ?? $result['data'] ?? [];

        $cuiNormalized = preg_replace('/[^0-9]/', '', $cui);

        foreach ($items as $item) {
            $codFiscal = preg_replace('/[^0-9]/', '', $item['codFiscal'] ?? '');
            if ($codFiscal === $cuiNormalized) {
                return $item;
            }
        }

        return null;
    }

    // ─── Import documente ────────────────────────────────────────────────────────

    /**
     * Importă un document în WinMentor.
     * $docType: 'comenzi-furnizori', 'facturi-intrare', etc.
     * Returnează răspunsul Bridge: ['isValid', 'importedCount', 'errors', 'warnings'].
     */
    public function importDocument(string $docType, array $lines, bool $validateOnly = false): array
    {
        if (! $validateOnly && ! $this->writesEnabled()) {
            $this->bridgeLog('warning', "WRITE BLOCAT (WINMENTOR_BRIDGE_WRITES=false): importDocument [{$docType}]");
            return ['isValid' => false, 'importedCount' => 0, 'errors' => ['Scrierile în WinMentor sunt dezactivate (WINMENTOR_BRIDGE_WRITES=false).']];
        }

        $this->selectFirma();

        $url    = "/api/import/{$docType}";
        $params = $validateOnly ? ['validateOnly' => 'true'] : [];

        $result = $this->post($url, ['lines' => $lines], $params);

        return $result['data'] ?? ['isValid' => false, 'importedCount' => 0, 'errors' => ['Răspuns invalid de la Bridge']];
    }

    // ─── Verificare completă înainte de push ─────────────────────────────────────

    /**
     * Verifică dacă toate produsele dintr-un PO există în WinMentor.
     * Creează automat produsele lipsă.
     * Returnează ['ok' => bool, 'created' => string[], 'errors' => string[]].
     */
    public function ensureArticoleExist(array $items): array
    {
        $created = [];
        $errors  = [];
        $umMap   = []; // sku => denUM din WinMentor

        foreach ($items as $item) {
            $sku = $item['sku'] ?? null;
            if (! $sku) {
                $errors[] = "Produsul \"{$item['product_name']}\" nu are SKU — nu poate fi verificat.";
                continue;
            }

            $found = $this->searchArticolBySku($sku);

            if ($found) {
                $umMap[$sku] = $found['denUM'] ?? null;
                continue;
            }

            // Nu există — îl creăm
            $product = isset($item['woo_product_id'])
                ? WooProduct::find($item['woo_product_id'])
                : null;

            if (! $product) {
                $errors[] = "Produsul cu SKU \"{$sku}\" nu există în WinMentor și nu poate fi creat (produsul ERP nu e găsit).";
                continue;
            }

            $createResult = $this->createArticol($product);

            if ($createResult['success']) {
                $created[] = "{$product->name} [{$sku}]";
                // Re-căutăm să luăm UM-ul din WinMentor după creare
                $newArticol = $this->searchArticolBySku($sku);
                $umMap[$sku] = $newArticol['denUM'] ?? $product->unit ?? 'Buc';
            } else {
                $errors[] = "Eroare la crearea \"{$product->name}\" [{$sku}]: {$createResult['error']}";
            }
        }

        return [
            'ok'      => empty($errors),
            'created' => $created,
            'errors'  => $errors,
            'umMap'   => $umMap, // sku => UM din WinMentor
        ];
    }

    /**
     * Verifică dacă furnizorul există în WinMentor după CUI.
     * Returnează ['ok' => bool, 'partener' => array|null, 'error' => string|null].
     */
    public function ensurePartenerExists(Supplier $supplier): array
    {
        $cui = $supplier->vat_number;

        if (! $cui) {
            return ['ok' => false, 'partener' => null, 'error' => "Furnizorul \"{$supplier->name}\" nu are CUI/CIF completat în ERP."];
        }

        $partener = $this->searchPartenerByCui($cui);

        if (! $partener) {
            return [
                'ok'       => false,
                'partener' => null,
                'error'    => "Furnizorul \"{$supplier->name}\" (CUI: {$cui}) nu a fost găsit în WinMentor. Adăugați-l manual sau sincronizați partenerii.",
            ];
        }

        return ['ok' => true, 'partener' => $partener, 'error' => null];
    }

    // ─── Config helpers ──────────────────────────────────────────────────────────

    /**
     * Setează câmpul de identificare parteneri pentru operațiile cu producători.
     * AddProduct necesită 'CodIntern'; ModiProduct poate folosi 'CodExtern' / 'CodIntern' / 'CodFiscal'.
     */
    public function setIdPartField(string $fieldName): void
    {
        $this->post('/api/config/id-part-field', ['fieldName' => $fieldName]);
    }

    /**
     * Apelează AddProduct pe Bridge cu info string-ul primit.
     */
    public function addProduct(string $info): array
    {
        return $this->post('/api/produse/add', ['info' => $info]);
    }

    public function setLogContext(?string $context): static
    {
        $this->logContext = $context;
        return $this;
    }

    public function writesEnabled(): bool
    {
        return \App\Models\IntegrationConnection::find(5)?->bridgeWritesEnabled() ?? true;
    }

    /**
     * Convertește vat_rate (ex: 21.00) la codul TVA valid pentru WinMentor.
     * Cote valide: 0, 5, 9, 19, 21. Alte valori generează eroarea 435.
     * Default: 21 (cota standard curentă).
     */
    private function resolveVatCode(?float $vatRate): int
    {
        $valid = [0, 5, 9, 19, 21];
        $rate  = (int) round($vatRate ?? 21);

        return in_array($rate, $valid, true) ? $rate : 21;
    }

    // ─── HTTP helpers ────────────────────────────────────────────────────────────

    private function get(string $path, array $query = [], bool $auth = true): array
    {
        $request = Http::timeout(30)->withoutVerifying();

        if ($auth) {
            $request = $request->withHeaders(['X-API-Key' => $this->apiKey]);
        }

        $fullUrl = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $this->bridgeLog('info', 'GET ' . $fullUrl);

        $start = microtime(true);
        try {
            $response = $request->get($this->baseUrl . $path, $query);
            $ms       = (int) ((microtime(true) - $start) * 1000);

            $this->bridgeLog('info', 'Response ' . $response->status(), ['body' => substr($response->body(), 0, 500)]);

            WinmentorApiLog::record('GET', $path, $query, $response->json() ?? $response->body(), $response->status(), $ms, $response->successful(), $this->logContext);

            if (! $response->successful()) {
                throw new \RuntimeException("WinMentor Bridge HTTP {$response->status()}: {$response->body()}");
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $start) * 1000);
            $this->bridgeLog('error', 'GET failed: ' . $e->getMessage(), ['url' => $fullUrl]);
            WinmentorApiLog::record('GET', $path, $query, $e->getMessage(), 0, $ms, false, $this->logContext);
            throw $e;
        }
    }

    private function bridgeLog(string $level, string $message, array $context = []): void
    {
        Log::channel('winmentor_bridge')->{$level}('[WinMentor Bridge] ' . $message, $context);
    }


    private function put(string $path, array $body = [], array $query = []): array
    {
        $request = Http::timeout(60)
            ->withoutVerifying()
            ->withHeaders(['X-API-Key' => $this->apiKey]);

        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $this->bridgeLog('info', 'PUT ' . $url, ['body_keys' => array_keys($body)]);

        $start = microtime(true);
        try {
            $response = $request->put($url, $body);
            $ms       = (int) ((microtime(true) - $start) * 1000);

            $this->bridgeLog('info', 'Response ' . $response->status(), ['body' => substr($response->body(), 0, 500)]);

            WinmentorApiLog::record('PUT', $path, $body, $response->json() ?? $response->body(), $response->status(), $ms, $response->successful(), $this->logContext);

            if (! $response->successful()) {
                throw new \RuntimeException("WinMentor Bridge HTTP {$response->status()}: {$response->body()}");
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $start) * 1000);
            $this->bridgeLog('error', 'PUT failed: ' . $e->getMessage(), ['url' => $url]);
            WinmentorApiLog::record('PUT', $path, $body, $e->getMessage(), 0, $ms, false, $this->logContext);
            throw $e;
        }
    }

    private function post(string $path, array $body = [], array $query = []): array
    {
        $request = Http::timeout(60)
            ->withoutVerifying()
            ->withHeaders(['X-API-Key' => $this->apiKey]);

        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $this->bridgeLog('info', 'POST ' . $url, ['body_keys' => array_keys($body)]);

        $start = microtime(true);
        try {
            $response = $request->post($url, $body);
            $ms       = (int) ((microtime(true) - $start) * 1000);

            $this->bridgeLog('info', 'Response ' . $response->status(), ['body' => substr($response->body(), 0, 500)]);

            WinmentorApiLog::record('POST', $path, $body, $response->json() ?? $response->body(), $response->status(), $ms, $response->successful(), $this->logContext);

            if (! $response->successful()) {
                throw new \RuntimeException("WinMentor Bridge HTTP {$response->status()}: {$response->body()}");
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $start) * 1000);
            $this->bridgeLog('error', 'POST failed: ' . $e->getMessage(), ['url' => $url]);
            WinmentorApiLog::record('POST', $path, $body, $e->getMessage(), 0, $ms, false, $this->logContext);
            throw $e;
        }
    }
}
