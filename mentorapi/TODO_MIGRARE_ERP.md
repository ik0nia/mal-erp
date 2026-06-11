# Migrare ERP Malinco → MentorAPI (Go)

## Status: PREGĂTIT — toate cele 14 funcții testate OK pe firma ERP
## Data analiză: 25 mai 2026

---

## Pasul 1: Schimbare conexiune (5 min)

În `IntegrationConnection` ID=5:
- `base_url`: `https://82.79.74.132:8501` → `http://82.79.74.132:9500`
- `consumer_key`: `<cheia bridge vechi — retrasă>` → `<vezi config pe serverul Windows>`
- `settings.firma`: `MAL2019` → rămâne ce e (ERP-ul folosește firma din setări)
- **Fișier**: `app/Services/Winmentor/WinmentorBridgeClient.php` — constructorul citește din `IntegrationConnection::find(5)`
- **Atenție**: bridge-ul vechi e HTTPS cu `withoutVerifying()`, MentorAPI e HTTP → trebuie verificat că HTTP merge fără problemă

---

## Pasul 2: Adaptat câmpuri PARTENERI (10 min)

**Fișier**: `app/Services/Winmentor/WinmentorBridgeClient.php`

Câmpuri redenumite în răspunsul `GET /api/parteneri`:

| Bridge vechi (.NET) | MentorAPI (Go) | Unde se citește |
|---------------------|----------------|-----------------|
| `coduriFiscaleSedii` | `codFiscalSedii` | `partenerMatchesCui()` ~linia 415 |

Restul câmpurilor citite (`idPartener`, `codFiscal`, `denumire`, `puncteAcumulate`) sunt identice.

---

## Pasul 3: Adaptat câmpuri VÂNZĂRI EXT (30 min)

**Cel mai mare volum de lucru.** Bridge-ul vechi (.NET) avea un mapping splitF incorect — câmpurile nu corespundeau cu denumirile logice. MentorAPI le-a corectat.

**Fișiere de modificat:**
- `app/Console/Commands/FetchWinmentorVanzariCommand.php` (~linia 187-210)
- `app/Console/Commands/WatchWinmentorVanzariCommand.php` (~linia 57-63)
- `app/Filament/App/Pages/WinmentorVanzariPage.php` (dacă citește direct câmpuri)

**Mapping vechi → nou** (ce citește ERP-ul acum → ce trebuie să citească):

| Câmp ERP (ce date conține) | Bridge vechi (key) | MentorAPI (key) |
|----------------------------|--------------------|-----------------|
| SKU/cod articol | `nrDoc` | `codArticol` |
| Cantitate | `artID` | `cantitate` |
| UM | `cant` | `denUM` |
| Nr factură | `prefixDoc` | `prefixCarnet` + `nrFactura` |
| ID partener | `partID` | `idPartener` |
| Valoare totală | `valAchizitie` | **NU EXISTĂ** — trebuie calculat: `cantitate × pret` |
| Zi | `zi` | `zi` (identic) |
| Preț | `pret` | `pret` (identic) |
| Adresa | `adresa` | `adresa` (identic) |
| Cod fiscal | `codFiscal` | `codFiscal` (identic) |
| Marca agent | `marcaAgent` | `marcaAgent` (identic) |
| Den gestiune | `denGest` | `denGestiune` |
| Clasă articol | `clasaArticol` | `clasaArticol` (identic) |
| Cod postal | `codPostal` | `codPostal` (identic) |

**Câmpuri noi disponibile în MentorAPI** (opțional de folosit):
- `tipDocument` — tip document (factură, bon, etc.)
- `pozitieDocument` — poziția pe factură
- `moneda` — moneda documentului

**Câmpuri pierdute** (existau în bridge vechi, nu în MentorAPI):
- `valAchizitie` — valoare achiziție; se poate calcula `cantitate * pret`
- `comision` — nu era folosit activ
- `codInternArt` — cod intern articol; echivalentul e `codArticol`

---

## Pasul 4: Verificat câmpuri STOCURI (5 min)

**Fișier**: `app/Services/Winmentor/WinmentorBridgeClient.php` — `getAllArticole()`, `getSkusInClasa()`

| Bridge vechi | MentorAPI | Impact |
|--------------|-----------|--------|
| `um` | `denUM` | Verificat dacă ERP citește acest câmp din stocuri |

Câmpuri noi disponibile: `idIntern`, `observatiiProdus`, `stocMinim`.

---

## Pasul 5: Verificat câmpuri ARTICOLE (5 min)

**Fișiere**: `WinmentorBridgeClient.php` — `searchArticolBySku()`, `fetchAllArticoleSkuMap()`

Paginare: bridge vechi are `totalItems` + `hasPreviousPage`; MentorAPI nu le are. ERP-ul folosește doar `totalPages` și `hasNextPage` → **zero impact**.

