<?php

namespace App\Services\Winmentor;

use App\Models\IntegrationConnection;
use App\Models\WooOrder;
use App\Models\WooOrderItem;
use App\Models\WooProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Importă o comandă online WooCommerce ca și COMANDĂ CLIENT în WinMentor (firma de producție MAL2019).
 *
 * Flux:
 *   1. dacă deja trimisă → refuză (anti-duplicare prin woo_orders.winmentor_sync_status)
 *   2. verifică TOATE produsele — codul WinMentor (codExtern) via winmentor_name → snapshot;
 *      dacă lipsește vreunul în WinMentor → blochează și raportează produsele
 *   3. verifică/creează clientul (persoană fizică sau juridică)
 *   4. importă COMANDA în WinMentor
 *   5. marchează comanda ca `synced` (cu numărul comenzii + ID client)
 *
 * MAL2019 are mereu luna curentă deschisă (firma de producție) → folosim now() ca an/lună.
 * Rămânem pe firma de producție (fără comutare) → fără riscul sesiunii COM partajate.
 * Apeluri HTTP brute către bridge, controlate explicit (nu prin WinmentorBridgeClient).
 */
class ImportWooOrderToWinmentorService
{
    /** Firma de producție — are mereu luna curentă deschisă. */
    private const FIRMA = 'MAL2019';

    /**
     * Articol serviciu pentru costul de livrare. Contabilitatea îl trece pe factură ca
     * „SERVICII MANIPULARE MARFA" (codExtern 9007767532752, UM=Lei, TVA 21%).
     * ATENȚIE la reactivare: UM=Lei → de verificat convenția cantitate/preț (qty=valoare vs qty=1).
     */
    private const TRANSPORT_COD = '9007767532752';
    private const TRANSPORT_UM = 'Lei';

    private string $base;
    private array $headers;

    public function __construct()
    {
        $conn = IntegrationConnection::find(5);
        $this->base    = rtrim((string) $conn?->bridgeUrl(), '/');
        $this->headers = ['X-API-Key' => (string) $conn?->bridgeApiKey()];
    }

    /**
     * @return array{success:bool, error:?string, client:?array, importedCount:int, skipped:array, nrComanda:?string}
     */
    public function import(WooOrder $order): array
    {
        $order->loadMissing('items');

        $fail = fn (string $msg, array $extra = []): array => array_merge([
            'success' => false, 'error' => $msg, 'client' => null,
            'importedCount' => 0, 'skipped' => [], 'nrComanda' => null,
        ], $extra);

        // 0. Anti-duplicare — deja trimisă cu succes?
        if ($order->winmentor_sync_status === 'synced') {
            return $fail('Comanda este deja importată în WinMentor (la '
                .optional($order->winmentor_synced_at)->format('d.m.Y H:i').'). Nu se retrimite.');
        }

        // Lock per-comandă — previne dublu-click / dublu-import al ACELEIAȘI comenzi.
        $lock = Cache::lock('woo-import-winmentor-'.$order->id, 120);
        if (! $lock->get()) {
            return $fail('Import deja în curs pentru această comandă. Așteaptă câteva secunde.');
        }

        try {
            // Asigură contextul corect pe bridge (firma de producție + luna curentă).
            $sel = $this->selectFirma(self::FIRMA, now()->year, now()->month);
            if (($sel['success'] ?? false) !== true) {
                return $fail('Nu am putut selecta firma '.self::FIRMA.': '.$this->errStr($sel));
            }

            // 1. Articole — TOATE trebuie să existe ca articol în WinMentor.
            //    Dacă lipsește ORICARE, blocăm importul (nu creăm nici clientul).
            [$items, $missing] = $this->resolveItems($order);
            if (! empty($missing)) {
                return $this->markFailed($order, $fail(
                    'Comanda NU poate fi importată — produse inexistente ca articol în WinMentor: '
                    .implode('; ', $missing),
                    ['skipped' => $missing]
                ));
            }
            if (empty($items)) {
                return $this->markFailed($order, $fail('Comanda nu are produse de importat.'));
            }

            // 2. Client — verifică sau creează (doar după ce știm că produsele există)
            $client = $this->resolveOrCreateClient($order);
            if (! ($client['ok'] ?? false)) {
                return $this->markFailed($order, $fail($client['error'] ?? 'Eroare la rezolvarea clientului.'));
            }

            // 3. Construiește liniile COMANDA + validează + importă
            $lines = $this->buildLines($order, (string) $client['id'], $items);

            $val = $this->post('/api/import/comenzi?validateOnly=true', ['lines' => $lines]);
            if (($val['data']['isValid'] ?? false) !== true) {
                return $this->markFailed($order, $fail(
                    'Validare WinMentor eșuată: '.$this->errStr($val, $val['data']['errors'] ?? null),
                    ['client' => $client]
                ));
            }

            $imp   = $this->post('/api/import/comenzi', ['lines' => $lines]);
            $count = (int) ($imp['data']['importedCount'] ?? 0);

            if ($count < 1) {
                return $this->markFailed($order, $fail(
                    'Importul nu a creat comanda (importedCount=0): '.$this->errStr($imp, $imp['data']['errors'] ?? null),
                    ['client' => $client]
                ));
            }

            // 4. Marchează comanda ca trimisă — anti-duplicare + cine/ce moment.
            $order->forceFill([
                'winmentor_sync_status' => 'synced',
                'winmentor_synced_at'   => now(),
                'winmentor_client_id'   => (string) $client['id'],
                'winmentor_synced_by'   => auth()->id(),
                'winmentor_sync_error'  => null,
            ])->save();

            Log::channel('daily')->info('[ImportWooComanda] Comandă online '.$order->number.' importată în '
                .self::FIRMA.' (client '.$client['id'].', '.count($items).' articole)');

            return [
                'success'       => true,
                'error'         => null,
                'client'        => $client,
                'importedCount' => $count,
                'skipped'       => [],
                'nrComanda'     => (string) $order->number,
            ];
        } catch (\Throwable $e) {
            return $this->markFailed($order, $fail('Excepție: '.$e->getMessage()));
        } finally {
            optional($lock)->release();
        }
    }

