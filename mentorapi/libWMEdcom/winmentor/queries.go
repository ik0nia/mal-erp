package winmentor

import (
	"fmt"
	"strings"
	"unsafe"

	"github.com/go-ole/go-ole"
)

// GetListaPersonal returns the employee list.
// Format: "Nume;Prenume;Marca;CNP;flagEsteActiv(Da/Nu);flagEsteAgent(Da/Nu);SerieBuletin;NumarBuletin;CodPostal"
func (c *Client) GetListaPersonal() ([]Employee, error) {
	records, err := c.callWithOutError("GetListaPersonal")
	if err != nil {
		return nil, err
	}

	var result []Employee
	for _, rec := range records {
		f := splitFields(rec, 10)
		result = append(result, Employee{
			Nume:         f[0],
			Marca:        f[1],
			CNP:          f[2],
			EsteActiv:    f[3],
			EsteAgent:    f[4],
			SerieBuletin: f[5],
			NumarBuletin: f[6],
			CodPostal:    f[7],
		})
	}
	return result, nil
}

// GetInfoPers returns employee info for a given personnel ID (Marca).
func (c *Client) GetInfoPers(idPers int) ([]string, error) {
	return c.callWithOutError("GetInfoPers", idPers)
}

// GetListaGestiuni returns the list of warehouses.
// PDF documents 2 fields but the DLL returns 3 (trailing empty field).
func (c *Client) GetListaGestiuni() ([]Gestiune, error) {
	records, err := c.callWithOutError("GetListaGestiuni")
	if err != nil {
		return nil, err
	}

	var result []Gestiune
	for _, rec := range records {
		f := splitFields(rec, 3) // 3rd field is always empty (trailing semicolon)
		result = append(result, Gestiune{
			Simbol:   f[0],
			Denumire: f[1],
		})
	}
	return result, nil
}

// GetListaBanci returns the national bank list.
// Raw format: "Simbol;Denumire;Field2;Field3;Field4" (5 fields, last 3 always empty)
func (c *Client) GetListaBanci() ([]Bank, error) {
	records, err := c.callWithOutError("GetListaBanci")
	if err != nil {
		return nil, err
	}

	var result []Bank
	for _, rec := range records {
		f := splitFields(rec, 5)
		result = append(result, Bank{
			Simbol:   f[0],
			Denumire: f[1],
		})
	}
	return result, nil
}

// GetVanzariExt returns the extended sales list for the work month.
// PDF documents 18 fields but the DLL actually returns 23.
func (c *Client) GetVanzariExt() ([]VanzareExt, error) {
	records, err := c.callWithOutError("GetVanzariExt")
	if err != nil {
		return nil, err
	}

	var result []VanzareExt
	for _, rec := range records {
		f := splitFields(rec, 23)
		result = append(result, VanzareExt{
			IDPartener:      f[0],
			Zi:              f[1],
			NrFactura:       f[2],
			CodArticol:      f[3],
			Cant:            f[4],
			DenUM:           f[5],
			Pret:            f[6],
			DenGest:         f[7],
			Unknown8:        f[8],
			LocatieClient:   f[9],
			MarcaAgent:      f[10],
			CodFiscal:       f[11],
			Unknown12:       f[12],
			Adresa:          f[13],
			Unknown14:       f[14],
			CodPostal:       f[15],
			ClasaArticol:    f[16],
			TipDocument:     f[17],
			PozitieDocument: f[18],
			PrefixCarnet:    f[19],
			Moneda:          f[20],
			Unknown21:       f[21],
			Unknown22:       f[22],
		})
	}
	return result, nil
}

