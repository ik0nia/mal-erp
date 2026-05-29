package api

import (
	"net/http"
	"strings"
)

// soldJSON is the JSON representation for balance records (11 fields).
type soldJSON struct {
	IDPartener   string `json:"idPartener"`
	TipDocument  string `json:"tipDocument"`
	NrDocument   string `json:"nrDocument"`
	DataDocument string `json:"dataDocument"`
	RestDePlata  string `json:"restDePlata"`
	DataScadenta string `json:"dataScadenta"`
	Sediu        string `json:"sediu"`
	MarcaAgent   string `json:"marcaAgent"`
	ValoareDoc   string `json:"valoareDocument"`
	Moneda       string `json:"moneda"`
	Camp10       string `json:"camp10,omitempty"`
}

// handleGetSolduri returns structured JSON for balances.
func (s *Server) handleGetSolduri(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	solduri, err := s.wm.GetSolduri()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]soldJSON, len(solduri))
	for i, sol := range solduri {
		items[i] = soldJSON{
			IDPartener:   sol.IDPartener,
			TipDocument:  sol.TipDocument,
			NrDocument:   sol.NrDocument,
			DataDocument: sol.DataDocument,
			RestDePlata:  sol.RestDePlata,
			DataScadenta: sol.DataScadenta,
			Sediu:        sol.Sediu,
			MarcaAgent:   sol.MarcaAgent,
			ValoareDoc:   sol.ValoareDoc,
			Moneda:       sol.Moneda,
			Camp10:       sol.Camp10,
		}
	}
	Success(w, items)
}

// soldExtJSON is the JSON for extended balances (clients or suppliers, 13 fields).
type soldExtJSON struct {
	IDPartener        string `json:"idPartener"`
	TipDocument       string `json:"tipDocument"`
	NrFactura         string `json:"nrFactura"`
	DataFactura       string `json:"dataFactura"`
	RestDePlata       string `json:"restDePlata"`
	TermenDePlata     string `json:"termenDePlata"`
	LocatiePartener   string `json:"locatiePartener"`
	MarcaAgent        string `json:"marcaAgent"`
	ValoareFactura    string `json:"valoareFactura"`
	ObservatiiFactura string `json:"observatiiFactura"`
	CotaTVA           string `json:"cotaTVA"`
	TipDoc            string `json:"tipDoc"`
	Camp10            string `json:"camp10,omitempty"`
}

// handleGetSolduriExt returns structured JSON for extended client balances.
// Supports query params: ?page=1&pageSize=500&search=70311&tipDoc=F.MAL
func (s *Server) handleGetSolduriExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	solduri, err := s.wm.GetSolduriExt()
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	// Convert all to JSON structs first
	all := make([]soldExtJSON, len(solduri))
	for i, sol := range solduri {
		all[i] = soldExtJSON{
			IDPartener:        sol.IDPartener,
			TipDocument:       sol.Tip,
			NrFactura:         sol.NrFactura,
			DataFactura:       sol.DataFactura,
			RestDePlata:       sol.RestDePlata,
			TermenDePlata:     sol.TermenDePlata,
			LocatiePartener:   sol.LocatiePartener,
			MarcaAgent:        sol.MarcaAgent,
			ValoareFactura:    sol.ValoareFactura,
			ObservatiiFactura: sol.ObservatiiFactura,
			CotaTVA:           sol.CotaTVA,
			TipDoc:            sol.TipDocument,
			Camp10:            sol.Camp10,
		}
	}

	// Filter by tipDoc if specified
	tipDoc := r.URL.Query().Get("tipDoc")
	search := strings.ToLower(r.URL.Query().Get("search"))

	var filtered []soldExtJSON
	for _, item := range all {
		if tipDoc != "" && item.TipDoc != tipDoc {
			continue
		}
		if search != "" {
			haystack := strings.ToLower(item.NrFactura + "|" + item.IDPartener + "|" + item.ObservatiiFactura + "|" + item.DataFactura)
			if !strings.Contains(haystack, search) {
				continue
			}
		}
		filtered = append(filtered, item)
	}

	// Pagination
	page := 1
	pageSize := len(filtered) // default: all
	if ps := r.URL.Query().Get("pageSize"); ps != "" {
		pageSize = mustAtoi(ps)
		if pageSize <= 0 {
			pageSize = 500
		}
	}
	if p := r.URL.Query().Get("page"); p != "" {
		page = mustAtoi(p)
		if page <= 0 {
			page = 1
		}
	}

	totalItems := len(filtered)
	totalPages := (totalItems + pageSize - 1) / pageSize
	if totalPages == 0 {
		totalPages = 1
	}

	start := (page - 1) * pageSize
	end := start + pageSize
	if start > totalItems {
		start = totalItems
	}
	if end > totalItems {
		end = totalItems
	}

	// If no pagination params, return all (backwards compatible)
	if r.URL.Query().Get("page") == "" && r.URL.Query().Get("pageSize") == "" && tipDoc == "" && search == "" {
		Success(w, all)
		return
	}

	SuccessPaginated(w, filtered[start:end], page, pageSize, totalPages, page < totalPages)
}

