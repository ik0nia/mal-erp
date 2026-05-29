# MentorAPI

REST API bridge for **WinMentor DocImpServer** — exposes the WinMentor COM/DLL interface as a modern JSON API.

Written in Go. Runs on Windows (32-bit, required by DocImpServer). Cross-compiled from Linux.

## What it does

WinMentor is a Romanian ERP system that stores data in a proprietary format. The only programmatic access is through a 32-bit COM DLL called `DocImpServer`. This project wraps that DLL into a standard HTTP/JSON API so any application can read and write WinMentor data.

**195 endpoints** — 111 GET (read), 80 POST (write/import), 4 PUT (update).

## Quick Start

```bash
# Build (from Linux or Windows)
GOOS=windows GOARCH=386 go build -ldflags="-s -w" -o mentorapi.exe .

# Run on Windows (where WinMentor is installed)
mentorapi.exe
# MentorAPI v1.3.0 — WinMentor DocImpServer REST Bridge
# Listening on http://0.0.0.0:9500
```

### Configuration

Create `appsettings.json` next to the exe:

```json
{
  "MentorAPI": {
    "Port": 9500,
    "BindAddress": "0.0.0.0",
    "ApiKey": "your-secret-key-here",
    "ComProgId": "DocImpServer.DocImpObject",
    "AllowGenericCalls": true,
    "LogLevel": "info",
    "MaxPageSize": 10000,
    "DefaultPageSize": 100
  }
}
```

### First API call

```bash
# Health check (no auth required)
curl http://localhost:9500/api/health

# Select company and month
curl -X POST -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"firma":"COMPANY_NAME","an":2026,"luna":5}' \
  http://localhost:9500/api/firme/select

# Get sales
curl -H "X-API-Key: YOUR_KEY" http://localhost:9500/api/vanzari/ext
```

## Documentation

- **Swagger UI**: `http://localhost:9500/docs` (built into the binary)
- **OpenAPI spec**: `http://localhost:9500/api/openapi.json`
- **Audit page**: see `audit/docs-complete.html` for field-by-field documentation

## Project Structure

```
mentorapi/
├── main.go                  # Entry point, Windows service support
├── config/config.go         # Configuration loader
├── api/                     # HTTP handlers & routing
│   ├── router.go            # All 195 routes
│   ├── server.go            # Server struct & lifecycle
│   ├── middleware.go         # Auth, CORS, logging, recovery
│   ├── swagger.go           # Embedded OpenAPI spec
│   ├── vanzari.go           # Sales endpoints
│   ├── comenzi.go           # Orders, transfers, receipts
│   ├── facturi.go           # Invoices, NIR
│   ├── financiar.go         # Balances, collections, payments
│   ├── stocuri.go           # Stock queries
│   ├── articole.go          # Product catalog
│   ├── parteneri.go         # Partners/customers/suppliers
│   ├── nomenclatoare.go     # Lookup tables
│   ├── import.go            # Generic INI import
│   ├── import_structured.go # Structured JSON import (8 doc types)
│   ├── produse_structured.go# Add/update products via JSON
│   └── openapi-spec.json    # OpenAPI 3.0 specification
├── libWMEdcom/              # COM interop library
│   └── winmentor/
│       ├── client.go        # COM connection & vtable calls
│       ├── vtable.go        # Low-level vtable interop (Windows)
│       ├── vtable_stub.go   # Stub for cross-compilation
│       ├── types.go         # All data structures
│       ├── queries.go       # DLL function wrappers
│       ├── articles.go      # Article-specific operations
│       ├── partners.go      # Partner-specific operations
│       └── documents.go     # Document import operations
├── audit/                   # Audit documentation (HTML)
│   ├── docs-complete.html   # Full endpoint documentation
│   └── examples/            # JSON response examples
└── appsettings.json         # Runtime configuration (not committed)
```

## Endpoints

See [api/README.md](api/README.md) for the complete endpoint reference.

See [libWMEdcom/README.md](libWMEdcom/README.md) for COM interop details.

## Architecture

```
┌─────────────┐     HTTP/JSON      ┌─────────────┐     COM vtable     ┌──────────────┐
│  Any client  │ ──────────────────>│  MentorAPI   │ ─────────────────>│ DocImpServer │
│  (ERP, curl) │ <──────────────────│  (Go, 32-bit)│ <─────────────────│ (WinMentor)  │
└─────────────┘                    └─────────────┘                    └──────────────┘
```

- **Auth**: API key via `X-API-Key` header
- **COM**: vtable calls (not IDispatch) — required by DocImpServer
- **Threading**: single-threaded COM apartment (CoInitialize), requests serialized
- **Caching**: firma selection cached, article maps cached per request batch

## Building

Requires Go 1.21+. Must be built as 32-bit Windows binary:

```bash
# From Linux (cross-compile)
GOOS=windows GOARCH=386 go build -ldflags="-s -w" -o mentorapi.exe .

# From Windows
set GOARCH=386
go build -ldflags="-s -w" -o mentorapi.exe .

# Verify
go vet ./...  # Run on target OS or with GOOS=windows GOARCH=386
```

## License

Proprietary. Internal use only.
