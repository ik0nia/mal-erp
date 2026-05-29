package winmentor

import (
	"fmt"
	"strings"
)

// --- Invoice Operations ---

// DateValide validates the invoice data packet previously sent via SetDocsData.
// Returns nil if valid.
func (c *Client) DateValide() error {
	result, err := c.callMethodInt("DateValide")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("DateValide: data invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("DateValide: data invalid")
	}
	return nil
}

// ImportaFacturi imports the invoices from data set via SetDocsData.
// Returns the number of imported invoices.
func (c *Client) ImportaFacturi() (int, error) {
	result, err := c.callMethodInt("ImportaFacturi")
	if err != nil {
		return 0, err
	}
	return result, nil
}

// ExistaFactura checks whether an invoice with the given number exists.
// Returns: -1 = error, 0 = doesn't exist, 1 = exists and is processed, 2 = exists and is unprocessed.
func (c *Client) ExistaFactura(numar int) (int, error) {
	return c.callMethodInt("ExistaFactura", numar)
}

// FactIntrareValida validates incoming invoice data sent via SetDocsData.
// Returns nil if valid.
func (c *Client) FactIntrareValida() error {
	result, err := c.callMethodInt("FactIntrareValida")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("FactIntrareValida: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("FactIntrareValida: data invalid")
	}
	return nil
}

// ImportaFactIntrare imports incoming invoices. Returns the number imported.
func (c *Client) ImportaFactIntrare() (int, error) {
	return c.callMethodInt("ImportaFactIntrare")
}

// --- Order Operations ---

// ComenziValide validates the order data sent via SetDocsData.
// Returns nil if valid.
func (c *Client) ComenziValide() error {
	result, err := c.callMethodInt("ComenziValide")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("ComenziValide: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("ComenziValide: data invalid")
	}
	return nil
}

// ImportaComenzi imports orders from SetDocsData. Returns the number imported.
func (c *Client) ImportaComenzi() (int, error) {
	return c.callMethodInt("ImportaComenzi")
}

// ComenziValideExt validates extended order structure.
func (c *Client) ComenziValideExt() error {
	result, err := c.callMethodInt("ComenziValideExt")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("ComenziValideExt: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("ComenziValideExt: data invalid")
	}
	return nil
}

// ImportaComenziExt imports extended orders. Returns the number imported.
func (c *Client) ImportaComenziExt() (int, error) {
	return c.callMethodInt("ImportaComenziExt")
}

// GetComenziNefacturate returns uninvoiced ordered articles.
// Raw format (26 fields): CodArticol;NrComanda;Cantitate;DenUM;DataComanda;IDPartener;MarcaAgent;
//   Pret;CantComanda;DenUM2;CodExtern2;SerieDocument;NrDocument;Observatii;SediuPartener;
//   Field15;DataLivrare;Field17;Field18;Field19;Pozitie;NumePartener;Flag;Field23;Moneda;Field25
func (c *Client) GetComenziNefacturate() ([]ComandaNefacturata, error) {
	records, err := c.callWithOutError("GetComenziNefacturate")
	if err != nil {
		return nil, err
	}

	var result []ComandaNefacturata
	for _, rec := range records {
		f := splitFields(rec, 26)
		result = append(result, ComandaNefacturata{
			CodArticol:    f[0],
			NrComanda:     f[1],
			Cantitate:     f[2],
			DenUM:         f[3],
			DataComanda:   f[4],
			IDPartener:    f[5],
			MarcaAgent:    f[6],
			Pret:          f[7],
			CantComanda:   f[8],
			CodExternAlt:  f[10],
			SerieDocument: f[11],
			NrDocument:    f[12],
			Observatii:    f[13],
			SediuPartener: f[14],
			DataLivrare:   f[16],
			Pozitie:       f[19],
			NumePartener:  f[20],
			Moneda:        f[23],
		})
	}
	return result, nil
}

// --- Cash Register (Monetare) ---

// MonetareValide validates cash register data sent via SetDocsData.
// Returns nil if valid. Per PDF: returns 0 if errors, 1 if valid.
func (c *Client) MonetareValide() error {
	result, err := c.callMethodInt("MonetareValide")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("MonetareValide: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("MonetareValide: data invalid")
	}
	return nil
}

// ImportaMonetare imports cash register data. Returns the number imported.
func (c *Client) ImportaMonetare() (int, error) {
	return c.callMethodInt("ImportaMonetare")
}

// --- Collections (Incasari) ---