    /** Marchează comanda ca eșuată (păstrează motivul) și întoarce rezultatul de eroare neschimbat. */
    private function markFailed(WooOrder $order, array $result): array
    {
        $order->forceFill([
            'winmentor_sync_status' => 'failed',
            'winmentor_sync_error'  => mb_substr((string) ($result['error'] ?? ''), 0, 1000),
        ])->save();

        return $result;
    }

    // ─── Client ──────────────────────────────────────────────────────────────────

    /** @return array{ok:bool, id?:string, name?:string, created?:bool, error?:string} */
    private function resolveOrCreateClient(WooOrder $order): array
    {
        $billing = (array) $order->billing;
        $fact    = $this->extractFacturare($order); // ['cui','cuiDigits','tip','nrReg'] din meta av_facturare
        $company = trim((string) ($billing['company'] ?? ''));
        $first   = trim((string) ($billing['first_name'] ?? ''));
        $last    = trim((string) ($billing['last_name'] ?? ''));

        // Persoană juridică dacă: tip=pers-jur SAU are CUI SAU are firmă în billing.
        $estePJ = $fact['tip'] === 'pers-jur' || $fact['cuiDigits'] !== '' || $company !== '';
        $name   = ($estePJ && $company !== '') ? $company : trim($first.' '.$last);
        if ($name === '') {
            $name = trim((string) $order->customer_name);
        }
        if ($name === '') {
            return ['ok' => false, 'error' => 'Comanda nu are nume de client (billing gol).'];
        }

        $phone = trim((string) ($order->customer_phone ?? $billing['phone'] ?? ''));
        $email = trim((string) ($order->customer_email ?? $billing['email'] ?? ''));

        // a) Persoană juridică cu CUI → caută după CUI (din av_facturare)
        if (strlen($fact['cuiDigits']) >= 5) {
            $found = $this->findPartenerByCui($fact['cuiDigits']);
            if ($found) {
                return ['ok' => true, 'id' => $found['idPartener'], 'name' => $found['denumire'] ?? $name, 'created' => false];
            }
        }

        // b) Caută după nume (+ confirmare telefon dacă există)
        $found = $this->findPartenerByName($name, $phone);
        if ($found) {
            return ['ok' => true, 'id' => $found['idPartener'], 'name' => $found['denumire'] ?? $name, 'created' => false];
        }

        // c) Nu există → creează (cu CUI + nr. reg. com din av_facturare)
        return $this->createClient($name, $fact['cui'], $fact['nrReg'], $billing, $phone, $email);
    }

    /**
     * Extrage datele de facturare din meta `av_facturare` (setate de checkout-ul site-ului):
     * cui, nr_reg_com, tip_facturare (pers-jur / pers-fiz).
     *
     * @return array{cui:string, cuiDigits:string, tip:string, nrReg:string}
     */
    private function extractFacturare(WooOrder $order): array
    {
        $av = [];
        foreach ((array) data_get($order->data, 'meta_data', []) as $m) {
            if (($m['key'] ?? '') === 'av_facturare' && is_array($m['value'] ?? null)) {
                $av = $m['value'];
                break;
            }
        }

        $cui = trim((string) ($av['cui'] ?? ''));

        return [
            'cui'       => $cui,                                  // ex. „RO36663535" (cum vine de pe site)
            'cuiDigits' => (string) preg_replace('/[^0-9]/', '', $cui),
            'tip'       => (string) ($av['tip_facturare'] ?? ''),
            'nrReg'     => trim((string) ($av['nr_reg_com'] ?? '')),
        ];
    }