// GetVanzariLuna returns the monthly sales (invoices).
// PDF documents 10 fields but the DLL actually returns 26.
func (c *Client) GetVanzariLuna() ([]VanzareLuna, error) {
	records, err := c.callWithOutError("GetVanzariLuna")
	if err != nil {
		return nil, err
	}

	var result []VanzareLuna
	for _, rec := range records {
		f := splitFields(rec, 26)
		result = append(result, VanzareLuna{
			IDPartener:        f[0],
			Zi:                f[1],
			NrFactura:         f[2],
			CodArticol:        f[3],
			NumarComanda:      f[4],
			Cant:              f[5],
			DenUM:             f[6],
			Pret:              f[7],
			MarcaAgent:        f[8],
			ValoareFactura:    f[9],
			DataScadenta:      f[10],
			TVAInclus:         f[11],
			CotaTVA:           f[12],
			TipDocument:       f[13],
			PrefixCarnet:      f[14],
			SerieDocument:     f[15],
			DenArticol:        f[16],
			Discount:          f[17],
			Unknown18:         f[18],
			DataEmitere:       f[19],
			SediuClient:       f[20],
			AdresaClient:      f[21],
			LocalitateClient:  f[22],
			ObservatiiFactura: f[23],
			Observatii2:       f[24],
			Unknown25:         f[25],
		})
	}
	return result, nil
}

// GetIncasariClienti returns the total collection value for a client in a given interval.
func (c *Client) GetIncasariClienti(an1, luna1, an2, luna2 int, partID string) ([]string, error) {
	return c.callWithOutError("GetIncasariClienti", an1, luna1, an2, luna2, partID)
}

// GetSolduri returns agent-level balance records.
// Invoices: "NrDoc;DataDoc;Rest;Termen;Agent;Valoare"
// Advances: "Doc;NrDoc;DataDoc;Suma;Agent;Valoare"
func (c *Client) GetSolduri() ([]Sold, error) {
	records, err := c.callWithOutError("GetSolduri")
	if err != nil {
		return nil, err
	}

	var result []Sold
	for _, rec := range records {
		f := splitFields(rec, 11)
		result = append(result, Sold{
			IDPartener:   f[0],
			TipDocument:  f[1],
			NrDocument:   f[2],
			DataDocument: f[3],
			RestDePlata:  f[4],
			DataScadenta: f[5],
			Sediu:        f[6],
			MarcaAgent:   f[7],
			ValoareDoc:   f[8],
			Moneda:       f[9],
			Camp10:       f[10],
		})
	}
	return result, nil
}

// GetListaCarnete returns the document book list.
func (c *Client) GetListaCarnete() ([]Carnet, error) {
	records, err := c.callWithOutError("GetListaCarnete")
	if err != nil {
		return nil, err
	}

	var result []Carnet
	for _, rec := range records {
		f := splitFields(rec, 4)
		result = append(result, Carnet{
			Simbol:      f[0],
			TipDocument: f[1],
			Denumire:    f[2],
		})
	}
	return result, nil
}

// GetNumarFactura checks if an invoice number is used within a document book.
// Signature: GetNumarFactura(const SimbolCarnet: WideString; out Error: Integer): Integer
// COM vtable: HRESULT GetNumarFactura([in] BSTR, [out] long*, [out,retval] long*)
func (c *Client) GetNumarFactura(simbolCarnet string) (result int, err error) {
	c.comDo(func() {
		if c.vtbl != nil {
			if _, ok := c.vtbl.methods["GetNumarFactura"]; ok {
				var errParam, retVal int32
				_, err = c.vtblCall("GetNumarFactura", simbolCarnet, unsafe.Pointer(&errParam), unsafe.Pointer(&retVal))
				if err == nil {
					if errParam != 0 {
						errs, _ := c.rawGetListaErori()
						if len(errs) > 0 {
							err = fmt.Errorf("GetNumarFactura failed: %s", strings.Join(errs, "; "))
							return
						}
						err = fmt.Errorf("GetNumarFactura failed with error code %d", errParam)
						return
					}
					result = int(retVal)
					return
				}
			}
		}

		var errParam int32
		errVariant := ole.NewVariant(ole.VT_I4|ole.VT_BYREF, int64(uintptr(unsafe.Pointer(&errParam))))

		var v *ole.VARIANT
		v, err = c.rawCall("GetNumarFactura", simbolCarnet, &errVariant)
		if err != nil {
			return
		}
		defer v.Clear()

		if errParam != 0 {
			errs, _ := c.rawGetListaErori()
			if len(errs) > 0 {
				err = fmt.Errorf("GetNumarFactura failed: %s", strings.Join(errs, "; "))
				return
			}
			err = fmt.Errorf("GetNumarFactura failed with error code %d", errParam)
			return
		}

		result = int(v.Val)
	})
	return
}

