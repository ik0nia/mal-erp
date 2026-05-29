package api

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"strings"

	"github.com/rayone121/libWMEdcom/winmentor"
)

// partenerJSON is the JSON representation matching what the ERP expects.
type partenerJSON struct {
	IDPartener          string `json:"idPartener"`
	Denumire            string `json:"denumire"`
	CodFiscal           string `json:"codFiscal"`
	Localitate          string `json:"localitate"`
	Adresa              string `json:"adresa"`
	Telefon             string `json:"telefon"`
	PersoanaContact     string `json:"persoanaContact"`
	SimbolClasa         string `json:"simbolClasa"`
	DenClasa            string `json:"denClasa"`
	SimbolCatPret       string `json:"simbolCatPret"`
	DenCategPret        string `json:"denCategPret"`
	MarcaAgent          string `json:"marcaAgent"`
	NumeAgent           string `json:"numeAgent"`
	PrenumeAgent        string `json:"prenumeAgent"`
	Scadenta            string `json:"scadenta"`
	Discount            string `json:"discount"`
	DenCritDiscount     string `json:"denCritDiscount"`
	CodExtern           string `json:"codExtern"`
	PartenerBlocat      string `json:"partenerBlocat"`
	CreditVanzare       string `json:"creditVanzare"`
	NrRegCom            string `json:"nrRegCom"`
	ContBanca           string `json:"contBanca"`
	LocalitatiSedii     string `json:"localitatiSedii"`
	Judet               string `json:"judet"`
	MarcaAgentiSedii    string `json:"marcaAgentiSedii"`
	Observatii          string `json:"observatii"`
	FlagSediuSocial     string `json:"flagSediuSocial"`
	CodPostalSedii      string `json:"codPostalSedii"`
	EmailSedii          string `json:"emailSedii"`
	TelPersoaneContact  string `json:"telPersoaneContact"`
	PFsauPJ             string `json:"pfSauPj"`
	MonedaImplicita     string `json:"monedaImplicita"`
	DataAdaugarii       string `json:"dataAdaugarii"`
	Trasee              string `json:"trasee"`
	PuncteAcumulate     string `json:"puncteAcumulate"`
	CodFiscalSedii      string `json:"codFiscalSedii"`
	InfoTipSediu        string `json:"infoTipSediu"`
	Tara                string   `json:"tara"`
	FlagPlataCard       string   `json:"flagPlataCard"`
	SerieBuletinSedii   string   `json:"serieBuletinSedii"`
	NumarBuletinSedii   string   `json:"numarBuletinSedii"`
	DenumiriSedii       []string `json:"denumiriSedii"`
	Camp48              string   `json:"camp48,omitempty"`
}

func partnerToJSON(p winmentor.Partner) partenerJSON {
	// Parse localitatiSedii to extract sediu IDs
	var denumiriSedii []string
	if p.LocalitatiSedii != "" {
		for _, s := range strings.Split(p.LocalitatiSedii, "~") {
			trimmed := strings.TrimSpace(s)
			if trimmed != "" {
				denumiriSedii = append(denumiriSedii, trimmed)
			}
		}
	}

	return partenerJSON{
		IDPartener:          p.ID,
		Denumire:            p.Denumire,
		CodFiscal:           p.CodFiscal,
		Localitate:          p.Localitate,
		Adresa:              p.Adresa,
		Telefon:             p.Telefon,
		PersoanaContact:     p.PersContact,
		SimbolClasa:         p.SimbolClasa,
		DenClasa:            p.DenClasa,
		SimbolCatPret:       p.SimbolCatPret,
		DenCategPret:        p.DenCatPret,
		MarcaAgent:          p.MarcaAgent,
		NumeAgent:           p.NumeAgent,
		PrenumeAgent:        p.PrenumeAgent,
		Scadenta:            p.Scadenta,
		Discount:            p.Discount,
		DenCritDiscount:     p.DenCritDiscount,
		CodExtern:           p.CodExtern,
		PartenerBlocat:      p.PartnerBlocat,
		CreditVanzare:       p.CreditVanzare,
		NrRegCom:            p.NrRegCom,
		ContBanca:           p.ContBanca,
		LocalitatiSedii:     p.LocalitatiSedii,
		Judet:               p.Judet,
		MarcaAgentiSedii:    p.MarcaAgentiSedii,
		Observatii:          p.Observatii,
		FlagSediuSocial:     p.FlagSediuSocial,
		CodPostalSedii:      p.CodPostalSedii,
		EmailSedii:          p.EmailSedii,
		TelPersoaneContact:  p.TelPersContact,
		PFsauPJ:             p.PFsauPJ,
		MonedaImplicita:     p.MonedaImplicita,
		DataAdaugarii:       p.DataAdaugarii,
		Trasee:              p.Trasee,
		PuncteAcumulate:     p.PuncteAcumulate,
		CodFiscalSedii:      p.CodFiscalSedii,
		InfoTipSediu:        p.InfoTipSediu,
		Tara:                p.Tara,
		FlagPlataCard:       p.FlagPlataCard,
		SerieBuletinSedii:   p.SerieBuletinSedii,
		NumarBuletinSedii:   p.NumarBuletinSedii,
		DenumiriSedii:       denumiriSedii,
		Camp48:              p.Unknown48,
	}
}