// handleGetSolduriFurn returns structured JSON for supplier balances.
func (s *Server) handleGetSolduriFurn(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	solduri, err := s.wm.GetSolduriFurn()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]soldExtJSON, len(solduri))
	for i, sol := range solduri {
		items[i] = soldExtJSON{
			IDPartener:        sol.IDPartener,
			TipDocument:       sol.Tip,
			NrFactura:         sol.NrFactura,
			DataFactura:       sol.DataFactura,
			RestDePlata:       sol.RestDePlata,
			TermenDePlata:     sol.TermenDePlata,
			LocatiePartener:   sol.LocatiePartener,
			MarcaAgent:        sol.MarcaAgent,
			ValoareFactura:    sol.ValoareFactura,
			ObservatiiFactura: sol.ObservatiiFactura,
			CotaTVA:           sol.CotaTVA,
			TipDoc:            sol.TipDocument,
			Camp10:            sol.Camp10,
		}
	}
	Success(w, items)
}

// handleGetSoldFactNeop returns WideString result.
func (s *Server) handleGetSoldFactNeop(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	partID := queryParam(r, "partId", "")
	result, err := s.wm.GetSoldFactNeop(partID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, result)
}

// soldPartJSON is the JSON for partner balance.
type soldPartJSON struct {
	CodExtern string `json:"codExtern"`
	Denumire  string `json:"denumire"`
	Sold      string `json:"sold"`
}

// handleGetSoldPart returns structured JSON for partner balance.
func (s *Server) handleGetSoldPart(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	partID := r.PathValue("id")
	result, err := s.wm.GetSoldPart(partID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	if result == nil {
		Success(w, nil)
		return
	}
	Success(w, soldPartJSON{
		CodExtern: result.CodExtern,
		Denumire:  result.Denumire,
		Sold:      result.Sold,
	})
}

// soldDetaliatJSON is the JSON for detailed balance entries.
type soldDetaliatJSON struct {
	Tip          string `json:"tip"`
	NrDocument   string `json:"nrDocument"`
	DataDocument string `json:"dataDocument"`
	Rest         string `json:"rest"`
	DataScadenta string `json:"dataScadenta"`
	MarcaAgent   string `json:"marcaAgent"`
	Moneda       string `json:"moneda"`
	Sediu        string `json:"sediu"`
}

// handleGetSoldDetaliat returns structured JSON for detailed balance.
func (s *Server) handleGetSoldDetaliat(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	partID := r.PathValue("id")
	details, err := s.wm.GetSoldDetaliat(partID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]soldDetaliatJSON, len(details))
	for i, d := range details {
		items[i] = soldDetaliatJSON{
			Tip:          d.Type,
			NrDocument:   d.NrDocument,
			DataDocument: d.DataDocument,
			Rest:         d.Rest,
			DataScadenta: d.DataScadenta,
			MarcaAgent:   d.MarcaAgent,
			Moneda:       d.Moneda,
			Sediu:        d.Sediu,
		}
	}
	Success(w, items)
}

// incasariClientiJSON — GetIncasariClienti.
// 2 fields: idPartener;totalIncasari (when partId is empty, field 0 is empty)
type incasariClientiJSON struct {
	IDPartener    string `json:"idPartener"`
	TotalIncasari string `json:"totalIncasari"`
}