// IncasariValideExt validates extended collection data sent via SetDocsData.
func (c *Client) IncasariValideExt() error {
	result, err := c.callMethodInt("IncasariValideExt")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("IncasariValideExt: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("IncasariValideExt: data invalid")
	}
	return nil
}

// ImportaIncasariExt imports extended collection data.
func (c *Client) ImportaIncasariExt() (int, error) {
	return c.callMethodInt("ImportaIncasariExt")
}

// PlatiValideExt validates payment packets sent via SetDocsData.
func (c *Client) PlatiValideExt() error {
	result, err := c.callMethodInt("PlatiValideExt")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("PlatiValideExt: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("PlatiValideExt: data invalid")
	}
	return nil
}

// ImportaPlatiExt imports payment packets.
func (c *Client) ImportaPlatiExt() (int, error) {
	return c.callMethodInt("ImportaPlatiExt")
}

// --- Consumption Notes (Bonuri de consum) ---

// BonuriConsumValide validates consumption note data.
func (c *Client) BonuriConsumValide() error {
	result, err := c.callMethodInt("BonuriConsumValide")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("BonuriConsumValide: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("BonuriConsumValide: data invalid")
	}
	return nil
}

// ImportaBonuriConsum imports consumption notes.
func (c *Client) ImportaBonuriConsum() (int, error) {
	return c.callMethodInt("ImportaBonuriConsum")
}

// --- Transfers ---

// TransferuriValide validates transfer data sent via SetDocsData.
func (c *Client) TransferuriValide() error {
	result, err := c.callMethodInt("TransferuriValide")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("TransferuriValide: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("TransferuriValide: data invalid")
	}
	return nil
}

// ImportaTransferuri imports warehouse transfers.
func (c *Client) ImportaTransferuri() (int, error) {
	return c.callMethodInt("ImportaTransferuri")
}

// --- Accounting Notes ---

// NCValide validates accounting notes sent via SetDocsData.
func (c *Client) NCValide() error {
	result, err := c.callMethodInt("NCValide")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("NCValide: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("NCValide: data invalid")
	}
	return nil
}

// ImportaNoteContabile imports accounting notes.
func (c *Client) ImportaNoteContabile() (int, error) {
	return c.callMethodInt("ImportaNoteContabile")
}

// --- Price Modifications ---

// ModifPretValide validates price modification data.
func (c *Client) ModifPretValide() error {
	result, err := c.callMethodInt("ModifPretValide")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("ModifPretValide: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("ModifPretValide: data invalid")
	}
	return nil
}

// ImportaModifPret imports price modifications.
func (c *Client) ImportaModifPret() (int, error) {
	return c.callMethodInt("ImportaModifPret")
}

// --- Supplier Orders ---

// ComenziFurnValide validates supplier order data.
func (c *Client) ComenziFurnValide() error {
	result, err := c.callMethodInt("ComenziFurnValide")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("ComenziFurnValide: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("ComenziFurnValide: data invalid")
	}
	return nil
}

// ImportaComenziFurn imports supplier orders.
func (c *Client) ImportaComenziFurn() (int, error) {
	return c.callMethodInt("ImportaComenziFurn")
}

// --- Collections (basic, non-extended) ---

// IncasariValide validates collection data sent via SetDocsData.
// DLL signature: IncasariValide(): Integer
func (c *Client) IncasariValide() error {
	result, err := c.callMethodInt("IncasariValide")
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("IncasariValide: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("IncasariValide: data invalid")
	}
	return nil
}

// ImportaIncasari imports collection data.
// DLL signature: ImportaIncasari(): Integer
func (c *Client) ImportaIncasari() (int, error) {
	return c.callMethodInt("ImportaIncasari")
}

// --- Inventory Adjustments ---

// ReglareInventarValida validates inventory adjustment data.
// DLL signature: ReglareInventarValida(TipReglare: Integer): Integer
func (c *Client) ReglareInventarValida(tipReglare int) error {
	result, err := c.callMethodInt("ReglareInventarValida", tipReglare)
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("ReglareInventarValida: invalid: %s", strings.Join(errs, "; "))
		}
		return fmt.Errorf("ReglareInventarValida: data invalid")
	}
	return nil
}

// ImportaReglareInventar imports inventory adjustments.
// DLL signature: ImportaReglareInventar(TipReglare: Integer): Integer
func (c *Client) ImportaReglareInventar(tipReglare int) (int, error) {
	return c.callMethodInt("ImportaReglareInventar", tipReglare)
}