func (s *Server) handleGetParteneri(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	page, pageSize := parsePageParams(r, s.cfg.DefaultPageSize, s.cfg.MaxPageSize)
	search := r.URL.Query().Get("search")
	cacheKey := "parteneri_typed"

	cached, ok := s.cache.Get(cacheKey)
	if !ok || queryParamBool(r, "refresh") {
		partners, err := s.wm.GetListaParteneri()
		if err != nil {
			ErrorInternal(w, err)
			return
		}
		items := make([]partenerJSON, len(partners))
		for i, p := range partners {
			items[i] = partnerToJSON(p)
		}
		s.cache.Set(cacheKey, items)
		cached = items
	}

	items, ok := cached.([]partenerJSON)
	if !ok {
		s.cache.Delete(cacheKey)
		ErrorInternal(w, fmt.Errorf("cache type mismatch"))
		return
	}

	if search != "" {
		searchLower := strings.ToLower(search)
		filtered := make([]partenerJSON, 0)
		for _, item := range items {
			if strings.Contains(strings.ToLower(item.Denumire), searchLower) ||
				strings.Contains(strings.ToLower(item.CodFiscal), searchLower) ||
				strings.Contains(strings.ToLower(item.IDPartener), searchLower) ||
				strings.Contains(strings.ToLower(item.CodExtern), searchLower) {
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

// infoPartJSON — GetInfoPart (partner info by ID).
// Returns 1 field: denumirePartener
type infoPartJSON struct {
	Denumire string `json:"denumire"`
}

func (s *Server) handleGetInfoPart(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetInfoPart(r.PathValue("id"))
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]infoPartJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 1)
		items = append(items, infoPartJSON{
			Denumire: f[0],
		})
	}
	Success(w, items)
}

func (s *Server) handleGetNextPartID(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	result, err := s.wm.GetNextPartID()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"nextId": result})
}