// GetArticoleVandute returns articles sold to a specific client by an agent from a given year/month.
func (c *Client) GetArticoleVandute(partID string, marcaAgent, anInceput, lunaInceput int) ([]string, error) {
	return c.callWithOutError("GetArticoleVandute", partID, marcaAgent, anInceput, lunaInceput)
}

// GetUltimeleVanzari returns the last N sales of an article to a partner by an agent.
func (c *Client) GetUltimeleVanzari(artID, partID string, marcaAgent, cate int) ([]string, error) {
	return c.callWithOutError("GetUltimeleVanzari", artID, partID, marcaAgent, cate)
}

// GetSoldFactNeop returns balances for unprocessed invoices.
// DLL signature: GetSoldFactNeop(PartID: WideString; out Error: Integer): WideString
// COM vtable: HRESULT GetSoldFactNeop([in] BSTR, [out] long*, [out,retval] BSTR*)
func (c *Client) GetSoldFactNeop(partID string) (result string, err error) {
	c.comDo(func() {
		if c.vtbl != nil {
			if _, ok := c.vtbl.methods["GetSoldFactNeop"]; ok {
				var errParam int32
				var resPtr *uint16
				_, vtErr := c.vtblCall("GetSoldFactNeop", partID, unsafe.Pointer(&errParam), unsafe.Pointer(&resPtr))
				if vtErr != nil {
					// Return vtable error directly (don't fallback to IDispatch)
					err = fmt.Errorf("GetSoldFactNeop vtable: %w", vtErr)
					return
				}
				if errParam != 0 {
					errs, _ := c.rawGetListaErori()
					if len(errs) > 0 {
						err = fmt.Errorf("GetSoldFactNeop failed: %s", strings.Join(errs, "; "))
						return
					}
					err = fmt.Errorf("GetSoldFactNeop failed with error code %d", errParam)
					return
				}
				result = ole.BstrToString(resPtr)
				if resPtr != nil {
					ole.SysFreeString((*int16)(unsafe.Pointer(resPtr)))
				}
				return
			}
		}
		err = fmt.Errorf("GetSoldFactNeop: vtable method not found")
	})
	return
}

// GetIncasariLuna returns monthly collections.
func (c *Client) GetIncasariLuna() ([]string, error) {
	return c.callWithOutError("GetIncasariLuna")
}

// GetMonede returns the list of currencies.
func (c *Client) GetMonede() ([]string, error) {
	return c.callWithOutError("GetMonede")
}

// GetListaSubunit returns the list of sub-units.
func (c *Client) GetListaSubunit() ([]string, error) {
	return c.callWithOutError("GetListaSubunit")
}

// GetIntrari returns incoming entries (purchases).
// DLL returns 11 fields per record.
func (c *Client) GetIntrari() ([]Intrare, error) {
	records, err := c.callWithOutError("GetIntrari")
	if err != nil {
		return nil, err
	}

	var result []Intrare
	for _, rec := range records {
		f := splitFields(rec, 11)
		result = append(result, Intrare{
			IDPartener: f[0],
			Data:       f[1],
			NrDoc:      f[2],
			CodArticol: f[3],
			Cant:       f[4],
			DenUM:      f[5],
			Pret:       f[6],
			DenGest:    f[7],
			Unknown8:   f[8],
			Flag:       f[9],
			Unknown10:  f[10],
		})
	}
	return result, nil
}

// GetReceptii returns receptions.
func (c *Client) GetReceptii() ([]string, error) {
	return c.callWithOutError("GetReceptii")
}

// GetTransferuri returns warehouse transfers.
func (c *Client) GetTransferuri() ([]string, error) {
	return c.callWithOutError("GetTransferuri")
}

// GetTranzactiiInCurs returns in-progress transactions.
func (c *Client) GetTranzactiiInCurs() ([]string, error) {
	return c.callWithOutError("GetTranzactiiInCurs")
}

// GetStocuriPeGestiuni returns stock levels per warehouse with stock valuations.
func (c *Client) GetStocuriPeGestiuni() ([]StocGestiune, error) {
	records, err := c.callWithOutError("GetStocuriPeGestiuni")
	if err != nil {
		return nil, err
	}

	var result []StocGestiune
	for _, rec := range records {
		f := splitFields(rec, 10)
		result = append(result, StocGestiune{
			DenGestiune:        f[0],
			SimbolGestiune:     f[1],
			Denumire:           f[2],
			CodExtern:          f[3],
			ContContabil:       f[4],
			UM:                 f[5],
			Stoc:               f[6],
			ValoareStoc:        f[7],
			ValoareStocPrecisa: f[8],
			CotaTVA:            f[9],
		})
	}
	return result, nil
}

