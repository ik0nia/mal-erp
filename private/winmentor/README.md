# WinMentor Bridge — Pre-documentație integrare ERP

> Versiunea Bridge: **1.2** (Martie 2026)
> Documentat: 2026-03-23

---

## Ce este WinMentor Bridge

Un micro-connector — un singur `.exe` self-contained (fără dependențe .NET, ~92MB) care rulează pe **serverul Windows** unde este instalat WinMentor Classic. Se conectează la interfața COM a WinMentor (`DocImpServer.DocImpObject`) și expune **90+ endpoint-uri REST JSON** accesibile din exterior prin HTTP/HTTPS.

```
ERP Laravel (erp.malinco.ro)
        ↕  HTTP/HTTPS + X-API-Key
WinMentor Bridge (.exe, port 8500/8501)
        ↕  COM (DocImpServer)
WinMentor Classic (Windows Server)
```

---

## Instalare & configurare (server Windows)

### Fișiere necesare
```
C:\WinMENT\WinMentorBridge\
    WinMentorBridge.exe       # aplicația (self-contained)
    appsettings.json          # configurare
```

### `appsettings.json` — câmpuri importante
| Parametru | Valoare default | Descriere |
|---|---|---|
| `Port` | `8500` | Port HTTP |
| `HttpsPort` | `8501` | Port HTTPS (`null` = dezactivat) |
| `BindAddress` | `0.0.0.0` | IP pe care ascultă |
| `ApiKey` | auto-generat | Cheia de autentificare (litere+cifre) |
| `ComProgId` | `DocImpServer.DocImpObject` | ProgID COM WinMentor |
| `ComIdleTimeoutSeconds` | `300` | Timeout inactivitate COM |
| `CacheDurationMinutes` | `5` | Durata cache în memorie |

### Rulare ca Windows Service
```bat
sc create WinMentorBridge binPath="C:\WinMENT\WinMentorBridge\WinMentorBridge.exe" start=auto
sc start WinMentorBridge
```

---

## Autentificare

Toate endpoint-urile (excepție: `/api/health`) necesită API Key:
```
Header:  X-API-Key: <cheia>
  sau
Query:   ?api_key=<cheia>
```

API Key-ul se găsește în `appsettings.json` pe serverul Windows după prima pornire.

---

## Format răspuns — uniform pentru toate endpoint-urile

```json
{
  "success": true,
  "data": { ... },
  "errors": [],
  "timestamp": "2026-03-23T10:00:00Z"
}
```

În caz de eroare: `success: false`, `data: null`, `errors: ["mesaj"]`.

Unele endpoint-uri returnează **raw `string[][]`** (date nestructurate direct din COM) — câmpurile depind de configurația WinMentor.

---

## Prerequisit esențial: selectarea firmei și lunii contabile

**Înainte de orice request pe date**, trebuie selectată firma și luna:

```http
POST /api/firme/select
Content-Type: application/json

{ "firma": "NUMELE FIRMEI DIN WINMENTOR", "an": 2026, "luna": 3 }
```

Bridge-ul reține această stare în memorie și o reapliclă automat la reconectare COM. La schimbarea firmei/lunii, cache-ul se invalidează automat.

---

## Endpoint-uri — referință rapidă

### Health & Diagnosticare
| Metodă | Endpoint | Auth | Descriere |
|---|---|---|---|
| GET | `/api/health` | NU | Status serviciu, comConnected |
| GET | `/api/diagnostics` | NU | Verificare COM în Registry |
| GET | `/api/versiuni` | DA | Versiuni WinMentor + DocImpServer |
| GET | `/api/erori` | DA | Buffer erori sesiune COM curentă |

### Firme
| Metodă | Endpoint | Descriere |
|---|---|---|
| GET | `/api/firme` | Lista firmelor din WinMentor |
| GET | `/api/firme/{numeFirma}/luni` | Lunile disponibile pentru firmă |
| POST | `/api/firme/select` | **Selectează firma + luna** (obligatoriu) |

### Produse & Articole
| Metodă | Endpoint | Descriere |
|---|---|---|
| GET | `/api/produse` | Lista produse; `?lastSync=dd.mm.yyyy hh:mm:ss` pentru sync incremental |
| GET | `/api/produse/stergeri` | Produse șterse de la `lastSync` |
| GET | `/api/articole` | Nomenclator articole (paginat, căutare, cache) |
| GET | `/api/clase-articole` | Clase articole |
| GET | `/api/categorii-pret` | Categorii de preț |
| GET | `/api/preturi/{artId}/{partId}` | Prețul unui articol pentru un partener |
| POST | `/api/produse/add` | Adaugă produs |
| PUT | `/api/produse/update` | Modifică produs |