// partnerRequest supports both structured JSON and raw "info" string.
type partnerRequest struct {
	// Raw semicolon-separated string (legacy/fallback)
	Info string `json:"info"`
	// Structured fields (preferred)
	ID                    string `json:"id"`
	Denumire              string `json:"denumire"`
	CodFiscal             string `json:"codFiscal"`
	SediulInLocalitatea   string `json:"localitate"`
	AdresaSediu           string `json:"adresa"`
	TelefonSediu          string `json:"telefon"`
	PersoaneContact       string `json:"persoaneContact"`
	SimbolClasa           string `json:"simbolClasa"`
	SimbolCategoriePret   string `json:"simbolCategoriePret"`
	IDAgentImplicit       string `json:"idAgent"`
	NrRegistrulComertului string `json:"nrRegCom"`
	Observatii            string `json:"observatii"`
	SimbolBanca           string `json:"simbolBanca"`
	NumeBanca             string `json:"numeBanca"`
	LocalitateBanca       string `json:"localitateBanca"`
	ContBanca             string `json:"contBanca"`
	ZiImplicitaPlata      string `json:"ziImplicitaPlata"`
	NumeSediuSecundar     string `json:"numeSediuSecundar"`
	AdresaSediuSecundar   string `json:"adresaSediuSecundar"`
	TelefonSediuSecundar  string `json:"telefonSediuSecundar"`
	LocalitateSediuSec    string `json:"localitateSediuSec"`
	IDAgentSediuSec       string `json:"idAgentSediuSec"`
	CodExtern             string `json:"codExtern"`
	SimbolAutoJudetLivr   string `json:"judetLivrare"`
	SimbolAutoJudetSediu  string `json:"judetSediu"`
	FlagPF                string `json:"flagPF"`
	ScadentaImplicita     string `json:"scadentaImplicita"`
	SimbolTipContabil     string `json:"simbolTipContabil"`
	FlagProducator        string `json:"flagProducator"`
	EmailSediuSocial      string `json:"emailSediu"`
	EmailSediiLivrare     string `json:"emailSediiLivrare"`
	TVAIncasare           string `json:"tvaIncasare"`
	SerAI                 string `json:"serAI"`
	NrAI                  string `json:"nrAI"`
	SimbolAutoTaraSediu   string `json:"taraSediu"`
}

func (req *partnerRequest) toPartnerInput() *winmentor.PartnerInput {
	return &winmentor.PartnerInput{
		ID:                    req.ID,
		Denumire:              req.Denumire,
		CodFiscal:             req.CodFiscal,
		SediulInLocalitatea:   req.SediulInLocalitatea,
		AdresaSediu:           req.AdresaSediu,
		TelefonSediu:          req.TelefonSediu,
		PersoaneContact:       req.PersoaneContact,
		SimbolClasa:           req.SimbolClasa,
		SimbolCategoriePret:   req.SimbolCategoriePret,
		IDAgentImplicit:       req.IDAgentImplicit,
		NrRegistrulComertului: req.NrRegistrulComertului,
		Observatii:            req.Observatii,
		SimbolBanca:           req.SimbolBanca,
		NumeBanca:             req.NumeBanca,
		LocalitateBanca:       req.LocalitateBanca,
		ContBanca:             req.ContBanca,
		ZiImplicitaPlata:      req.ZiImplicitaPlata,
		NumeSediuSecundar:     req.NumeSediuSecundar,
		AdresaSediuSecundar:   req.AdresaSediuSecundar,
		TelefonSediuSecundar:  req.TelefonSediuSecundar,
		LocalitateSediuSec:    req.LocalitateSediuSec,
		IDAgentSediuSec:       req.IDAgentSediuSec,
		CodExtern:             req.CodExtern,
		SimbolAutoJudetLivr:   req.SimbolAutoJudetLivr,
		SimbolAutoJudetSediu:  req.SimbolAutoJudetSediu,
		FlagPF:                req.FlagPF,
		ScadentaImplicita:     req.ScadentaImplicita,
		SimbolTipContabil:     req.SimbolTipContabil,
		FlagProducator:        req.FlagProducator,
		EmailSediuSocial:      req.EmailSediuSocial,
		EmailSediiLivrare:     req.EmailSediiLivrare,
		TVAIncasare:           req.TVAIncasare,
		SerAI:                 req.SerAI,
		NrAI:                  req.NrAI,
		SimbolAutoTaraSediu:   req.SimbolAutoTaraSediu,
	}
}

func (s *Server) handleAdaugaPartener(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	var req partnerRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON")
		return
	}

	p := req.toPartnerInput()
	log.Printf("[AdaugaPartener] record: %s", p.ToRecord())
	result, err := s.wm.AdaugaPartener(p)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "AdaugaPartener", "result": result})
}

