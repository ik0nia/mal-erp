package api

import (
	"fmt"
	"net/http"
	"strings"

	"github.com/rayone121/libWMEdcom/winmentor"
)

// stockArticleJSON is the JSON representation matching what the ERP expects.
type stockArticleJSON struct {
	CodExtern      string `json:"codExtern"`
	Denumire       string `json:"denumire"`
	DenUM          string `json:"denUM"`
	PretVanzare    string `json:"pretVanzare"`
	Stoc           string `json:"stoc"`
	SimbolClasa    string `json:"simbolClasa"`
	DenClasa       string `json:"denClasa"`
	IDProducator   string `json:"idProducator"`
	DenProducator  string `json:"denProducator"`
	IDFurnizor     string `json:"idFurnizor"`
	DenFurnizor    string `json:"denFurnizor"`
	SimbolGestiune string `json:"simbolGestiune"`
	DenGestiune    string `json:"denGestiune"`
	CotaTVA        string `json:"cotaTVA"`
	FlagTVAInclus  string `json:"flagTVAInclus"`
	PretCuTVA      string `json:"pretCuTVA"`
	StocRezervat     string `json:"stocRezervat"`
	IDIntern         string `json:"idIntern"`
	StocMinim        string `json:"stocMinim"`
	ObservatiiProdus string `json:"observatiiProdus"`
	Camp20           string `json:"camp20,omitempty"`
}

func stockArticleToJSON(a winmentor.StockArticle) stockArticleJSON {
	return stockArticleJSON{
		CodExtern:      a.CodExtern,
		Denumire:       a.Denumire,
		DenUM:          a.UM,
		PretVanzare:    a.PretVanzare,
		Stoc:           a.Stoc,
		SimbolClasa:    a.SimbolClasa,
		DenClasa:       a.DenClasa,
		IDProducator:   a.IDProducator,
		DenProducator:  a.DenProducator,
		IDFurnizor:     a.IDFurnizor,
		DenFurnizor:    a.DenFurnizor,
		SimbolGestiune: a.SimbolGestiune,
		DenGestiune:    a.DenGestiune,
		CotaTVA:        a.CotaTVA,
		FlagTVAInclus:  a.FlagTVAInclus,
		PretCuTVA:      a.PretCuTVA,
		StocRezervat:     a.StocRezervat,
		IDIntern:         a.IDIntern,
		StocMinim:        a.StocMinim,
		ObservatiiProdus: a.ObservatiiProdus,
		Camp20:           a.Unknown20,
	}
}