func (s *Server) handleGetIncasari(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	an1 := queryParamInt(r, "an1", 2020)
	luna1 := queryParamInt(r, "luna1", 1)
	an2 := queryParamInt(r, "an2", 2026)
	luna2 := queryParamInt(r, "luna2", 12)
	partID := queryParam(r, "partId", "")
	rows, err := s.wm.GetIncasariClienti(an1, luna1, an2, luna2, partID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]incasariClientiJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 2)
		items = append(items, incasariClientiJSON{
			IDPartener:    f[0],
			TotalIncasari: f[1],
		})
	}
	Success(w, items)
}

// incasareLunaJSON — GetIncasariLuna.
// 5 fields: pozitie;dataIncasare;documentRef;idPartener;suma
type incasareLunaJSON struct {
	Pozitie      string `json:"pozitie"`
	DataIncasare string `json:"dataIncasare"`
	DocumentRef  string `json:"documentRef"`
	IDPartener   string `json:"idPartener"`
	Suma         string `json:"suma"`
}

func (s *Server) handleGetIncasariLuna(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetIncasariLuna()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]incasareLunaJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 5)
		items = append(items, incasareLunaJSON{
			Pozitie:      f[0],
			DataIncasare: f[1],
			DocumentRef:  f[2],
			IDPartener:   f[3],
			Suma:         f[4],
		})
	}
	Success(w, items)
}

// handleGetIncasariFactura uses callReturningStrings (no out Error param).
func (s *Server) handleGetIncasariFactura(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	an := queryParamInt(r, "an", 2026)
	luna := queryParamInt(r, "luna", 4)
	nrFact := queryParamInt(r, "nrFact", 0)
	serieFact := queryParam(r, "serieFact", "")
	idPart := queryParam(r, "partId", "")
	result, err := s.wm.GetIncasariFactura(an, luna, nrFact, serieFact, idPart)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, result)
}

// handleGetPlatiFactura uses callReturningStrings (no out Error param).
func (s *Server) handleGetPlatiFactura(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	an := queryParamInt(r, "an", 2026)
	luna := queryParamInt(r, "luna", 4)
	nrFact := queryParamInt(r, "nrFact", 0)
	serie := queryParam(r, "serie", "")
	idPart := queryParam(r, "partId", "")
	result, err := s.wm.GetPlatiFactura(an, luna, nrFact, serie, idPart)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, result)
}

// plataLunaJSON — GetPlatiLuna.
// 5 fields: pozitie;data;documentRef;idPartener;suma
type plataLunaJSON struct {
	Pozitie     string `json:"pozitie"`
	Data        string `json:"data"`
	DocumentRef string `json:"documentRef"`
	IDPartener  string `json:"idPartener"`
	Suma        string `json:"suma"`
}

func (s *Server) handleGetPlatiLuna(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetPlatiLuna")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]plataLunaJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 5)
		items = append(items, plataLunaJSON{
			Pozitie:     f[0],
			Data:        f[1],
			DocumentRef: f[2],
			IDPartener:  f[3],
			Suma:        f[4],
		})
	}
	Success(w, items)
}

// incasareClientExtJSON — GetIncasariClientiExt.
// 6 fields: idPartener;documentRef;data;suma;detaliiFacturi;trailing
type incasareClientExtJSON struct {
	IDPartener     string `json:"idPartener"`
	DocumentRef    string `json:"documentRef"`
	Data           string `json:"data"`
	Suma           string `json:"suma"`
	DetaliiFacturi string `json:"detaliiFacturi"`
	Camp5          string `json:"camp5,omitempty"`
}

func (s *Server) handleGetIncasariClientiExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	an1 := queryParamInt(r, "an1", 2026)
	luna1 := queryParamInt(r, "luna1", 1)
	an2 := queryParamInt(r, "an2", 2026)
	luna2 := queryParamInt(r, "luna2", 12)
	partID := queryParam(r, "partId", "")
	rows, err := s.wm.RawQuery("GetIncasariClientiExt", an1, luna1, an2, luna2, partID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]incasareClientExtJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 6)
		items = append(items, incasareClientExtJSON{
			IDPartener:     f[0],
			DocumentRef:    f[1],
			Data:           f[2],
			Suma:           f[3],
			DetaliiFacturi: f[4],
			Camp5:          f[5],
		})
	}
	Success(w, items)
}
