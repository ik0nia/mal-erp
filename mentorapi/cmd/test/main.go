package main

import (
	"fmt"
	"log"

	"github.com/rayone121/libWMEdcom/winmentor"
)

func main() {
	fmt.Println("Testing DocImpServer connection...")

	client, err := winmentor.NewClient()
	if err != nil {
		log.Fatalf("Failed to connect: %v", err)
	}
	defer client.Close()

	fmt.Println("Connected! Calling GetListaFirme...")

	firme, err := client.GetListaFirme()
	if err != nil {
		log.Fatalf("GetListaFirme failed: %v", err)
	}

	fmt.Printf("Found %d companies:\n", len(firme))
	for _, f := range firme {
		fmt.Printf("  - %s\n", f)
	}
}
