# API Endpoints

Complete reference for all 195 MentorAPI endpoints.

## Authentication

All endpoints (except `/api/health` and `/api/diagnostics`) require the `X-API-Key` header.

## Setup — Select Company

Before querying data, select a company and work month:

```bash
POST /api/firme/select
{"firma": "COMPANY_NAME", "an": 2026, "luna": 5}
```

---

## GET Endpoints (111)

### System (6)

| Endpoint | Description |
|----------|-------------|
| `GET /api/health` | Health check (no auth) — returns version, uptime, COM status |
| `GET /api/diagnostics` | COM diagnostics (no auth) |
| `GET /api/erori` | Last WinMentor errors |
| `GET /api/versiuni` | DLL versions |
| `GET /api/constante?name=X` | WinMentor constants |
| `GET /api/com/methods` | List available COM methods |

### Companies (2)

| Endpoint | Description |
|----------|-------------|
| `GET /api/firme` | List all companies |
| `GET /api/firme/{name}/luni` | Available months for a company |

### Articles & Products (6)

| Endpoint | Description |
|----------|-------------|
| `GET /api/articole` | Product catalog (paginated, 28+ fields) |
| `GET /api/articole/clase` | Article classes |
| `GET /api/produse` | Products with sync timestamp |
| `GET /api/produse/stergeri` | Deleted products since last sync |
| `GET /api/atribute/valori` | Attribute values |
| `GET /api/retete` | Active recipes |

### Stock (12)

| Endpoint | Description |
|----------|-------------|
| `GET /api/stocuri` | Stock per article (paginated, 20+ fields) |
| `GET /api/stocuri/ext` | Extended stock (supplier, producer info) |
| `GET /api/stocuri/ext2?gestiune=X` | Extended stock v2 per warehouse (**requires gestiune**) |
| `GET /api/stocuri/ext3` | Extended stock v3 |
| `GET /api/stocuri/ext4` | Extended stock v4 |
| `GET /api/stocuri/ext5` | Extended stock v5 |
| `GET /api/stocuri/pe-gestiuni` | Stock grouped by warehouse |
| `GET /api/stocuri/articol/{id}` | Stock for specific article |
| `GET /api/stocuri/articol/{id}/detaliat` | Detailed stock per lot/entry |
| `GET /api/stocuri/ambalaje/initial` | Initial packaging stock |
| `GET /api/stocuri/ambalaje/miscari` | Packaging movements |
| `GET /api/reziduale` | Residual stock |

### Partners (8)

| Endpoint | Description |
|----------|-------------|
| `GET /api/parteneri` | All partners (paginated, 42+ fields) |
| `GET /api/parteneri/clase` | Partner classes |
| `GET /api/parteneri/next-id` | Next available partner ID |
| `GET /api/parteneri/{id}/info` | Detailed partner info |
| `GET /api/clienti` | Client list |
| `GET /api/localitati` | Localities |
| `GET /api/nomenclator-localitati` | Full locality catalog |
| `GET /api/delegati` | Delegates/drivers |

### Sales (8)

| Endpoint | Description |
|----------|-------------|
| `GET /api/vanzari/ext` | Extended sales (18 fields — invoice, article, price, warehouse, agent) |
| `GET /api/vanzari/luna` | Monthly sales (23 fields — full invoice details) |
| `GET /api/vanzari/emulare` | Cash register emulation (all receipts, 21 fields) |
| `GET /api/vanzari/emulare/nedescarcata` | Undownloaded cash register entries |
| `GET /api/vanzari/articole-vandute` | Articles sold per partner (requires SetArtAnalizat) |
| `GET /api/vanzari/ultimele` | Last N sales of an article |
| `GET /api/vanzari/istoric` | Sales history count |
| `GET /api/vanzari/istoric/all` | Sales history with record iteration |

### Outgoing / Receipts (5)

| Endpoint | Description |
|----------|-------------|
| `GET /api/iesiri/info?zi=X` | Outgoing documents for a day |
| `GET /api/iesiri/info-ext?nrDoc=X&zi=X` | Line details for an outgoing document |
| `GET /api/bonuri/info?zi=X` | Receipts list for a day |
| `GET /api/bonuri/info-ext?nrBon=X&zi=X` | Receipt line details |
| `GET /api/receptii/subunitati` | Sub-unit receptions |

### Invoices & Entries (10)

| Endpoint | Description |
|----------|-------------|
| `GET /api/intrari` | Incoming entries (purchases) |
| `GET /api/receptii` | Receptions (NIR) — 21+ fields |
| `GET /api/receptii/neoperate` | Unprocessed receptions |
| `GET /api/nir/atribute` | NIR attributes |
| `GET /api/carnete` | Invoice booklets |
| `GET /api/carnete/ext` | Extended booklets |
| `GET /api/facturi/exists/{nr}` | Check if invoice exists |
| `GET /api/facturi/ext/{prefix}/{nr}/exists` | Check invoice with prefix |
| `GET /api/facturi/intrare/exists` | Check incoming invoice |
| `GET /api/facturi/numar/{carnet}` | Next invoice number for booklet |

