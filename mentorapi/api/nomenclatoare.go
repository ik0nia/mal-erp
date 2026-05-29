package api

import (
	"encoding/json"
	"net/http"
)

// gestiuneJSON is the JSON representation for warehouses.
type gestiuneJSON struct {
	Simbol   string `json:"simbol"`
	Denumire string `json:"denumire"`
}

// handleGetGestiuni returns structured JSON for warehouses.
func (s *Server) handleGetGestiuni(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	gestiuni, err := s.wm.GetListaGestiuni()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]gestiuneJSON, len(gestiuni))
	for i, g := range gestiuni {
		items[i] = gestiuneJSON{
			Simbol:   g.Simbol,
			Denumire: g.Denumire,
		}
	}
	Success(w, items)
}

// employeeJSON is the JSON representation for employees.
// Note: Nume+Prenume are combined in field [0], no separate Prenume.
type employeeJSON struct {
	Nume         string `json:"nume"`
	Marca        string `json:"marca"`
	CNP          string `json:"cnp"`
	EsteActiv    string `json:"esteActiv"`
	EsteAgent    string `json:"esteAgent"`
	SerieBuletin string `json:"serieBuletin"`
	NumarBuletin string `json:"numarBuletin"`
	CodPostal    string `json:"codPostal"`
}

// handleGetPersonal returns structured JSON for employees.
func (s *Server) handleGetPersonal(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	employees, err := s.wm.GetListaPersonal()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]employeeJSON, len(employees))
	for i, e := range employees {
		items[i] = employeeJSON{
			Nume:         e.Nume,
			Marca:        e.Marca,
			CNP:          e.CNP,
			EsteActiv:    e.EsteActiv,
			EsteAgent:    e.EsteAgent,
			SerieBuletin: e.SerieBuletin,
			NumarBuletin: e.NumarBuletin,
			CodPostal:    e.CodPostal,
		}
	}
	Success(w, items)
}

// infoPersJSON — GetInfoPers (employee info by ID).
// Returns 1 field: numeComplet
type infoPersJSON struct {
	NumeComplet string `json:"numeComplet"`
}

func (s *Server) handleGetInfoPers(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetInfoPers(mustAtoi(r.PathValue("id")))
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]infoPersJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 1)
		items = append(items, infoPersJSON{
			NumeComplet: f[0],
		})
	}
	Success(w, items)
}

// bankJSON is the JSON representation for banks.
type bankJSON struct {
	Simbol   string `json:"simbol"`
	Denumire string `json:"denumire"`
}

// handleGetBanci returns structured JSON for banks.
func (s *Server) handleGetBanci(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	banks, err := s.wm.GetListaBanci()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]bankJSON, len(banks))
	for i, b := range banks {
		items[i] = bankJSON{
			Simbol:   b.Simbol,
			Denumire: b.Denumire,
		}
	}
	Success(w, items)
}

// ofertaJSON is the JSON representation for price offers.
type ofertaJSON struct {
	PartID      string `json:"partId"`
	ArtID       string `json:"artId"`
	DataInceput string `json:"dataInceput"`
	DataSfarsit string `json:"dataSfarsit"`
	Pret        string `json:"pret"`
	Cantitate   string `json:"cantitate"`
	Discount    string `json:"discount"`
	CantMinima  string `json:"cantMinima"`
	Moneda      string `json:"moneda"`
}

// handleGetOferte returns structured JSON for price offers.
func (s *Server) handleGetOferte(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	oferte, err := s.wm.GetOferte()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]ofertaJSON, len(oferte))
	for i, o := range oferte {
		items[i] = ofertaJSON{
			PartID:      o.PartID,
			ArtID:       o.ArtID,
			DataInceput: o.DataInceput,
			DataSfarsit: o.DataSfarsit,
			Pret:        o.Pret,
			Cantitate:   o.Cantitate,
			Discount:    o.Discount,
			CantMinima:  o.CantMinima,
			Moneda:      o.Moneda,
		}
	}
	Success(w, items)
}

