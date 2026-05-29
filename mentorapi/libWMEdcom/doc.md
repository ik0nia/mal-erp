# libWMEdcom API Documentation

Go library for the WinMENTOR DocImpServer DCOM interface. Provides goroutine-safe access to all 145 COM methods for reading nomenclatures, querying stock/balances, and importing documents into WinMENTOR.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Data Format Conventions](#data-format-conventions)
- [API Reference](#api-reference)
  - [Session Setup](#session-setup)
  - [Configuration](#configuration)
  - [Partners](#partners)
  - [Articles & Stock](#articles--stock)
  - [Documents - Invoices](#documents---invoices)
  - [Documents - Orders](#documents---orders)
  - [Documents - Cash Register](#documents---cash-register)
  - [Documents - Collections & Payments](#documents---collections--payments)
  - [Documents - Consumption Notes](#documents---consumption-notes)
  - [Documents - Transfers](#documents---transfers)
  - [Documents - Accounting Notes](#documents---accounting-notes)
  - [Documents - Price Modifications](#documents---price-modifications)
  - [Documents - Supplier Orders](#documents---supplier-orders)
  - [Documents - Inventory Adjustments](#documents---inventory-adjustments)
  - [Sales & Collections Queries](#sales--collections-queries)
  - [Employees](#employees)
  - [Warehouses & Banks](#warehouses--banks)
  - [Balances](#balances)
  - [Inventory & Receiving](#inventory--receiving)
  - [Discounts](#discounts)
  - [Miscellaneous](#miscellaneous)
- [Data Types](#data-types)
- [Document Import Workflow](#document-import-workflow)
- [Error Handling](#error-handling)

---

## Installation

```bash
go get github.com/rayone121/libWMEdcom
```

**Requirements:** Windows with WinMENTOR installed (`DocImpServer.dll` registered), Go 1.22+.

## Quick Start

```go
package main

import (
    "fmt"
    "log"

    "github.com/rayone121/libWMEdcom/winmentor"
)

func main() {
    client, err := winmentor.NewClient()
    if err != nil {
        log.Fatal(err)
    }
    defer client.Close()

    // Select company and work month
    client.SetNumeFirma("MYCOMPANY")
    client.SetLunaLucru(2024, 10)

    // Query partners
    partners, _ := client.GetListaParteneri()
    fmt.Printf("Found %d partners\n", len(partners))

    // Query stock
    stock, _ := client.GetStocArticole()
    fmt.Printf("Found %d stock articles\n", len(stock))

    // Import invoices
    lines := []string{"header;field1;field2;...", "line1;..."}
    client.SetDocsData(lines)
    if err := client.DateValide(); err != nil {
        errs, _ := client.GetListaErori()
        log.Fatal("Validation errors:", errs)
    }
    count, _ := client.ImportaFacturi()
    fmt.Printf("Imported %d invoices\n", count)
}
```

## Architecture

All COM calls are dispatched to a dedicated goroutine locked to an OS thread via `runtime.LockOSThread()`. This ensures thread affinity required by COM. The client is safe to use from any goroutine.

```
  Goroutine A ──┐
                ├── comCh (channel) ──► COM Goroutine (locked to OS thread)
  Goroutine B ──┘                           │
                                            ├── rawCall("MethodName", args...)
                                            ├── oleutil.CallMethod(obj, ...)
                                            └── cleanup & return
```

- `NewClient()` spawns the COM goroutine, initializes COM with `COINIT_MULTITHREADED`, and creates the `DocImpServer.DocImpObjectClass` COM object.
- `Close()` closes the channel and waits for the COM goroutine to release all COM resources and exit.

## Data Format Conventions

- **Semicolon-separated fields**: All record data exchanged with WinMENTOR uses semicolons (`;`) as field delimiters.
- **Multi-value fields**: Fields like seats, agents, emails use tilde (`~`) as separator within a single field. Example: `"Sediu1~Sediu2~Sediu3"`.
- **Boolean flags**: Typically `"Da"/"Nu"` or `"D"/"N"` or `"PF"/"PJ"`.
- **Dates**: Format varies by method, commonly `"dd.mm.yyyy"` or `"dd.mm.yyyy hh:mm:ss"`.
- **Partner/Article IDs**: Can be external codes, fiscal codes, or internal codes, depending on `SetIDPartField`/`SetIDArtField` configuration.

---

## API Reference

### Session Setup

#### `NewClient() (*Client, error)`
Creates a new COM connection to DocImpServer. Spawns a dedicated COM goroutine.

#### `Close()`
Releases the COM object, uninitializes COM, and terminates the COM goroutine. Must be called when done (use `defer`).

#### `GetListaFirme() ([]string, error)`
Returns the list of company short names available in the WinMENTOR data directory.

#### `GetListaLuni(numeFirma string) ([]string, error)`
Returns the list of work months for the given company. Format: `"yyyy_mm"`.

#### `SetNumeFirma(numeFirma string) error`
Sets the active company. Must be called before any data queries.

#### `SetLunaLucru(an, luna int) error`
Sets the working month. `an` = year (e.g. 2024), `luna` = month (1-12).

#### `GetListaErori() ([]string, error)`
Returns the list of error messages from the last failed operation. Call this after a validate method returns an error.

#### `GetVersiuni() (resultCode int, verMentor float64, verServer float64, err error)`
Returns the WinMENTOR application version and DocImpServer DLL version.

#### `GetStringConstanta(id int, simbol string) (string, error)`
Returns a string constant value by its ID and symbol.

---

### Configuration

#### `SetIDPartField(fieldName string) error`
Sets which field is used for partner identification across all operations.

| Value | Description |
|-------|-------------|
| `"CodExtern"` | External code (default) |
| `"CodFiscal"` | Fiscal code |
| `"CodIntern"` | Internal WinMENTOR code |

#### `SetIDArtField(fieldName string) error`
Sets which field is used for article identification.

| Value | Description |
|-------|-------------|
| `"CodExtern"` | External code (default) |
| `"CodIntern"` | Internal WinMENTOR code |

#### `SetDocsData(lines []string) error`
Sends a document data packet to the server. Must be called before any validate/import method. The `lines` parameter is an array of semicolon-separated strings representing document header and detail lines.

#### `SetDescarcareAutomata(automat int) error`
Enables/disables automatic stock discharge. `1` = automatic, `0` = manual.

#### `SetCmdImplicitAcceptat(implicitAcceptat int) error`
Sets whether orders are accepted by default. `1` = accepted, `0` = not accepted.

#### `SetClasaArt(numeClasa string) error`
Sets the active article class filter for subsequent queries.

#### `SetIndexNart(indexName string) error`
Deprecated predecessor to `SetIDArtField`. Kept for backward compatibility.

#### `SetFiltruDocNeoperate(flag int) error`
Sets the filter for unprocessed documents. `1` = show only unprocessed, `0` = show all.

#### `SetInclusivCmdFurn(flag int) error`
Sets whether supplier orders are included in queries. `1` = include, `0` = exclude.

#### `SetInclusivFactAviz(flag int) error`
Sets whether delivery notes (avize) are included in queries. `1` = include, `0` = exclude.

#### `SetArtAnalizat(idArticol string) (int, error)`
Sets the article to be analyzed for subsequent analysis queries.

#### `SetCatPretImplicita(idCatPret string) (int, error)`
Sets the default price category.

---

### Partners

#### `GetListaParteneri() ([]Partner, error)`
Returns all partners (customers/suppliers). Each partner has 38 fields. See [`Partner`](#partner) type.

#### `GetSoldPart(partID string) (*SoldPartener, error)`
Returns the balance for a specific partner. See [`SoldPartener`](#soldpartener) type.

#### `GetSoldDetaliat(partID string) ([]DetailedBalance, error)`
Returns detailed balance broken down by invoices and advances. See [`DetailedBalance`](#detailedbalance) type.

#### `GetClaseParteneri() ([]ClasaParteneri, error)`
Returns the list of partner classes. See [`ClasaParteneri`](#clasaparteneri) type.

#### `AdaugaPartener(p *PartnerInput) (int, error)`
Adds a new partner. Returns the new partner ID on success, 0 on failure. See [`PartnerInput`](#partnerinput) type.

```go
p := &winmentor.PartnerInput{
    Denumire:  "Acme Corp SRL",
    CodFiscal: "RO12345678",
    SediulInLocalitatea: "Bucuresti",
    AdresaSediu: "Str. Exemplu nr. 1",
}
newID, err := client.AdaugaPartener(p)
```

#### `ModificaPartener(p *PartnerInput) (int, error)`
Modifies an existing partner. The `ID` field in `PartnerInput` must be set. Returns the partner ID on success.

#### `GetNextPartID() (string, error)`
Returns the next available partner ID.

#### `GenCodParteneri() (int, error)`
Generates the next internal/external/fiscal code for partners. Returns -1 on error.

#### `GetInfoPart(partID string) ([]string, error)`
Returns raw partner info for a given partner ID.

#### `GetListaClienti(anInceput, lunaInceput int) ([]ClientInfo, error)`
Returns clients that had activity starting from the given year/month. See [`ClientInfo`](#clientinfo) type.

#### `GetListaLocalitati() ([]string, error)`
Returns the list of partner localities.

---

### Articles & Stock

#### `GetStocArticole() ([]StockArticle, error)`
Returns all articles with current stock levels (17 fields each). See [`StockArticle`](#stockarticle) type.

#### `GetStocArticol(articolID, gestID string) (*ArticleStock, error)`
Returns stock info for a single article in a specific warehouse. See [`ArticleStock`](#articlestock) type.

```go
stock, err := client.GetStocArticol("ART001", "GEST1")
if stock != nil {
    fmt.Printf("%s: %s %s in stock\n", stock.Denumire, stock.Stoc, stock.UM)
}
```

#### `GetNomenclatorArticole() ([]NomenclatorArticol, error)`
Returns the full article nomenclature (24 fields each). See [`NomenclatorArticol`](#nomenclatorarticol) type.

#### `GetClaseArticole() ([]ClasaArticole, error)`
Returns the list of article classes. See [`ClasaArticole`](#clasaarticole) type.

#### `GenCodArticole() (int, error)`
Generates external codes for all articles in the nomenclature. Returns -1 on error.

#### `GetProducts(lastSyncDate string) ([]Product, error)`
Returns products modified since `lastSyncDate` (format: `"dd.mm.yyyy hh:mm:ss"`). Useful for incremental sync. See [`Product`](#product) type.

#### `GetStergeriProduse(lastSyncDate string) ([]DeletedProduct, error)`
Returns products deleted since `lastSyncDate`. See [`DeletedProduct`](#deletedproduct) type.

#### `AddProduct(infoProdus string) (int, error)`
Adds a new product. `infoProdus` is a semicolon-separated record string.

#### `ModiProduct(infoProdus string) (int, error)`
Modifies an existing product.

#### `AddClasaArt(infoClasa string) (int, error)`
Adds a new article class. `infoClasa` is a semicolon-separated record string.

#### `GetPretVanzare(artID, partID string) ([]string, error)`
Returns the sale price(s) for a given article/partner combination.

#### `GetListaCatPret() ([]CategoriePret, error)`
Returns price categories for the current company. See [`CategoriePret`](#categoriepret) type.

#### `GetListaArtCatPret() ([]string, error)`
Returns the price categories assigned to each article.

#### `GetListaArtCatPretExt() ([]string, error)`
Returns extended article price category info.

#### `GetListaArtCatPret2(artID string) ([]string, error)`
Returns price categories for a specific article by ID.

#### `GetOferte() ([]Oferta, error)`
Returns the list of price offers. See [`Oferta`](#oferta) type.

#### `GetOferteClienti() ([]string, error)`
Returns client-specific offers.

#### `GetStocArticoleExt() ([]string, error)`
Returns extended stock articles data.

#### `GetStocArticoleExt2(gestID string) ([]string, error)`
Returns extended stock articles for a specific warehouse.

#### `GetStocArtDetaliat(artID, gestID string) ([]string, error)`
Returns detailed stock for an article in a specific warehouse (batch-level detail).

#### `GetStocuriPeGestiuni() ([]string, error)`
Returns stock levels aggregated per warehouse.

---

### Documents - Invoices

All document import follows the same workflow: `SetDocsData` -> Validate -> Import -> `GetListaErori` (on failure).

#### `DateValide() error`
Validates invoice data previously sent via `SetDocsData`. Returns `nil` if valid.

#### `ImportaFacturi() (int, error)`
Imports validated invoices. Returns the number of invoices imported.

#### `ExistaFactura(numar int) (int, error)`
Checks if an invoice with the given number exists.

| Return | Meaning |
|--------|---------|
| `-1` | Error |
| `0` | Doesn't exist |
| `1` | Exists and is processed |
| `2` | Exists and is unprocessed |

#### `ExistaFacturaExt(numar int, serie string) (int, error)`
Extended invoice existence check (by number and series). Same return values as `ExistaFactura`.

#### `ExistaFacturaIntrare(partID, serie string, numar int) (int, error)`
Checks if an incoming (purchase) invoice exists.

#### `FactIntrareValida() error`
Validates incoming (purchase) invoice data sent via `SetDocsData`.

#### `ImportaFactIntrare() (int, error)`
Imports incoming invoices. Returns the number imported.

---

### Documents - Orders

#### `ComenziValide() error`
Validates order data sent via `SetDocsData`.

#### `ImportaComenzi() (int, error)`
Imports orders. Returns the number imported.

#### `ComenziValideExt() error`
Validates extended order data.

#### `ImportaComenziExt() (int, error)`
Imports extended orders. Returns the number imported.

#### `GetComenziNefacturate() ([]ComandaNefacturata, error)`
Returns uninvoiced order lines. See [`ComandaNefacturata`](#comandanefacturata) type.

#### `GetInfoComenzi() ([]string, error)`
Returns order info.

#### `GetInfoCmdNefacturate() ([]string, error)`
Returns info on uninvoiced orders.

#### `GetInfoCmdSubNefacturate() ([]string, error)`
Returns sub-order uninvoiced info.

#### `ActualizeazaAcceptat(liniiComenzi []string) (int, error)`
Updates acceptance status for order lines. `liniiComenzi` contains the lines to update.

#### `GetCmdSubunitActive() ([]string, error)`
Returns active sub-unit orders.

---

### Documents - Cash Register

#### `MonetareValide() error`
Validates cash register (monetar) data sent via `SetDocsData`. Returns `nil` if valid.

#### `ImportaMonetare() (int, error)`
Imports cash register data. Returns the number imported.

---

### Documents - Collections & Payments

#### `IncasariValide() error`
Validates collection (incasari) data.

#### `ImportaIncasari() (int, error)`
Imports collections.

#### `IncasariValideExt() error`
Validates extended collection data.

#### `ImportaIncasariExt() (int, error)`
Imports extended collections.

#### `PlatiValideExt() error`
Validates payment data.

#### `ImportaPlatiExt() (int, error)`
Imports payments.

---

### Documents - Consumption Notes

#### `BonuriConsumValide() error`
Validates consumption note (bon de consum) data.

#### `ImportaBonuriConsum() (int, error)`
Imports consumption notes. Returns the number imported.

---

### Documents - Transfers

#### `TransferuriValide() error`
Validates warehouse transfer data.

#### `ImportaTransferuri() (int, error)`
Imports warehouse transfers. Returns the number imported.

---

### Documents - Accounting Notes

#### `NCValide() error`
Validates accounting note (nota contabila) data.

#### `ImportaNoteContabile() (int, error)`
Imports accounting notes. Returns the number imported.

---

### Documents - Price Modifications

#### `ModifPretValide() error`
Validates price modification data.

#### `ImportaModifPret() (int, error)`
Imports price modifications. Returns the number imported.

---

### Documents - Supplier Orders

#### `ComenziFurnValide() error`
Validates supplier order data.

#### `ImportaComenziFurn() (int, error)`
Imports supplier orders. Returns the number imported.

---

### Documents - Inventory Adjustments

#### `ReglareInventarValida(tipReglare int) error`
Validates inventory adjustment data. `tipReglare` specifies the adjustment type.

#### `ImportaReglareInventar(tipReglare int) (int, error)`
Imports inventory adjustments. Returns the number imported.

---

### Sales & Collections Queries

#### `GetVanzariExt() ([]VanzareExt, error)`
Returns extended sales for the current work month (18 fields each). See [`VanzareExt`](#vanzareext) type.

#### `GetVanzariLuna() ([]VanzareLuna, error)`
Returns monthly sales (10 fields each). See [`VanzareLuna`](#vanzareluna) type.

#### `GetArticoleVandute(partID string, marcaAgent, anInceput, lunaInceput int) ([]string, error)`
Returns articles sold to a specific client by an agent from a given start date.

#### `GetUltimeleVanzari(artID, partID string, marcaAgent, cate int) ([]string, error)`
Returns the last `cate` sales of an article to a partner by an agent.

#### `GetIstoricVanzari(marca, anInceput, lunaInceput int) (int, error)`
Initializes a sales history iterator for an agent. Use with `GetListRecord` to retrieve records.

#### `GetListRecord() (result string, eof int, err error)`
Returns the next record from an active iterator (started by `GetIstoricVanzari`). `eof` = 1 when no more records.

```go
_, err := client.GetIstoricVanzari(1, 2024, 1)
for {
    record, eof, err := client.GetListRecord()
    if err != nil || eof == 1 {
        break
    }
    fmt.Println(record)
}
```

#### `GetIncasariClienti(an1, luna1, an2, luna2 int, partID string) ([]string, error)`
Returns collections for a client within a date interval.

#### `GetIncasariLuna() ([]string, error)`
Returns all collections for the current work month.

#### `GetIncasariFactura(an, luna, nrFact int, serieFact, idPart string) ([]string, error)`
Returns collections for a specific invoice.

#### `GetPlatiFactura(an, luna, nrFact int, serie, idPart string) ([]string, error)`
Returns payments for a specific invoice.

---

### Employees

#### `GetListaPersonal() ([]Employee, error)`
Returns the full employee list (9 fields each). See [`Employee`](#employee) type.

#### `GetInfoPers(idPers int) ([]string, error)`
Returns detailed info for an employee by their Marca (personnel ID).

---

### Warehouses & Banks

#### `GetListaGestiuni() ([]Gestiune, error)`
Returns all warehouses. See [`Gestiune`](#gestiune) type.

#### `AdaugaGestiune(infoGest string) (int, error)`
Adds a new warehouse. `infoGest` is a semicolon-separated record string.

#### `GetListaBanci() ([]Bank, error)`
Returns the national bank list. See [`Bank`](#bank) type.

#### `GetListaSubunit() ([]string, error)`
Returns the list of sub-units.

---

### Balances

#### `GetSolduri() ([]Sold, error)`
Returns agent-level balance records (invoices and advances). See [`Sold`](#sold) type.

#### `GetSolduriExt() ([]SoldExt, error)`
Returns extended client balance details (10 fields each). See [`SoldExt`](#soldext) type.

#### `GetSolduriFurn() ([]SoldExt, error)`
Returns extended supplier balance details. Same structure as `GetSolduriExt`.

#### `GetSoldFactNeop(partID string) (string, error)`
Returns the balance for unprocessed invoices of a specific partner. Returns a string value.

---

### Inventory & Receiving

#### `GetNirAtribute() ([]string, error)`
Returns NIR (goods reception note) attributes.

#### `GetStocInitialAmbalaj(an, luna int) ([]string, error)`
Returns initial packaging stock for a given year/month.

#### `GetMiscariAmbalaje(anStart, lunaStart, anStop, lunaStop int) ([]string, error)`
Returns packaging movements within a date range.

#### `GetIntrari() ([]string, error)`
Returns incoming entries for the current work month.

#### `GetReceptii() ([]string, error)`
Returns receptions for the current work month.

#### `GetReceptiiIntrSubunit() ([]string, error)`
Returns sub-unit incoming receptions.

#### `GetTransferuri() ([]string, error)`
Returns warehouse transfers for the current work month.

#### `GetTranzactiiInCurs() ([]string, error)`
Returns in-progress transactions.

#### `GetInventoryOrders(gestID string) ([]string, error)`
Returns inventory orders for a specific warehouse.

#### `SetInventoryOrders(data []string) (int, error)`
Sets inventory order data.

#### `SetReceivingList(data []string) (int, error)`
Sets the receiving list data.

#### `GetReceivingStatus(partID, serieDoc, nrDoc string) (string, error)`
Returns the receiving status for a specific document.

#### `SetPickedList(data []string) (int, error)`
Sets the picked list data.

#### `GetDeliveryOrders() ([]string, error)`
Returns delivery orders.

#### `SetDeliveryList(data []string) (int, error)`
Sets the delivery list data.

#### `GetDispozitiiDeLivrare(gestID string) ([]string, error)`
Returns delivery dispositions for a specific warehouse.

---

### Discounts

#### `GetCritDiscPeArticole() ([]string, error)`
Returns discount criteria per article.

#### `GetCritDiscPeClase() ([]string, error)`
Returns discount criteria per class.

#### `GetCritDiscPart() ([]string, error)`
Returns partner discount criteria.

#### `GetIntervaleDisc(criteriu int) ([]string, error)`
Returns discount intervals for a given criterion.

#### `GetArticoleDisc(criteriu int) ([]string, error)`
Returns articles with discounts for a given criterion.

---

### Miscellaneous

#### `GetDocFromFile(fileName string) (int, error)`
Reads document data from a file (alternative to `SetDocsData`).

#### `CheckDocument(tipDoc, prefixDoc string, nrDoc int) (int, error)`
Checks a document by type, prefix, and number.

#### `PotIntroduceDoc(an, luna int) (int, error)`
Checks if documents can be introduced for a given year/month.

#### `GetListaCarnete() ([]Carnet, error)`
Returns the document book list. See [`Carnet`](#carnet) type.

#### `GetListacarneteExt() ([]string, error)`
Returns extended document book list.

#### `GetNumarFactura(simbolCarnet string) (int, error)`
Returns the next available invoice number within a document book.

#### `GetMonede() ([]string, error)`
Returns the list of currencies.

#### `GetNomenclatorLocalitati() ([]string, error)`
Returns the locality nomenclature.

#### `GetListaDelegati() ([]string, error)`
Returns the list of delegates.

#### `GetSuppliersOrders() ([]string, error)`
Returns supplier orders.

#### `GetInfoBon(zi int) ([]string, error)`
Returns receipt info for a given day of the current month.

#### `GetInfoBonExt(nrBon, zi int) ([]string, error)`
Returns extended receipt info for a specific receipt number and day.

#### `GetInfoIesiri(zi int) ([]string, error)`
Returns outgoing entries info for a given day.

#### `GetInfoIesiriExt(nrDoc, zi int) ([]string, error)`
Returns extended outgoing entries info.

#### `GetInfoCmdBon(nrCmd int) ([]string, error)`
Returns command receipt info for a specific command number.

#### `GetInfoCmdBonuri(zi int) ([]string, error)`
Returns command receipts info for a given day.

---

## Data Types

### Partner

Returned by `GetListaParteneri()`. 38 fields.

| Field | Type | Description |
|-------|------|-------------|
| `ID` | string | Partner identifier |
| `Denumire` | string | Name |
| `CodFiscal` | string | Fiscal code |
| `Localitate` | string | City/locality |
| `Adresa` | string | Address |
| `Telefon` | string | Phone |
| `PersContact` | string | Contact person |
| `SimbolClasa` | string | Class symbol |
| `DenClasa` | string | Class name |
| `SimbolCatPret` | string | Price category symbol |
| `DenCatPret` | string | Price category name |
| `MarcaAgent` | string | Agent ID (marca) |
| `NumeAgent` | string | Agent last name |
| `PrenumeAgent` | string | Agent first name |
| `Scadenta` | string | Payment term |
| `Discount` | string | Discount |
| `DenCritDiscount` | string | Discount criterion name |
| `SediiPartener` | string | Branch offices (`~` separated) |
| `CodExtern` | string | External code |
| `PartnerBlocat` | string | Blocked flag (`DA`/`NU`) |
| `CreditVanzare` | string | Sales credit |
| `CodFiscal2` | string | Secondary fiscal code |
| `ContBanca` | string | Bank account |
| `LocalitatiSedii` | string | Branch localities (`~` separated) |
| `Tara` | string | Country |
| `MarcaAgentiSedii` | string | Branch agent IDs (`~` separated) |
| `Observatii` | string | Notes |
| `FlagSediuSocial` | string | Head office flags (`~` separated) |
| `CodPostalSedii` | string | Branch postal codes (`~` separated) |
| `EmailSedii` | string | Branch emails (`~` separated) |
| `TelPersContact` | string | Contact person phone |
| `PFsauPJ` | string | `PF` (individual) or `PJ` (company) |
| `MonedaImplicita` | string | Default currency |
| `DataAdaugarii` | string | Date added |
| `Trasee` | string | Routes |
| `PuncteAcumulate` | string | Accumulated points |
| `CodFiscalSedii` | string | Branch fiscal codes (`~` separated) |
| `InfoTipSediu` | string | Branch type info (`~` separated) |

### PartnerInput

Used by `AdaugaPartener()` and `ModificaPartener()`. 35 fields.

| Field | Type | Description |
|-------|------|-------------|
| `ID` | string | Partner ID (required for modify) |
| `Denumire` | string | Name |
| `CodFiscal` | string | Fiscal code |
| `SediulInLocalitatea` | string | Head office locality |
| `AdresaSediu` | string | Head office address |
| `TelefonSediu` | string | Head office phone |
| `PersoaneContact` | string | Contact persons (`~` separated) |
| `SimbolClasa` | string | Class symbol |
| `SimbolCategoriePret` | string | Price category symbol |
| `IDAgentImplicit` | string | Default agent ID |
| `NrRegistrulComertului` | string | Trade register number |
| `Observatii` | string | Notes |
| `SimbolBanca` | string | Bank symbols (`~` separated) |
| `NumeBanca` | string | Bank names (`~` separated) |
| `LocalitateBanca` | string | Bank localities (`~` separated) |
| `ContBanca` | string | Bank accounts (`~` separated) |
| `ZiImplicitaPlata` | string | Default payment day |
| `NumeSediuSecundar` | string | Branch names (`~` separated) |
| `AdresaSediuSecundar` | string | Branch addresses (`~` separated) |
| `TelefonSediuSecundar` | string | Branch phones (`~` separated) |
| `LocalitateSediuSec` | string | Branch localities (`~` separated) |
| `IDAgentSediuSec` | string | Branch agent IDs (`~` separated) |
| `CodExtern` | string | External code |
| `SimbolAutoJudetLivr` | string | Auto-county for delivery |
| `SimbolAutoJudetSediu` | string | Auto-county for head office |
| `FlagPF` | string | Individual flag |
| `ScadentaImplicita` | string | Default payment term |
| `SimbolTipContabil` | string | Accounting type symbol |
| `FlagProducator` | string | Producer flag (`P`) |
| `EmailSediuSocial` | string | Head office email |
| `EmailSediiLivrare` | string | Delivery branch emails (`~` separated) |
| `TVAIncasare` | string | VAT on collection flag (`D`) |
| `SerAI` | string | AI series |
| `NrAI` | string | AI number |
| `SimbolAutoTaraSediu` | string | Auto-country for head office |

Call `p.ToRecord()` to serialize to a semicolon-separated string for the COM method.

### StockArticle

Returned by `GetStocArticole()`. 17 fields.

| Field | Type | Description |
|-------|------|-------------|
| `CodExtern` | string | External code |
| `Denumire` | string | Name |
| `UM` | string | Unit of measure |
| `PretVanzare` | string | Sale price |
| `Stoc` | string | Current stock |
| `SimbolClasa` | string | Class symbol |
| `DenClasa` | string | Class name |
| `IDProducator` | string | Producer ID |
| `DenProducator` | string | Producer name |
| `IDFurnizor` | string | Supplier ID |
| `DenFurnizor` | string | Supplier name |
| `SimbolGestiune` | string | Warehouse symbol |
| `DenGestiune` | string | Warehouse name |
| `CotaTVA` | string | VAT rate |
| `FlagTVAInclus` | string | VAT included in price (`D`/`N`) |
| `PretCuTVA` | string | Price with VAT |
| `StocRezervat` | string | Reserved stock |

### ArticleStock

Returned by `GetStocArticol()`. 5 fields.

| Field | Type | Description |
|-------|------|-------------|
| `CodExtern` | string | External code |
| `Denumire` | string | Name |
| `UM` | string | Unit of measure |
| `PretVanzare` | string | Sale price |
| `Stoc` | string | Current stock |

### NomenclatorArticol

Returned by `GetNomenclatorArticole()`. 24 fields.

| Field | Type | Description |
|-------|------|-------------|
| `CodExtern` | string | External code |
| `Denumire` | string | Name |
| `DenUM` | string | Unit of measure |
| `PretVanzare` | string | Sale price |
| `SimbolClasa` | string | Class symbol |
| `DenClasa` | string | Class name |
| `CodExternProducator` | string | Producer external code |
| `DenProducator` | string | Producer name |
| `GestImplicita` | string | Default warehouse |
| `CodExternUnic` | string | Unique external code |
| `CotaTVA` | string | VAT rate |
| `DenUMSecundara` | string | Secondary unit of measure |
| `ParitateUMSecundara` | string | Secondary UM conversion rate |
| `Masa` | string | Mass/weight |
| `Serviciu` | string | Service flag |
| `CodVamal` | string | Customs code |
| `PretMinim` | string | Minimum price |
| `CantImplicita` | string | Default quantity |
| `PretValuta` | string | Price in foreign currency |
| `DataAdaug` | string | Date added |
| `Masa2` | string | Secondary mass |
| `PretVCuTVA` | string | Price with VAT |
| `Locatie` | string | Location |
| `PretReferinta` | string | Reference price |

### Product

Returned by `GetProducts()`. 11 fields.

| Field | Type | Description |
|-------|------|-------------|
| `IDArticol` | string | Article ID |
| `Denumire` | string | Name |
| `DenUM` | string | Unit of measure |
| `IDProducator` | string | Producer ID |
| `DenumireProducator` | string | Producer name |
| `TipSerie` | string | Series type |
| `DataAdaugarii` | string | Date added |
| `DataUltimeiModificari` | string | Last modified date |
| `TipUM` | string | UM type |
| `CodInternWinMentor` | string | WinMENTOR internal code |
| `SimbolClasa` | string | Class symbol |

### DeletedProduct

Returned by `GetStergeriProduse()`.

| Field | Type | Description |
|-------|------|-------------|
| `CodInternWinMentor` | string | Internal code |
| `DataOraStergerii` | string | Deletion date/time |

### SoldPartener

Returned by `GetSoldPart()`.

| Field | Type | Description |
|-------|------|-------------|
| `CodExtern` | string | External code |
| `Denumire` | string | Name |
| `Sold` | string | Balance amount |

### DetailedBalance

Returned by `GetSoldDetaliat()`.

| Field | Type | Description |
|-------|------|-------------|
| `Type` | string | `"Factura"` or `"Avans"` |
| `NrDocument` | string | Document number |
| `DataDocument` | string | Document date |
| `Rest` | string | Remaining amount |

### Employee

Returned by `GetListaPersonal()`. 9 fields.

| Field | Type | Description |
|-------|------|-------------|
| `Nume` | string | Last name |
| `Prenume` | string | First name |
| `Marca` | string | Personnel ID |
| `CNP` | string | National ID number (CNP) |
| `EsteActiv` | string | Active flag (`Da`/`Nu`) |
| `EsteAgent` | string | Is sales agent (`Da`/`Nu`) |
| `SerieBuletin` | string | ID card series |
| `NumarBuletin` | string | ID card number |
| `CodPostal` | string | Postal code |

### Gestiune

Returned by `GetListaGestiuni()`.

| Field | Type | Description |
|-------|------|-------------|
| `Simbol` | string | Symbol |
| `Denumire` | string | Name |

### Bank

Returned by `GetListaBanci()`.

| Field | Type | Description |
|-------|------|-------------|
| `Simbol` | string | Symbol |
| `Denumire` | string | Name |

### ClasaParteneri

Returned by `GetClaseParteneri()`.

| Field | Type | Description |
|-------|------|-------------|
| `Simbol` | string | Symbol |
| `Denumire` | string | Name |

### ClasaArticole

Returned by `GetClaseArticole()`.

| Field | Type | Description |
|-------|------|-------------|
| `Simbol` | string | Symbol |
| `Denumire` | string | Name |

### CategoriePret

Returned by `GetListaCatPret()`.

| Field | Type | Description |
|-------|------|-------------|
| `Simbol` | string | Symbol |
| `Denumire` | string | Name |

### Oferta

Returned by `GetOferte()`.

| Field | Type | Description |
|-------|------|-------------|
| `PartID` | string | Partner ID |
| `ArtID` | string | Article ID |
| `DataInceput` | string | Start date |
| `DataSfarsit` | string | End date |
| `Pret` | string | Price |
| `Cantitate` | string | Quantity |

### VanzareExt

Returned by `GetVanzariExt()`. 18 fields.

| Field | Type | Description |
|-------|------|-------------|
| `PartID` | string | Partner ID |
| `Zi` | string | Day |
| `PrefixDoc` | string | Document prefix |
| `NrDoc` | string | Document number |
| `ArtID` | string | Article ID |
| `Cant` | string | Quantity |
| `DenUM` | string | Unit of measure |
| `Pret` | string | Price |
| `DenGest` | string | Warehouse name |
| `CodInternArt` | string | Article internal code |
| `LocatieClient` | string | Client location |
| `Adresa` | string | Address |
| `Comision` | string | Commission |
| `CodFisca` | string | Fiscal code |
| `MarcaAgent` | string | Agent ID |
| `ValAchizitie` | string | Acquisition value |
| `CodPostal` | string | Postal code |
| `ClasaArticol` | string | Article class |

### VanzareLuna

Returned by `GetVanzariLuna()`. 10 fields.

| Field | Type | Description |
|-------|------|-------------|
| `IDPartener` | string | Partner ID |
| `Zi` | string | Day |
| `NrFactura` | string | Invoice number |
| `IDArticol` | string | Article ID |
| `NumarComanda` | string | Order number |
| `Cant` | string | Quantity |
| `DenUM` | string | Unit of measure |
| `Pret` | string | Price |
| `MarcaAgent` | string | Agent ID |
| `ValoareFactura` | string | Invoice value |

### SoldExt

Returned by `GetSolduriExt()` and `GetSolduriFurn()`. 10 fields.

| Field | Type | Description |
|-------|------|-------------|
| `IDPartener` | string | Partner ID |
| `Tip` | string | Type (`"Factura"`, `"Avans"`, etc.) |
| `NrFactura` | string | Invoice number |
| `DataFactura` | string | Invoice date |
| `RestDePlata` | string | Remaining to pay |
| `TermenDePlata` | string | Payment deadline |
| `LocatiePartener` | string | Partner location |
| `MarcaAgent` | string | Agent ID |
| `ValoareFactura` | string | Invoice value |
| `ObservatiiFactura` | string | Invoice notes |

### ComandaNefacturata

Returned by `GetComenziNefacturate()`.

| Field | Type | Description |
|-------|------|-------------|
| `IDArticol` | string | Article ID |
| `NumarComanda` | string | Order number |
| `Cant` | string | Quantity |
| `DenArticol` | string | Article name |

### Sold

Returned by `GetSolduri()`.

| Field | Type | Description |
|-------|------|-------------|
| `NrDoc` | string | Document number |
| `DataDoc` | string | Document date |
| `Rest` | string | Remaining amount |
| `Termen` | string | Payment deadline |
| `Agent` | string | Agent |
| `Valoare` | string | Value |

### Carnet

Returned by `GetListaCarnete()`.

| Field | Type | Description |
|-------|------|-------------|
| `Simbol` | string | Symbol |
| `Denumire` | string | Name |

### ClientInfo

Returned by `GetListaClienti()`. 14 fields.

| Field | Type | Description |
|-------|------|-------------|
| `CodIntern` | string | Internal code |
| `CodExtern` | string | External code |
| `Denumire` | string | Name |
| `CodFiscal` | string | Fiscal code |
| `Localitate` | string | City/locality |
| `Judet` | string | County |
| `Adresa` | string | Address |
| `Telefon` | string | Phone |
| `MarcaAgent` | string | Agent ID |
| `DataFact` | string | Invoice date |
| `SediiPart` | string | Partner branches |
| `SimbolClasa` | string | Class symbol |
| `DenumireClasa` | string | Class name |
| `LocalitSedii` | string | Branch localities |

---

## Document Import Workflow

All document types follow the same pattern:

```go
// 1. Prepare data as semicolon-separated strings
lines := []string{
    "header;field1;field2;...",
    "detail;field1;field2;...",
    "detail;field1;field2;...",
}

// 2. Send data to server
client.SetDocsData(lines)

// 3. Validate (method depends on document type)
if err := client.DateValide(); err != nil {
    // 4. Get detailed errors on validation failure
    errs, _ := client.GetListaErori()
    log.Printf("Validation errors: %v", errs)
    return
}

// 5. Import (method depends on document type)
count, err := client.ImportaFacturi()
fmt.Printf("Imported %d documents\n", count)
```

### Validate/Import Method Pairs

| Document Type | Validate | Import |
|--------------|----------|--------|
| Outgoing invoices | `DateValide()` | `ImportaFacturi()` |
| Incoming invoices | `FactIntrareValida()` | `ImportaFactIntrare()` |
| Orders | `ComenziValide()` | `ImportaComenzi()` |
| Extended orders | `ComenziValideExt()` | `ImportaComenziExt()` |
| Cash register | `MonetareValide()` | `ImportaMonetare()` |
| Collections | `IncasariValide()` | `ImportaIncasari()` |
| Extended collections | `IncasariValideExt()` | `ImportaIncasariExt()` |
| Payments | `PlatiValideExt()` | `ImportaPlatiExt()` |
| Consumption notes | `BonuriConsumValide()` | `ImportaBonuriConsum()` |
| Transfers | `TransferuriValide()` | `ImportaTransferuri()` |
| Accounting notes | `NCValide()` | `ImportaNoteContabile()` |
| Price modifications | `ModifPretValide()` | `ImportaModifPret()` |
| Supplier orders | `ComenziFurnValide()` | `ImportaComenziFurn()` |
| Inventory adjustments | `ReglareInventarValida(tip)` | `ImportaReglareInventar(tip)` |

---

## Error Handling

The library uses Go's standard error handling patterns:

- **Validation methods** (`DateValide`, `ComenziValide`, etc.) return `error` directly. On failure, the error message includes details from `GetListaErori()`.
- **Import methods** (`ImportaFacturi`, `ImportaComenzi`, etc.) return `(int, error)` where the int is the count of imported documents.
- **Query methods** return `([]Type, error)` or `([]string, error)`.
- **COM errors** are wrapped with context (e.g., `"CallMethod GetListaParteneri: ..."`)
- After any failed operation, call `GetListaErori()` for detailed error messages from WinMENTOR.

```go
if err := client.DateValide(); err != nil {
    // err already contains GetListaErori() details
    // but you can also call it explicitly:
    errs, _ := client.GetListaErori()
    for _, e := range errs {
        fmt.Println("Error:", e)
    }
}
```
