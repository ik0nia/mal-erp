package winmentor

import "strings"

// GetStocArticole returns the full stock article list.
// PDF documents 17 fields but the DLL actually returns 21.
func (c *Client) GetStocArticole() ([]StockArticle, error) {
	records, err := c.callWithOutError("GetStocArticole")
	if err != nil {
		return nil, err
	}

	var result []StockArticle
	for _, rec := range records {
		f := splitFields(rec, 21)
		result = append(result, StockArticle{
			CodExtern:        f[0],
			Denumire:         f[1],
			UM:               f[2],
			PretVanzare:      f[3],
			Stoc:             f[4],
			SimbolClasa:      f[5],
			DenClasa:         f[6],
			IDProducator:     f[7],
			DenProducator:    f[8],
			IDFurnizor:       f[9],
			DenFurnizor:      f[10],
			SimbolGestiune:   f[11],
			DenGestiune:      f[12],
			CotaTVA:          f[13],
			FlagTVAInclus:    f[14],
			PretCuTVA:        f[15],
			StocRezervat:     f[16],
			StocMinim:        f[17],
			IDIntern:         f[18],
			ObservatiiProdus: f[19],
			Unknown20:        f[20],
		})
	}
	return result, nil
}

// GetStocArticol returns stock info for a single article by ID and warehouse.
// DLL signature: GetStocArticol(ArticolID, GestID: WideString; out Error: Integer): OleVariant
// Note: actual DLL returns just the stock quantity as a single string (e.g. "69"),
// not a 5-field semicolon-separated record as documented.
func (c *Client) GetStocArticol(articolID, gestID string) (*ArticleStock, error) {
	records, err := c.callWithOutError("GetStocArticol", articolID, gestID)
	if err != nil {
		return nil, err
	}
	if len(records) == 0 {
		return nil, nil
	}
	raw := records[0]
	if !strings.Contains(raw, ";") {
		// DLL returns just the stock quantity
		return &ArticleStock{
			CodExtern:   articolID,
			Stoc:        raw,
		}, nil
	}
	f := splitFields(raw, 5)
	return &ArticleStock{
		CodExtern:   f[0],
		Denumire:    f[1],
		UM:          f[2],
		PretVanzare: f[3],
		Stoc:        f[4],
	}, nil
}

// GenCodArticole generates external codes for articles in the nomenclature.
// Returns -1 on error, or the number of articles updated.
func (c *Client) GenCodArticole() (int, error) {
	return c.callMethodInt("GenCodArticole")
}

// GetProducts returns products modified since lastSyncDate (format: "dd.mm.yyyy hh:mm:ss").
func (c *Client) GetProducts(lastSyncDate string) ([]Product, error) {
	records, err := c.callWithOutError("GetProducts", lastSyncDate)
	if err != nil {
		return nil, err
	}

	var result []Product
	for _, rec := range records {
		f := splitFields(rec, 11)
		result = append(result, Product{
			IDArticol:             f[0],
			Denumire:              f[1],
			DenUM:                 f[2],
			IDProducator:          f[3],
			DenumireProducator:    f[4],
			TipSerie:              f[5],
			DataAdaugarii:         f[6],
			DataUltimeiModificari: f[7],
			TipUM:                 f[8],
			CodInternWinMentor:   f[9],
			SimbolClasa:           f[10],
		})
	}
	return result, nil
}

// GetStergeriProduse returns products deleted since lastSyncDate.
// Format: "CodInternWinMentor;DataOraStergerii"
func (c *Client) GetStergeriProduse(lastSyncDate string) ([]DeletedProduct, error) {
	records, err := c.callWithOutError("GetStergeriProduse", lastSyncDate)
	if err != nil {
		return nil, err
	}

	var result []DeletedProduct
	for _, rec := range records {
		f := splitFields(rec, 2)
		result = append(result, DeletedProduct{
			CodInternWinMentor: f[0],
			DataOraStergerii:   f[1],
		})
	}
	return result, nil
}