### Orders (12)

| Endpoint | Description |
|----------|-------------|
| `GET /api/comenzi/furnizori` | Supplier orders |
| `GET /api/comenzi/nefacturate` | Uninvoiced orders (18 fields) |
| `GET /api/comenzi/nefacturate/info` | Uninvoiced order details |
| `GET /api/comenzi/info` | Active orders (9 fields) |
| `GET /api/comenzi/stadiu` | Order status |
| `GET /api/comenzi/interne/articole` | Internal order articles |
| `GET /api/comenzi/interne/materiale` | Internal order materials |
| `GET /api/comenzi/sub-nefacturate/info` | Sub-unit uninvoiced |
| `GET /api/comenzi/subunitati-active` | Active sub-units |
| `GET /api/comenzi/bon/info?nrCmd=X` | Receipt linked to order |
| `GET /api/comenzi/bonuri/info?zi=X` | Order receipts for a day |
| `GET /api/transferuri` | Warehouse transfers |

### Financial (14)

| Endpoint | Description |
|----------|-------------|
| `GET /api/solduri` | Client balances (10+ fields) |
| `GET /api/solduri/ext` | Extended balances (12+ fields) |
| `GET /api/solduri/furnizori` | Supplier balances |
| `GET /api/solduri/facturi-neoperate` | Unprocessed invoice balances |
| `GET /api/solduri/partener/{id}` | Balance for specific partner |
| `GET /api/solduri/partener/{id}/detaliat` | Detailed balance per invoice |
| `GET /api/incasari` | Collections summary |
| `GET /api/incasari/luna` | Monthly collections |
| `GET /api/incasari/ext` | Extended collections (with invoice details) |
| `GET /api/incasari/factura` | Collections per invoice |
| `GET /api/plati/luna` | Monthly payments |
| `GET /api/plati/factura` | Payments per invoice |
| `GET /api/preturi/vanzare` | Sale price (requires SetArtAnalizat) |
| `GET /api/preturi/categorii` | Price categories |

### Prices & Discounts (8)

| Endpoint | Description |
|----------|-------------|
| `GET /api/preturi/articole-categorii` | Article price categories |
| `GET /api/preturi/articole-categorii/ext` | Extended article price categories |
| `GET /api/preturi/articol-categorii2` | Article categories v2 |
| `GET /api/discount/pe-articole` | Discounts per article |
| `GET /api/discount/pe-clase` | Discounts per class |
| `GET /api/discount/pe-parteneri` | Discounts per partner |
| `GET /api/discount/articole` | Article discount criteria |
| `GET /api/discount/intervale` | Discount intervals |

### Offers (2)

| Endpoint | Description |
|----------|-------------|
| `GET /api/oferte` | Offers |
| `GET /api/oferte/clienti` | Client-specific offers |

### Lookup tables (7)

| Endpoint | Description |
|----------|-------------|
| `GET /api/gestiuni` | Warehouses |
| `GET /api/personal` | Employees |
| `GET /api/personal/{id}` | Employee details |
| `GET /api/banci` | Banks |
| `GET /api/monede` | Currencies |
| `GET /api/subunitati` | Sub-units |
| `GET /api/unitati-masura` | Units of measure |

### Logistics (6)

| Endpoint | Description |
|----------|-------------|
| `GET /api/livrari/dispozitii?gestiune=X` | Delivery orders (**requires gestiune**) |
| `GET /api/livrari/orders` | Delivery order details |
| `GET /api/inventory/orders?gestiune=X` | Inventory orders (**requires gestiune**) |
| `GET /api/receiving/status` | Receiving status |
| `GET /api/system/tranzactii-in-curs` | Transactions in progress |
| `GET /api/system/pot-introduce-doc` | Can introduce document check |

---

## POST Endpoints (80)

### Configuration (11)

| Endpoint | Description |
|----------|-------------|
| `POST /api/firme/select` | Select company + work month |
| `POST /api/config/id-art-field` | Set article ID field (CodExtern/CodIntern) |
| `POST /api/config/id-part-field` | Set partner ID field |
| `POST /api/config/art-analizat` | Set article for analysis |
| `POST /api/config/cat-pret-implicita` | Set default price category |
| `POST /api/config/cod-cmd-analizata` | Set order for analysis |
| `POST /api/config/data-referinta` | Set reference date |
| `POST /api/config/index-nart` | Set article index |
| `POST /api/config/cant-receptii` | Set reception quantities |
| `POST /api/config/filtru-doc-neoperate` | Set unprocessed doc filter |
| `POST /api/config/inclusiv-fact-aviz` | Include invoices/notices flag |

