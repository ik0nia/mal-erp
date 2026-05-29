package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"

	"github.com/rayone121/libWMEdcom/winmentor"
)

// nomenclatorArticolJSON is the JSON representation for articles.
type nomenclatorArticolJSON struct {
	CodExtern           string `json:"codExtern"`
	CodIntern           string `json:"codIntern"`
	Denumire            string `json:"denumire"`
	DenUM               string `json:"denUM"`
	PretVanzare         string `json:"pretVanzare"`
	SimbolClasa         string `json:"simbolClasa"`
	DenClasa            string `json:"denClasa"`
	CodExternProducator string `json:"codExternProducator"`
	DenProducator       string `json:"denProducator"`
	GestImplicita       string `json:"gestImplicita"`
	CodExternUnic       string `json:"codExternUnic"`
	CodExternAlt        string `json:"codExternAlt"`
	CotaTVA             string `json:"cotaTVA"`
	DenUMSecundara      string `json:"denUMSecundara"`
	ParitateUMSecundara string `json:"paritateUMSecundara"`
	Masa                string `json:"masa"`
	Serviciu            string `json:"serviciu"`
	CodVamal            string `json:"codVamal"`
	PretMinim           string `json:"pretMinim"`
	CantImplicita       string `json:"cantImplicita"`
	PretValuta          string `json:"pretValuta"`
	DataAdaug           string `json:"dataAdaug"`
	PretVCuTVA          string `json:"pretVCuTVA"`
	Locatie             string `json:"locatie"`
	PretReferinta       string `json:"pretReferinta"`
	FlagActiv           string `json:"flagActiv"`
	CodAlternativ       string `json:"codAlternativ"`
	CotaTVA2            string `json:"cotaTVA2"`
	Camp25              string `json:"camp25,omitempty"`
	Camp27              string `json:"camp27,omitempty"`
	Camp30              string `json:"camp30,omitempty"`
	Camp31              string `json:"camp31,omitempty"`
	Camp32              string `json:"camp32,omitempty"`
	Camp33              string `json:"camp33,omitempty"`
	Camp34              string `json:"camp34,omitempty"`
	Camp35              string `json:"camp35,omitempty"`
	Camp37              string `json:"camp37,omitempty"`
	Camp38              string `json:"camp38,omitempty"`
	Camp39              string `json:"camp39,omitempty"`
}

func nomenclatorToJSON(a winmentor.NomenclatorArticol) nomenclatorArticolJSON {
	return nomenclatorArticolJSON{
		CodExtern:           a.CodExtern,
		CodIntern:           a.CodExtern, // alias — CodIntern = CodExtern in nomenclator
		Denumire:            a.Denumire,
		DenUM:               a.DenUM,
		PretVanzare:         a.PretVanzare,
		SimbolClasa:         a.SimbolClasa,
		DenClasa:            a.DenClasa,
		CodExternProducator: a.CodExternProducator,
		DenProducator:       a.DenProducator,
		GestImplicita:       a.GestImplicita,
		CodExternUnic:       a.CodExternUnic,
		CodExternAlt:        a.CodExternUnic, // alias — codExternAlt = CodExternUnic (field [9])
		CotaTVA:             a.CotaTVA,
		DenUMSecundara:      a.DenUMSecundara,
		ParitateUMSecundara: a.ParitateUMSecundara,
		Masa:                a.Masa,
		Serviciu:            a.Serviciu,
		CodVamal:            a.CodVamal,
		PretMinim:           a.PretMinim,
		CantImplicita:       a.CantImplicita,
		PretValuta:          a.PretValuta,
		DataAdaug:           a.DataAdaug,
		PretVCuTVA:          a.PretVCuTVA,
		Locatie:             a.Locatie,
		PretReferinta:       a.PretReferinta,
		FlagActiv:           a.FlagActiv,
		CodAlternativ:       a.CodAlternativ,
		CotaTVA2:            a.CotaTVA2,
		Camp25:              a.Unknown25,
		Camp27:              a.Unknown27,
		Camp30:              a.Unknown30,
		Camp31:              a.Unknown31,
		Camp32:              a.Unknown32,
		Camp33:              a.Unknown33,
		Camp34:              a.Unknown34,
		Camp35:              a.Unknown35,
		Camp37:              a.Unknown37,
		Camp38:              a.Unknown38,
		Camp39:              a.Unknown39,
	}
}