**Câmpuri produse (sync incremental):** `idArticol`, `denumire`, `denUM`, `idProducator`, `denProducator`, `codIntern`, `simbolClasa`, `dataUltimeiModificari`

**Câmpuri articole (nomenclator):** `codExtern`, `denumire`, `denUM`, `pretVanzare`, `pretVCuTVA`, `cotaTVA`, `simbolClasa`, `denProducator`, `masa`, `codVamal`

### Stocuri
| Metodă | Endpoint | Descriere |
|---|---|---|
| GET | `/api/stocuri` | Stoc articole (paginat, căutare, cache) |
| GET | `/api/stocuri/{artId}` | Stoc pentru un articol specific |
| GET | `/api/stocuri/{artId}/detaliat` | Detalii pe loturi/serii |
| GET | `/api/stocuri/pe-gestiuni` | Stoc detaliat pe gestiuni |

**Câmpuri stocuri:** `codExtern`, `denumire`, `stoc`, `stocRezervat`, `pretVanzare`, `pretCuTVA`, `cotaTVA`, `simbolGestiune`, `denFurnizor`

### Parteneri (Furnizori / Clienți)
| Metodă | Endpoint | Descriere |
|---|---|---|
| GET | `/api/parteneri` | Lista parteneri (paginat, căutare, cache) |
| GET | `/api/parteneri/{partId}/info` | Info complete partener (string `;`-separat) |
| POST | `/api/parteneri/add` | Adaugă partener nou |
| PUT | `/api/parteneri/update` | Modifică partener existent |
| GET | `/api/parteneri/next-id` | Următorul ID disponibil |

**Câmpuri parteneri:** `idPartener`, `denumire`, `codFiscal`, `localitate`, `adresa`, `telefon`, `persoanaContact`, `codExtern`, `emailSedii[]`, `contBanca`, `discount`, `scadenta`, `partenerBlocat`

**Identificare partener** — configurabilă prin `/api/config/id-part-field`: `CodIntern` (default), `CodExtern`, sau `CodFiscal`.

### Vânzări & Facturi
| Metodă | Endpoint | Descriere |
|---|---|---|
| GET | `/api/vanzari` | Facturi vânzare luna curentă |
| GET | `/api/vanzari/ext` | Vânzări cu 18 câmpuri extinse |
| GET | `/api/vanzari/articole-vandute` | Articole vândute per partener (`?partId&marcaAgent&anInceput&lunaInceput`) |
| GET | `/api/facturi/{numar}/exists` | Verifică existența facturii (`"posted"/"not_found"`) |
| GET | `/api/facturi/numar/{simbolCarnet}` | Numărul următor disponibil pe carnet |
| GET | `/api/facturi/pot-introduce` | Verifică dacă se pot introduce documente în luna curentă |

### Comenzi
| Metodă | Endpoint | Descriere |
|---|---|---|
| GET | `/api/comenzi/nefacturate` | Comenzi nefacturate luna curentă |
| GET | `/api/comenzi/furnizori` | Comenzi către furnizori |

### Solduri & Încasări
| Metodă | Endpoint | Descriere |
|---|---|---|
| GET | `/api/solduri` | Solduri toți clienții (facturi + avansuri) |
| GET | `/api/solduri/{partId}` | Sold un partener |
| GET | `/api/solduri/{partId}/detaliat` | Sold detaliat (facturi + avansuri) |
| GET | `/api/solduri/furnizori` | Solduri furnizori |
| GET | `/api/incasari` | Încasări interval (`?an1&luna1&an2&luna2&partId`) |
| GET | `/api/incasari/luna` | Încasări luna curentă |

### Import Documente
**Pipeline automat:** `SetDocsData → Validare → Import`

```http
POST /api/import/{docType}?validateOnly=false
Content-Type: application/json

{ "lines": ["camp1;camp2;camp3;...", "camp1;camp2;..."] }
```

Răspuns: `{ isValid, importedCount, errors[], warnings[] }`

| `docType` | Descriere |
|---|---|
| `facturi-iesire` | Facturi vânzare |
| `facturi-intrare` | Facturi cumpărare / NIR |
| `comenzi` | Comenzi interne |
| `comenzi-furnizori` | Comenzi către furnizori |
| `incasari` | Încasări |
| `plati` | Plăți |
| `bonuri-consum` | Bonuri consum intern |
| `transferuri` | Transferuri inter-gestiuni |
| `note-contabile` | Note contabile |
| `modificari-pret` | Modificări de preț |

