# WinMentor Bridge — Plan de Integrare cu ERP Malinco

> Document de analiză și propuneri de implementare
> Bazat pe starea curentă a ERP-ului + API Reference Bridge v1.2
> Redactat: 2026-03-23

---

## Situația actuală

ERP-ul are deja o integrare **parțială** cu WinMentor, dar bazată pe **fișiere CSV exportate manual** (sau programat), nu pe API direct.

### Ce funcționează azi (prin CSV)

| Flux | Mecanism | Fișiere cheie |
|---|---|---|
| Import produse + stocuri WinMentor → ERP | `IntegrationConnection` tip `winmentor_csv`, URL CSV configurabil | `ImportWinmentorCsvAction`, `StockImportWinmentorCommand` |
| Push prețuri WinMentor → WooCommerce | Job `PushWinmentorPricesToWooJob` (după import CSV) | `PushWinmentorProductsToWooCommand` |
| Populare nume WinMentor pe produse | `PopulateWinmentorNamesCommand` (batch) | `winmentor_name` pe `WooProduct` |
| Planificare import zilnic | `DispatchScheduledWinmentorImportsCommand` (cron) | `routes/console.php` |

### Ce lipsește / ce e manual azi

- Sincronizare **în timp real** (CSV = snapshot, nu live)
- Push comenzi de achiziție (`PurchaseOrder`) → WinMentor (dublu introdus manual)
- Facturile din comenzile online (WooOrder) nu ajung automat în WinMentor
- Solduri clienți/furnizori din WinMentor nu sunt accesibile în ERP
- Parteneri (furnizori/clienți) nu se sincronizează bidirecțional

---

## Arhitectura propusă

```
┌─────────────────────────────────────────────────────┐
│                  ERP Laravel (erp.malinco.ro)        │
│                                                      │
│  WooProduct ←──────────────────── Produse sync      │
│  ProductStock ←──────────────────── Stocuri sync    │
│  Supplier ←──────────────────── Parteneri sync      │
│  PurchaseOrder ──────────────────→ Comenzi furnizori│
│  WooOrder ───────────────────────→ Facturi iesire   │
│  BI Reports ←─────────────── Vânzări / Solduri      │
└──────────────────┬──────────────────────────────────┘
                   │  HTTP + X-API-Key
                   │  (tunel VPN sau IP fix)
┌──────────────────▼──────────────────────────────────┐
│         WinMentor Bridge (Windows Server)            │
│              port 8500 / 8501                        │
└──────────────────┬──────────────────────────────────┘
                   │  COM
┌──────────────────▼──────────────────────────────────┐
│              WinMentor Classic                       │
└─────────────────────────────────────────────────────┘
```

---

## Propuneri de implementare — prioritizate

---

### 1. WinmentorBridgeClient — Service de bază

**Prioritate: FUNDAMENT — se face primul**

Înlocuiește logica CSV cu un HTTP client dedicat. Toate celelalte fluxuri folosesc acest service.

```php
// app/Services/Winmentor/WinmentorBridgeClient.php

class WinmentorBridgeClient
{
    // Citit din AppSetting:
    // KEY_WINMENTOR_BRIDGE_URL  → ex: http://192.168.1.x:8500
    // KEY_WINMENTOR_BRIDGE_KEY  → API key din appsettings.json
    // KEY_WINMENTOR_FIRMA       → numele firmei din WinMentor
    // KEY_WINMENTOR_AN          → anul contabil
    // KEY_WINMENTOR_LUNA        → luna contabilă

    public function health(): array
    public function selectFirma(): void   // POST /api/firme/select (lazy, cached)
    public function getProduse(?string $lastSync = null): array
    public function getStocuri(int $page = 1, int $pageSize = 1000): array
    public function getParteneri(string $search = '', bool $refresh = false): array
    public function importDocument(string $docType, array $lines, bool $validateOnly = false): array
    public function getSolduri(): array
    public function getVanzari(): array
}
```

**AppSetting keys noi:** `KEY_WINMENTOR_BRIDGE_URL`, `KEY_WINMENTOR_BRIDGE_KEY`, `KEY_WINMENTOR_FIRMA`, `KEY_WINMENTOR_AN`, `KEY_WINMENTOR_LUNA`

**Configurare în UI:** secțiune nouă în `AppSettingsPage` → "WinMentor Bridge"

---

### 2. Sync produse prin API (înlocuire CSV)

**Prioritate: ÎNALTĂ**

**Situație curentă:** `ImportWinmentorCsvAction` descarcă un CSV de la un URL, parsează coloane (`codextern`, `name`, `price`, `stock`), upsertează `WooProduct` + `ProductStock`.

**Propunere:** Nou provider `winmentor_bridge` pe `IntegrationConnection` care folosește `WinmentorBridgeClient` în loc de CSV.

```
GET /api/produse?lastSync={AppSetting::KEY_WINMENTOR_LAST_SYNC}
```