// subunitateJSON — GetListaSubunit.
// 1 field: denumire
type subunitateJSON struct {
	Denumire string `json:"denumire"`
}

func (s *Server) handleGetSubunitati(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetListaSubunit()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]subunitateJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 1)
		items = append(items, subunitateJSON{
			Denumire: f[0],
		})
	}
	Success(w, items)
}

// delegatJSON — GetListaDelegati.
// 11 fields: camp0;marca;numeComplet;nrAuto;tipActIdent;nrActIdent;serieActIdent;emitentActIdent;tipTransport;camp9;camp10
type delegatJSON struct {
	IDPartener      string `json:"idPartener"`
	Marca           string `json:"marca"`
	NumeComplet     string `json:"numeComplet"`
	NrAuto          string `json:"nrAuto"`
	TipActIdent     string `json:"tipActIdent"`
	NrActIdent      string `json:"nrActIdent"`
	SerieActIdent   string `json:"serieActIdent"`
	EmitentActIdent string `json:"emitentActIdent"`
	TipTransport    string `json:"tipTransport"`
	CNP             string `json:"cnp"`
	Camp10          string `json:"camp10,omitempty"`
}

func (s *Server) handleGetDelegati(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetListaDelegati()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]delegatJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 11)
		items = append(items, delegatJSON{
			IDPartener:      f[0],
			Marca:           f[1],
			NumeComplet:     f[2],
			NrAuto:          f[3],
			TipActIdent:     f[4],
			NrActIdent:      f[5],
			SerieActIdent:   f[6],
			EmitentActIdent: f[7],
			TipTransport:    f[8],
			CNP:             f[9],
			Camp10:          f[10],
		})
	}
	Success(w, items)
}

// monedaJSON — GetMonede.
// 2 fields: denumire;simbol
type monedaJSON struct {
	Denumire string `json:"denumire"`
	Simbol   string `json:"simbol"`
}

func (s *Server) handleGetMonede(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetMonede()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]monedaJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 2)
		items = append(items, monedaJSON{
			Denumire: f[0],
			Simbol:   f[1],
		})
	}
	Success(w, items)
}

// AdaugaGestiune creates a new warehouse. Input: "Simbol;Denumire"
func (s *Server) handleAdaugaGestiune(w http.ResponseWriter, r *http.Request) {
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
	result, err := s.wm.AdaugaGestiune(info)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "AdaugaGestiune", "result": result})
}

