package api

import "net/http"

// articolVandutJSON — GetArticoleVandute.
// 2 fields: codArticol;cantitate
type articolVandutJSON struct {
	CodArticol string `json:"codArticol"`
	Cantitate  string `json:"cantitate"`
}

// ultimaVanzareJSON — GetUltimeleVanzari.
// 2 fields: data;cantitate
type ultimaVanzareJSON struct {
	Data      string `json:"data"`
	Cantitate string `json:"cantitate"`
}

// vanzareLunaJSON is the JSON representation for monthly sales.
type vanzareLunaJSON struct {
	IDPartener        string `json:"idPartener"`
	Zi                string `json:"zi"`
	NrFactura         string `json:"nrFactura"`
	CodArticol        string `json:"codArticol"`
	NumarComanda      string `json:"numarComanda"`
	Cant              string `json:"cant"`
	DenUM             string `json:"denUM"`
	Pret              string `json:"pret"`
	MarcaAgent        string `json:"marcaAgent"`
	ValoareFactura    string `json:"valoareFactura"`
	DataScadenta      string `json:"dataScadenta"`
	TVAInclus         string `json:"tvaInclus"`
	CotaTVA           string `json:"cotaTVA"`
	TipDocument       string `json:"tipDocument"`
	PrefixCarnet      string `json:"prefixCarnet"`
	SerieDocument     string `json:"serieDocument"`
	DenArticol        string `json:"denArticol"`
	Discount          string `json:"discount"`
	DataEmitere       string `json:"dataEmitere"`
	SediuClient       string `json:"sediuClient"`
	AdresaClient      string `json:"adresaClient"`
	LocalitateClient  string `json:"localitateClient"`
	ObservatiiFactura string `json:"observatiiFactura"`
	Camp18            string `json:"camp18,omitempty"`
	Camp25            string `json:"camp25,omitempty"`
}

// handleGetVanzariLuna returns structured JSON for monthly sales.
func (s *Server) handleGetVanzariLuna(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	sales, err := s.wm.GetVanzariLuna()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]vanzareLunaJSON, len(sales))
	for i, v := range sales {
		items[i] = vanzareLunaJSON{
			IDPartener:        v.IDPartener,
			Zi:                v.Zi,
			NrFactura:         v.NrFactura,
			CodArticol:        v.CodArticol,
			NumarComanda:      v.NumarComanda,
			Cant:              v.Cant,
			DenUM:             v.DenUM,
			Pret:              v.Pret,
			MarcaAgent:        v.MarcaAgent,
			ValoareFactura:    v.ValoareFactura,
			DataScadenta:      v.DataScadenta,
			TVAInclus:         v.TVAInclus,
			CotaTVA:           v.CotaTVA,
			TipDocument:       v.TipDocument,
			PrefixCarnet:      v.PrefixCarnet,
			SerieDocument:     v.SerieDocument,
			DenArticol:        v.DenArticol,
			Discount:          v.Discount,
			DataEmitere:       v.DataEmitere,
			SediuClient:       v.SediuClient,
			AdresaClient:      v.AdresaClient,
			LocalitateClient:  v.LocalitateClient,
			ObservatiiFactura: v.ObservatiiFactura,
			Camp18:            v.Unknown18,
			Camp25:            v.Unknown25,
		}
	}
	Success(w, items)
}

// vanzareExtJSON is the JSON representation for extended sales.
// DLL returns 23 fields per record.
type vanzareExtJSON struct {
	IDPartener      string `json:"idPartener"`      // [0]  ID partener
	Zi              string `json:"zi"`              // [1]  ziua lunii
	NrFactura       string `json:"nrFactura"`       // [2]  nr. factură/document
	CodArticol      string `json:"codArticol"`      // [3]  cod extern articol (EAN)
	Cantitate       string `json:"cantitate"`       // [4]  cantitate
	DenUM           string `json:"denUM"`           // [5]  unitate de măsură
	Pret            string `json:"pret"`            // [6]  preț unitar
	DenGestiune     string `json:"denGestiune"`     // [7]  denumire gestiune
	Camp8           string `json:"camp8,omitempty"` // [8]
	LocatieClient   string `json:"locatieClient"`   // [9]  locație/sediu client
	MarcaAgent      string `json:"marcaAgent"`      // [10] marcă agent
	CodFiscal       string `json:"codFiscal"`       // [11] cod fiscal client
	Camp12          string `json:"camp12,omitempty"` // [12]
	Adresa          string `json:"adresa"`          // [13] adresă client
	Camp14          string `json:"camp14,omitempty"` // [14]
	CodPostal       string `json:"codPostal"`       // [15] cod poștal
	ClasaArticol    string `json:"clasaArticol"`    // [16] clasă articol
	TipDocument     string `json:"tipDocument"`     // [17] tip document ("=", "S")
	PozitieDocument string `json:"pozitieDocument"` // [18] poziție pe document
	PrefixCarnet    string `json:"prefixCarnet"`    // [19] prefix carnet
	Moneda          string `json:"moneda"`          // [20] monedă
	Camp21          string `json:"camp21,omitempty"` // [21]
	Camp22          string `json:"camp22,omitempty"` // [22]
}