// GetDispozitiiDeLivrare returns delivery dispositions.
// DLL signature: GetDispozitiiDeLivrare(GestID: WideString; out Error: Integer): OleVariant
func (c *Client) GetDispozitiiDeLivrare(gestID string) ([]string, error) {
	return c.callWithOutError("GetDispozitiiDeLivrare", gestID)
}

// GetNomenclatorLocalitati returns the locality nomenclature.
func (c *Client) GetNomenclatorLocalitati() ([]string, error) {
	return c.callWithOutError("GetNomenclatorLocalitati")
}

// --- Misc operations ---

// AddProduct adds a product via the COM server.
// DLL signature: AddProduct(InfoProdus: WideString): Integer
func (c *Client) AddProduct(infoProdus string) (int, error) {
	return c.callMethodInt("AddProduct", infoProdus)
}

// ModiProduct modifies a product via the COM server.
// DLL signature: ModiProduct(InfoProdus: WideString): Integer
func (c *Client) ModiProduct(infoProdus string) (int, error) {
	return c.callMethodInt("ModiProduct", infoProdus)
}

// AdaugaGestiune adds a new warehouse.
// DLL signature: AdaugaGestiune(InfoGest: WideString): Integer
func (c *Client) AdaugaGestiune(infoGest string) (int, error) {
	return c.callMethodInt("AdaugaGestiune", infoGest)
}

// CheckDocument checks a document.
// DLL signature: CheckDocument(TipDoc, PrefixDoc: WideString; NrDoc: Integer; out Error: Integer): Integer
// COM vtable: HRESULT CheckDocument([in] BSTR, [in] BSTR, [in] long, [out] long*, [out,retval] long*)
func (c *Client) CheckDocument(tipDoc, prefixDoc string, nrDoc int) (result int, err error) {
	c.comDo(func() {
		if c.vtbl != nil {
			if _, ok := c.vtbl.methods["CheckDocument"]; ok {
				var errParam, retVal int32
				_, err = c.vtblCall("CheckDocument", tipDoc, prefixDoc, nrDoc, unsafe.Pointer(&errParam), unsafe.Pointer(&retVal))
				if err == nil {
					if errParam != 0 {
						errs, _ := c.rawGetListaErori()
						if len(errs) > 0 {
							err = fmt.Errorf("CheckDocument failed: %s", strings.Join(errs, "; "))
							return
						}
						err = fmt.Errorf("CheckDocument failed with error code %d", errParam)
						return
					}
					result = int(retVal)
					return
				}
			}
		}

		var errParam int32
		errVariant := ole.NewVariant(ole.VT_I4|ole.VT_BYREF, int64(uintptr(unsafe.Pointer(&errParam))))

		var v *ole.VARIANT
		v, err = c.rawCall("CheckDocument", tipDoc, prefixDoc, nrDoc, &errVariant)
		if err != nil {
			return
		}
		defer v.Clear()

		if errParam != 0 {
			errs, _ := c.rawGetListaErori()
			if len(errs) > 0 {
				err = fmt.Errorf("CheckDocument failed: %s", strings.Join(errs, "; "))
				return
			}
			err = fmt.Errorf("CheckDocument failed with error code %d", errParam)
			return
		}

		result = int(v.Val)
	})
	return
}

// PotIntroduceDoc checks if a document can be introduced.
// DLL signature: PotIntroduceDoc(An, Luna: Integer): Integer
func (c *Client) PotIntroduceDoc(an, luna int) (int, error) {
	return c.callMethodInt("PotIntroduceDoc", an, luna)
}

// SetDescarcareAutomata sets automatic discharge mode.
// DLL signature: SetDescarcareAutomata(Automat: Integer): void
func (c *Client) SetDescarcareAutomata(automat int) error {
	return c.callMethodVoid("SetDescarcareAutomata", automat)
}