**Mapping câmpuri Bridge → WooProduct:**

| Bridge | WooProduct | Note |
|---|---|---|
| `idArticol` | — | cheie de match internă |
| `codIntern` | `sku` (sau câmp nou `winmentor_cod`) | identificator unic |
| `denumire` | `winmentor_name` | numele original din WinMentor |
| `denUM` | `unit` | unitate de măsură |
| `simbolClasa` | — | poate alimenta categorii |
| `dataUltimeiModificari` | — | filtru sync incremental |

**Câmp nou recomandat pe `woo_products`:** `winmentor_id` (string) — `codIntern` din WinMentor, cheia de legătură permanentă.

**Avantaj față de CSV:** sync incremental real (`lastSync`), fără dependență de export manual, ștergeri detectabile via `/api/produse/stergeri`.

---

### 3. Sync stocuri în timp real

**Prioritate: ÎNALTĂ**

**Situație curentă:** stocurile vin tot din CSV (aceeași acțiune). Se actualizează o dată pe zi sau la trigger manual.

**Propunere:** Job nou `SyncWinmentorStockJob` care:

```
GET /api/stocuri?pageSize=5000&refresh=true
```

Upsertează `ProductStock` cu `stoc - stocRezervat` = stoc disponibil real.

**Mapping câmpuri:**

| Bridge | ProductStock | Note |
|---|---|---|
| `codExtern` | `woo_product_id` (via SKU match) | |
| `stoc` | `quantity` | cantitate totală |
| `stocRezervat` | — | de scăzut din `quantity` |
| `simbolGestiune` | `location_id` | dacă gestiunile = locații ERP |
| `denFurnizor` | — | info suplimentar |

**Scheduling propus:** la fiecare 15-30 minute (nu mai există delay de o zi). Sau webhook push din Bridge dacă va fi suportat în v2.

---

### 4. Push PurchaseOrder → WinMentor (comenzi furnizori)

**Prioritate: ÎNALTĂ — elimină dubla introducere**

**Situație curentă:** când o comandă de achiziție (`PurchaseOrder`) ajunge la status `sent`, e trimisă manual prin email. Un operator o reintroduce în WinMentor.

**Propunere:** La tranziția `approved → sent`, dispatch automat `PushPurchaseOrderToWinmentorJob`:

```
POST /api/import/comenzi-furnizori
{
  "lines": [
    "{nr_comanda};{data};{cod_furnizor};{cod_articol};{cantitate};{pret_unitar};...",
    ...
  ]
}
```

**Ce trebuie rezolvat:**
- Formatul exact al liniilor pentru `comenzi-furnizori` (din proiectul Delphi sample WinMentor)
- `Supplier` trebuie să aibă `winmentor_id` (codul partenerului din WinMentor)
- `WooProduct` trebuie să aibă `winmentor_id` (codul articolului din WinMentor)
- Validare cu `validateOnly=true` înainte de import efectiv

**Flow propus:**

```
PurchaseOrder::STATUS_SENT
    → PushPurchaseOrderToWinmentorJob
        → WinmentorBridgeClient::importDocument('comenzi-furnizori', $lines, validateOnly: true)
        → dacă valid: importDocument fără validateOnly
        → salvează `winmentor_order_nr` pe PurchaseOrder
        → notificare Filament: "Comandă trimisă în WinMentor #..."
        → dacă eșec: notificare eroare + log
```

**Câmp nou recomandat pe `purchase_orders`:** `winmentor_order_nr` (string, nullable)

---

### 5. Push WooOrder → WinMentor (facturi iesire)

**Prioritate: MEDIE**

**Situație curentă:** comenzile online (WooCommerce) sunt sincronizate local în `woo_orders`, dar nu ajung automat în WinMentor ca facturi.

**Propunere:** La sincronizarea `WooSyncOrdersCommand`, comenzile cu status `completed` sau `processing` se trimit în WinMentor:

```
POST /api/import/facturi-iesire
{
  "lines": ["{partener};{data};{articol};{cantitate};{pret};..."]
}
```

**Dificultăți:**
- Clientul WooCommerce trebuie să existe ca partener în WinMentor (sau creat automat via `POST /api/parteneri/add`)
- Dacă clientul e persoană fizică (fără CUI), trebuie tratat separat în WinMentor
- De discutat cu contabilitate: se emit facturi din WinMentor sau din alt sistem?

**Recomandare:** Început cu `validateOnly=true` pentru a vedea rata de succes înainte de activare.

---

### 6. Sync parteneri bidirecțional (Supplier ↔ WinMentor)

**Prioritate: MEDIE**

**Situație curentă:** `Supplier` e populat manual sau din emailuri descoperite. Nu există legătură cu nomenclatorul de parteneri din WinMentor.

**Propunere A — Import furnizori din WinMentor → Supplier:**

```
GET /api/parteneri?pageSize=5000&search={optional}
```