// handleGetVanzariExt returns structured JSON for extended sales.
func (s *Server) handleGetVanzariExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	sales, err := s.wm.GetVanzariExt()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]vanzareExtJSON, len(sales))
	for i, v := range sales {
		items[i] = vanzareExtJSON{
			IDPartener:      v.IDPartener,
			Zi:              v.Zi,
			NrFactura:       v.NrFactura,
			CodArticol:      v.CodArticol,
			Cantitate:       v.Cant,
			DenUM:           v.DenUM,
			Pret:            v.Pret,
			DenGestiune:     v.DenGest,
			Camp8:           v.Unknown8,
			LocatieClient:   v.LocatieClient,
			MarcaAgent:      v.MarcaAgent,
			CodFiscal:       v.CodFiscal,
			Camp12:          v.Unknown12,
			Adresa:          v.Adresa,
			Camp14:          v.Unknown14,
			CodPostal:       v.CodPostal,
			ClasaArticol:    v.ClasaArticol,
			TipDocument:     v.TipDocument,
			PozitieDocument: v.PozitieDocument,
			PrefixCarnet:    v.PrefixCarnet,
			Moneda:          v.Moneda,
			Camp21:          v.Unknown21,
			Camp22:          v.Unknown22,
		}
	}
	Success(w, items)
}

// handleGetArticoleVandute returns structured JSON for articles sold (requires SetArtAnalizat first).
func (s *Server) handleGetArticoleVandute(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	partID := queryParam(r, "partId", "")
	marcaAgent := queryParamInt(r, "marcaAgent", 0)
	an := queryParamInt(r, "an", 2020)
	luna := queryParamInt(r, "luna", 1)
	rows, err := s.wm.GetArticoleVandute(partID, marcaAgent, an, luna)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]articolVandutJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 2)
		items = append(items, articolVandutJSON{
			CodArticol: f[0],
			Cantitate:  f[1],
		})
	}
	Success(w, items)
}

// handleGetUltimeleVanzari returns structured JSON for last sales per article/partner.
func (s *Server) handleGetUltimeleVanzari(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	artID := queryParam(r, "artId", "")
	partID := queryParam(r, "partId", "")
	marcaAgent := queryParamInt(r, "marcaAgent", 0)
	cate := queryParamInt(r, "cate", 10)
	rows, err := s.wm.GetUltimeleVanzari(artID, partID, marcaAgent, cate)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]ultimaVanzareJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 2)
		items = append(items, ultimaVanzareJSON{
			Data:      f[0],
			Cantitate: f[1],
		})
	}
	Success(w, items)
}

// vanzareEmulareJSON — GetVanzariEmulare.
// 21 fields: idBon;pozitie;data;valoare;f4;f5;f6;f7;f8;f9;numeClient;cantitate;denArticol;codArticol;codExtern;nrComanda;cantitate2;pret;denGestiune;simbolGestiune;f20
type vanzareEmulareJSON struct {
	IDBon          string `json:"idBon"`
	Pozitie        string `json:"pozitie"`
	Data           string `json:"data"`
	Valoare        string `json:"valoare"`
	Camp4          string `json:"camp4,omitempty"`
	Camp5          string `json:"camp5,omitempty"`
	Camp6          string `json:"camp6,omitempty"`
	Camp7          string `json:"camp7,omitempty"`
	Camp8          string `json:"camp8,omitempty"`
	Camp9          string `json:"camp9,omitempty"`
	NumeClient     string `json:"numeClient"`
	Cantitate      string `json:"cantitate"`
	DenArticol     string `json:"denArticol"`
	CodArticol     string `json:"codArticol"`
	CodExtern      string `json:"codExtern"`
	NrComanda      string `json:"nrComanda"`
	CantVanduta    string `json:"cantVanduta"`
	Pret           string `json:"pret"`
	DenGestiune    string `json:"denGestiune"`
	SimbolGestiune string `json:"simbolGestiune"`
	Camp20         string `json:"camp20,omitempty"`
}

func (s *Server) handleGetVanzariEmulare(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetVanzariEmulare")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]vanzareEmulareJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 21)
		items = append(items, vanzareEmulareJSON{
			IDBon:          f[0],
			Pozitie:        f[1],
			Data:           f[2],
			Valoare:        f[3],
			Camp4:          f[4],
			Camp5:          f[5],
			Camp6:          f[6],
			Camp7:          f[7],
			Camp8:          f[8],
			Camp9:          f[9],
			NumeClient:     f[10],
			Cantitate:      f[11],
			DenArticol:     f[12],
			CodArticol:     f[13],
			CodExtern:      f[14],
			NrComanda:      f[15],
			CantVanduta:    f[16],
			Pret:           f[17],
			DenGestiune:    f[18],
			SimbolGestiune: f[19],
			Camp20:         f[20],
		})
	}
	Success(w, items)
}

// handleGetEmulareNedescarcata — GetEmulareNedescarcata (structure unknown, returns splitRows).
func (s *Server) handleGetEmulareNedescarcata(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetEmulareNedescarcata")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// GetIstoricVanzari returns Integer (not OleVariant) — needs typed call
func (s *Server) handleGetIstoricVanzari(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	marca := queryParamInt(r, "marca", 0)
	an := queryParamInt(r, "an", 2020)
	luna := queryParamInt(r, "luna", 1)
	result, err := s.wm.GetIstoricVanzari(marca, an, luna)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"result": result})
}

// GetIstoricVanzari + GetListRecord iterator
func (s *Server) handleGetIstoricVanzariAll(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	marca := queryParamInt(r, "marca", 0)
	an := queryParamInt(r, "an", 2020)
	luna := queryParamInt(r, "luna", 1)
	limit := queryParamInt(r, "limit", 100)

	total, err := s.wm.GetIstoricVanzari(marca, an, luna)
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	records := make([]string, 0, total)
	for i := 0; i < total && i < limit; i++ {
		rec, eof, err := s.wm.GetListRecord()
		if err != nil {
			Success(w, map[string]interface{}{
				"total":     total,
				"fetched":   len(records),
				"records":   records,
				"error":     err.Error(),
				"stoppedAt": i,
			})
			return
		}
		records = append(records, rec)
		if eof == 1 {
			break
		}
	}

	Success(w, map[string]interface{}{
		"total":   total,
		"fetched": len(records),
		"records": records,
	})
}
