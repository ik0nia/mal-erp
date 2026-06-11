<?php

namespace App\Services\Winmentor;

use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReception;
use App\Models\Supplier;
use App\Models\WooProduct;
use Illuminate\Support\Facades\Log;

/**
 * Importă o comandă furnizor în WinMentor după confirmarea recepției cantitative.
 *
 * Flux:
 *  1. Verifică Bridge accesibil
 *  2. Verifică/creează furnizorul în WinMentor
 *  3. Verifică/creează produsele lipsă în WinMentor
 *  4. Construiește formatul INI și importă comanda furnizor
 *  5. Actualizează PO cu status, timestamp și nr. document WinMentor
 */
class PushComenziFurnizoriService
{
    public function __construct(
        private readonly WinmentorBridgeClient $bridge,
    ) {}

    /**
     * @return array{success: bool, error: string|null, orderNr: string|null}
     */
    public function push(PurchaseOrder $po): array
    {
        $po->loadMissing(['supplier', 'items.product']);

        $this->bridge->setLogContext("ComenziFurnizori::{$po->number}");

        $this->log('info', "Start import PO [{$po->number}] furnizor [{$po->supplier->name}]");

        // 1. Bridge accesibil?
        if (! $this->bridge->isReachable()) {
            return $this->fail($po, 'WinMentor Bridge nu este accesibil.');
        }

        $this->bridge->selectFirma();
        $this->bridge->setIdPartField('CodIntern');

        // 2. Furnizor — verifică sau creează
        $partenerResult = $this->ensureFurnizorExists($po->supplier);
        if (! $partenerResult['ok']) {
            return $this->fail($po, $partenerResult['error']);
        }

        // 3. Produse — rezolvă SKU-urile noastre (EAN) și verifică/creează în WinMentor
        $receivedItems = $po->items->filter(fn ($item) => (float) $item->received_quantity > 0);
        [$items, $skuMap] = $this->resolveItemsForWinmentor($receivedItems, $po->supplier_id);

        if (empty($items)) {
            return $this->fail($po, 'Niciun produs cu cantitate recepționată > 0.');
        }

        $articoleResult = $this->bridge->ensureArticoleExist($items);
        if (! $articoleResult['ok']) {
            return $this->fail($po, implode("\n", $articoleResult['errors']));
        }

        if ($articoleResult['created']) {
            $this->log('info', "Produse create automat: " . implode(', ', $articoleResult['created']));
        }

        // 4. Construiește liniile INI și importă
        $conn  = \App\Models\IntegrationConnection::find(5);
        $lines = $this->buildLines($po, $partenerResult['partener'], $conn, $articoleResult['umMap'] ?? [], $skuMap);

        $this->log('info', "Import comenzi-furnizori [{$po->number}]", ['lines' => $lines]);

        // Validare
        $validateResult = $this->bridge->importDocument('comenzi-furnizori', $lines, validateOnly: true);
        if (! ($validateResult['isValid'] ?? false)) {
            $errors = implode(', ', $validateResult['errors'] ?? ['Format invalid']);
            return $this->fail($po, "Validare eșuată: {$errors}");
        }

        // Import efectiv
        $importResult = $this->bridge->importDocument('comenzi-furnizori', $lines, validateOnly: false);
        $this->log('info', "Răspuns import [{$po->number}]", ['result' => $importResult]);

        $orderNr = $importResult['importedCount'] > 0
            ? ($importResult['warnings'][0] ?? null)
            : null;

        $po->update([
            'winmentor_sync_status' => PurchaseOrder::WINMENTOR_SYNCED,
            'winmentor_sync_error'  => null,
            'winmentor_synced_at'   => now(),
            'winmentor_order_nr'    => $orderNr,
        ]);

        $this->log('info', "PO [{$po->number}] importat cu succes în WinMentor");

        return ['success' => true, 'error' => null, 'orderNr' => $orderNr];
    }

    // ─── Furnizor ────────────────────────────────────────────────────────────────

    private function ensureFurnizorExists(Supplier $supplier): array
    {
        return $this->bridge->ensurePartenerExists($supplier);
    }