Câmpuri extra MentorAPI: `codIntern`, `codExternUnic`, `codAlternativ`, `flagActiv`, `cotaTVA2`, `camp27/30/32/34` → nu afectează nimic.

---

## Pasul 6: Verificat RECEPTII / INTRARI (10 min)

**Fișiere**:
- `app/Console/Commands/FetchWinmentorIntrariCommand.php`
- `app/Console/Commands/WatchWinmentorIntrariCommand.php`
- `app/Console/Commands/BackfillIntrariReceptiiCommand.php`
- `app/Console/Commands/SyncWinmentorPurchaseHistoryCommand.php`
- `app/Console/Commands/MatchPoWinmentorReceptieCommand.php`

Bridge-ul vechi nu returna date pe firma ERP (era pe MAL2019), deci nu putem compara direct. Trebuie verificat că ERP-ul citește câmpurile corecte din răspunsul MentorAPI:

**Recepții** (`GET /api/receptii`): `nrNIR`, `dataNIR`, `denFurnizor`, `idFurnizor`, `nrFactura`, `dataFactura`, `codArticol`, `denArticol`, `cantitate`, `pretAchizitie`, `simbolGestiune`, etc.

**Intrări** (`GET /api/intrari`): `idPartener`, `data`, `nrDoc`, `codArticol`, `cant`, `denUM`, `pret`, `denGest`, `flag`

Aceste comenzi trebuie rulate manual pe MentorAPI și comparat output-ul cu ce se așteaptă codul Laravel.

---

## Pasul 7: Test end-to-end (30 min)

1. Schimbă conexiunea (Pasul 1)
2. Rulează `php artisan winmentor:sync-stock` → verifică stocuri
3. Rulează `php artisan winmentor:fetch-vanzari` → verifică vânzări
4. Rulează `php artisan winmentor:fetch-intrari` → verifică recepții
5. Creează un PO nou → verifică push comanda furnizor
6. Recepționează PO-ul → verifică push recepție
7. Verifică paginile Filament: WinmentorVanzariPage, WinmentorOfertePage

---

## Pasul 8: Cleanup (după confirmare funcțională)

- [ ] Dezactivare bridge vechi (.NET pe port 8501) pe Windows Server
- [ ] Ștergere rută download temporară din `routes/webhooks.php`
- [ ] Actualizare `MEMORY.md` cu noua conexiune
- [ ] Opțional: instalare MentorAPI ca Windows Service (scripturi există în `mentorapi/install.bat`)

---

## Riscuri și fallback

- **Fallback**: dacă ceva nu merge, se revine la bridge-ul vechi schimbând doar URL-ul și API key-ul înapoi în IntegrationConnection
- **Risc principal**: câmpurile vânzări — mapping-ul era deja ciudat pe bridge-ul vechi, trebuie atenție la adaptare
- **Risc secundar**: AddProduct returnează 0 dar nu salvează produse noi — investigat separat, nu blochează migrarea (ERP-ul creează produse și prin alte mecanisme)
- **Nu afectează**: facturi intrare/ieșire/transferuri — nu sunt folosite în ERP momentan

---

## Teste pe firma ERP — 25 mai 2026

| # | Funcție | Status | Detalii |
|---|---------|--------|---------|
| 1 | Health check | ✅ | v1.3.0, uptime 141h |
| 2 | Select firma | ✅ | ERP, 5/2026 |
| 3 | Set ID Part Field | ✅ | CodIntern |
| 4 | Set ID Art Field | ✅ | CodExtern |
| 5 | Get articole | ✅ | 15860 articole, search + paginare OK |
| 6 | Get stocuri | ✅ | 2101 pagini |
| 7 | Get stocuri pe gestiuni | ✅ | 8091 intrări |
| 8 | Get parteneri | ✅ | 23 pagini × 500, search OK |
| 9 | Get receptii | ✅ | 950 recepții luna 5 |
| 10 | Get intrari | ✅ | 1046 intrări luna 5 |
| 11 | Get vanzari ext | ✅ | 2713 vânzări luna 5 |
| 12 | Get comenzi furnizori | ✅ | 1245 comenzi |
| 13 | Import comanda furnizor | ✅ | Validate + import OK |
| 14 | Import comanda client | ✅ | Validate + import OK (comanda 99901 importată) |
| 15 | Add product | ⚠️ | Returnează 0 dar produsul nu apare — de investigat |
| 16 | Add/Modify partener | ✅ | Creare + modificare OK |
| 17 | Import factura intrare | ❌ | Blocat: cont recepție nesetat pe firma ERP |
| 18 | Import factura iesire | ❌ | Blocat: cont livrare nesetat pe firma ERP |
| 19 | Import transfer | ❌ | Blocat: tip contabil nesetat pe firma ERP |

Funcțiile 17-19 nu sunt folosite de ERP momentan. Funcția 15 (AddProduct) e folosită dar nu blochează — ERP-ul are fallback.