// handleGetArticole calls GetNomenclatorArticole and returns structured JSON.
func (s *Server) handleGetArticole(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	page, pageSize := parsePageParams(r, s.cfg.DefaultPageSize, s.cfg.MaxPageSize)
	search := r.URL.Query().Get("search")
	cacheKey := "articole_typed"

	cached, ok := s.cache.Get(cacheKey)
	if !ok || queryParamBool(r, "refresh") {
		rows, err := s.wm.GetNomenclatorArticole()
		if err != nil {
			ErrorInternal(w, err)
			return
		}
		items := make([]nomenclatorArticolJSON, len(rows))
		for i, a := range rows {
			items[i] = nomenclatorToJSON(a)
		}
		s.cache.Set(cacheKey, items)
		cached = items
	}

	items, ok := cached.([]nomenclatorArticolJSON)
	if !ok {
		s.cache.Delete(cacheKey)
		ErrorInternal(w, fmt.Errorf("cache type mismatch"))
		return
	}

	// Filter by search
	if search != "" {
		searchLower := strings.ToLower(search)
		filtered := make([]nomenclatorArticolJSON, 0)
		for _, item := range items {
			if strings.Contains(strings.ToLower(item.CodExtern), searchLower) ||
				strings.Contains(strings.ToLower(item.Denumire), searchLower) {
				filtered = append(filtered, item)
			}
		}
		items = filtered
	}

	// Paginate
	total := len(items)
	totalPages := 0
	if total > 0 {
		totalPages = (total + pageSize - 1) / pageSize
	}
	start := (page - 1) * pageSize
	if start > total {
		start = total
	}
	end := start + pageSize
	if end > total {
		end = total
	}
	hasNext := end < total

	SuccessPaginated(w, items[start:end], page, pageSize, totalPages, hasNext)
}

// productJSON is the JSON representation for GetProducts results.
type productJSON struct {
	IDArticol             string `json:"idArticol"`
	Denumire              string `json:"denumire"`
	DenUM                 string `json:"denUM"`
	IDProducator          string `json:"idProducator"`
	DenumireProducator    string `json:"denumireProducator"`
	TipSerie              string `json:"tipSerie"`
	DataAdaugarii         string `json:"dataAdaugarii"`
	DataUltimeiModificari string `json:"dataUltimeiModificari"`
	TipUM                 string `json:"tipUM"`
	CodInternWinMentor    string `json:"codInternWinMentor"`
	SimbolClasa           string `json:"simbolClasa"`
}

// handleGetProducts calls GetProducts and returns structured JSON.
func (s *Server) handleGetProducts(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	lastSync := queryParam(r, "lastSync", "01.01.2000 00:00:00")

	products, err := s.wm.GetProducts(lastSync)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]productJSON, len(products))
	for i, p := range products {
		items[i] = productJSON{
			IDArticol:             p.IDArticol,
			Denumire:              p.Denumire,
			DenUM:                 p.DenUM,
			IDProducator:          p.IDProducator,
			DenumireProducator:    p.DenumireProducator,
			TipSerie:              p.TipSerie,
			DataAdaugarii:         p.DataAdaugarii,
			DataUltimeiModificari: p.DataUltimeiModificari,
			TipUM:                 p.TipUM,
			CodInternWinMentor:    p.CodInternWinMentor,
			SimbolClasa:           p.SimbolClasa,
		}
	}
	Success(w, items)
}

// deletedProductJSON is the JSON for deleted products.
type deletedProductJSON struct {
	CodInternWinMentor string `json:"codInternWinMentor"`
	DataOraStergerii   string `json:"dataOraStergerii"`
}

// handleGetStergeriProduse calls GetStergeriProduse and returns structured JSON.
func (s *Server) handleGetStergeriProduse(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	lastSync := queryParam(r, "lastSync", "01.01.2000 00:00:00")

	deleted, err := s.wm.GetStergeriProduse(lastSync)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]deletedProductJSON, len(deleted))
	for i, d := range deleted {
		items[i] = deletedProductJSON{
			CodInternWinMentor: d.CodInternWinMentor,
			DataOraStergerii:   d.DataOraStergerii,
		}
	}
	Success(w, items)
}