// GetDocFromFile(fileName)
func (s *Server) handleGetDocFromFile(w http.ResponseWriter, r *http.Request) {
	var req struct {
		FileName string `json:"fileName"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON")
		return
	}
	s.rawQueryHandler(w, r, "GetDocFromFile", req.FileName)
}

// pretVanzareJSON — GetPretVanzare.
// 1 field: pret
type pretVanzareJSON struct {
	Pret string `json:"pret"`
}

func (s *Server) handleGetPretVanzare(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	artID := queryParam(r, "artId", "")
	partID := queryParam(r, "partId", "")
	rows, err := s.wm.RawQuery("GetPretVanzare", artID, partID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]pretVanzareJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 1)
		items = append(items, pretVanzareJSON{
			Pret: f[0],
		})
	}
	Success(w, items)
}

// categoriePretJSON is the JSON for price categories.
type categoriePretJSON struct {
	Simbol   string `json:"simbol"`
	Denumire string `json:"denumire"`
	Flag     string `json:"flag"`
}

// handleGetCatPret returns structured JSON for price categories.
func (s *Server) handleGetCatPret(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	cats, err := s.wm.GetListaCatPret()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]categoriePretJSON, len(cats))
	for i, c := range cats {
		items[i] = categoriePretJSON{
			Simbol:   c.Simbol,
			Denumire: c.Denumire,
			Flag:     c.Flag,
		}
	}
	Success(w, items)
}

// handleGetArtCatPret — GetListaArtCatPret (returns null when no data).
func (s *Server) handleGetArtCatPret(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetListaArtCatPret")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// handleGetArtCatPretExt — GetListaArtCatPretExt (returns null when no data).
func (s *Server) handleGetArtCatPretExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetListaArtCatPretExt")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// ofertaClientJSON — GetOferteClienti.
// 11 fields: idPartener;codArticol;camp2;camp3;camp4;camp5;camp6;camp7;moneda;categoriePret;camp10
type ofertaClientJSON struct {
	IDPartener    string `json:"idPartener"`
	CodArticol    string `json:"codArticol"`
	DataInceput   string `json:"dataInceput"`
	DataSfarsit   string `json:"dataSfarsit"`
	Pret          string `json:"pret"`
	Cantitate     string `json:"cantitate"`
	Discount      string `json:"discount"`
	CantMinima    string `json:"cantMinima"`
	Moneda        string `json:"moneda"`
	CategoriePret string `json:"categoriePret"`
	Camp10        string `json:"camp10,omitempty"`
}

func (s *Server) handleGetOferteClienti(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetOferteClienti()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]ofertaClientJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 11)
		items = append(items, ofertaClientJSON{
			IDPartener:    f[0],
			CodArticol:    f[1],
			DataInceput:   f[2],
			DataSfarsit:   f[3],
			Pret:          f[4],
			Cantitate:     f[5],
			Discount:      f[6],
			CantMinima:    f[7],
			Moneda:        f[8],
			CategoriePret: f[9],
			Camp10:        f[10],
		})
	}
	Success(w, items)
}

// discArticolJSON — GetCritDiscPeArticole.
// 3 fields: denumireCriteriu;codArticol;procentDiscount
type discArticolJSON struct {
	DenumireCriteriu string `json:"denumireCriteriu"`
	CodArticol       string `json:"codArticol"`
	ProcentDiscount  string `json:"procentDiscount"`
}

func (s *Server) handleGetDiscArticole(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetCritDiscPeArticole()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]discArticolJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 3)
		items = append(items, discArticolJSON{
			DenumireCriteriu: f[0],
			CodArticol:       f[1],
			ProcentDiscount:  f[2],
		})
	}
	Success(w, items)
}

// handleGetDiscClase — GetCritDiscPeClase (returns null when no data).
func (s *Server) handleGetDiscClase(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetCritDiscPeClase")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// discPartenerJSON — GetCritDiscPart.
// 2 fields: idPartener;denumireCriteriu
type discPartenerJSON struct {
	IDPartener       string `json:"idPartener"`
	DenumireCriteriu string `json:"denumireCriteriu"`
}

func (s *Server) handleGetDiscPart(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetCritDiscPart()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]discPartenerJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 2)
		items = append(items, discPartenerJSON{
			IDPartener:       f[0],
			DenumireCriteriu: f[1],
		})
	}
	Success(w, items)
}

// handleGetArtCatPret2 — GetListaArtCatPret2 per article (often empty).
func (s *Server) handleGetArtCatPret2(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	artID := queryParam(r, "artId", "")
	rows, err := s.wm.RawQuery("GetListaArtCatPret2", artID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// handleGetIntervaleDisc — GetIntervaleDisc per criteriu (often empty).
func (s *Server) handleGetIntervaleDisc(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	criteriu := queryParamInt(r, "criteriu", 0)
	rows, err := s.wm.RawQuery("GetIntervaleDisc", criteriu)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// handleGetArticoleDisc — GetArticoleDisc per criteriu (often empty).
func (s *Server) handleGetArticoleDisc(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	criteriu := queryParamInt(r, "criteriu", 0)
	rows, err := s.wm.RawQuery("GetArticoleDisc", criteriu)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// SetArtAnalizat — returns int
func (s *Server) handleSetArtAnalizat(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	artID := queryParam(r, "artId", "")
	result, err := s.wm.SetArtAnalizat(artID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetArtAnalizat", "artId": artID, "result": result, "applied": true})
}

// SetCatPretImplicita — returns int
func (s *Server) handleSetCatPretImplicita(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	catPret := queryParam(r, "catPret", "")
	result, err := s.wm.SetCatPretImplicita(catPret)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetCatPretImplicita", "catPret": catPret, "result": result, "applied": true})
}

// SetFiltruDocNeoperate — void method
func (s *Server) handleSetFiltruDocNeoperate(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	flag := queryParamInt(r, "flag", 0)
	if err := s.wm.SetFiltruDocNeoperate(flag); err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetFiltruDocNeoperate", "flag": flag, "applied": true})
}

// SetInclusivFactAviz — void method
func (s *Server) handleSetInclusivFactAviz(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	flag := queryParamInt(r, "flag", 0)
	if err := s.wm.SetInclusivFactAviz(flag); err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetInclusivFactAviz", "flag": flag, "applied": true})
}

// unitateMasuraJSON — GetUM.
// 4 fields: denumire;;;
type unitateMasuraJSON struct {
	Denumire string `json:"denumire"`
	Camp1    string `json:"camp1,omitempty"`
	Camp2    string `json:"camp2,omitempty"`
	Camp3    string `json:"camp3,omitempty"`
}

func (s *Server) handleGetUM(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetUM")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]unitateMasuraJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 4)
		items = append(items, unitateMasuraJSON{
			Denumire: f[0],
			Camp1:    f[1],
			Camp2:    f[2],
			Camp3:    f[3],
		})
	}
	Success(w, items)
}

// valoareAtributJSON — GetValoriAtribute.
// 5 fields: idAtribut;denumireAtribut;tip;valoare;trailing
type valoareAtributJSON struct {
	IDAtribut       string `json:"idAtribut"`
	DenumireAtribut string `json:"denumireAtribut"`
	Tip             string `json:"tip"`
	Valoare         string `json:"valoare"`
	Camp4           string `json:"camp4,omitempty"`
}

func (s *Server) handleGetValoriAtribute(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetValoriAtribute")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]valoareAtributJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 5)
		items = append(items, valoareAtributJSON{
			IDAtribut:       f[0],
			DenumireAtribut: f[1],
			Tip:             f[2],
			Valoare:         f[3],
			Camp4:           f[4],
		})
	}
	Success(w, items)
}

// handleGetReteteActive — GetReteteActive (structure unknown, returns splitRows).
func (s *Server) handleGetReteteActive(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetReteteActive")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// handleGetReziduale — GetReziduale (structure unknown, returns splitRows).
func (s *Server) handleGetReziduale(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetReziduale")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// SetCodCmdAnalizata — returns int
func (s *Server) handleSetCodCmdAnalizata(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	nrCmd := queryParamInt(r, "nrCmd", 0)
	_, err := s.wm.RawQuery("SetCodCmdAnalizata", nrCmd)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetCodCmdAnalizata", "nrCmd": nrCmd, "applied": true})
}

// SetDataReferinta — sets reference date
func (s *Server) handleSetDataReferinta(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	data := queryParam(r, "data", "")
	if data == "" {
		ErrorBadRequest(w, "data is required")
		return
	}
	_, err := s.wm.RawQuery("SetDataReferinta", data)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetDataReferinta", "data": data, "applied": true})
}

// SetIndexNart — sets article index
func (s *Server) handleSetIndexNart(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	index := queryParamInt(r, "index", 0)
	_, err := s.wm.RawQuery("SetIndexNart", index)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetIndexNart", "index": index, "applied": true})
}

// SetCantReceptii — sets reception quantity flag
func (s *Server) handleSetCantReceptii(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	flag := queryParamInt(r, "flag", 0)
	_, err := s.wm.RawQuery("SetCantReceptii", flag)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetCantReceptii", "flag": flag, "applied": true})
}