// SetCmdImplicitAcceptat sets the default order acceptance flag.
// DLL signature: SetCmdImplicitAcceptat(ImplicitAcceptat: Integer): void
func (c *Client) SetCmdImplicitAcceptat(implicitAcceptat int) error {
	return c.callMethodVoid("SetCmdImplicitAcceptat", implicitAcceptat)
}

// ActualizeazaAcceptat updates acceptance status for order lines.
// DLL signature: ActualizeazaAcceptat(LiniiComenzi: OleVariant): Integer
func (c *Client) ActualizeazaAcceptat(liniiComenzi []string) (int, error) {
	return c.callMethodInt("ActualizeazaAcceptat", liniiComenzi)
}

// GetDocFromFile reads document data from a file.
// DLL signature: GetDocFromFile(FileName: WideString): Integer
func (c *Client) GetDocFromFile(fileName string) (int, error) {
	return c.callMethodInt("GetDocFromFile", fileName)
}

// SetClasaArt sets the article class.
// DLL signature: SetClasaArt(NumeClasa: WideString): void
func (c *Client) SetClasaArt(numeClasa string) error {
	return c.callMethodVoid("SetClasaArt", numeClasa)
}

// AddClasaArt adds an article class.
// DLL signature: AddClasaArt(InfoClasa: WideString): Integer
func (c *Client) AddClasaArt(infoClasa string) (int, error) {
	return c.callMethodInt("AddClasaArt", infoClasa)
}

// GetNirAtribute returns NIR (goods reception note) attributes.
func (c *Client) GetNirAtribute() ([]string, error) {
	return c.callWithOutError("GetNirAtribute")
}

// GetStocInitialAmbalaj returns initial packaging stock.
// DLL signature: GetStocInitialAmbalaj(An, Luna: Integer; out Error: Integer): OleVariant
func (c *Client) GetStocInitialAmbalaj(an, luna int) ([]string, error) {
	return c.callWithOutError("GetStocInitialAmbalaj", an, luna)
}

// GetMiscariAmbalaje returns packaging movements.
// DLL signature: GetMiscariAmbalaje(AnStart, LunaStart, AnStop, LunaStop: Integer; out Error: Integer): OleVariant
func (c *Client) GetMiscariAmbalaje(anStart, lunaStart, anStop, lunaStop int) ([]string, error) {
	return c.callWithOutError("GetMiscariAmbalaje", anStart, lunaStart, anStop, lunaStop)
}

// SetIndexNart is a deprecated method (predecessor to SetIDArtField). Kept for compatibility.
func (c *Client) SetIndexNart(indexName string) error {
	return c.callMethodVoid("SetIndexNart", indexName)
}

// ExistaFacturaExt extended invoice existence check.
// DLL signature: ExistaFacturaExt(Numar: Integer; Serie: WideString): Integer
func (c *Client) ExistaFacturaExt(numar int, serie string) (int, error) {
	return c.callMethodInt("ExistaFacturaExt", numar, serie)
}

// ExistaFacturaIntrare checks if an incoming invoice exists.
// DLL signature: ExistaFacturaIntrare(PartID, Serie: WideString; Numar: Integer): Integer
func (c *Client) ExistaFacturaIntrare(partID, serie string, numar int) (int, error) {
	return c.callMethodInt("ExistaFacturaIntrare", partID, serie, numar)
}

// GetStocArticoleExt returns extended stock articles.
func (c *Client) GetStocArticoleExt() ([]string, error) {
	return c.callWithOutError("GetStocArticoleExt")
}

// GetStocArticoleExt2 returns extended stock articles (variant 2).
// DLL signature: GetStocArticoleExt2(GestID: WideString; out Error: Integer): OleVariant
func (c *Client) GetStocArticoleExt2(gestID string) ([]string, error) {
	return c.callWithOutError("GetStocArticoleExt2", gestID)
}

// GetStocArtDetaliat returns detailed article stock.
// DLL signature: GetStocArtDetaliat(ArtID, GestID: WideString; out Error: Integer): OleVariant
func (c *Client) GetStocArtDetaliat(artID, gestID string) ([]string, error) {
	return c.callWithOutError("GetStocArtDetaliat", artID, gestID)
}