// handleGetStocuri calls GetStocArticole and returns structured JSON.
func (s *Server) handleGetStocuri(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	page, pageSize := parsePageParams(r, s.cfg.DefaultPageSize, s.cfg.MaxPageSize)
	search := r.URL.Query().Get("search")
	cacheKey := "stocuri_typed"

	cached, ok := s.cache.Get(cacheKey)
	if !ok || queryParamBool(r, "refresh") {
		stock, err := s.wm.GetStocArticole()
		if err != nil {
			ErrorInternal(w, err)
			return
		}
		items := make([]stockArticleJSON, len(stock))
		for i, a := range stock {
			items[i] = stockArticleToJSON(a)
		}
		s.cache.Set(cacheKey, items)
		cached = items
	}

	items, ok := cached.([]stockArticleJSON)
	if !ok {
		s.cache.Delete(cacheKey)
		ErrorInternal(w, fmt.Errorf("cache type mismatch"))
		return
	}

	if search != "" {
		searchLower := strings.ToLower(search)
		filtered := make([]stockArticleJSON, 0)
		for _, item := range items {
			if strings.Contains(strings.ToLower(item.CodExtern), searchLower) ||
				strings.Contains(strings.ToLower(item.Denumire), searchLower) {
				filtered = append(filtered, item)
			}
		}
		items = filtered
	}

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

// handleGetStocArticol returns structured stock for a single article.
func (s *Server) handleGetStocArticol(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	artID := r.PathValue("id")
	gestID := queryParam(r, "gestiune", "")
	result, err := s.wm.GetStocArticol(artID, gestID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	if result == nil {
		Success(w, nil)
		return
	}
	Success(w, map[string]interface{}{
		"codExtern":   result.CodExtern,
		"denumire":    result.Denumire,
		"denUM":       result.UM,
		"pretVanzare": result.PretVanzare,
		"stoc":        result.Stoc,
	})
}

// stocArtDetaliatJSON — GetStocArtDetaliat.
// 16 fields: simbolGestiune;dataIntrare;camp2;camp3;pretAchizitie;stoc;dataExpirare;denFurnizor;camp8;denArticol;denUM;pretVanzare;cont;operat;cotaTVA;camp15
type stocArtDetaliatJSON struct {
	SimbolGestiune string `json:"simbolGestiune"`
	DataIntrare    string `json:"dataIntrare"`
	Camp2          string `json:"camp2,omitempty"`
	Camp3          string `json:"camp3,omitempty"`
	PretAchizitie  string `json:"pretAchizitie"`
	Stoc           string `json:"stoc"`
	DataExpirare   string `json:"dataExpirare"`
	DenFurnizor    string `json:"denFurnizor"`
	Camp8          string `json:"camp8,omitempty"`
	DenArticol     string `json:"denArticol"`
	DenUM          string `json:"denUM"`
	PretVanzare    string `json:"pretVanzare"`
	Cont           string `json:"cont"`
	Operat         string `json:"operat"`
	CotaTVA        string `json:"cotaTVA"`
	Camp15         string `json:"camp15,omitempty"`
}

func (s *Server) handleGetStocArtDetaliat(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	artID := r.PathValue("id")
	gestID := queryParam(r, "gestiune", "")
	rows, err := s.wm.GetStocArtDetaliat(artID, gestID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]stocArtDetaliatJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 16)
		items = append(items, stocArtDetaliatJSON{
			SimbolGestiune: f[0],
			DataIntrare:    f[1],
			Camp2:          f[2],
			Camp3:          f[3],
			PretAchizitie:  f[4],
			Stoc:           f[5],
			DataExpirare:   f[6],
			DenFurnizor:    f[7],
			Camp8:          f[8],
			DenArticol:     f[9],
			DenUM:          f[10],
			PretVanzare:    f[11],
			Cont:           f[12],
			Operat:         f[13],
			CotaTVA:        f[14],
			Camp15:         f[15],
		})
	}
	Success(w, items)
}

// stocArticolExtJSON — GetStocArticoleExt / GetStocArticoleExt2.
// 20 fields: codExtern;denumire;denUM;pretVanzare;stoc;camp5;camp6;idProducator;denProducator;
//   idFurnizor;denFurnizor;simbolGestiune;denGestiune;cotaTVA;flagTVAInclus;pretCuTVA;
//   camp16;dataExpirare;camp18;camp19
type stocArticolExtJSON struct {
	CodExtern      string `json:"codExtern"`
	Denumire       string `json:"denumire"`
	DenUM          string `json:"denUM"`
	PretVanzare    string `json:"pretVanzare"`
	Stoc           string `json:"stoc"`
	SimbolClasa    string `json:"simbolClasa"`
	DenClasa       string `json:"denClasa"`
	IDProducator   string `json:"idProducator"`
	DenProducator  string `json:"denProducator"`
	IDFurnizor     string `json:"idFurnizor"`
	DenFurnizor    string `json:"denFurnizor"`
	SimbolGestiune string `json:"simbolGestiune"`
	DenGestiune    string `json:"denGestiune"`
	CotaTVA        string `json:"cotaTVA"`
	FlagTVAInclus  string `json:"flagTVAInclus"`
	PretCuTVA      string `json:"pretCuTVA"`
	Camp16         string `json:"camp16,omitempty"`
	DataExpirare   string `json:"dataExpirare"`
	Camp18         string `json:"camp18,omitempty"`
	Masa           string `json:"masa"`
}

func parseStocArticolExt(rows []string) []stocArticolExtJSON {
	items := make([]stocArticolExtJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 20)
		items = append(items, stocArticolExtJSON{
			CodExtern:      f[0],
			Denumire:       f[1],
			DenUM:          f[2],
			PretVanzare:    f[3],
			Stoc:           f[4],
			SimbolClasa:    f[5],
			DenClasa:       f[6],
			IDProducator:   f[7],
			DenProducator:  f[8],
			IDFurnizor:     f[9],
			DenFurnizor:    f[10],
			SimbolGestiune: f[11],
			DenGestiune:    f[12],
			CotaTVA:        f[13],
			FlagTVAInclus:  f[14],
			PretCuTVA:      f[15],
			Camp16:         f[16],
			DataExpirare:   f[17],
			Camp18:         f[18],
			Masa:           f[19],
		})
	}
	return items
}