    /**
     * Creează un partener nou în WinMentor din datele furnizorului ERP.
     * Structura AdaugaPartener: 35 câmpuri separate prin ";"
     */
    private function createPartener(Supplier $supplier): array
    {
        if (! $this->bridge->writesEnabled()) {
            return ['success' => false, 'error' => 'Scrierile în WinMentor sunt dezactivate.'];
        }

        $this->bridge->selectFirma();
        $this->bridge->setIdPartField('CodFiscal');

        // Obținem next ID disponibil
        $nextId = $this->getNextPartenerId();

        if (!$nextId || $nextId === '0') {
            $this->log('error', "Nu s-a putut obține un ID valid pentru furnizor nou [{$supplier->name}] — next-id a returnat [{$nextId}]");
            return ['success' => false, 'error' => "Nu s-a putut genera un ID furnizor valid în WinMentor. Adăugați furnizorul manual."];
        }

        $fields = array_fill(0, 35, '');
        $fields[0]  = $nextId;                              // 1  ID Partener
        $fields[1]  = $supplier->name;                      // 2  Denumire
        $fields[2]  = $supplier->vat_number ?? '';          // 3  Cod Fiscal
        $fields[3]  = '';                                   // 4  Localitate sediu
        $fields[4]  = $supplier->address ?? '';             // 5  Adresa sediu
        $fields[5]  = $supplier->phone ?? '';               // 6  Telefon
        $fields[6]  = $supplier->contact_person ?? '';      // 7  Persoana contact
        $fields[7]  = '';                                   // 8  Simbol Clasa
        $fields[8]  = '';                                   // 9  Simbol categorie pret
        $fields[9]  = '';                                   // 10 ID Agent implicit
        $fields[10] = $supplier->reg_number ?? '';          // 11 Nr. Registrul comertului
        $fields[11] = $supplier->notes ?? '';               // 12 Observatii
        $fields[12] = '';                                   // 13 Simbol banca
        $fields[13] = $supplier->bank_name ?? '';           // 14 Nume banca
        $fields[14] = '';                                   // 15 Localitate banca
        $fields[15] = $supplier->bank_account ?? '';        // 16 Cont banca
        // 17-29 goale
        $fields[22] = '';                                   // 23 CodExtern
        // 30 email sediu social
        $fields[29] = $supplier->email ?? '';               // 30 email

        $info = implode(';', $fields);

        $this->log('info', "AdaugaPartener [{$supplier->name}]", ['info' => $info]);

        try {
            $result = $this->post('/api/parteneri/add', ['info' => $info]);

            if ($result['success'] ?? false) {
                $this->log('info', "Furnizor [{$supplier->name}] creat în WinMentor");
                return ['success' => true, 'error' => null];
            }

            $error = implode(', ', $result['errors'] ?? ['Eroare necunoscută']);
            $this->log('error', "Eroare creare furnizor [{$supplier->name}]: {$error}");
            return ['success' => false, 'error' => $error];

        } catch (\Throwable $e) {
            $this->log('error', "Excepție creare furnizor [{$supplier->name}]: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function getNextPartenerId(): string
    {
        try {
            $conn    = \App\Models\IntegrationConnection::find(5);
            $baseUrl = rtrim($conn?->bridgeUrl() ?? '', '/');
            $apiKey  = $conn?->bridgeApiKey() ?? '';

            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withoutVerifying()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get($baseUrl . '/api/parteneri/next-id');

            return (string) ($response->json()['data'] ?? '0');
        } catch (\Throwable) {
            return '0';
        }
    }

    // ─── Format linii INI comenzi-furnizori ─────────────────────────────────────

    private function buildLines(PurchaseOrder $po, array $partener, ?\App\Models\IntegrationConnection $conn, array $umMap = [], array $skuMap = []): array
    {
        $idPartener = $partener['idPartener'] ?? '';
        $data       = $po->received_at?->format('d.m.Y') ?? now()->format('d.m.Y');
        $an         = $conn?->bridgeAn() ?? now()->year;
        $luna       = $conn?->bridgeLuna() ?? now()->month;
        $moneda     = strtoupper($po->currency ?? 'RON') === 'EUR' ? 'EUR' : 'LEI';

        $receivedItems = $po->items
            ->filter(fn ($item) => (float) $item->received_quantity > 0)
            ->sortBy(fn ($item) => $item->invoice_position ?? PHP_INT_MAX);
        $totalArticole = $receivedItems->count();

        $lines = [
            '[InfoPachet]',
            "AnLucru={$an}",
            "LunaLucru={$luna}",
            'Tipdocument=COMANDA FURNIZOR',
            'TotalComenzi=1',
            'Logon=Master',
            '',
            '[Comanda_1]',
            'NrDoc=' . substr(ltrim(str_replace('PO-', '', $po->number), '0'), -8),
            'SimbolCarnet=',
            'Operatie=A',
            "Data={$data}",
            "DataLivrare={$data}",
            "CodFurnizor={$idPartener}",
            'Locatie=',
            "Moneda={$moneda}",
            "TotalArticole={$totalArticole}",
            'Observatii=' . ($po->received_notes ?? ''),
            '',
            '[Items_1]',
        ];

        $i = 1;
        foreach ($receivedItems as $item) {
            $originalSku = $item->sku ?? '';
            $sku         = $skuMap[$originalSku] ?? $originalSku;
            $um          = $umMap[$sku] ?? $item->product?->unit ?? 'Buc';
            $cant        = rtrim(rtrim(number_format((float) $item->received_quantity, 3, '.', ''), '0'), '.');
            $pret        = rtrim(rtrim(number_format((float) $item->unit_price, 4, '.', ''), '0'), '.');

            $lines[] = "Item_{$i}={$sku};{$um};{$cant};{$pret};0;{$data};";
            $i++;
        }

        return $lines;
    }

    // ─── Batch (mai multe PO-uri → un singur document WinMentor) ────────────────

    public function pushBatch(\Illuminate\Support\Collection $orders): array
    {
        $first = $orders->first();
        $first->loadMissing(['supplier']);

        $this->bridge->setLogContext("ComenziFurnizori::BATCH-{$first->number}+");

        $this->log('info', "Start import BATCH " . $orders->pluck('number')->implode(', '));

        if (! $this->bridge->isReachable()) {
            return $this->failBatch($orders, 'WinMentor Bridge nu este accesibil.');
        }

        $this->bridge->selectFirma();
        $this->bridge->setIdPartField('CodIntern');

        $partenerResult = $this->ensureFurnizorExists($first->supplier);
        if (! $partenerResult['ok']) {
            return $this->failBatch($orders, $partenerResult['error']);
        }

        // Colectăm toate produsele din toate PO-urile
        $receivedItems = $orders->flatMap(fn ($o) => $o->items->filter(fn ($item) => (float) $item->received_quantity > 0));
        [$allItems, $skuMap] = $this->resolveItemsForWinmentor($receivedItems, $first->supplier_id);

        if (empty($allItems)) {
            return $this->failBatch($orders, 'Niciun produs cu cantitate recepționată > 0.');
        }

        $articoleResult = $this->bridge->ensureArticoleExist($allItems);
        if (! $articoleResult['ok']) {
            return $this->failBatch($orders, implode("\n", $articoleResult['errors']));
        }

        $conn  = \App\Models\IntegrationConnection::find(5);
        $lines = $this->buildBatchLines($orders, $partenerResult['partener'], $conn, $articoleResult['umMap'] ?? [], $skuMap);

        $this->log('info', "Import batch comenzi-furnizori", ['lines' => $lines]);

        $validateResult = $this->bridge->importDocument('comenzi-furnizori', $lines, validateOnly: true);
        if (! ($validateResult['isValid'] ?? false)) {
            $errors = implode(', ', $validateResult['errors'] ?? ['Format invalid']);
            return $this->failBatch($orders, "Validare eșuată: {$errors}");
        }

        $importResult = $this->bridge->importDocument('comenzi-furnizori', $lines, validateOnly: false);
        $orderNr = $importResult['importedCount'] > 0 ? ($importResult['warnings'][0] ?? null) : null;

        $orders->each(fn ($o) => $o->updateQuietly([
            'winmentor_sync_status' => PurchaseOrder::WINMENTOR_SYNCED,
            'winmentor_sync_error'  => null,
            'winmentor_synced_at'   => now(),
            'winmentor_order_nr'    => $orderNr,
        ]));

        $this->log('info', "BATCH importat cu succes în WinMentor, nr. doc: {$orderNr}");

        return ['success' => true, 'error' => null, 'orderNr' => $orderNr];
    }

    // ─── Recepție parțială (un singur PurchaseOrderReception → WinMentor) ──────

    public function pushReception(PurchaseOrderReception $reception): array
    {
        $reception->loadMissing(['purchaseOrder.supplier', 'purchaseOrder.items.product', 'items']);
        $po = $reception->purchaseOrder;

        $this->bridge->setLogContext("Recepție::{$po->number}#R{$reception->reception_number}");
        $this->log('info', "Start import recepție #{$reception->reception_number} din PO [{$po->number}]");

        if (! $this->bridge->isReachable()) {
            return $this->failReception($reception, 'WinMentor Bridge nu este accesibil.');
        }

        $this->bridge->selectFirma();
        $this->bridge->setIdPartField('CodIntern');

        $partenerResult = $this->ensureFurnizorExists($po->supplier);
        if (! $partenerResult['ok']) {
            return $this->failReception($reception, $partenerResult['error']);
        }

        // Construim items din recepția curentă (nu din tot PO-ul)
        $receptionItems = $reception->items->filter(fn ($ri) => (float) $ri->received_quantity > 0);
        if ($receptionItems->isEmpty()) {
            return $this->failReception($reception, 'Niciun produs cu cantitate recepționată > 0.');
        }

        // Map reception items → PO items pentru SKU resolution
        $poItemsMap = $po->items->keyBy('id');
        $fakeItems = $receptionItems->map(function ($ri) use ($poItemsMap) {
            $poItem = $poItemsMap[$ri->order_item_id] ?? null;
            if (! $poItem) return null;
            // Creăm un obiect temporar cu received_quantity din recepție, restul din PO item
            return (object) [
                'sku'               => $poItem->sku,
                'supplier_sku'      => $poItem->supplier_sku,
                'woo_product_id'    => $poItem->woo_product_id,
                'product_name'      => $poItem->product_name,
                'received_quantity'  => $ri->received_quantity,
                'unit_price'        => $poItem->unit_price,
                'invoice_position'  => $ri->invoice_position,
                'product'           => $poItem->product,
            ];
        })->filter();

        [$items, $skuMap] = $this->resolveItemsForWinmentor($fakeItems, $po->supplier_id);

        if (empty($items)) {
            return $this->failReception($reception, 'Niciun produs rezolvat pentru WinMentor.');
        }

        $articoleResult = $this->bridge->ensureArticoleExist($items);
        if (! $articoleResult['ok']) {
            return $this->failReception($reception, implode("\n", $articoleResult['errors']));
        }

        $conn  = \App\Models\IntegrationConnection::find(5);
        $lines = $this->buildReceptionLines($reception, $po, $fakeItems, $partenerResult['partener'], $conn, $articoleResult['umMap'] ?? [], $skuMap);

        $this->log('info', "Import recepție #{$reception->reception_number} [{$po->number}]", ['lines' => $lines]);

        $validateResult = $this->bridge->importDocument('comenzi-furnizori', $lines, validateOnly: true);
        if (! ($validateResult['isValid'] ?? false)) {
            $errors = implode(', ', $validateResult['errors'] ?? ['Format invalid']);
            return $this->failReception($reception, "Validare eșuată: {$errors}");
        }

        $importResult = $this->bridge->importDocument('comenzi-furnizori', $lines, validateOnly: false);
        $orderNr = $importResult['importedCount'] > 0 ? ($importResult['warnings'][0] ?? null) : null;

        $reception->update([
            'winmentor_sync_status' => PurchaseOrderReception::WINMENTOR_SYNCED,
            'winmentor_sync_error'  => null,
            'winmentor_synced_at'   => now(),
            'winmentor_order_nr'    => $orderNr,
        ]);

        // Documentul (NrDoc = numărul PO) e acum în WinMentor — marcăm și PO-ul,
        // altfel winmentor:retry-failed-po-sync l-ar reimporta și ar dubla comanda
        if ($po->winmentor_sync_status !== PurchaseOrder::WINMENTOR_SYNCED) {
            $po->update([
                'winmentor_sync_status' => PurchaseOrder::WINMENTOR_SYNCED,
                'winmentor_sync_error'  => null,
                'winmentor_synced_at'   => now(),
                'winmentor_order_nr'    => $orderNr,
            ]);
        }

        $this->log('info', "Recepție #{$reception->reception_number} [{$po->number}] importată cu succes");

        return ['success' => true, 'error' => null, 'orderNr' => $orderNr];
    }

    private function buildReceptionLines(
        PurchaseOrderReception $reception,
        PurchaseOrder $po,
        \Illuminate\Support\Collection $fakeItems,
        array $partener,
        ?\App\Models\IntegrationConnection $conn,
        array $umMap = [],
        array $skuMap = [],
    ): array {
        $idPartener = $partener['idPartener'] ?? '';
        $data       = $reception->received_at->format('d.m.Y');
        $an         = $conn?->bridgeAn() ?? now()->year;
        $luna       = $conn?->bridgeLuna() ?? now()->month;
        $moneda     = strtoupper($po->currency ?? 'RON') === 'EUR' ? 'EUR' : 'LEI';

        $receivedItems = $fakeItems->filter(fn ($item) => (float) $item->received_quantity > 0)
            ->sortBy(fn ($item) => $item->invoice_position ?? PHP_INT_MAX);
        $totalArticole = $receivedItems->count();

        // NrDoc: max 8 chars (limită WinMentor). Format: ultimele 6 cifre PO + suffix literă (a-z)
        $poDigits = ltrim(str_replace('PO-', '', $po->number), '0');
        if (! $reception->is_final || $reception->reception_number > 1) {
            $suffix = chr(96 + min($reception->reception_number, 26)); // 1→a, 2→b, ..., 26→z
            $nrDoc  = substr($poDigits, -7) . $suffix; // 7 cifre + 1 literă = 8 max
        } else {
            $nrDoc = substr($poDigits, -8); // max 8 cifre
        }

        $notes = $reception->received_notes ?? '';

        $lines = [
            '[InfoPachet]',
            "AnLucru={$an}",
            "LunaLucru={$luna}",
            'Tipdocument=COMANDA FURNIZOR',
            'TotalComenzi=1',
            'Logon=Master',
            '',
            '[Comanda_1]',
            "NrDoc={$nrDoc}",
            'SimbolCarnet=',
            'Operatie=A',
            "Data={$data}",
            "DataLivrare={$data}",
            "CodFurnizor={$idPartener}",
            'Locatie=',
            "Moneda={$moneda}",
            "TotalArticole={$totalArticole}",
            "Observatii={$notes}",
            '',
            '[Items_1]',
        ];

        $i = 1;
        foreach ($receivedItems as $item) {
            $originalSku = $item->sku ?? '';
            $sku         = $skuMap[$originalSku] ?? $originalSku;
            $um          = $umMap[$sku] ?? $item->product?->unit ?? 'Buc';
            $cant        = rtrim(rtrim(number_format((float) $item->received_quantity, 3, '.', ''), '0'), '.');
            $pret        = rtrim(rtrim(number_format((float) $item->unit_price, 4, '.', ''), '0'), '.');

            $lines[] = "Item_{$i}={$sku};{$um};{$cant};{$pret};0;{$data};";
            $i++;
        }

        return $lines;
    }

    private function failReception(PurchaseOrderReception $reception, string $error): array
    {
        $this->log('error', "Eroare import recepție #{$reception->reception_number}: {$error}");
        $reception->updateQuietly([
            'winmentor_sync_status' => PurchaseOrderReception::WINMENTOR_FAILED,
            'winmentor_sync_error'  => $error,
        ]);
        return ['success' => false, 'error' => $error, 'orderNr' => null];
    }

    private function buildBatchLines(\Illuminate\Support\Collection $orders, array $partener, ?\App\Models\IntegrationConnection $conn, array $umMap = [], array $skuMap = []): array
    {
        $first      = $orders->first();
        $idPartener = $partener['idPartener'] ?? '';
        $data       = $first->received_at?->format('d.m.Y') ?? now()->format('d.m.Y');
        $an         = $conn?->bridgeAn() ?? now()->year;
        $luna       = $conn?->bridgeLuna() ?? now()->month;
        $moneda     = strtoupper($first->currency ?? 'RON') === 'EUR' ? 'EUR' : 'LEI';

        // Toate items-urile recepționate din toate PO-urile
        $receivedItems = $orders->flatMap(fn ($o) => $o->items->filter(fn ($item) => (float) $item->received_quantity > 0));

        // TotalArticole = linii unice per SKU (după cumulare)
        $uniqueSkus    = $receivedItems->map(fn ($i) => $i->sku ?? ('__name__' . $i->product_name))->unique()->count();
        $totalArticole = $uniqueSkus;

        // Observații combinate
        $notes = $orders->pluck('received_notes')->filter()->implode(' | ');

        // NrDoc din primul PO (max 8 chars — limită WinMentor)
        $nrDoc = substr(ltrim(str_replace('PO-', '', $first->number), '0'), -8);

        $lines = [
            '[InfoPachet]',
            "AnLucru={$an}",
            "LunaLucru={$luna}",
            'Tipdocument=COMANDA FURNIZOR',
            'TotalComenzi=1',
            'Logon=Master',
            '',
            '[Comanda_1]',
            "NrDoc={$nrDoc}",
            'SimbolCarnet=',
            'Operatie=A',
            "Data={$data}",
            "DataLivrare={$data}",
            "CodFurnizor={$idPartener}",
            'Locatie=',
            "Moneda={$moneda}",
            "TotalArticole={$totalArticole}",
            "Observatii={$notes}",
            '',
            '[Items_1]',
        ];

        // Cumulăm cantitățile per SKU rezolvat — același produs din PO-uri diferite → o singură linie
        $merged = [];
        foreach ($receivedItems as $item) {
            $originalSku  = $item->sku ?? '';
            $resolvedSku  = $skuMap[$originalSku] ?? $originalSku;
            $mergeKey     = $resolvedSku ?: ('__name__' . $item->product_name);
            if (!isset($merged[$mergeKey])) {
                $merged[$mergeKey] = [
                    'sku'  => $resolvedSku,
                    'um'   => $umMap[$resolvedSku] ?? $item->product?->unit ?? 'Buc',
                    'cant' => 0.0,
                    'pret' => (float) $item->unit_price,
                ];
            }
            $merged[$mergeKey]['cant'] += (float) $item->received_quantity;
        }

        $i = 1;
        foreach ($merged as $row) {
            $cant    = rtrim(rtrim(number_format($row['cant'], 3, '.', ''), '0'), '.');
            $pret    = rtrim(rtrim(number_format($row['pret'], 4, '.', ''), '0'), '.');
            $lines[] = "Item_{$i}={$row['sku']};{$row['um']};{$cant};{$pret};0;{$data};";
            $i++;
        }

        return $lines;
    }

    private function failBatch(\Illuminate\Support\Collection $orders, string $error): array
    {
        $this->log('error', "Eroare import BATCH: {$error}");
        $orders->each(fn ($o) => $o->updateQuietly([
            'winmentor_sync_status' => PurchaseOrder::WINMENTOR_FAILED,
            'winmentor_sync_error'  => $error,
        ]));
        return ['success' => false, 'error' => $error, 'orderNr' => null];
    }

    // ─── SKU Resolution ──────────────────────────────────────────────────────────

    /**
     * Primește o colecție de PurchaseOrderItem și returnează:
     *   [0] array de items pentru ensureArticoleExist (cu SKU-ul ERP/EAN rezolvat)
     *   [1] skuMap: [supplier_sku|po_item_sku => resolved_sku] pentru buildLines
     *
     * Logica de rezolvare pentru fiecare item:
     *  1. Dacă are woo_product_id → folosim WooProduct->sku (EAN)
     *  2. Altfel, căutăm în woo_products.sku direct (poate fi deja EAN)
     *  3. Altfel, căutăm în product_suppliers.supplier_sku pentru furnizorul respectiv
     *  4. Dacă tot nu găsim, folosim $item->sku ca atare
     */
    private function resolveItemsForWinmentor(\Illuminate\Support\Collection $items, ?int $supplierId): array
    {
        $resolved  = [];
        $skuMap    = []; // originalSku => resolvedSku

        // Pre-load: toți woo_product_id unici (pentru lookup în bulk)
        $wooIdMap = [];
        $wooProductIds = $items->pluck('woo_product_id')->filter()->unique()->values()->all();
        if ($wooProductIds) {
            WooProduct::whereIn('id', $wooProductIds)->get()->each(function ($p) use (&$wooIdMap) {
                $wooIdMap[$p->id] = $p;
            });
        }

        // Pre-load: lookup supplier_sku → WooProduct pentru furnizorul dat
        $supplierSkuMap = []; // supplier_sku => WooProduct
        if ($supplierId) {
            $poSkus = $items->pluck('sku')->filter()->unique()->values()->all();
            if ($poSkus) {
                ProductSupplier::where('supplier_id', $supplierId)
                    ->whereIn('supplier_sku', $poSkus)
                    ->with('product')
                    ->get()
                    ->each(function ($ps) use (&$supplierSkuMap) {
                        if ($ps->product) {
                            $supplierSkuMap[$ps->supplier_sku] = $ps->product;
                        }
                    });
            }
        }

        foreach ($items as $item) {
            $originalSku = $item->sku ?? '';

            // Fallback: dacă SKU-ul e gol dar product_name arată a fi un EAN (doar cifre, 8-14 caractere),
            // îl folosim ca SKU — frecvent la items adăugate manual cu EAN-ul în câmpul greșit
            if ($originalSku === '' && preg_match('/^\d{8,14}$/', trim($item->product_name ?? ''))) {
                $eanFromName = trim($item->product_name);
                $wooByEan    = WooProduct::where('sku', $eanFromName)->first();
                if ($wooByEan) {
                    $this->log('info', "SKU rezolvat din product_name (EAN): [{$eanFromName}] → [{$wooByEan->sku}] ({$wooByEan->name})");
                    $skuMap[$originalSku] = $wooByEan->sku;
                    $resolved[] = [
                        'sku'            => $wooByEan->sku,
                        'product_name'   => $wooByEan->name,
                        'woo_product_id' => $wooByEan->id,
                        'supplier_sku'   => $item->supplier_sku ?? null,
                    ];
                    continue;
                }
            }

            $itemSupplierSku = $item->supplier_sku ?? null;

            // 1. woo_product_id direct
            if ($item->woo_product_id && isset($wooIdMap[$item->woo_product_id])) {
                $wooProduct  = $wooIdMap[$item->woo_product_id];
                $resolvedSku = $wooProduct->sku ?: $originalSku;
                $skuMap[$originalSku] = $resolvedSku;
                $resolved[] = [
                    'sku'            => $resolvedSku,
                    'product_name'   => $item->product_name,
                    'woo_product_id' => $wooProduct->id,
                    'supplier_sku'   => $itemSupplierSku,
                ];
                continue;
            }

            // 2. supplier_sku match → obținem WooProduct cu SKU EAN
            if ($originalSku && isset($supplierSkuMap[$originalSku])) {
                $wooProduct  = $supplierSkuMap[$originalSku];
                $resolvedSku = $wooProduct->sku ?: $originalSku;
                $skuMap[$originalSku] = $resolvedSku;
                $this->log('info', "SKU rezolvat: [{$originalSku}] → [{$resolvedSku}] ({$wooProduct->name})");
                $resolved[] = [
                    'sku'            => $resolvedSku,
                    'product_name'   => $item->product_name,
                    'woo_product_id' => $wooProduct->id,
                    'supplier_sku'   => $itemSupplierSku,
                ];
                continue;
            }

            // 3. Folosim SKU-ul din PO item as-is (poate fi deja EAN)
            $skuMap[$originalSku] = $originalSku;
            $resolved[] = [
                'sku'            => $originalSku,
                'product_name'   => $item->product_name,
                'woo_product_id' => null,
                'supplier_sku'   => $itemSupplierSku,
            ];
        }

        return [$resolved, $skuMap];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function fail(PurchaseOrder $po, string $error): array
    {
        $this->log('error', "Eroare import PO [{$po->number}]: {$error}");

        $po->update([
            'winmentor_sync_status' => PurchaseOrder::WINMENTOR_FAILED,
            'winmentor_sync_error'  => $error,
        ]);

        return ['success' => false, 'error' => $error, 'orderNr' => null];
    }

    private function post(string $path, array $body): array
    {
        $conn    = \App\Models\IntegrationConnection::find(5);
        $baseUrl = rtrim($conn?->bridgeUrl() ?? '', '/');
        $apiKey  = $conn?->bridgeApiKey() ?? '';

        $response = \Illuminate\Support\Facades\Http::timeout(60)
            ->withoutVerifying()
            ->withHeaders(['X-API-Key' => $apiKey])
            ->post($baseUrl . $path, $body);

        return $response->json() ?? [];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('daily')->{$level}('[WinMentor ComenziFurnizori] ' . $message, $context);
    }
}