// GetListaArtCatPretExt returns extended article price categories.
func (c *Client) GetListaArtCatPretExt() ([]string, error) {
	return c.callWithOutError("GetListaArtCatPretExt")
}

// GetListaDelegati returns the list of delegates.
func (c *Client) GetListaDelegati() ([]string, error) {
	return c.callWithOutError("GetListaDelegati")
}

// GetOferteClienti returns client offers.
func (c *Client) GetOferteClienti() ([]string, error) {
	return c.callWithOutError("GetOferteClienti")
}

// GetCritDiscPeArticole returns discount criteria per article.
func (c *Client) GetCritDiscPeArticole() ([]string, error) {
	return c.callWithOutError("GetCritDiscPeArticole")
}

// GetCritDiscPeClase returns discount criteria per class.
func (c *Client) GetCritDiscPeClase() ([]string, error) {
	return c.callWithOutError("GetCritDiscPeClase")
}

// GetCritDiscPart returns partner discount criteria.
func (c *Client) GetCritDiscPart() ([]string, error) {
	return c.callWithOutError("GetCritDiscPart")
}

// GetSuppliersOrders returns supplier orders.
func (c *Client) GetSuppliersOrders() ([]string, error) {
	return c.callWithOutError("GetSuppliersOrders")
}

// GetInventoryOrders returns inventory orders.
// DLL signature: GetInventoryOrders(GestID: WideString; out Error: Integer): OleVariant
func (c *Client) GetInventoryOrders(gestID string) ([]string, error) {
	return c.callWithOutError("GetInventoryOrders", gestID)
}

// SetInventoryOrders sets inventory orders data.
// DLL signature: SetInventoryOrders(Data: OleVariant): Integer
func (c *Client) SetInventoryOrders(data []string) (int, error) {
	return c.callMethodInt("SetInventoryOrders", data)
}

// SetReceivingList sets the receiving list data.
// DLL signature: SetReceivingList(Data: OleVariant): Integer
func (c *Client) SetReceivingList(data []string) (int, error) {
	return c.callMethodInt("SetReceivingList", data)
}

// GetReceivingStatus returns receiving status.
// DLL signature: GetReceivingStatus(PartID, SerieDoc, NrDoc: WideString): WideString
func (c *Client) GetReceivingStatus(partID, serieDoc, nrDoc string) (string, error) {
	return c.callMethodString("GetReceivingStatus", partID, serieDoc, nrDoc)
}

// SetPickedList sets the picked list data.
// DLL signature: SetPickedList(Data: OleVariant): Integer
func (c *Client) SetPickedList(data []string) (int, error) {
	return c.callMethodInt("SetPickedList", data)
}

// GetDeliveryOrders returns delivery orders.
func (c *Client) GetDeliveryOrders() ([]string, error) {
	return c.callWithOutError("GetDeliveryOrders")
}

// SetDeliveryList sets the delivery list data.
// DLL signature: SetDeliveryList(Data: OleVariant): Integer
func (c *Client) SetDeliveryList(data []string) (int, error) {
	return c.callMethodInt("SetDeliveryList", data)
}

// GetListacarneteExt returns extended document book list.
func (c *Client) GetListacarneteExt() ([]string, error) {
	return c.callWithOutError("GetListacarneteExt")
}

// GetInfoCmdNefacturate returns info on uninvoiced orders.
func (c *Client) GetInfoCmdNefacturate() ([]string, error) {
	return c.callWithOutError("GetInfoCmdNefacturate")
}

// GetInfoCmdSubNefacturate returns sub-order uninvoiced info.
func (c *Client) GetInfoCmdSubNefacturate() ([]string, error) {
	return c.callWithOutError("GetInfoCmdSubNefacturate")
}

// GetIncasariFactura returns collections per invoice.
// DLL signature: GetIncasariFactura(An, Luna, NrFact: Integer; SerieFact, IDPart: WideString): OleVariant
func (c *Client) GetIncasariFactura(an, luna, nrFact int, serieFact, idPart string) ([]string, error) {
	return c.callReturningStrings("GetIncasariFactura", an, luna, nrFact, serieFact, idPart)
}

// GetInfoBon returns receipt info for a given day.
// DLL signature: GetInfoBon(Zi: Integer; out Error: Integer): OleVariant
func (c *Client) GetInfoBon(zi int) ([]string, error) {
	return c.callWithOutError("GetInfoBon", zi)
}

