# MentorAPI — Funcții declarate care NU funcționează (de descoperit / reparat)

_Ultima actualizare: 2026-09-15. Context: reconciliere scadențar furnizor._

## De ce contează (contextul)
Scadențarul furnizor din WinMentor (`GetSolduriFurn`) e **inconsistent cu soldul real** pentru
~38/157 furnizori (3,15 mil. lei „fantomă" — facturi deja stinse prin compensare care apar ca
deschise). Soldul agregat (`GetSoldPartener`) e corect, dar **nu avem lista corectă de facturi
neplătite** per furnizor. O rezolvăm momentan euristic (FIFO), dar am vrea sursa exactă.

**`GetSoldFactNeop` ar rezolva asta exact** — dacă am face-o să meargă.

---

## 1. GetSoldFactNeop ⭐ (PRIORITAR — ne-ar da facturile neplătite exacte)
- **Ce ar trebui să facă:** returnează facturile neplătite (sold neoperat) ale unui partener.
- **Status:** RUPTĂ. 
  - Cu param string (partId) → `HRESULT 0x8000FFFF` / „The server threw an exception".
  - Cu param **int** → **RESETEAZĂ bridge-ul COM** (connection reset + timeout; revine în ~6s prin auto-reconnect). ⚠️ **PERICULOS în producție.**
- **Implementare curentă:** `libWMEdcom/winmentor/queries.go:278` — `vtblCall("GetSoldFactNeop", partID, &errParam int32, &resPtr BSTR)`.
- **Documentație:** Rev.1.5 (22.05.2026, ULTIMA) o listează DOAR ca nume în anexă (pag. 15), **fără semnătură/parametri**. → nu știm câți/ce parametri de intrare cere.
- **Ipoteze de testat (LA NOAPTE, cu sync ERP OPRIT):**
  1. Necesită context: `SetIDPartField('CodIntern')` + `SetLunaLucru(an,luna)` ÎNAINTE (testat parțial cu SetIDPartField — tot excepție).
  2. Semnătură diferită: poate cere `(partID, an, luna)` sau `(an, luna, partID)` sau o dată de referință.
  3. Poate returnează OleVariant (listă), nu BSTR — de verificat calling convention (ca `GetSolduriExt`).
  4. Poate cere `SetDocsData` / `SetDocsRefData` înainte.
- **⚠️ NU proba cu param int prin `/api/com/call`** — crapă bridge-ul.
- **Deblocare sigură:** semnătura oficială de la suport Intelsoft (WinMentor).

## 2. GetReceivingStatus
- Status: nefuncțională (TODO MentorAPI „importuri blocate"). Nedocumentată detaliat.
- Nefolosită de ERP momentan. Prioritate mică.

## 3. GetListaCarneteExt (GetListacarneteExt)
- Status: nefuncțională. Există `GetListaCarnete` (simplă) care merge.
- Nefolosită momentan. Prioritate mică.

## 4. SetInventoryOrders
- Status: nefuncțională (setter). Nedocumentat.
- Nefolosită momentan. Prioritate mică.

---

## Plan de teste pentru LA NOAPTE (sigur)
> ⚠️ Bridge-ul COM e stare globală + în producție. Testează DOAR cu:
> - sync ERP oprit (Horizon pauză / fereastra nocturnă),
> - pe un partener de test,
> - revenire la firma MAL2019 după (vezi `feedback_mentorapi_firma_global_state`).

**Apel generic:** `POST http://82.79.74.132:9500/api/com/call` cu header `X-API-Key`, body:
```json
{ "method": "GetSoldFactNeop", "params": ["728016740"] }
```

Din ERP (tinker), reflectând `post()` privat:
```php
$b = app(\App\Services\Winmentor\WinmentorBridgeClient::class);
$ref = new \ReflectionMethod($b,'post'); $ref->setAccessible(true);
$b->getSoldPartener('728016740'); // setup firma + SetIDPartField(CodIntern)
$ref->invoke($b,'/api/com/call',['method'=>'GetSoldFactNeop','params'=>['728016740']],[],40);
```

**Combinații de încercat** (fiecare cu bridge sănătos înainte — verifică `isReachable()`):
- `params: []`
- `params: ["728016740"]` (CodIntern)
- `params: ["728016740", 2026, 9]`
- `params: [2026, 9, "728016740"]`
- după `SetLunaLucru(2026,9)` apoi `["728016740"]`
- ❌ NU `params: [728016740]` (int simplu — a crăpat bridge-ul)

**De observat:** ce eroare exactă dă (`GetListaErori`), dacă schimbă calling convention rezolvă.

---

## Referință rapidă — ce MERGE deja (pt comparație)
| Funcție | Status | Note |
|---|---|---|
| `GetSoldPartener` (GetSoldPart) | ✅ | agregat corect: `CodExtern;Denumire;Sold` |
| `GetSoldDetaliat` | ✅ (doar CLIENT) | facturi+avansuri client; 0 pe furnizor pur |
| `GetSolduriFurn` | ✅ dar NESIGUR | detaliu furnizor; conține facturi stinse (fantomă) |
| `GetPlatiFactura(an,luna FACTURII,nrFact,serie,partId)` | ✅ | plăți bancare per factură (read-only) |
| `GetSolduriExt` | ✅ | detaliu client (facturi+avansuri) |

---

## Email de trimis la suport Intelsoft (WinMentor)
Subiect: DocImpServer — semnătura funcției `GetSoldFactNeop`

> Bună ziua,
> Folosim DocImpServer (Rev.1.5) pentru interfațare. Funcția `GetSoldFactNeop` apare în lista
> din anexă dar fără semnătură. La apel ne dă `HRESULT 0x8000FFFF` („server threw an exception").
> Ne puteți spune: (1) semnătura completă (parametri de intrare + tip return), (2) dacă necesită
> un context prealabil (SetLunaLucru / SetIDPartField / SetDocsData), (3) ce returnează exact
> (structura liniilor)? Scopul: lista facturilor neplătite per furnizor.
> Mulțumim.

---

## Fișiere relevante în cod
- `mentorapi/libWMEdcom/winmentor/queries.go:278` — GetSoldFactNeop (de corectat semnătura)
- `mentorapi/api/financiar.go:193` — handler `/api/solduri/facturi-neoperate`
- `mentorapi/api/router.go` — rute
- `mentorapi/doc-oficial/Functii-interfatare-WinMENTOR-Rev1.5-2026-05.pdf` — doc oficial (ULTIMA)
- Reconciliere ERP: `app/Console/Commands/ReconcileSupplier{Auto,Fifo}Command.php`, `winmentor:reconcile-solduri`
- Build MentorAPI: `cd mentorapi && GOOS=windows GOARCH=386 go build -ldflags="-s -w" -o mentorapi.exe .` → redeploy pe PC Windows
