// Package winmentor provides a Go client for the WinMENTOR DocImpServer DCOM interface.
//
// DocImpServer is a COM/DCOM automation server that allows external applications to
// read nomenclatures, query stock/balances, and import documents (invoices, orders, etc.)
// into the WinMENTOR accounting application.
//
// # Requirements
//
// Windows with WinMENTOR installed (DocImpServer.dll registered) and Go 1.22+.
//
// # Getting Started
//
// Create a client, select a company and work month, then call any query or import method:
//
//	client, err := winmentor.NewClient()
//	if err != nil {
//	    log.Fatal(err)
//	}
//	defer client.Close()
//
//	// Select company and work month
//	err = client.SetNumeFirma("MYCOMPANY")
//	err = client.SetLunaLucru(2024, 10)
//
//	// Query data
//	partners, err := client.GetListaParteneri()
//	stock, err := client.GetStocArticole()
//
// # Architecture
//
// All COM calls are dispatched to a dedicated goroutine locked to an OS thread
// via runtime.LockOSThread. This ensures the thread affinity required by COM.
// The client is safe to use from any goroutine — calls are serialized internally
// through a channel.
//
// NewClient spawns the COM goroutine, initializes COM (STA), and creates the
// DocImpServer.DocImpObject COM object. At startup the client discovers the
// vtable layout from the type library and uses early-binding (direct vtable)
// calls, bypassing IDispatch::Invoke which the Delphi server does not implement
// correctly. If vtable discovery fails, calls fall back to IDispatch. Close
// tears everything down in the correct order.
//
// # Configuration
//
// Before querying or importing, configure partner/article identification:
//
//	client.SetIDPartField("CodExtern")  // or "CodFiscal", "CodIntern"
//	client.SetIDArtField("CodExtern")   // or "CodIntern"
//
// These control which field is used to identify partners and articles across
// all operations.
//
// # Document Import Workflow
//
// All document types (invoices, orders, cash register, etc.) follow the same pattern:
//
//  1. Prepare data as semicolon-separated strings.
//  2. Send to server via SetDocsData.
//  3. Validate with the appropriate method.
//  4. Import with the corresponding import method.
//  5. On validation failure, call GetListaErori for details.
//
// Example for invoices:
//
//	lines := []string{"header;field1;field2", "detail;field1;field2"}
//	client.SetDocsData(lines)
//	if err := client.DateValide(); err != nil {
//	    errs, _ := client.GetListaErori()
//	    log.Fatal("Validation errors:", errs)
//	}
//	count, err := client.ImportaFacturi()
//
// Validate/Import pairs:
//
//	Outgoing invoices:      DateValide          / ImportaFacturi
//	Incoming invoices:      FactIntrareValida   / ImportaFactIntrare
//	Orders:                 ComenziValide       / ImportaComenzi
//	Extended orders:        ComenziValideExt    / ImportaComenziExt
//	Cash register:          MonetareValide      / ImportaMonetare
//	Collections:            IncasariValide      / ImportaIncasari
//	Extended collections:   IncasariValideExt   / ImportaIncasariExt
//	Payments:               PlatiValideExt      / ImportaPlatiExt
//	Consumption notes:      BonuriConsumValide  / ImportaBonuriConsum
//	Transfers:              TransferuriValide   / ImportaTransferuri
//	Accounting notes:       NCValide            / ImportaNoteContabile
//	Price modifications:    ModifPretValide     / ImportaModifPret
//	Supplier orders:        ComenziFurnValide   / ImportaComenziFurn
//	Inventory adjustments:  ReglareInventarValida / ImportaReglareInventar
//
// # Data Format Conventions
//
// Record fields are separated by semicolons (;). Multi-value fields such as
// branch offices, agent IDs, and emails use tilde (~) as a separator within
// a single field.
//
// Boolean flags are typically "Da"/"Nu", "D"/"N", or "PF"/"PJ".
// Dates are formatted as "dd.mm.yyyy" or "dd.mm.yyyy hh:mm:ss".
//
// # Sales History Iterator
//
// GetIstoricVanzari initializes an iterator. Use GetListRecord in a loop to
// retrieve records:
//
//	client.GetIstoricVanzari(marca, 2024, 1)
//	for {
//	    record, eof, err := client.GetListRecord()
//	    if err != nil || eof == 1 {
//	        break
//	    }
//	    fmt.Println(record)
//	}
package winmentor