// GetInfoBonExt returns extended receipt info.
// DLL signature: GetInfoBonExt(NrBon, Zi: Integer; out Error: Integer): OleVariant
func (c *Client) GetInfoBonExt(nrBon, zi int) ([]string, error) {
	return c.callWithOutError("GetInfoBonExt", nrBon, zi)
}

// GetInfoIesiri returns outgoing entries info for a given day.
// DLL signature: GetInfoIesiri(Zi: Integer; out Error: Integer): OleVariant
func (c *Client) GetInfoIesiri(zi int) ([]string, error) {
	return c.callWithOutError("GetInfoIesiri", zi)
}

// GetInfoIesiriExt returns extended outgoing entries info.
// DLL signature: GetInfoIesiriExt(NrDoc, Zi: Integer; out Error: Integer): OleVariant
func (c *Client) GetInfoIesiriExt(nrDoc, zi int) ([]string, error) {
	return c.callWithOutError("GetInfoIesiriExt", nrDoc, zi)
}

// GetInfoCmdBon returns command receipt info.
// DLL signature: GetInfoCmdBon(NrCmd: Integer; out Error: Integer): OleVariant
func (c *Client) GetInfoCmdBon(nrCmd int) ([]string, error) {
	return c.callWithOutError("GetInfoCmdBon", nrCmd)
}

// GetInfoCmdBonuri returns command receipts info.
// DLL signature: GetInfoCmdBonuri(Zi: Integer; out Error: Integer): OleVariant
func (c *Client) GetInfoCmdBonuri(zi int) ([]string, error) {
	return c.callWithOutError("GetInfoCmdBonuri", zi)
}

// GetReceptiiIntrSubunit returns sub-unit incoming receptions.
func (c *Client) GetReceptiiIntrSubunit() ([]string, error) {
	return c.callWithOutError("GetReceptiiIntrSubunit")
}

// --- Methods discovered via DLL cross-check (not in PDF) ---

// GetIstoricVanzari returns sales history for an agent from a given start date.
// DLL signature: GetIstoricVanzari(Marca, AnInceput, LunaInceput: Integer; out Error: Integer): Integer
// COM vtable: HRESULT GetIstoricVanzari([in] long, [in] long, [in] long, [out] long*, [out,retval] long*)
func (c *Client) GetIstoricVanzari(marca, anInceput, lunaInceput int) (result int, err error) {
	c.comDo(func() {
		if c.vtbl != nil {
			if _, ok := c.vtbl.methods["GetIstoricVanzari"]; ok {
				var errParam, retVal int32
				_, err = c.vtblCall("GetIstoricVanzari", marca, anInceput, lunaInceput, unsafe.Pointer(&errParam), unsafe.Pointer(&retVal))
				if err == nil {
					if errParam != 0 {
						errs, _ := c.rawGetListaErori()
						if len(errs) > 0 {
							err = fmt.Errorf("GetIstoricVanzari failed: %s", strings.Join(errs, "; "))
							return
						}
						err = fmt.Errorf("GetIstoricVanzari failed with error code %d", errParam)
						return
					}
					result = int(retVal)
					return
				}
			}
		}

		var errParam int32
		errVariant := ole.NewVariant(ole.VT_I4|ole.VT_BYREF, int64(uintptr(unsafe.Pointer(&errParam))))

		var v *ole.VARIANT
		v, err = c.rawCall("GetIstoricVanzari", marca, anInceput, lunaInceput, &errVariant)
		if err != nil {
			return
		}
		defer v.Clear()

		if errParam != 0 {
			errs, _ := c.rawGetListaErori()
			if len(errs) > 0 {
				err = fmt.Errorf("GetIstoricVanzari failed: %s", strings.Join(errs, "; "))
				return
			}
			err = fmt.Errorf("GetIstoricVanzari failed with error code %d", errParam)
			return
		}

		result = int(v.Val)
	})
	return
}