func (s *Server) handleGetStocuriExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetStocArticoleExt()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, parseStocArticolExt(rows))
}

func (s *Server) handleGetStocuriExt2(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	gestID := queryParam(r, "gestiune", "")
	rows, err := s.wm.GetStocArticoleExt2(gestID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, parseStocArticolExt(rows))
}

// stocGestiuneJSON is the JSON representation for stock per warehouse.
type stocGestiuneJSON struct {
	DenGestiune        string `json:"denGestiune"`
	SimbolGestiune     string `json:"simbolGestiune"`
	Denumire           string `json:"denumire"`
	CodExtern          string `json:"codExtern"`
	ContContabil       string `json:"contContabil"`
	DenUM              string `json:"denUM"`
	Stoc               string `json:"stoc"`
	ValoareStoc        string `json:"valoareStoc"`
	ValoareStocPrecisa string `json:"valoareStocPrecisa"`
	CotaTVA            string `json:"cotaTVA"`
}

// handleGetStocuriPeGestiuni returns stock per warehouse as structured JSON.
func (s *Server) handleGetStocuriPeGestiuni(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	items, err := s.wm.GetStocuriPeGestiuni()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	result := make([]stocGestiuneJSON, len(items))
	for i, sg := range items {
		result[i] = stocGestiuneJSON{
			DenGestiune:        sg.DenGestiune,
			SimbolGestiune:     sg.SimbolGestiune,
			Denumire:           sg.Denumire,
			CodExtern:          sg.CodExtern,
			ContContabil:       sg.ContContabil,
			DenUM:              sg.UM,
			Stoc:               sg.Stoc,
			ValoareStoc:        sg.ValoareStoc,
			ValoareStocPrecisa: sg.ValoareStocPrecisa,
			CotaTVA:            sg.CotaTVA,
		}
	}
	Success(w, result)
}

// stocInitialAmbalajJSON — GetStocInitialAmbalaj.
// 4 fields: idPartener;codArticol;denArticol;cantitate
type stocInitialAmbalajJSON struct {
	IDPartener string `json:"idPartener"`
	CodArticol string `json:"codArticol"`
	DenArticol string `json:"denArticol"`
	Cantitate  string `json:"cantitate"`
}

func (s *Server) handleGetStocInitialAmbalaj(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	an := queryParamInt(r, "an", 2026)
	luna := queryParamInt(r, "luna", 4)
	rows, err := s.wm.GetStocInitialAmbalaj(an, luna)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]stocInitialAmbalajJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 4)
		items = append(items, stocInitialAmbalajJSON{
			IDPartener: f[0],
			CodArticol: f[1],
			DenArticol: f[2],
			Cantitate:  f[3],
		})
	}
	Success(w, items)
}

// handleGetStocuriExt3 — GetStocArticoleExt3 (structure unknown, returns splitRows).
func (s *Server) handleGetStocuriExt3(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetStocArticoleExt3")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// handleGetStocuriExt4 — GetStocArticoleExt4 (structure unknown, returns splitRows).
func (s *Server) handleGetStocuriExt4(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetStocArticoleExt4")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// handleGetStocuriExt5 — GetStocArticoleExt5 (structure unknown, returns splitRows).
func (s *Server) handleGetStocuriExt5(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetStocArticoleExt5")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// handleGetMiscariAmbalaje — GetMiscariAmbalaje (structure rarely populated).
func (s *Server) handleGetMiscariAmbalaje(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	anStart := queryParamInt(r, "anStart", 2026)
	lunaStart := queryParamInt(r, "lunaStart", 1)
	anStop := queryParamInt(r, "anStop", 2026)
	lunaStop := queryParamInt(r, "lunaStop", 12)
	rows, err := s.wm.RawQuery("GetMiscariAmbalaje", anStart, lunaStart, anStop, lunaStop)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}