    /** @return array{ok:bool, id?:string, name?:string, created?:bool, error?:string} */
    private function createClient(string $name, string $cui, string $nrReg, array $billing, string $phone, string $email): array
    {
        // next-id are flakiness pe COM → reîncercări
        $id = null;
        for ($i = 0; $i < 6 && ! $id; $i++) {
            $nid = $this->get('/api/parteneri/next-id');
            $id  = $nid['data']['nextId'] ?? null;
            if (! $id) {
                usleep(400_000);
            }
        }
        if (! $id) {
            return ['ok' => false, 'error' => 'Nu am putut obține un ID nou de partener din WinMentor (next-id gol).'];
        }

        $localitate = trim((string) ($billing['city'] ?? ''));
        $adresa     = trim(((string) ($billing['address_1'] ?? '')).' '.((string) ($billing['address_2'] ?? '')));
        $judet      = trim((string) ($billing['state'] ?? ''));
        $estePF     = $cui === ''; // fără CUI → persoană fizică

        $add = $this->post('/api/parteneri/add', [
            'id'              => $id,
            'denumire'        => $name,
            'codFiscal'       => $cui,                 // ex. „RO36663535"; gol pentru persoană fizică
            'nrRegCom'        => $nrReg,               // nr. registrul comerțului (din av_facturare)
            'flagPF'          => $estePF ? 'PF' : '',  // "PF" = persoană fizică; gol = juridică (confirmat live 2026-06-23)
            'localitate'      => $localitate,
            'judetSediu'      => $judet,
            'adresa'          => $adresa,
            'telefon'         => $phone,
            'persoaneContact' => $name,
            'emailSediu'      => $email,               // câmpul corect WinMentor (nu „email")
        ]);

        if (($add['success'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'Crearea clientului a eșuat: '.$this->errStr($add)];
        }

        return ['ok' => true, 'id' => (string) $id, 'name' => $name, 'created' => true];
    }

    private function findPartenerByCui(string $cui): ?array
    {
        $page = 1;
        do {
            $r = $this->get('/api/parteneri', ['page' => $page, 'pageSize' => 500]);
            if (($r['success'] ?? false) !== true) {
                return null;
            }
            foreach ($r['data']['items'] ?? [] as $it) {
                $itCui = preg_replace('/[^0-9]/', '', (string) ($it['codFiscal'] ?? ''));
                if ($itCui !== '' && $itCui === $cui) {
                    return $it;
                }
            }
            $total = (int) ($r['data']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $total && $page <= 60);

        return null;
    }

    private function findPartenerByName(string $name, string $phone): ?array
    {
        $target = $this->normalizeName($name);
        $phoneN = preg_replace('/[^0-9]/', '', $phone);
        $page   = 1;
        do {
            $r = $this->get('/api/parteneri', ['page' => $page, 'pageSize' => 500]);
            if (($r['success'] ?? false) !== true) {
                return null;
            }
            foreach ($r['data']['items'] ?? [] as $it) {
                if ($this->normalizeName((string) ($it['denumire'] ?? '')) === $target) {
                    // dacă avem telefon de ambele părți, cerem să se potrivească (evită omonime)
                    $itPhone = preg_replace('/[^0-9]/', '', (string) ($it['telefon'] ?? ''));
                    if ($phoneN === '' || $itPhone === '' || $itPhone === $phoneN) {
                        return $it;
                    }
                }
            }
            $total = (int) ($r['data']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $total && $page <= 60);

        return null;
    }

    private function normalizeName(string $n): string
    {
        $n = mb_strtolower(trim($n));
        $n = preg_replace('/[^a-z0-9\s]/u', '', $n);

        return trim(preg_replace('/\s+/', ' ', $n));
    }

    // ─── Articole ────────────────────────────────────────────────────────────────

    /**
     * @return array{0:array<int,array{cod:string,um:string,cant:string,pret:string}>, 1:array<int,string>}
     */
    private function resolveItems(WooOrder $order): array
    {
        $items   = [];
        $missing = [];

        foreach ($order->items as $it) {
            // Codul WinMentor al produsului (codExtern) — NU codul furnizorului din câmpul SKU.
            $cod = $this->resolveWinmentorCode($it);
            if ($cod === null) {
                $missing[] = $it->name.' (nu are cod WinMentor — verificați produsul în ERP)';
                continue;
            }

            // Asigură-te că articolul chiar EXISTĂ în firma WinMentor curentă.
            $art = $this->findArticol($cod);
            if (! $art) {
                $missing[] = $it->name.' [cod '.$cod.']';
                continue;
            }

            $items[] = [
                'cod'  => (string) ($art['codExtern'] ?? $cod),
                'um'   => (string) ($art['denUM'] ?? 'Buc') ?: 'Buc',
                'cant' => (string) (int) $it->quantity,
                'pret' => number_format((float) $it->price, 4, ',', ''),
            ];
        }

        return [$items, $missing];
    }

    /**
     * Rezolvă codul de articol WinMentor (codExtern) al unui produs din comandă.
     * Ordine: 1) denumirea WinMentor a produsului → snapshot articole → cod_extern;
     *         2) SKU-ul nostru, DOAR dacă e EAN (cifre) — niciodată un cod de furnizor (ex. „XGT617").
     */
    private function resolveWinmentorCode(WooOrderItem $item): ?string
    {
        $wp = $item->woo_product_id
            ? WooProduct::where('woo_id', $item->woo_product_id)->first(['sku', 'winmentor_name'])
            : null;

        if ($wp && trim((string) $wp->winmentor_name) !== '') {
            $cod = DB::table('winmentor_articles_snapshot')
                ->where('denumire', $wp->winmentor_name)
                ->value('cod_extern');
            if ($cod) {
                return (string) $cod;
            }
        }

        foreach ([$wp->sku ?? null, $item->sku] as $candidate) {
            $c = trim((string) $candidate);
            if (preg_match('/^\d{8,14}$/', $c)) {
                return $c;
            }
        }

        return null;
    }

    private function findArticol(string $code): ?array
    {
        $r = $this->get('/api/articole', ['search' => $code]);
        $items = $r['data']['items'] ?? $r['data'] ?? [];
        $needle = mb_strtolower($code);
        foreach ($items as $it) {
            foreach (['codExtern', 'codIntern', 'codExternAlt'] as $f) {
                if (mb_strtolower(trim((string) ($it[$f] ?? ''))) === $needle) {
                    return $it;
                }
            }
        }

        return null;
    }

    // ─── Construire linii COMANDA ────────────────────────────────────────────────

    /** @param array<int,array{cod:string,um:string,cant:string,pret:string}> $items */
    private function buildLines(WooOrder $order, string $codClient, array $items): array
    {
        $an    = now()->year;
        $luna  = now()->month;
        $data  = now()->format('d.m.Y'); // dată în luna curentă (deschisă pe MAL2019)
        $obs   = 'Comandă online #'.$order->number.' (WooCommerce)';

        // Serviciul de manipulare/transport — valoarea de livrare din comandă (fără TVA).
        // Articolul are UM=Lei → cantitate = valoarea în lei, preț unitar = 1.
        $allItems = $items;
        $shipping = (float) $order->shipping_total;
        if ($shipping > 0) {
            $allItems[] = [
                'cod'  => self::TRANSPORT_COD,
                'um'   => self::TRANSPORT_UM,
                'cant' => number_format($shipping, 4, ',', ''),
                'pret' => '1',
            ];
        }

        $lines = [
            '[InfoPachet]',
            'AnLucru='.$an,
            'LunaLucru='.$luna,
            'Tipdocument=COMANDA',
            'TotalComenzi=1',
            'Logon=Master',
            '',
            '[Comanda_1]',
            'NrDoc='.$order->number,
            'SimbolCarnet=',
            'Operatie=A',
            'Data='.$data,
            'CodClient='.$codClient,
            'Moneda=LEI',
            'TotalArticole='.count($allItems),
            'Observatii='.$obs,
            '',
            '[Items_1]',
        ];

        foreach ($allItems as $i => $item) {
            $lines[] = 'Item_'.($i + 1).'='.$item['cod'].';'.$item['um'].';'.$item['cant'].';'.$item['pret'].';0;'.$data.';';
        }

        return $lines;
    }

    // ─── Bridge HTTP ─────────────────────────────────────────────────────────────

    private function selectFirma(string $firma, int $an, int $luna): array
    {
        return $this->post('/api/firme/select', ['firma' => $firma, 'an' => $an, 'luna' => $luna]);
    }

    private function get(string $path, array $query = []): array
    {
        return Http::timeout(60)->withoutVerifying()->withHeaders($this->headers)
            ->get($this->base.$path, $query)->json() ?? [];
    }

    private function post(string $path, array $body): array
    {
        return Http::timeout(90)->withoutVerifying()->withHeaders($this->headers)
            ->post($this->base.$path, $body)->json() ?? [];
    }

    private function errStr(array $resp, ?array $errors = null): string
    {
        $errs = $errors ?? $resp['errors'] ?? $resp['data']['errors'] ?? [];

        return ! empty($errs) ? implode(' | ', (array) $errs) : 'eroare necunoscută';
    }
}