func (s *Server) handleModificaPartener(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	var req partnerRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON")
		return
	}

	p := req.toPartnerInput()
	log.Printf("[ModificaPartener] record: %s", p.ToRecord())
	result, err := s.wm.ModificaPartener(p)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "ModificaPartener", "result": result})
}

func (s *Server) handleGenCodParteneri(w http.ResponseWriter, r *http.Request) {
	s.genericComCall(w, r, "GenCodParteneri")
}

func (s *Server) handleSetIDPartField(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	var req struct {
		FieldName string `json:"fieldName"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON")
		return
	}
	if err := s.wm.SetIDPartField(req.FieldName); err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"fieldName": req.FieldName, "set": true})
}

// clasaParteneriJSON is the JSON for partner classes.
type clasaParteneriJSON struct {
	Simbol   string `json:"simbol"`
	Denumire string `json:"denumire"`
}

// handleGetClaseParteneri returns structured JSON for partner classes.
func (s *Server) handleGetClaseParteneri(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	classes, err := s.wm.GetClaseParteneri()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]clasaParteneriJSON, len(classes))
	for i, c := range classes {
		items[i] = clasaParteneriJSON{
			Simbol:   c.Simbol,
			Denumire: c.Denumire,
		}
	}
	Success(w, items)
}

// clientInfoJSON is the JSON for client list items.
type clientInfoJSON struct {
	CodIntern     string `json:"codIntern"`
	CodExtern     string `json:"codExtern"`
	Denumire      string `json:"denumire"`
	CodFiscal     string `json:"codFiscal"`
	Localitate    string `json:"localitate"`
	Judet         string `json:"judet"`
	Adresa        string `json:"adresa"`
	Telefon       string `json:"telefon"`
	MarcaAgent    string `json:"marcaAgent"`
	DataFact      string `json:"dataFact"`
	SediiPart     string `json:"sediiPart"`
	SimbolClasa   string `json:"simbolClasa"`
	DenumireClasa string `json:"denumireClasa"`
	LocalitSedii  string `json:"localitSedii"`
}

// handleGetClienti returns structured JSON for client list.
func (s *Server) handleGetClienti(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	an := queryParamInt(r, "an", 2020)
	luna := queryParamInt(r, "luna", 1)
	clients, err := s.wm.GetListaClienti(an, luna)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]clientInfoJSON, len(clients))
	for i, c := range clients {
		items[i] = clientInfoJSON{
			CodIntern:     c.CodIntern,
			CodExtern:     c.CodExtern,
			Denumire:      c.Denumire,
			CodFiscal:     c.CodFiscal,
			Localitate:    c.Localitate,
			Judet:         c.Judet,
			Adresa:        c.Adresa,
			Telefon:       c.Telefon,
			MarcaAgent:    c.MarcaAgent,
			DataFact:      c.DataFact,
			SediiPart:     c.SediiPart,
			SimbolClasa:   c.SimbolClasa,
			DenumireClasa: c.DenumireClasa,
			LocalitSedii:  c.LocalitSedii,
		}
	}
	Success(w, items)
}

// localitateJSON — GetListaLocalitati.
// 1 field: denumire
type localitateJSON struct {
	Denumire string `json:"denumire"`
}

func (s *Server) handleGetLocalitati(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetListaLocalitati()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]localitateJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 1)
		items = append(items, localitateJSON{
			Denumire: f[0],
		})
	}
	Success(w, items)
}

// nomenclatorLocalitateJSON — GetNomenclatorLocalitati.
// 3 fields: denumire;judet;codPostal
type nomenclatorLocalitateJSON struct {
	Denumire  string `json:"denumire"`
	Judet     string `json:"judet"`
	CodPostal string `json:"codPostal"`
}

func (s *Server) handleGetNomenclatorLocalitati(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetNomenclatorLocalitati()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]nomenclatorLocalitateJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 3)
		items = append(items, nomenclatorLocalitateJSON{
			Denumire:  f[0],
			Judet:     f[1],
			CodPostal: f[2],
		})
	}
	Success(w, items)
}
