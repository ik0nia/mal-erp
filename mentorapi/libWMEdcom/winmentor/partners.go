package winmentor

import (
	"fmt"
	"strings"
	"unsafe"

	ole "github.com/go-ole/go-ole"
)

// GetListaParteneri returns the full list of partners.
// PDF documents 38 fields but the DLL actually returns 49.
// Field layout diverges from the PDF starting at index [17].
func (c *Client) GetListaParteneri() ([]Partner, error) {
	records, err := c.callWithOutError("GetListaParteneri")
	if err != nil {
		return nil, err
	}

	partners := make([]Partner, 0, len(records))
	for _, rec := range records {
		f := splitFields(rec, 49)
		partners = append(partners, Partner{
			ID:               f[0],
			Denumire:         f[1],
			CodFiscal:        f[2],
			Localitate:       f[3],
			Adresa:           f[4],
			Telefon:          f[5],
			PersContact:      f[6],
			SimbolClasa:      f[7],
			DenClasa:         f[8],
			SimbolCatPret:    f[9],
			DenCatPret:       f[10],
			MarcaAgent:       f[11],
			NumeAgent:        f[12],
			PrenumeAgent:     f[13],
			Scadenta:         f[14],
			Discount:         f[15],
			DenCritDiscount:  f[16],
			CodExtern:        f[17],
			PartnerBlocat:    f[18],
			CreditVanzare:    f[19],
			NrRegCom:         f[20],
			ContBanca:        f[21],
			LocalitatiSedii:  f[22],
			Judet:            f[23],
			MarcaAgentiSedii: f[24],
			Observatii:       f[25],
			FlagSediuSocial:  f[26],
			CodPostalSedii:   f[27],
			EmailSedii:       f[28],
			TelPersContact:   f[29],
			PFsauPJ:          f[30],
			MonedaImplicita:  f[31],
			DataAdaugarii:    f[32],
			Trasee:           f[33],
			PuncteAcumulate:  f[34],
			CodFiscalSedii:   f[35],
			InfoTipSediu:     f[36],
			FlagPlataCard:     f[37],
			FlagNonUE:         f[38],
			DenumiriSediiExt:  f[39],
			SerieBuletinSedii: f[40],
			NumarBuletinSedii: f[41],
			Tara:              f[42],
			TelefonSediiExt:   f[43],
			FlagRezilient:     f[44],
			LimitaCredit:      f[45],
			SoldCurent:        f[46],
			PuncteBonus:       f[47],
			Unknown48:         f[48],
		})
	}
	return partners, nil
}

// GetSoldPart returns the balance for a given partner ID.
// Format: "CodExtern;Denumire;Sold"
func (c *Client) GetSoldPart(partID string) (*SoldPartener, error) {
	records, err := c.callWithOutError("GetSoldPart", partID)
	if err != nil {
		return nil, err
	}
	if len(records) == 0 {
		return nil, nil
	}
	f := splitFields(records[0], 3)
	return &SoldPartener{
		CodExtern: f[0],
		Denumire:  f[1],
		Sold:      f[2],
	}, nil
}

// GetSoldDetaliat returns the detailed balance for a partner, broken down by invoices and advances.
func (c *Client) GetSoldDetaliat(partID string) ([]DetailedBalance, error) {
	records, err := c.callWithOutError("GetSoldDetaliat", partID)
	if err != nil {
		return nil, err
	}

	var result []DetailedBalance
	for _, rec := range records {
		f := splitFields(rec, 11)
		result = append(result, DetailedBalance{
			Type:         f[0],
			NrDocument:   f[1],
			DataDocument: f[2],
			Rest:         f[3],
			DataScadenta: f[4],
			MarcaAgent:   f[5],
			Moneda:       f[6],
			Sediu:        f[7],
		})
	}
	return result, nil
}

// GetClaseParteneri returns the list of partner classes.
func (c *Client) GetClaseParteneri() ([]ClasaParteneri, error) {
	records, err := c.callWithOutError("GetClaseParteneri")
	if err != nil {
		return nil, err
	}

	var result []ClasaParteneri
	for _, rec := range records {
		f := splitFields(rec, 2)
		result = append(result, ClasaParteneri{
			Simbol:   f[0],
			Denumire: f[1],
		})
	}
	return result, nil
}

// AdaugaPartener adds a new partner. Returns the new partner ID on success.
func (c *Client) AdaugaPartener(p *PartnerInput) (int, error) {
	result, err := c.callMethodInt("AdaugaPartener", p.ToRecord())
	if err != nil {
		return 0, err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return 0, fmt.Errorf("AdaugaPartener failed: %s", strings.Join(errs, "; "))
		}
		return 0, fmt.Errorf("AdaugaPartener failed")
	}
	return result, nil
}

// ModificaPartener modifies an existing partner. Same structure as AdaugaPartener.
func (c *Client) ModificaPartener(p *PartnerInput) (int, error) {
	result, err := c.callMethodInt("ModificaPartener", p.ToRecord())
	if err != nil {
		return 0, err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return 0, fmt.Errorf("ModificaPartener failed: %s", strings.Join(errs, "; "))
		}
		return 0, fmt.Errorf("ModificaPartener failed")
	}
	return result, nil
}

