<?php

namespace App\Services\Winmentor;

use App\Models\WooProduct;
use Illuminate\Support\Facades\Log;

/**
 * Serviciu dedicat adaugarii de produse noi in WinMentor prin Bridge.
 *
 * Flux:
 *  1. Verifica daca produsul exista deja in WinMentor (dupa SKU/CodExtern)
 *  2. Daca exista → skip, returneaza ['success' => true, 'skipped' => true]
 *  3. Daca nu exista → construieste info string si apeleaza AddProduct
 *
 * Format info string AddProduct (19 campuri separate prin ";"):
 *  0  CodExtern         — SKU produs (EAN / cod bare)
 *  1  Denumire          — Numele produsului
 *  2  DenUM ?           — (necunoscut, lasat gol)
 *  3  IDProducator      — 0 = fara producator
 *  4  UM                — Unitate masura (Buc)
 *  5  TipSerie          — (lasat gol)
 *  6  CotaTVA           — 21 (cota standard actuala Romania)
 *  7  TVAInclus         — N (pret fara TVA)
 *  8  GestiuneImplicita — MP (Magazin Practic)
 *  9  CodExternAlt      — acelasi cu CodExtern (poz 0)
 *  10 DenUMSecundara ?  — (necunoscut, lasat gol)
 *  11 ParitateUMSec ?   — (necunoscut, lasat gol)
 *  12 SimbolClasa       — 1
 *  13 Masa              — greutate in kg
 *  14 Serviciu ?        — (lasat gol)
 *  15 PretVanzare       — pretul regular fara TVA
 *  16 PretMinim ?       — (necunoscut, lasat gol)
 *  17 CantImplicita ?   — (necunoscut, lasat gol)
 *  18 Flag/PretValuta ? — (lasat gol)
 *
 * Surse documentatie campuri: reverse engineering 2DocImpCl.exe + NomenclatorArticol
 * libWMEdcom (github.com/rayone121/libWMEdcom) + Articole noi.pdf (download.winmentor.ro)
 */
class AddProductService
{
    private const UM_DEFAULT     = 'Buc';
    private const TVA_DEFAULT   = '21';
    private const TVA_INCLUS    = 'N';
    private const TOTAL_FIELDS  = 19;

    public function __construct(
        private readonly WinmentorBridgeClient $bridge,
    ) {}

    /**
     * Adauga un produs in WinMentor daca nu exista deja.
     *
     * @return array{success: bool, skipped: bool, created: bool, error: string|null}
     */
    public function addIfNotExists(WooProduct $product): array
    {
        $sku = $product->sku;

        $this->log('info', "Verificare existenta produs [{$sku}]");

        // 1. Verifica daca exista deja
        $existing = $this->bridge->searchArticolBySku($sku);

        if ($existing) {
            $this->log('info', "Produs [{$sku}] exista deja in WinMentor — skip");
            return ['success' => true, 'skipped' => true, 'created' => false, 'error' => null];
        }

        // 2. Nu exista — construim info string si adaugam
        return $this->create($product);
    }

    /**
     * Forteaza crearea produsului (fara verificare prealabila).
     * Returneaza eroare 414 daca exista deja.
     *
     * @return array{success: bool, skipped: bool, created: bool, error: string|null}
     */
    public function create(WooProduct $product): array
    {
        if (! $this->bridge->writesEnabled()) {
            $this->log('warning', "WRITE BLOCAT (writes_enabled=false): AddProduct [{$product->sku}]");
            return ['success' => false, 'skipped' => false, 'created' => false, 'error' => 'Scrierile in WinMentor sunt dezactivate.'];
        }

        $this->bridge->selectFirma();
        $this->bridge->setIdPartField('CodIntern');

        $info = $this->buildInfoString($product);

        $this->log('info', "AddProduct [{$product->sku}]", [
            'info_string' => $info,
            'denumire'    => $product->winmentor_name ?? $product->name,
        ]);

        try {
            $result = $this->bridge->addProduct($info);

            if ($result['success'] ?? false) {
                $this->log('info', "Produs [{$product->sku}] adaugat cu succes in WinMentor", [
                    'response' => $result['data'] ?? [],
                ]);
                return ['success' => true, 'skipped' => false, 'created' => true, 'error' => null];
            }

            $error = implode(', ', $result['errors'] ?? ['Eroare necunoscuta']);
            $this->log('error', "Eroare AddProduct [{$product->sku}]: {$error}");
            return ['success' => false, 'skipped' => false, 'created' => false, 'error' => $error];

        } catch (\Throwable $e) {
            $this->log('error', "Exceptie AddProduct [{$product->sku}]: {$e->getMessage()}");
            return ['success' => false, 'skipped' => false, 'created' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Construieste string-ul de 19 campuri pentru AddProduct.
     *
     * Pozitii confirmate empiric (testare directa pe Bridge, Aprilie 2026):
     *  0  CodExtern    — SKU
     *  1  Denumire     — Numele produsului
     *  3  IDProducator — 0 = fara producator (confirmat)
     *  4  UM           — Buc (confirmat, majoritate articole)
     *  6  CotaTVA      — 21 (confirmat)
     *  7  TVAInclus    — N (pret fara TVA)
     *  9  CodExternAlt — = CodExtern (confirmat)
     * 14  PretVanzare  — pret fara TVA (confirmat: trimis 1.5 → pretVanzare=1,5)
     * 17  Masa         — greutate kg (confirmat: trimis 99.99 → masa=99,99)
     *
     * Pozitii NETESTATE / necunoscute — lasate goale:
     *  2  (necunoscut)
     *  5  TipSerie — lasat gol
     *  8  GestiuneImplicita — NU functioneaza prin AddProduct
     * 10  (necunoscut)
     * 11  (necunoscut)
     * 12  SimbolClasa — NU functioneaza prin AddProduct
     * 13  (necunoscut)
     * 15  (necunoscut)
     * 16  (necunoscut)
     * 18  (necunoscut)
     */
    private function buildInfoString(WooProduct $product): string
    {
        $fields = array_fill(0, self::TOTAL_FIELDS, '');

        $fields[0]  = (string) $product->sku;                                      // CodExtern
        $fields[1]  = (string) ($product->winmentor_name ?? $product->name);        // Denumire
        // $fields[2] — necunoscut, lasat gol
        $fields[3]  = '0';                                                          // IDProducator — fara producator
        $fields[4]  = self::UM_DEFAULT;                                             // UM = Buc
        // $fields[5] — TipSerie, lasat gol
        $fields[6]  = self::TVA_DEFAULT;                                            // CotaTVA = 21
        $fields[7]  = self::TVA_INCLUS;                                             // TVAInclus = D
        // $fields[8] — GestiuneImplicita NU functioneaza, lasat gol
        $fields[9]  = (string) $product->sku;                                       // CodExternAlt = CodExtern
        // $fields[10-13] — necunoscute, lasate goale
        $fields[14] = $product->regular_price ? (string) $product->regular_price : ''; // PretVanzare (CONFIRMAT poz 14)
        // $fields[15-16] — necunoscute, lasate goale
        $fields[17] = $product->weight ? (string) $product->weight : '';            // Masa kg (CONFIRMAT poz 17)
        // $fields[18] — necunoscut, lasat gol

        return implode(';', $fields);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('daily')->{$level}('[WinMentor AddProduct] ' . $message, $context);
    }
}