### Create/Modify (7)

| Endpoint | Description |
|----------|-------------|
| `POST /api/produse/add` | Add product (19 fields, semicolon-separated) |
| `PUT /api/produse/update` | Update product (7 fields, ModiProduct) |
| `POST /api/produse/add-json` | Add product (structured JSON) |
| `PUT /api/produse/update-json` | Update product (structured JSON) |
| `POST /api/parteneri/add` | Add partner (35 fields) |
| `PUT /api/parteneri/update` | Update partner |
| `POST /api/gestiuni/add` | Add warehouse |

### Structured Import — JSON (16)

Modern endpoints that accept JSON and convert to INI format automatically:

| Endpoint | Description |
|----------|-------------|
| `POST /api/import-doc/comenzi-furnizori/validate` | Validate supplier order |
| `POST /api/import-doc/comenzi-furnizori/import` | Import supplier order |
| `POST /api/import-doc/facturi-intrare/validate` | Validate incoming invoice |
| `POST /api/import-doc/facturi-intrare/import` | Import incoming invoice |
| `POST /api/import-doc/facturi-iesire/validate` | Validate outgoing invoice |
| `POST /api/import-doc/facturi-iesire/import` | Import outgoing invoice |
| `POST /api/import-doc/comenzi/validate` | Validate client order |
| `POST /api/import-doc/comenzi/import` | Import client order |
| `POST /api/import-doc/transferuri/validate` | Validate transfer |
| `POST /api/import-doc/transferuri/import` | Import transfer |
| `POST /api/import-doc/bonuri-consum/validate` | Validate consumption note |
| `POST /api/import-doc/bonuri-consum/import` | Import consumption note |
| `POST /api/import-doc/modificari-pret/validate` | Validate price change |
| `POST /api/import-doc/modificari-pret/import` | Import price change |
| `POST /api/import-doc/reglare-inventar/validate` | Validate inventory adjustment |
| `POST /api/import-doc/reglare-inventar/import` | Import inventory adjustment |

### Legacy Import — INI lines (28)

Older endpoints that accept raw INI `lines[]`. Same functionality as structured import but requires manual INI formatting:

| Endpoint | Status |
|----------|--------|
| `POST /api/import/comenzi-furnizori/*` | Working |
| `POST /api/import/facturi-intrare/*` | Working |
| `POST /api/import/facturi-iesire/*` | Working |
| `POST /api/import/comenzi/*` | Working |
| `POST /api/import/comenzi-ext/*` | Working |
| `POST /api/import/transferuri/*` | Working |
| `POST /api/import/bonuri-consum/*` | Working |
| `POST /api/import/modificari-pret/*` | Working |
| `POST /api/import/reglare-inventar/*` | Working |
| `POST /api/import/incasari/*` | Blocked (DLL error 506) |
| `POST /api/import/plati/*` | Blocked (DLL error 506) |
| `POST /api/import/note-contabile/*` | Blocked (DLL error 122) |
| `POST /api/import/monetare/*` | Blocked (DLL error 502) |
| `POST /api/import/{docType}` | Generic import |

### Previews (4)

| Endpoint | Description |
|----------|-------------|
| `POST /api/produse/add-json/preview` | Preview AddProduct string |
| `POST /api/produse/update-json/preview` | Preview ModiProduct string |
| `POST /api/import-doc/{docType}/preview` | Preview INI lines |
| `POST /api/system/doc-from-file` | Parse INI file |

### Other (14)

| Endpoint | Description |
|----------|-------------|
| `POST /api/articole/gen-cod` | Generate article codes |
| `POST /api/articole/set-clasa` | Set article class |
| `POST /api/articole/add-clasa` | Add article class |
| `PUT /api/articole/modifica` | Modify article |
| `POST /api/parteneri/gen-cod` | Generate partner codes |
| `POST /api/comenzi/set-acceptat` | Set order accepted flag |
| `POST /api/comenzi/actualizeaza-acceptat` | Update accepted orders |
| `POST /api/comenzi/set-inclusiv-furn` | Include supplier orders flag |
| `POST /api/receiving/set-list` | Set receiving list |
| `POST /api/inventory/set-orders` | Set inventory orders |
| `POST /api/livrari/set-picked` | Set picked list |
| `POST /api/livrari/set-delivery` | Set delivery list |
| `POST /api/system/descarcare-automata` | Auto-download flag |
| `POST /api/auth/logon` | COM authentication |
| `POST /api/com/call` | Generic COM method call |

---

## Unknown Fields

Some DLL functions return fields whose meaning is not documented. These are exposed as `campX` (where X is the position index) with `omitempty` — they only appear in the response if non-empty. This ensures forward compatibility: if WinMentor starts populating these fields in the future, the data will be visible immediately.

Example: `"camp8": "some_value"` means position 8 of the DLL response contains data we haven't identified yet.