> **Important:** formatul exact al liniilor (câmpuri, ordine) este documentat în proiectul Delphi sample al WinMentor — nu în acest API Reference.

### Nomenclatoare
`GET /api/gestiuni`, `/api/personal`, `/api/clase-parteneri`, `/api/banci`, `/api/carnete`, `/api/oferte`, `/api/localitati`, `/api/clienti`, `/api/monede`, `/api/delegati`, `/api/subunitati`

### Discount
`GET /api/discount/pe-articole`, `/api/discount/pe-clase`, `/api/discount/pe-parteneri`

---

## Paginare, Căutare, Cache

Endpoint-urile `/api/parteneri`, `/api/articole`, `/api/stocuri` suportă:

| Parametru | Descriere |
|---|---|
| `page` | Numărul paginii (default: 1) |
| `pageSize` | Înregistrări per pagină (default: 100, max: 5000) |
| `search` | Căutare case-insensitive în câmpurile cheie |
| `refresh=true` | Ignoră cache-ul, citește fresh din COM |

Prima cerere → citit din COM (câteva secunde). Cererile următoare → din cache (instant). Cache expiră după `CacheDurationMinutes` sau la `POST /api/firme/select`.

---

## Identificatori — configurare importantă

Înainte de orice operațiune CRUD sau import, trebuie setat ce câmp e folosit ca ID:

```http
POST /api/config/id-art-field
{ "fieldName": "CodExtern" }   # CodExtern | CodIntern

POST /api/config/id-part-field
{ "fieldName": "CodExtern" }   # CodExtern | CodFiscal | CodIntern
```

**Recomandare:** `CodExtern` dacă ERP-ul nostru are propriile coduri (ex: SKU WooCommerce). Setarea se face o singură dată per sesiune.

---

## Integrare cu ERP Malinco — puncte de conectare

### 1. Sync produse → `WooProduct`
```
GET /api/produse?lastSync={ultima_sincronizare}
```
- Sync incremental pe baza `dataUltimeiModificari`
- Câmpul `codIntern` din WinMentor = identificatorul de legătură cu `WooProduct`
- Ștergeri separate prin `/api/produse/stergeri`

### 2. Sync stocuri → `ProductStock`
```
GET /api/stocuri?pageSize=5000
```
- Câmpul `stoc` − `stocRezervat` = stoc disponibil real
- Suportă filtrare pe gestiune via `/api/stocuri/pe-gestiuni`

### 3. Parteneri/Furnizori → `Supplier`
```
GET /api/parteneri?search={termen}&pageSize=5000
```
- `emailSedii[]` → mapează pe `SupplierContact`
- `codFiscal` → identificator unic pentru deduplicare

### 4. Push comenzi achiziție → WinMentor
```
POST /api/import/comenzi-furnizori
{ "lines": ["{formatWinMentor}"] }
```
- `PurchaseOrder` status `sent` → push spre WinMentor
- Formatul liniilor din proiectul Delphi sample (de obținut de la furnizorul WinMentor)

### 5. Pull vânzări → BI
```
GET /api/vanzari/ext               # luna curentă
GET /api/vanzari/articole-vandute  # per partener, interval
```

### 6. Solduri clienți → rapoarte
```
GET /api/solduri
GET /api/solduri/{partId}/detaliat
```

---

## Configurare în `AppSetting`

Se recomandă stocarea în DB (model `AppSetting`) a:
- `KEY_WINMENTOR_BRIDGE_URL` — ex: `http://192.168.1.x:8500`
- `KEY_WINMENTOR_BRIDGE_API_KEY` — cheia generată pe server
- `KEY_WINMENTOR_FIRMA` — numele firmei exact din WinMentor
- `KEY_WINMENTOR_LAST_SYNC` — timestamp ultima sincronizare produse

---

## Note tehnice

- Bridge-ul rulează **doar pe Windows** (COM dependency)
- Conexiunea COM e **lazy** — se deschide la primul request care o necesită
- La idle > 300s, COM-ul se deconectează automat; la next request se reconectează și reaplică firma/luna
- HTTPS self-signed implicit (cert valid 2 ani) — în Postman dezactivat SSL verification
- `BindAddress: "127.0.0.1"` pentru securitate dacă ERP-ul are acces prin tunel/VPN

---

## Fișiere în acest folder

| Fișier | Descriere |
|---|---|
| `WinMentorBridge-API-Reference-v1.2.docx` | Referință completă toate endpoint-urile, parametri, tipuri, exemple |
| `WinMentorBridge-Tutorial-v1.2.docx` | Ghid instalare, configurare, testare Postman, troubleshooting |
| `README.md` | Acest fișier — pre-documentație integrare ERP |
