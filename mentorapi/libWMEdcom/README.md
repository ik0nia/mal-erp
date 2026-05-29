# libWMEdcom

Go library for interacting with the WinMentor **DocImpServer** COM object.

## What is DocImpServer?

DocImpServer is a 32-bit COM DLL provided by WinMentor (Romanian ERP software by IndSoft). It provides read/write access to WinMentor's database through OLE Automation.

## Why vtable instead of IDispatch?

DocImpServer does **not** support `IDispatch::Invoke` reliably. Standard Go COM libraries (like `go-ole`) use IDispatch, which fails silently or returns wrong data with this DLL.

This library uses **direct vtable calls** — it reads the COM object's virtual function table and calls methods by their vtable offset. This is lower-level but works correctly with DocImpServer.

## Architecture

```
┌──────────────┐
│  client.go   │  High-level API: Connect(), SelectFirma(), etc.
├──────────────┤
│  queries.go  │  DLL function wrappers: GetVanzariExt(), GetSolduri(), etc.
│  articles.go │  Article-specific: GetNomenclatorArticole(), GetProducts()
│  partners.go │  Partner-specific: GetListaParteneri(), AddPartener()
│  documents.go│  Import: ValidateDocument(), ImportDocument()
├──────────────┤
│  types.go    │  All data structures (VanzareExt, Partner, Sold, etc.)
├──────────────┤
│  vtable.go   │  Low-level vtable interop (Windows only)
│  vtable_stub │  Stub for cross-compilation on Linux/macOS
│  helpers.go  │  Field parsing, string splitting
└──────────────┘
```

## Key Types

| Type | Fields | Description |
|------|--------|-------------|
| `Partner` | 49 | Full partner record (customer/supplier) |
| `NomenclatorArticol` | 40 | Product catalog entry |
| `StockArticle` | 21 | Stock per article per warehouse |
| `VanzareExt` | 23 | Extended sale line (invoice + article + partner) |
| `VanzareLuna` | 26 | Monthly sale with full invoice details |
| `Sold` | 11 | Client/supplier balance |
| `SoldExt` | 13 | Extended balance with invoice type |
| `Intrare` | 11 | Incoming entry (purchase) |

## Usage

```go
client := winmentor.NewClient("DocImpServer.DocImpObject")
if err := client.Connect(); err != nil {
    log.Fatal(err)
}
defer client.Disconnect()

// Select company
client.SetNumeFirma("COMPANY_NAME")
client.SetLunaLucru(2026, 5)

// Read sales
sales, err := client.GetVanzariExt()

// Read stock
stock, err := client.GetStocArticole()

// Import document
err = client.ImportDocument(lines)
```

## Important Notes

- **32-bit only**: DocImpServer is a 32-bit DLL. Build with `GOARCH=386`.
- **Single-threaded**: COM apartment is single-threaded (`CoInitialize`). All calls are serialized via `comDo()`.
- **vtable_stub.go**: Allows cross-compilation on Linux/macOS. The stub panics at runtime — only the Windows build works.
- **Field parsing**: DLL returns semicolon-separated strings. `splitFields()` handles padding for missing fields.

## Building

```bash
# Cross-compile from Linux
GOOS=windows GOARCH=386 go build ./...

# On Windows
set GOARCH=386
go build ./...
```