// GetListRecord returns the next record from an iterator.
// DLL signature: GetListRecord(out EOF: Integer): WideString
// COM vtable: HRESULT GetListRecord([out] long*, [out,retval] BSTR*)
func (c *Client) GetListRecord() (result string, eof int, err error) {
	c.comDo(func() {
		if c.vtbl != nil {
			if _, ok := c.vtbl.methods["GetListRecord"]; ok {
				var eofParam int32
				var resPtr *uint16
				_, err = c.vtblCall("GetListRecord", unsafe.Pointer(&eofParam), unsafe.Pointer(&resPtr))
				if err == nil {
					result = ole.BstrToString(resPtr)
					ole.SysFreeString((*int16)(unsafe.Pointer(resPtr)))
					eof = int(eofParam)
					return
				}
			}
		}

		var eofParam int32
		eofVariant := ole.NewVariant(ole.VT_I4|ole.VT_BYREF, int64(uintptr(unsafe.Pointer(&eofParam))))

		var v *ole.VARIANT
		v, err = c.rawCall("GetListRecord", &eofVariant)
		if err != nil {
			return
		}
		defer v.Clear()

		result = v.ToString()
		eof = int(eofParam)
	})
	return
}

// GetInfoComenzi returns order info.
// DLL signature: GetInfoComenzi(out Error: Integer): OleVariant
func (c *Client) GetInfoComenzi() ([]string, error) {
	return c.callWithOutError("GetInfoComenzi")
}

// GetListaArtCatPret2 returns price categories for a specific article.
// DLL signature: GetListaArtCatPret2(ArtID: WideString; out Error: Integer): OleVariant
func (c *Client) GetListaArtCatPret2(artID string) ([]string, error) {
	return c.callWithOutError("GetListaArtCatPret2", artID)
}

// SetFiltruDocNeoperate sets the filter for unprocessed documents.
// DLL signature: SetFiltruDocNeoperate(Flag: Integer): void
func (c *Client) SetFiltruDocNeoperate(flag int) error {
	return c.callMethodVoid("SetFiltruDocNeoperate", flag)
}

// GetCmdSubunitActive returns active sub-unit orders.
// DLL signature: GetCmdSubunitActive(out Error: Integer): OleVariant
func (c *Client) GetCmdSubunitActive() ([]string, error) {
	return c.callWithOutError("GetCmdSubunitActive")
}

// SetInclusivCmdFurn sets whether to include supplier orders.
// DLL signature: SetInclusivCmdFurn(Flag: Integer): void
func (c *Client) SetInclusivCmdFurn(flag int) error {
	return c.callMethodVoid("SetInclusivCmdFurn", flag)
}

// GetPlatiFactura returns payments for a specific invoice.
// DLL signature: GetPlatiFactura(An, Luna, NrFact: Integer; Serie, IDPart: WideString): OleVariant
func (c *Client) GetPlatiFactura(an, luna, nrFact int, serie, idPart string) ([]string, error) {
	return c.callReturningStrings("GetPlatiFactura", an, luna, nrFact, serie, idPart)
}

// SetInclusivFactAviz sets whether to include delivery notes.
// DLL signature: SetInclusivFactAviz(Flag: Integer): void
func (c *Client) SetInclusivFactAviz(flag int) error {
	return c.callMethodVoid("SetInclusivFactAviz", flag)
}

// GetIntervaleDisc returns discount intervals.
// DLL signature: GetIntervaleDisc(Criteriu: Integer; out Error: Integer): OleVariant
func (c *Client) GetIntervaleDisc(criteriu int) ([]string, error) {
	return c.callWithOutError("GetIntervaleDisc", criteriu)
}

// GetArticoleDisc returns articles with discounts.
// DLL signature: GetArticoleDisc(Criteriu: Integer; out Error: Integer): OleVariant
func (c *Client) GetArticoleDisc(criteriu int) ([]string, error) {
	return c.callWithOutError("GetArticoleDisc", criteriu)
}

// SetArtAnalizat sets the article to be analyzed.
// DLL signature: SetArtAnalizat(IDArticol: WideString): Integer
func (c *Client) SetArtAnalizat(idArticol string) (int, error) {
	return c.callMethodInt("SetArtAnalizat", idArticol)
}

// SetCatPretImplicita sets the default price category.
// DLL signature: SetCatPretImplicita(IDCatPret: WideString): Integer
func (c *Client) SetCatPretImplicita(idCatPret string) (int, error) {
	return c.callMethodInt("SetCatPretImplicita", idCatPret)
}