// GetNextPartID returns the next available partner ID (assumes numeric IDs).
// Signature: GetNextPartID(out Error: Integer): WideString
// COM vtable: HRESULT GetNextPartID([out] long*, [out,retval] BSTR*)
func (c *Client) GetNextPartID() (result string, err error) {
	c.comDo(func() {
		if c.vtbl != nil {
			if _, ok := c.vtbl.methods["GetNextPartID"]; ok {
				var errParam int32
				var resPtr *uint16
				_, err = c.vtblCall("GetNextPartID", unsafe.Pointer(&errParam), unsafe.Pointer(&resPtr))
				if err == nil {
					if errParam != 0 {
						errs, _ := c.rawGetListaErori()
						if len(errs) > 0 {
							err = fmt.Errorf("GetNextPartID failed: %s", strings.Join(errs, "; "))
							return
						}
						err = fmt.Errorf("GetNextPartID failed with error code %d", errParam)
						return
					}
					result = ole.BstrToString(resPtr)
					ole.SysFreeString((*int16)(unsafe.Pointer(resPtr)))
					return
				}
			}
		}

		var errParam int32
		errVariant := ole.NewVariant(ole.VT_I4|ole.VT_BYREF, int64(uintptr(unsafe.Pointer(&errParam))))

		var v *ole.VARIANT
		v, err = c.rawCall("GetNextPartID", &errVariant)
		if err != nil {
			return
		}
		defer v.Clear()

		if errParam != 0 {
			errs, _ := c.rawGetListaErori()
			if len(errs) > 0 {
				err = fmt.Errorf("GetNextPartID failed: %s", strings.Join(errs, "; "))
				return
			}
			err = fmt.Errorf("GetNextPartID failed with error code %d", errParam)
			return
		}

		result = v.ToString()
	})
	return
}

// GenCodParteneri generates the next internal/external/fiscal code for partners,
// depending on the "Cod pentru identificare partener" constant setting.
// Returns the code or -1 on error.
func (c *Client) GenCodParteneri() (int, error) {
	return c.callMethodInt("GenCodParteneri")
}

// GetInfoPart returns partner info for a given PartID.
func (c *Client) GetInfoPart(partID string) ([]string, error) {
	return c.callWithOutError("GetInfoPart", partID)
}

// GetListaClienti returns a list of clients starting from a given year/month.
// Format: "CodIntern;CodExtern;Denumire;CodFiscal;Localitate;Judet;Adr;Tel;MarcaAgent;DataFact;SediiPart;Simbol_Clasa;Denumire_Clasa;LocalitSedii"
func (c *Client) GetListaClienti(anInceput, lunaInceput int) ([]ClientInfo, error) {
	records, err := c.callWithOutError("GetListaClienti", anInceput, lunaInceput)
	if err != nil {
		return nil, err
	}

	var result []ClientInfo
	for _, rec := range records {
		f := splitFields(rec, 14)
		result = append(result, ClientInfo{
			CodIntern:     f[0],
			CodExtern:     f[1],
			Denumire:      f[2],
			CodFiscal:     f[3],
			Localitate:    f[4],
			Judet:         f[5],
			Adresa:        f[6],
			Telefon:       f[7],
			MarcaAgent:    f[8],
			DataFact:      f[9],
			SediiPart:     f[10],
			SimbolClasa:   f[11],
			DenumireClasa: f[12],
			LocalitSedii:  f[13],
		})
	}
	return result, nil
}

// GetSolduriExt returns extended balance details for clients (invoices + advances).
func (c *Client) GetSolduriExt() ([]SoldExt, error) {
	records, err := c.callWithOutError("GetSolduriExt")
	if err != nil {
		return nil, err
	}

	var result []SoldExt
	for _, rec := range records {
		f := splitFields(rec, 13)
		result = append(result, SoldExt{
			IDPartener:        f[0],
			Tip:               f[1],
			NrFactura:         f[2],
			DataFactura:       f[3],
			RestDePlata:       f[4],
			TermenDePlata:     f[5],
			LocatiePartener:   f[6],
			MarcaAgent:        f[7],
			ValoareFactura:    f[8],
			ObservatiiFactura: f[9],
			Camp10:            f[10],
			CotaTVA:           f[11],
			TipDocument:       f[12],
		})
	}
	return result, nil
}

// GetSolduriFurn returns detailed supplier balances (same structure as GetSolduriExt).
func (c *Client) GetSolduriFurn() ([]SoldExt, error) {
	records, err := c.callWithOutError("GetSolduriFurn")
	if err != nil {
		return nil, err
	}

	var result []SoldExt
	for _, rec := range records {
		f := splitFields(rec, 13)
		result = append(result, SoldExt{
			IDPartener:        f[0],
			Tip:               f[1],
			NrFactura:         f[2],
			DataFactura:       f[3],
			RestDePlata:       f[4],
			TermenDePlata:     f[5],
			LocatiePartener:   f[6],
			MarcaAgent:        f[7],
			ValoareFactura:    f[8],
			ObservatiiFactura: f[9],
			Camp10:            f[10],
			CotaTVA:           f[11],
			TipDocument:       f[12],
		})
	}
	return result, nil
}

// GetListaLocalitati returns partner localities.
func (c *Client) GetListaLocalitati() ([]string, error) {
	return c.callWithOutError("GetListaLocalitati")
}