Match pe `codFiscal` (CUI) sau `codExtern`. Creează/actualizează `Supplier` + `SupplierContact` din `emailSedii[]` + `telefon`.

**Câmp nou recomandat pe `suppliers`:** `winmentor_id` (string) — `idPartener` din Bridge

**Propunere B — Push furnizor nou din ERP → WinMentor:**

Când un `Supplier` nou e creat în ERP și nu există în WinMentor:
```
POST /api/parteneri/add
{ "info": "{cod};{denumire};{cui};{localitate};..." }
```

---

### 7. Solduri & Încasări → BI

**Prioritate: MEDIE-JOASĂ**

**Situație curentă:** modulul BI are metrici din WooCommerce + stocuri. Datele financiare reale (facturi, solduri, încasări) sunt doar în WinMentor.

**Propunere:** Job zilnic `SyncWinmentorFinancialsJob`:

```
GET /api/solduri            → tabel local winmentor_solduri (partener, factura, rest_de_plata, scadenta)
GET /api/incasari?...       → tabel local winmentor_incasari
GET /api/vanzari/ext        → tabel local winmentor_vanzari (luna curentă)
```

**Use cases BI deblocate:**
- Clienți cu solduri restante > X zile
- Top clienți după valoare facturată (nu doar comenzi online)
- Comparativ încasări vs. facturi emise per lună
- Alerte clienți aproape de limita de credit

---

### 8. Verificare facturi la recepție marfă

**Prioritate: JOASĂ — nice to have**

Când o comandă de achiziție e marcată ca `received` în ERP, verificăm automat dacă NIR-ul a fost operat în WinMentor:

```
GET /api/facturi-intrare/{nr_factura}/exists
→ { status: "posted" | "not_found" }
```

Afișare în `ViewPurchaseOrder`: badge "Operat în WinMentor ✓" sau "Neopereat ⚠".

---

## Rezumat priorități

| # | Flux | Effort | Impact | Prioritate |
|---|---|---|---|---|
| 1 | `WinmentorBridgeClient` service | Mic | Fundament | **Acum** |
| 2 | Sync produse via API (înlocuire CSV) | Mediu | Înalt | **Acum** |
| 3 | Sync stocuri în timp real | Mic | Înalt | **Acum** |
| 4 | Push PurchaseOrder → WinMentor | Mediu | Înalt | **Sprint 2** |
| 5 | Push WooOrder → facturi WinMentor | Mare | Mediu | **Sprint 3** |
| 6 | Sync parteneri bidirecțional | Mediu | Mediu | **Sprint 3** |
| 7 | Solduri/Încasări → BI | Mediu | Mediu | **Sprint 4** |
| 8 | Verificare NIR la recepție | Mic | Mic | **Backlog** |

---

## Cerințe tehnice comune

### Conectivitate
Bridge-ul rulează pe Windows Server local. ERP-ul e pe server Ubuntu remote. Variante:
- **VPN site-to-site** (recomandat) — ERP accesează Bridge pe IP privat
- **Reverse proxy / tunel SSH** — dacă VPN nu e disponibil
- **IP fix + firewall rule** — dacă serverul WinMentor are IP public (mai puțin sigur)

### AppSetting keys de adăugat
```php
KEY_WINMENTOR_BRIDGE_URL   = 'winmentor_bridge_url'    // ex: http://10.0.0.5:8500
KEY_WINMENTOR_BRIDGE_KEY   = 'winmentor_bridge_key'    // API key (encrypted)
KEY_WINMENTOR_FIRMA        = 'winmentor_firma'         // ex: "MALINCO SRL"
KEY_WINMENTOR_AN           = 'winmentor_an'            // ex: 2026
KEY_WINMENTOR_LUNA         = 'winmentor_luna'          // ex: 3
KEY_WINMENTOR_LAST_SYNC    = 'winmentor_last_sync'     // timestamp format dd.mm.yyyy hh:mm:ss
```

### Câmpuri noi recomandate pe modele existente
```
woo_products:      winmentor_id (string, nullable, index)
suppliers:         winmentor_id (string, nullable)
purchase_orders:   winmentor_order_nr (string, nullable)
```

### Gestionare erori
- Bridge-ul poate fi offline (WinMentor pe server Windows, restart, maintenance)
- Toate joburile de push trebuie să aibă `tries = 3` + `backoff` + notificare la eșec final
- Log separat: `storage/logs/winmentor-bridge.log`
- Buton "Retry" manual în Filament pentru comenzile eșuate

---

## Ce nu schimbăm

- Structura `IntegrationConnection` rămâne — adăugăm provider `winmentor_bridge` pe lângă `winmentor_csv`
- `ImportWinmentorCsvAction` rămâne funcțional ca fallback (dacă Bridge nu e disponibil, se poate reveni la CSV)
- `SyncRun` rămâne mecanismul de tracking pentru sincronizări