// GetClaseArticole returns the list of article classes.
// Format: "SimbolClasa;NumeClasa"
func (c *Client) GetClaseArticole() ([]ClasaArticole, error) {
	records, err := c.callWithOutError("GetClaseArticole")
	if err != nil {
		return nil, err
	}

	var result []ClasaArticole
	for _, rec := range records {
		f := splitFields(rec, 2)
		result = append(result, ClasaArticole{
			Simbol:   f[0],
			Denumire: f[1],
		})
	}
	return result, nil
}

// GetNomenclatorArticole returns the full article nomenclature.
// PDF documents 24 fields but the DLL actually returns 40.
func (c *Client) GetNomenclatorArticole() ([]NomenclatorArticol, error) {
	records, err := c.callWithOutError("GetNomenclatorArticole")
	if err != nil {
		return nil, err
	}

	var result []NomenclatorArticol
	for _, rec := range records {
		f := splitFields(rec, 40)
		result = append(result, NomenclatorArticol{
			CodExtern:           f[0],
			Denumire:            f[1],
			DenUM:               f[2],
			PretVanzare:         f[3],
			SimbolClasa:         f[4],
			DenClasa:            f[5],
			CodExternProducator: f[6],
			DenProducator:       f[7],
			GestImplicita:       f[8],
			CodExternUnic:       f[9],
			CotaTVA:             f[10],
			DenUMSecundara:      f[11],
			ParitateUMSecundara: f[12],
			Masa:                f[13],
			Serviciu:            f[14],
			CodVamal:            f[15],
			PretMinim:           f[16],
			CantImplicita:       f[17],
			PretValuta:          f[18],
			DataAdaug:           f[19],
			Masa2:               f[20],
			PretVCuTVA:          f[21],
			Locatie:             f[22],
			PretReferinta:       f[23],
			FlagActiv:           f[24],
			Unknown25:           f[25],
			CodAlternativ:       f[26],
			Unknown27:           f[27],
			CotaTVA2:            f[28],
			FlagNU29:            f[29],
			Unknown30:           f[30],
			Unknown31:           f[31],
			Unknown32:           f[32],
			Unknown33:           f[33],
			Unknown34:           f[34],
			Unknown35:           f[35],
			FlagNU36:            f[36],
			Unknown37:           f[37],
			Unknown38:           f[38],
			Unknown39:           f[39],
		})
	}
	return result, nil
}

// GetPretVanzare returns the sale price(s) for an article/partner combination.
func (c *Client) GetPretVanzare(artID, partID string) ([]string, error) {
	return c.callWithOutError("GetPretVanzare", artID, partID)
}

// GetListaCatPret returns the price categories for the current company.
func (c *Client) GetListaCatPret() ([]CategoriePret, error) {
	records, err := c.callWithOutError("GetListaCatPret")
	if err != nil {
		return nil, err
	}

	var result []CategoriePret
	for _, rec := range records {
		f := splitFields(rec, 3) // raw: "Simbol;Denumire;Flag" (3 fields)
		result = append(result, CategoriePret{
			Simbol:   f[0],
			Denumire: f[1],
			Flag:     f[2],
		})
	}
	return result, nil
}

// GetListaArtCatPret returns the price categories specified per article.
func (c *Client) GetListaArtCatPret() ([]string, error) {
	return c.callWithOutError("GetListaArtCatPret")
}

// GetOferte returns the list of price offers.
// Raw format: "PartID;ArtID;DataInceput;DataSfarsit;Pret;Cantitate;Discount;CantMinima;Field8;Moneda;Field10"
func (c *Client) GetOferte() ([]Oferta, error) {
	records, err := c.callWithOutError("GetOferte")
	if err != nil {
		return nil, err
	}

	var result []Oferta
	for _, rec := range records {
		f := splitFields(rec, 11)
		result = append(result, Oferta{
			PartID:      f[0],
			ArtID:       f[1],
			DataInceput: f[2],
			DataSfarsit: f[3],
			Pret:        f[4],
			Cantitate:   f[5],
			Discount:    f[6],
			CantMinima:  f[7],
			Moneda:      f[9],
		})
	}
	return result, nil
}
