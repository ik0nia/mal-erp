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
            return ($result['success'] ?? false) === true
                && ($result['data']['comConnected'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── Mentenanță (închidere de lună) ─────────────────────────────────────────

    /**
     * Deconectează COM-ul de la WinMentor și intră în mod mentenanță.
     * Folosit la închiderea de lună — Mentor cere toți utilizatorii deconectați.
     * Cât timp e activ, toate apelurile COM primesc HTTP 503.
     */
    public function comDisconnect(): array
    {
        return $this->post('/api/com/disconnect');
    }

    /**
     * Iese din mod mentenanță și reconectează COM-ul.
     * Conexiunea nouă pierde firma selectată, deci o re-selectăm imediat.
     * Prima conectare după disconnect e lentă (logon COM complet, ~60s),
     * de-aia timeout mare — a nu se apela sincron dintr-un request web.
     */
    public function comConnect(): array
    {
        $result = $this->post('/api/com/connect', timeout: 180);

        Cache::forget("winmentor_firma_selected_{$this->firma}_{$this->an}_{$this->luna}");
        $this->selectFirma();

        return $result;
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
    public function createArticol(WooProduct $product, ?string $supplierSku = null): array
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
            $supplierSku ?? $product->sku,                          // 9  CodExternAlt (cod furnizor sau EAN)
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

            // Articolul există deja în WinMentor (fetch paginat l-a ratat) — nu e eroare
            if (str_contains(strtolower($error), 'exist') || str_contains(strtolower($error), 'duplicat')) {
                Log::channel('winmentor_bridge')->info('[WinMentor] Produs deja existent (skip creare)', ['sku' => $product->sku]);
                return ['success' => true, 'error' => null];
            }

            return ['success' => false, 'error' => $error];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Actualizează un produs existent în WinMentor (ModiProduct).
     * Format: 7 câmpuri separate prin ";": CodExtern;Producator;Clasa;Pret;(gol);Denumire;(gol)
     * Prerequisite: SetIDPartField(CodExtern).
     * Câmpurile goale nu se modifică.
     */
    public function updateArticol(string $sku, string $newName): array
    {
        if (! $this->writesEnabled()) {
            return ['success' => false, 'error' => 'Scrierile în WinMentor sunt dezactivate.'];
        }

        $this->selectFirma();
        $this->setIdPartField('CodExtern');

        // ModiProduct: CodExtern;Producator;Clasa;Pret;(gol);Denumire;(gol)
        $info = "{$sku};;;;;{$newName};";

        try {
            $result = $this->put('/api/produse/update', ['info' => $info]);

            if ($result['success'] ?? false) {
                Log::channel('winmentor_bridge')->info('[WinMentor] Produs actualizat', ['sku' => $sku, 'name' => $newName]);
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
     *
     * MentorAPI returnează named fields; consumatorii (SyncWinmentorPurchaseHistoryCommand)
     * așteaptă array pozițional: [0]=partId, [1]=data, [3]=sku, [5]=uom, [6]=pret
     */
    public function getIntrari(): array
    {
        $result = $this->get('/api/intrari');
        $items  = $result['data'] ?? [];

        return array_map(fn (array $row) => [
            /* [0] partId */ $row['idPartener'] ?? '',
            /* [1] data   */ $row['data'] ?? '',
            /* [2] nrDoc  */ $row['nrDoc'] ?? '',
            /* [3] sku    */ $row['codArticol'] ?? '',
            /* [4] cant   */ $row['cant'] ?? '',
            /* [5] uom    */ $row['denUM'] ?? '',
            /* [6] pret   */ $row['pret'] ?? '',
            /* [7] denGest*/ $row['denGest'] ?? '',
            /* [8] camp8  */ $row['camp8'] ?? '',
            /* [9] flag   */ $row['flag'] ?? '',
        ], $items);
    }

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

    public function getComenziFurnizori(): array
    {
        $result = $this->get('/api/comenzi/furnizori');
        return $result['data'] ?? [];
    }

    /**
     * Returnează toate recepțiile din luna de lucru curentă.
     *
     * MentorAPI returnează named fields; consumatorii (FetchWinmentorIntrariCommand,
     * WatchWinmentorIntrariCommand) așteaptă array pozițional cu 22 câmpuri.
     * Convertim din named → positional aici ca să nu modificăm toate comenzile.
     */
    public function getReceptii(): array
    {
        $result = $this->get('/api/receptii');
        $items  = $result['data'] ?? [];

        return array_map(fn (array $row) => [
            /* [0]  den_gestiune      */ $row['denGestiune'] ?? '',
            /* [1]  simbol_gestiune   */ $row['simbolGestiune'] ?? '',
            /* [2]  nr_receptie (NIR) */ $row['nrNIR'] ?? '',
            /* [3]  data_receptie     */ $row['dataNIR'] ?? '',
            /* [4]  den_furnizor      */ $row['denFurnizor'] ?? '',
            /* [5]  part_id           */ $row['idFurnizor'] ?? '',
            /* [6]  nr_factura        */ $row['nrFactura'] ?? '',
            /* [7]  data_factura      */ $row['dataFactura'] ?? '',
            /* [8]  sku               */ $row['codArticol'] ?? '',
            /* [9]  den_articol       */ $row['denArticol'] ?? '',
            /* [10] cont              */ $row['cont'] ?? '',
            /* [11] uom               */ $row['denUM'] ?? '',
            /* [12] cant_comandata    */ $row['cantitate'] ?? '',
            /* [13] cant_receptionata */ $row['cantReceptionata'] ?? '',
            /* [14] pret_intrare      */ $row['pretAchizitie'] ?? '',
            /* [15] pret_vanzare      */ $row['pretVanzare'] ?? '',
            /* [16] tva               */ $row['cotaTVA'] ?? '',
            /* [17] id_comanda_wm     */ $row['nrDoc'] ?? '',
            /* [18] operat            */ $row['operat'] ?? '',
            /* [19] operatFact        */ $row['operatFact'] ?? '',
            /* [20] idIntern          */ $row['idIntern'] ?? '',
            /* [21] (padding)         */ '',
        ], $items);
    }

    /**
     * Returnează vânzările din luna de lucru curentă (/api/vanzari/ext).
     *
     * MentorAPI returnează câmpuri cu nume corecte. Consumatorii (FetchWinmentorVanzariCommand,
     * WatchWinmentorVanzariCommand) așteaptă câmpurile cu etichetele "greșite" (shifted)
     * ale bridge-ului vechi. Remapăm aici ca să nu modificăm comenzile.
     *
     * MentorAPI → Bridge vechi (cum așteaptă consumatorii):
     * nrFactura → prefixDoc, codArticol → nrDoc, cantitate → artID,
     * denUM → cant, pret → denUM, denGestiune → pret,
     * codFiscal → adresa, adresa → codFiscal, (lipsă) → valAchizitie
     */
    public function getVanzari(): array
    {
        $result = $this->get('/api/vanzari/ext');
        $items  = $result['data'] ?? [];

        return array_map(fn (array $row) => [
            'partID'       => $row['idPartener'] ?? '',
            'zi'           => $row['zi'] ?? '',
            'prefixDoc'    => $row['nrFactura'] ?? '',      // consumers read this as nr_factura
            'nrDoc'        => $row['codArticol'] ?? '',     // consumers read this as sku
            'artID'        => $row['cantitate'] ?? '',      // consumers read this as cantitate
            'cant'         => $row['denUM'] ?? '',          // consumers read this as uom
            'denUM'        => $row['pret'] ?? '',           // consumers read this as pret
            'pret'         => $row['denGestiune'] ?? '',    // consumers read this as den_gestiune
            'adresa'       => $row['codFiscal'] ?? '',      // consumers read this as cod_fiscal_client
            'codFiscal'    => $row['adresa'] ?? '',         // consumers read this as adresa_client
            'marcaAgent'   => $row['marcaAgent'] ?? '',
            'valAchizitie' => '0',
            'clasaArticol' => $row['clasaArticol'] ?? '',
            // Câmpuri MentorAPI (noi)
            'tipDocument'         => $row['tipDocument'] ?? '',
            'denArticol'          => $row['denArticol'] ?? '',
            'discount'            => $row['discount'] ?? '',
            'serieDocument'       => $row['serieDocument'] ?? '',
            'observatiiFactura'   => $row['observatiiFactura'] ?? '',
            'localitateClient'    => $row['localitateClient'] ?? '',
            'pozitieDocument'     => $row['pozitieDocument'] ?? '',
            'prefixCarnet'        => $row['prefixCarnet'] ?? '',
            'moneda'              => $row['moneda'] ?? '',
            'locatieClient'       => $row['locatieClient'] ?? '',
        ], $items);
    }

    /**
     * Returnează vânzările din luna de lucru curentă (/api/vanzari/luna).
     * Format mai bogat decât /ext: include denArticol, discount, tipDocument (AE/F),
     * dataScadenta, dataEmitere, observatiiFactura, etc.
     * NU include bonurile de casă (tipDocument S) — doar facturi + avize.
     */
    public function getVanzariLuna(): array
    {
        $result = $this->get('/api/vanzari/luna');
        return $result['data'] ?? [];
    }

    /**
     * Returnează bonurile de casă din emularea casei de marcat (/api/vanzari/emulare).
     * Date bogate: idBon, pozitie, denArticol, numeClient, nrComanda (nr. casă),
     * valoare, pret, cantitate, gestiune.
     * Nr. bon zilnic = rang idBon în ziua respectivă.
     */
    public function getVanzariEmulare(): array
    {
        $result = $this->get('/api/vanzari/emulare', timeout: 60);
        return $result['data'] ?? [];
    }

    /**
     * Istoric vânzări agent (READ-ONLY). GET /api/vanzari/istoric/all
     * GetIstoricVanzari(marca, anInceput, lunaInceput) + iterare GetListRecord.
     * marca=0 pare să însemne toți agenții; formatul recordurilor e brut (string-uri).
     */
    public function getIstoricVanzariAll(int $marca, int $an, int $luna, int $limit = 200): array
    {
        $result = $this->get('/api/vanzari/istoric/all', [
            'marca' => $marca, 'an' => $an, 'luna' => $luna, 'limit' => $limit,
        ], timeout: 120);

        return $result['data'] ?? [];
    }

    /**
     * Sold curent al unui partener (READ-ONLY). GET /api/solduri/partener/{id}
     * Nu modifică nimic în WinMentor.
     */
    public function getSoldPartener(string $id): array
    {
        $this->selectFirma();
        $this->setIdPartField('CodIntern'); // $id = wm_id (ID intern partener)
        $result = $this->get('/api/solduri/partener/' . rawurlencode($id), timeout: 60);
        return $result['data'] ?? [];
    }

    /**
     * Sold detaliat pe facturi/avansuri — facturi de încasat (READ-ONLY).
     * GET /api/solduri/partener/{id}/detaliat — $id = wm_id (ID intern partener).
     */
    public function getSoldDetaliat(string $id): array
    {
        $this->selectFirma();
        $this->setIdPartField('CodIntern');
        $result = $this->get('/api/solduri/partener/' . rawurlencode($id) . '/detaliat', timeout: 60);
        return $result['data'] ?? [];
    }

    /**
     * Încasări ale unui client pe interval (READ-ONLY). GET /api/incasari/ext
     * $id = wm_id (ID intern partener). Câmpuri: data, documentRef, suma, detaliiFacturi.
     */
    public function getIncasariClient(string $id, int $an1, int $luna1, int $an2, int $luna2): array
    {
        $this->selectFirma();
        $this->setIdPartField('CodIntern');
        $result = $this->get('/api/incasari/ext', [
            'partId' => $id,
            'an1'    => $an1,
            'luna1'  => $luna1,
            'an2'    => $an2,
            'luna2'  => $luna2,
        ], timeout: 60);
        return $result['data'] ?? [];
    }

    /**
     * Sediile de livrare alternative ale unui partener (READ-ONLY, 1 apel via search).
     * Returnează listă [['denumire','localitate','cod_postal'], ...].
     */
    public function getSediiLivrare(string $cui, ?string $wmId = null): array
    {
        $this->selectFirma();
        $result = $this->get('/api/parteneri', ['search' => $cui, 'pageSize' => 20, 'page' => 1], timeout: 30);
        $items = $result['data']['items'] ?? [];
        $item = null;
        if ($wmId !== null) {
            foreach ($items as $it) {
                if (($it['idPartener'] ?? '') === $wmId) {
                    $item = $it;
                    break;
                }
            }
        }
        $item ??= $items[0] ?? null;
        if (! $item) {
            return [];
        }

        $den = $item['denumiriSedii'] ?? [];
        $loc = array_values(array_filter(explode('~', (string) ($item['localitatiSedii'] ?? ''))));
        $cp  = array_values(array_filter(explode('~', (string) ($item['codPostalSedii'] ?? ''))));
        $sedii = [];
        $count = max(count($den), count($loc));
        for ($i = 0; $i < $count; $i++) {
            $sedii[] = [
                'denumire'   => $den[$i] ?? '',
                'localitate' => $loc[$i] ?? '',
                'cod_postal' => $cp[$i] ?? '',
            ];
        }
        return $sedii;
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
        $page = 1;
        do {
            $result = $this->get('/api/parteneri', ['pageSize' => 500, 'page' => $page]);
            if (! ($result['success'] ?? false)) {
                return null;
            }

            $items      = $result['data']['items'] ?? [];
            $totalPages = (int) ($result['data']['totalPages'] ?? 1);

            foreach ($items as $item) {
                if (($item['idPartener'] ?? '') === $partId) {
                    return $item;
                }
            }

            $page++;
        } while ($page <= $totalPages);

        return null;
    }

    // ─── Parteneri ──────────────────────────────────────────────────────────────

    /**
     * Caută un partener în WinMentor după CUI/CIF (vat_number).
     * Verifică codFiscal principal, coduriFiscaleSedii și puncteAcumulate.
     * Returnează array-ul partenerului sau null dacă nu există.
     */
    public function searchPartenerByCui(string $cui): ?array
    {
        $this->selectFirma();

        $cuiNormalized = preg_replace('/[^0-9]/', '', $cui);

        if (strlen($cuiNormalized) < 5) {
            return null; // CUI prea scurt, risc de false positive
        }

        $page = 1;
        do {
            $result = $this->get('/api/parteneri', ['pageSize' => 500, 'page' => $page]);
            if (! ($result['success'] ?? false)) {
                return null;
            }

            $items      = $result['data']['items'] ?? [];
            $totalPages = (int) ($result['data']['totalPages'] ?? 1);

            foreach ($items as $item) {
                if ($this->partenerMatchesCui($item, $cuiNormalized)) {
                    return $item;
                }
            }

            $page++;
        } while ($page <= $totalPages);

        return null;
    }

    /**
     * Caută TOȚI partenerii din WinMentor cu un CUI dat.
     * Necesar pentru CUI duble (mai multe firme cu același cod fiscal),
     * unde dezambiguarea se face apoi după nume.
     * Returnează array de parteneri (poate fi gol).
     */
    public function searchParteneriByCui(string $cui): array
    {
        $this->selectFirma();

        $cuiNormalized = preg_replace('/[^0-9]/', '', $cui);

        if (strlen($cuiNormalized) < 5) {
            return []; // CUI prea scurt, risc de false positive
        }

        $matches = [];
        $page = 1;
        do {
            $result = $this->get('/api/parteneri', ['pageSize' => 500, 'page' => $page]);
            if (! ($result['success'] ?? false)) {
                return $matches;
            }

            $items      = $result['data']['items'] ?? [];
            $totalPages = (int) ($result['data']['totalPages'] ?? 1);

            foreach ($items as $item) {
                if ($this->partenerMatchesCui($item, $cuiNormalized)) {
                    $matches[] = $item;
                }
            }

            $page++;
        } while ($page <= $totalPages);

        return $matches;
    }

    /**
     * Dintr-o listă de parteneri (de obicei cu același CUI), alege-l pe cel
     * al cărui nume se potrivește cel mai bine cu numele furnizorului ERP.
     * Returnează null dacă niciun candidat nu trece verificarea de nume.
     */
    private function pickBestPartenerByName(string $erpName, array $candidates): ?array
    {
        // Un singur candidat: îl validăm tot prin nume (CUI corect dar firmă greșită = risc)
        $best      = null;
        $bestScore = -1.0;

        foreach ($candidates as $candidate) {
            $name = $candidate['denumire'] ?? '';
            if (! $this->partenerNameMatches($erpName, $name)) {
                continue;
            }

            similar_text(mb_strtolower($erpName), mb_strtolower($name), $percent);
            // Partenerii dezactivați (prefix "X" în denumire = nu mai lucrăm cu ei) au
            // prioritate mai mică, dar rămân fallback dacă e singurul match.
            $score = ($this->isDeactivatedPartenerName($name) ? 0.0 : 1000.0) + $percent;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $candidate;
            }
        }

        return $best;
    }

    /**
     * Convenție WinMentor: un partener al cărui denumire începe cu unul/mai mulți "X"
     * urmați de o literă (ex: "XMETALURGICA INDUSTRIAL SRL") este o înregistrare
     * dezactivată — firmă cu care nu mai lucrăm. La CUI dublu o evităm.
     */
    private function isDeactivatedPartenerName(string $name): bool
    {
        return (bool) preg_match('/^\s*X+[A-Z]/', $name);
    }

    /**
     * Verifică dacă un partener WinMentor corespunde unui CUI normalizat.
     * Compară pe codFiscal principal, coduriFiscaleSedii și puncteAcumulate.
     */
    private function partenerMatchesCui(array $item, string $cuiNormalized): bool
    {
        // 1. codFiscal principal — doar dacă arată ca un CUI valid (5-13 cifre)
        $codFiscal = preg_replace('/[^0-9]/', '', $item['codFiscal'] ?? '');
        if ($codFiscal === $cuiNormalized && strlen($codFiscal) >= 5 && strlen($codFiscal) <= 13) {
            return true;
        }

        // 2. codFiscalSedii / coduriFiscaleSedii — CUI-urile sediilor (string "~" separated sau array)
        $sediiRaw = $item['codFiscalSedii'] ?? $item['coduriFiscaleSedii'] ?? [];
        $sediiList = is_array($sediiRaw) ? $sediiRaw : array_filter(explode('~', (string) $sediiRaw));
        foreach ($sediiList as $cuiSediu) {
            $norm = preg_replace('/[^0-9]/', '', (string) $cuiSediu);
            if ($norm === $cuiNormalized && strlen($norm) >= 5 && strlen($norm) <= 13) {
                return true;
            }
        }

        // 3. puncteAcumulate — uneori conține CUI-ul urmat de "~"
        $puncte = preg_replace('/[^0-9]/', '', $item['puncteAcumulate'] ?? '');
        if ($puncte === $cuiNormalized && strlen($puncte) >= 5 && strlen($puncte) <= 13) {
            return true;
        }

        return false;
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
        $this->setIdPartField('CodIntern');

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
     *
     * Optimizat: un singur fetch paginat al tuturor articolelor WinMentor → map local,
     * apoi creăm doar ce lipsește (fără re-search per SKU și fără re-search după creare).
     */
    public function ensureArticoleExist(array $items): array
    {
        $created = [];
        $updated = [];
        $errors  = [];
        $umMap   = [];

        $this->selectFirma();

        // Un singur apel bulk în loc de câte un GET per SKU
        $articoleMap = $this->fetchAllArticoleSkuMap();

        $toCreate = [];
        foreach ($items as $item) {
            $sku = $item['sku'] ?? null;
            if (! $sku) {
                $errors[] = "Produsul \"{$item['product_name']}\" nu are SKU — nu poate fi verificat.";
                continue;
            }

            $key = strtolower(trim($sku));

            if (isset($articoleMap[$key])) {
                $umMap[$sku] = $articoleMap[$key]['denUM'] ?? null;

                // Verifică dacă denumirea s-a schimbat — dacă da, update în WinMentor
                $wmName  = trim($articoleMap[$key]['denumire'] ?? '');
                $erpName = trim($item['product_name'] ?? '');
                $product = ! empty($item['woo_product_id']) ? WooProduct::find($item['woo_product_id']) : null;
                $expectedName = $product ? trim($product->winmentor_name ?? $product->name) : $erpName;

                if ($wmName !== '' && $expectedName !== '' && $wmName !== $expectedName) {
                    $updateResult = $this->updateArticol($sku, $expectedName);
                    if ($updateResult['success']) {
                        $updated[] = "{$expectedName} [{$sku}]";
                    }
                    // Nu e eroare blocantă — continuăm oricum
                }
            } else {
                // Bulk map poate rata produse cu codExtern nepopulat → fallback search direct
                $found = $this->searchArticolBySku($sku);
                if ($found) {
                    $umMap[$sku] = $found['denUM'] ?? null;
                } else {
                    $toCreate[] = $item;
                }
            }
        }

        foreach ($toCreate as $item) {
            $sku     = $item['sku'];
            $product = ! empty($item['woo_product_id'])
                ? WooProduct::find($item['woo_product_id'])
                : WooProduct::where('sku', $sku)->first();

            if (! $product) {
                $errors[] = "Produsul cu SKU \"{$sku}\" nu există în WinMentor și nu poate fi creat (produsul ERP nu e găsit).";
                continue;
            }

            $supplierSku  = $item['supplier_sku'] ?? null;
            $createResult = $this->createArticol($product, $supplierSku);

            if ($createResult['success']) {
                $created[]   = "{$product->name} [{$sku}]";
                $umMap[$sku] = $product->unit ?? 'Buc';
            } else {
                $errors[] = "Eroare la crearea \"{$product->name}\" [{$sku}]: {$createResult['error']}";
            }
        }

        if (! empty($updated)) {
            Log::channel('winmentor_bridge')->info('[WinMentor] Denumiri actualizate: ' . implode(', ', $updated));
        }

        return [
            'ok'      => empty($errors),
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
            'umMap'   => $umMap,
        ];
    }

    /**
     * Returnează set-ul de SKU-uri (lowercase) ale articolelor din WinMentor
     * care aparțin clasei date (ex: '1'), indiferent de gestiune.
     * Folosește /api/stocuri care include câmpul simbolClasa.
     *
     * @return array<string, true> — [sku_lowercase => true]
     */
    public function getSkusInClasa(string $clasa, int $pageSize = 5000): array
    {
        $this->selectFirma();

        $skus = [];
        $page = 1;

        do {
            $result     = $this->get('/api/stocuri', ['page' => $page, 'pageSize' => $pageSize], timeout: 120);
            $data       = $result['data'] ?? [];
            $items      = $data['items'] ?? [];
            $hasNext    = $data['hasNextPage'] ?? false;
            $totalPages = (int) ($data['totalPages'] ?? 1);

            foreach ($items as $item) {
                if (($item['simbolClasa'] ?? '') !== $clasa) {
                    continue;
                }

                $sku = $item['codExtern'] ?? null;
                if ($sku) {
                    $skus[strtolower(trim($sku))] = true;
                }
            }

            $page++;
        } while ($hasNext && $page <= $totalPages);

        return $skus;
    }

    /**
     * Fetch paginat al tuturor articolelor din WinMentor.
     * Returnează [normalizedSku => article] — cheie lowercase pentru lookup rapid.
     * Timeout ridicat (120s) pentru că poate returna mii de articole.
     */
    public function fetchAllArticoleSkuMap(): array
    {
        $map      = [];
        $page     = 1;
        $pageSize = 10000; // acoperă cataloage mari; reducem numărul de pagini HTTP

        do {
            $result  = $this->get('/api/articole', ['page' => $page, 'pageSize' => $pageSize], timeout: 120);
            $data    = $result['data'] ?? [];
            // Bridge-ul poate returna fie {items:[...], hasNextPage:bool} fie direct [...]
            $items   = $data['items'] ?? (is_array($data) && ! isset($data['items']) ? $data : []);
            $hasNext = $data['hasNextPage'] ?? false;

            foreach ($items as $item) {
                $sku = $item['codExtern'] ?? $item['codIntern'] ?? null;
                if ($sku && ! isset($map[strtolower(trim($sku))])) {
                    $map[strtolower(trim($sku))] = $item;
                }
            }

            $page++;
        } while ($hasNext);

        return $map;
    }

    /**
     * Verifică dacă furnizorul există în WinMentor.
     * Prioritate: winmentor_id (din sincronizare) → fallback CUI paginated.
     * Returnează ['ok' => bool, 'partener' => array|null, 'error' => string|null].
     */
    public function ensurePartenerExists(Supplier $supplier): array
    {
        // Apelantul trebuie să fi setat deja selectFirma() + setIdPartField('CodIntern')
        // ÎNAINTE de a apela această metodă.

        // 1. Avem winmentor_id din sincronizare → lookup direct după ID.
        //    ATENȚIE: mai multe firme pot avea ACELAȘI CUI (ex: CEMPLUS RO vs PROMIX PLUS).
        //    Verificăm și numele — dacă ID-ul stocat duce la altă firmă, îl ignorăm și re-rezolvăm.
        if ($supplier->winmentor_id) {
            $partener = $this->searchPartenerById($supplier->winmentor_id);
            if ($partener) {
                $foundName = $partener['denumire'] ?? '';
                // Acceptăm ID-ul stocat doar dacă numele se potrivește ȘI partenerul nu e
                // dezactivat (prefix "X" = nu mai lucrăm cu el). Altfel re-rezolvăm.
                if ($this->partenerNameMatches($supplier->name, $foundName) && ! $this->isDeactivatedPartenerName($foundName)) {
                    return ['ok' => true, 'partener' => $partener, 'error' => null];
                }
                // ID-ul stocat duce la altă firmă (CUI dublu) sau la un partener dezactivat
                // — îl ignorăm și mergem pe CUI + nume (care preferă partenerul activ).
                Log::channel('daily')->warning("[WinMentor Bridge] winmentor_id={$supplier->winmentor_id} duce la \"{$foundName}\" (≠ \"{$supplier->name}\" sau dezactivat). Re-rezolv după CUI + nume.");
            } else {
                // ID-ul nu mai e valid — va fi corectat mai jos prin fallback CUI
                Log::channel('daily')->warning("[WinMentor Bridge] winmentor_id={$supplier->winmentor_id} invalid pentru [{$supplier->name}], fallback pe CUI");
            }
        }

        // 2. Fallback: caută paginated după CUI — pot exista MAI MULȚI parteneri cu același CUI
        $cui = $supplier->vat_number;

        if (! $cui) {
            return ['ok' => false, 'partener' => null, 'error' => "Furnizorul \"{$supplier->name}\" nu are CUI/CIF completat în ERP și nu are winmentor_id setat."];
        }

        $candidates = $this->searchParteneriByCui($cui);

        if (empty($candidates)) {
            return [
                'ok'       => false,
                'partener' => null,
                'error'    => "Furnizorul \"{$supplier->name}\" (CUI: {$cui}) nu a fost găsit în WinMentor. Sincronizați partenerii sau completați winmentor_id.",
            ];
        }

        // Alegem partenerul al cărui nume se potrivește cel mai bine (CUI dublu = firme diferite)
        $partener = $this->pickBestPartenerByName($supplier->name, $candidates);

        if (! $partener) {
            $names = implode(', ', array_map(
                fn ($p) => '"' . ($p['denumire'] ?? '?') . '" (ID: ' . ($p['idPartener'] ?? '?') . ')',
                $candidates
            ));
            Log::channel('daily')->warning("[WinMentor Bridge] CUI {$cui} match suspect pentru [{$supplier->name}]: candidați [{$names}]. Nu suprascriu winmentor_id.");
            return [
                'ok'       => false,
                'partener' => null,
                'error'    => "CUI {$cui} găsit în WinMentor la: {$names}, dar niciun nume nu corespunde cu \"{$supplier->name}\". Verificați manual.",
            ];
        }

        $foundId   = $partener['idPartener'] ?? '';
        $foundName = $partener['denumire'] ?? '';

        // Salvăm winmentor_id verificat pentru viitor (doar dacă s-a schimbat)
        $oldId = $supplier->winmentor_id;
        if ($oldId !== $foundId) {
            $supplier->updateQuietly(['winmentor_id' => $foundId]);
            Log::channel('daily')->info("[WinMentor Bridge] winmentor_id corectat: [{$supplier->name}] {$oldId} → {$foundId} (\"{$foundName}\", verificat prin CUI + nume)");
        }

        return ['ok' => true, 'partener' => $partener, 'error' => null];
    }

    /**
     * Compară numele furnizorului ERP cu denumirea din WinMentor (fuzzy).
     * Returnează true dacă primele cuvinte semnificative se potrivesc.
     */
    private function partenerNameMatches(string $erpName, string $wmName): bool
    {
        $normalize = function (string $name): string {
            $name = mb_strtolower($name);
            // Eliminăm sufixe juridice comune
            $name = preg_replace('/\b(s\.?r\.?l\.?|s\.?a\.?|s\.?r\.?l|s\.?c\.?s\.?|srl|sa|ii|pfa)\b/i', '', $name);
            // Eliminăm caractere speciale
            $name = preg_replace('/[^a-z0-9\s]/u', '', $name);
            return trim(preg_replace('/\s+/', ' ', $name));
        };

        $a = $normalize($erpName);
        $b = $normalize($wmName);

        if ($a === '' || $b === '') {
            return false;
        }

        // Match exact după normalizare
        if ($a === $b) {
            return true;
        }

        // Unul îl conține pe celălalt
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        // Primul cuvânt semnificativ (de obicei numele companiei) se potrivește
        $firstA = explode(' ', $a)[0] ?? '';
        $firstB = explode(' ', $b)[0] ?? '';
        if (strlen($firstA) >= 3 && $firstA === $firstB) {
            return true;
        }

        // Similar_text — peste 70% e suficient
        similar_text($a, $b, $percent);

        return $percent >= 70;
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

    private function get(string $path, array $query = [], bool $auth = true, int $timeout = 30): array
    {
        $request = Http::timeout($timeout)->withoutVerifying();

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

    private function post(string $path, array $body = [], array $query = [], int $timeout = 60): array
    {
        $request = Http::timeout($timeout)
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