// handleAddProduct calls AddProduct(info).
func (s *Server) handleAddProduct(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var req struct {
		Info string `json:"info"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON body")
		return
	}
	if req.Info == "" {
		ErrorBadRequest(w, "info is required")
		return
	}

	result, err := s.wm.AddProduct(req.Info)
	if err != nil {
		errors := s.getComErrors()
		Error(w, http.StatusBadRequest, append([]string{err.Error()}, errors...)...)
		return
	}

	s.cache.InvalidatePrefix("articole")
	Success(w, map[string]interface{}{
		"result":  result,
		"message": "Produs adaugat",
	})
}

// handleModiProduct calls ModiProduct(info).
func (s *Server) handleModiProduct(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var req struct {
		Info string `json:"info"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON body")
		return
	}
	if req.Info == "" {
		ErrorBadRequest(w, "info is required")
		return
	}

	result, err := s.wm.ModiProduct(req.Info)
	if err != nil {
		errors := s.getComErrors()
		Error(w, http.StatusBadRequest, append([]string{err.Error()}, errors...)...)
		return
	}

	s.cache.InvalidatePrefix("articole")
	s.cache.InvalidatePrefix("stocuri")
	Success(w, map[string]interface{}{
		"result":  result,
		"message": "Produs modificat",
	})
}

// handleModificaArticol calls ModificaArticol(info) — same pattern as ModiProduct.
func (s *Server) handleModificaArticol(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var req struct {
		Info string `json:"info"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON body")
		return
	}
	if req.Info == "" {
		ErrorBadRequest(w, "info is required")
		return
	}

	rows, err := s.wm.RawQuery("ModificaArticol", req.Info)
	if err != nil {
		errors := s.getComErrors()
		Error(w, http.StatusBadRequest, append([]string{err.Error()}, errors...)...)
		return
	}

	s.cache.InvalidatePrefix("articole")
	s.cache.InvalidatePrefix("stocuri")
	Success(w, map[string]interface{}{
		"result":  rows,
		"message": "Articol modificat",
	})
}

// handleGenCodArticole calls GenCodArticole.
func (s *Server) handleGenCodArticole(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	result, err := s.wm.GenCodArticole()
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	Success(w, map[string]interface{}{
		"articlesUpdated": result,
	})
}

// clasaArticolJSON is the JSON for article classes.
type clasaArticolJSON struct {
	Simbol   string `json:"simbol"`
	Denumire string `json:"denumire"`
}

// handleGetClaseArticole calls GetClaseArticole and returns structured JSON.
func (s *Server) handleGetClaseArticole(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	classes, err := s.wm.GetClaseArticole()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]clasaArticolJSON, len(classes))
	for i, c := range classes {
		items[i] = clasaArticolJSON{
			Simbol:   c.Simbol,
			Denumire: c.Denumire,
		}
	}
	Success(w, items)
}

// handleSetIDArtField calls SetIDArtField.
func (s *Server) handleSetIDArtField(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var req struct {
		FieldName string `json:"fieldName"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON body")
		return
	}

	if err := s.wm.SetIDArtField(req.FieldName); err != nil {
		ErrorInternal(w, err)
		return
	}

	Success(w, map[string]interface{}{"fieldName": req.FieldName, "set": true})
}

// handleSetClasaArt sets the article class for subsequent operations.
func (s *Server) handleSetClasaArt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	var req struct {
		NumeClasa string `json:"numeClasa"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON")
		return
	}
	if req.NumeClasa == "" {
		ErrorBadRequest(w, "Specify 'numeClasa'")
		return
	}
	if err := s.wm.SetClasaArt(req.NumeClasa); err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetClasaArt", "numeClasa": req.NumeClasa, "applied": true})
}

// handleAddClasaArt adds a new article class. Input: "Simbol;Denumire"
func (s *Server) handleAddClasaArt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	var req struct {
		Info     string `json:"info"`
		Simbol   string `json:"simbol"`
		Denumire string `json:"denumire"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON")
		return
	}
	info := req.Info
	if info == "" && req.Simbol != "" {
		info = req.Simbol + ";" + req.Denumire
	}
	if info == "" {
		ErrorBadRequest(w, "Specify 'simbol'+'denumire' or 'info' (Simbol;Denumire)")
		return
	}
	result, err := s.wm.AddClasaArt(info)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "AddClasaArt", "result": result})
}
