// Example usage of the winmentor package.
// This must be compiled and run on Windows where DocImpServer.dll is registered.
package main

import (
	"fmt"
	"log"

	"github.com/rayone121/libWMEdcom/winmentor"
)

func main() {
	// Create a new client (initializes COM and connects to DocImpServer).
	client, err := winmentor.NewClient()
	if err != nil {
		log.Fatalf("Failed to connect: %v", err)
	}
	defer client.Close()

	// List available companies.
	firme, err := client.GetListaFirme()
	if err != nil {
		log.Fatalf("GetListaFirme: %v", err)
	}
	fmt.Println("Available companies:")
	for _, f := range firme {
		fmt.Printf("  - %s\n", f)
	}

	if len(firme) == 0 {
		log.Fatal("No companies found")
	}

	// Set the active company.
	companyName := firme[0]
	if err := client.SetNumeFirma(companyName); err != nil {
		log.Fatalf("SetNumeFirma(%s): %v", companyName, err)
	}
	fmt.Printf("\nActive company: %s\n", companyName)

	// List available work months.
	luni, err := client.GetListaLuni(companyName)
	if err != nil {
		log.Fatalf("GetListaLuni: %v", err)
	}
	fmt.Println("\nAvailable months:")
	for _, l := range luni {
		fmt.Printf("  - %s\n", l)
	}

	// Set work month (e.g., October 2024).
	if err := client.SetLunaLucru(2024, 10); err != nil {
		log.Fatalf("SetLunaLucru: %v", err)
	}

	// Get partners.
	partners, err := client.GetListaParteneri()
	if err != nil {
		log.Fatalf("GetListaParteneri: %v", err)
	}
	fmt.Printf("\nPartners (%d):\n", len(partners))
	for i, p := range partners {
		fmt.Printf("  %d. [%s] %s - %s\n", i+1, p.ID, p.Denumire, p.CodFiscal)
		if i >= 9 {
			fmt.Printf("  ... and %d more\n", len(partners)-10)
			break
		}
	}

	// Get stock articles.
	stock, err := client.GetStocArticole()
	if err != nil {
		log.Fatalf("GetStocArticole: %v", err)
	}
	fmt.Printf("\nStock articles (%d):\n", len(stock))
	for i, s := range stock {
		fmt.Printf("  %d. [%s] %s - Stoc: %s %s, Pret: %s\n",
			i+1, s.CodExtern, s.Denumire, s.Stoc, s.UM, s.PretVanzare)
		if i >= 9 {
			fmt.Printf("  ... and %d more\n", len(stock)-10)
			break
		}
	}

	// Get warehouses.
	gestiuni, err := client.GetListaGestiuni()
	if err != nil {
		log.Fatalf("GetListaGestiuni: %v", err)
	}
	fmt.Printf("\nWarehouses (%d):\n", len(gestiuni))
	for _, g := range gestiuni {
		fmt.Printf("  - [%s] %s\n", g.Simbol, g.Denumire)
	}

	// Example: Import invoices workflow.
	// 1. Prepare invoice data lines (semicolon-separated).
	// 2. Send to server via SetDocsData.
	// 3. Validate with DateValide.
	// 4. Import with ImportaFacturi.
	// 5. Check errors with GetListaErori.
	//
	// invoiceLines := []string{
	//     "header line...",
	//     "detail line 1...",
	//     "detail line 2...",
	// }
	// client.SetDocsData(invoiceLines)
	// if err := client.DateValide(); err != nil {
	//     errs, _ := client.GetListaErori()
	//     log.Fatalf("Validation errors: %v", errs)
	// }
	// count, err := client.ImportaFacturi()
	// fmt.Printf("Imported %d invoices\n", count)
}
