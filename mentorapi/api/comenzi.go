package api

import (
	"net/http"
	"strings"
)

// comandaNefacturataJSON is the JSON for uninvoiced orders.
type comandaNefacturataJSON struct {
	CodArticol    string `json:"codArticol"`
	NrComanda     string `json:"nrComanda"`
	Cantitate     string `json:"cantitate"`
	DenUM         string `json:"denUM"`
	DataComanda   string `json:"dataComanda"`
	IDPartener    string `json:"idPartener"`
	MarcaAgent    string `json:"marcaAgent"`
	Pret          string `json:"pret"`
	CantComanda   string `json:"cantComanda"`
	CodExternAlt  string `json:"codExternAlt"`
	SerieDocument string `json:"serieDocument"`
	NrDocument    string `json:"nrDocument"`
	Observatii    string `json:"observatii"`
	SediuPartener string `json:"sediuPartener"`
	DataLivrare   string `json:"dataLivrare"`
	Pozitie       string `json:"pozitie"`
	NumePartener  string `json:"numePartener"`
	Moneda        string `json:"moneda"`
}

// handleGetComenziNefacturate returns structured JSON for uninvoiced orders.
func (s *Server) handleGetComenziNefacturate(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	orders, err := s.wm.GetComenziNefacturate()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]comandaNefacturataJSON, len(orders))
	for i, o := range orders {
		items[i] = comandaNefacturataJSON{
			CodArticol:    o.CodArticol,
			NrComanda:     o.NrComanda,
			Cantitate:     o.Cantitate,
			DenUM:         o.DenUM,
			DataComanda:   o.DataComanda,
			IDPartener:    o.IDPartener,
			MarcaAgent:    o.MarcaAgent,
			Pret:          o.Pret,
			CantComanda:   o.CantComanda,
			CodExternAlt:  o.CodExternAlt,
			SerieDocument: o.SerieDocument,
			NrDocument:    o.NrDocument,
			Observatii:    o.Observatii,
			SediuPartener: o.SediuPartener,
			DataLivrare:   o.DataLivrare,
			Pozitie:       o.Pozitie,
			NumePartener:  o.NumePartener,
			Moneda:        o.Moneda,
		}
	}
	Success(w, items)
}

// comandaFurnizorJSON is the JSON for supplier orders (GetSuppliersOrders).
// 10 fields: nrComanda;idPartener;denPartener;dataComanda;dataLivrare;codArticol;cantitate;denUM;stare;camp9
type comandaFurnizorJSON struct {
	NrComanda   string `json:"nrComanda"`
	IDPartener  string `json:"idPartener"`
	DenPartener string `json:"denPartener"`
	DataComanda string `json:"dataComanda"`
	DataLivrare string `json:"dataLivrare"`
	CodArticol  string `json:"codArticol"`
	Cantitate   string `json:"cantitate"`
	DenUM       string `json:"denUM"`
	Stare       string `json:"stare"`
	Camp9       string `json:"camp9,omitempty"`
}

func (s *Server) handleGetComenziFurnizori(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetSuppliersOrders()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]comandaFurnizorJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 10)
		items = append(items, comandaFurnizorJSON{
			NrComanda:   f[0],
			IDPartener:  f[1],
			DenPartener: f[2],
			DataComanda: f[3],
			DataLivrare: f[4],
			CodArticol:  f[5],
			Cantitate:   f[6],
			DenUM:       f[7],
			Stare:       f[8],
			Camp9:       f[9],
		})
	}
	Success(w, items)
}

// handleGetInfoCmdNefacturate — GetInfoCmdNefacturate (structure unknown, always empty).
func (s *Server) handleGetInfoCmdNefacturate(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetInfoCmdNefacturate")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// handleGetInfoCmdSubNefacturate — GetInfoCmdSubNefacturate (structure unknown, always empty).
func (s *Server) handleGetInfoCmdSubNefacturate(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetInfoCmdSubNefacturate")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// transferJSON is the JSON for warehouse transfers (GetTransferuri).
// 15 fields: gestSursa;gestDest;nrDoc;data;codArticol;denArticol;cantitate;pretVanzare;pretAchizitie;contSursa;contDest;camp11;valoare;camp13;camp14
type transferJSON struct {
	GestSursa     string `json:"gestSursa"`
	GestDest      string `json:"gestDest"`
	NrDoc         string `json:"nrDoc"`
	Data          string `json:"data"`
	CodArticol    string `json:"codArticol"`
	DenArticol    string `json:"denArticol"`
	Cantitate     string `json:"cantitate"`
	PretVanzare   string `json:"pretVanzare"`
	PretAchizitie string `json:"pretAchizitie"`
	ContSursa     string `json:"contSursa"`
	ContDest      string `json:"contDest"`
	Camp11        string `json:"camp11,omitempty"`
	Valoare       string `json:"valoare"`
	Camp13        string `json:"camp13,omitempty"`
	Camp14        string `json:"camp14,omitempty"`
}

func (s *Server) handleGetTransferuri(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetTransferuri()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]transferJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 15)
		items = append(items, transferJSON{
			GestSursa:     f[0],
			GestDest:      f[1],
			NrDoc:         f[2],
			Data:          f[3],
			CodArticol:    f[4],
			DenArticol:    f[5],
			Cantitate:     f[6],
			PretVanzare:   f[7],
			PretAchizitie: f[8],
			ContSursa:     f[9],
			ContDest:      f[10],
			Camp11:        f[11],
			Valoare:       f[12],
			Camp13:        f[13],
			Camp14:        f[14],
		})
	}
	Success(w, items)
}

// infoCmdBonJSON — GetInfoCmdBon (per order number).
// 8 fields: nrComanda;pozitie;numeClient;idPartener;valoare;camp5;camp6;camp7
type infoCmdBonJSON struct {
	NrComanda  string `json:"nrComanda"`
	Pozitie    string `json:"pozitie"`
	NumeClient string `json:"numeClient"`
	IDPartener string `json:"idPartener"`
	Valoare    string `json:"valoare"`
	Camp5      string `json:"camp5,omitempty"`
	Camp6      string `json:"camp6,omitempty"`
	Camp7      string `json:"camp7,omitempty"`
}

func (s *Server) handleGetInfoCmdBon(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	nrCmd := queryParamInt(r, "nrCmd", 0)
	rows, err := s.wm.GetInfoCmdBon(nrCmd)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]infoCmdBonJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 8)
		items = append(items, infoCmdBonJSON{
			NrComanda:  f[0],
			Pozitie:    f[1],
			NumeClient: f[2],
			IDPartener: f[3],
			Valoare:    f[4],
			Camp5:      f[5],
			Camp6:      f[6],
			Camp7:      f[7],
		})
	}
	Success(w, items)
}

// handleGetInfoCmdBonuri — GetInfoCmdBonuri (structure unknown, always empty).
func (s *Server) handleGetInfoCmdBonuri(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	zi := queryParamInt(r, "zi", 0)
	rows, err := s.wm.RawQuery("GetInfoCmdBonuri", zi)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// infoBonJSON — GetInfoBon (receipt info per day).
// 4 fields: nrBon;data;camp2;camp3
type infoBonJSON struct {
	NrBon string `json:"nrBon"` // [0] număr bon
	Data  string `json:"data"`  // [1] data (dd.mm.yyyy)
	Camp2 string `json:"camp2"` // [2] necunoscut
	Camp3 string `json:"camp3"` // [3] necunoscut
}

func (s *Server) handleGetInfoBon(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	zi := queryParamInt(r, "zi", 1)
	rows, err := s.wm.GetInfoBon(zi)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]infoBonJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 4)
		items = append(items, infoBonJSON{
			NrBon: f[0],
			Data:  f[1],
			Camp2: f[2],
			Camp3: f[3],
		})
	}
	Success(w, items)
}

// handleGetInfoBonExt — GetInfoBonExt (structure rarely populated).
func (s *Server) handleGetInfoBonExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	nrBon := queryParamInt(r, "nrBon", 0)
	zi := queryParamInt(r, "zi", 0)
	rows, err := s.wm.RawQuery("GetInfoBonExt", nrBon, zi)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// SetCmdImplicitAcceptat — void method
func (s *Server) handleSetCmdImplicitAcceptat(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	flag := queryParamInt(r, "flag", 1)
	if err := s.wm.SetCmdImplicitAcceptat(flag); err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetCmdImplicitAcceptat", "flag": flag, "applied": true})
}

// ActualizeazaAcceptat — takes OleVariant ([]string), returns Integer
func (s *Server) handleActualizeazaAcceptat(w http.ResponseWriter, r *http.Request) {
	s.variantArrayHandler(w, r, "ActualizeazaAcceptat")
}

// infoComenziJSON — GetInfoComenzi.
// 9 fields: idPartener;dataComanda;nrComanda;codArticol;cantitate;pret;camp6;camp7;acceptat
type infoComenziJSON struct {
	IDPartener  string `json:"idPartener"`
	DataComanda string `json:"dataComanda"`
	NrComanda   string `json:"nrComanda"`
	CodArticol  string `json:"codArticol"`
	Cantitate   string `json:"cantitate"`
	Pret        string `json:"pret"`
	Livrat      string `json:"livrat"`
	Facturat    string `json:"facturat"`
	Acceptat    string `json:"acceptat"`
}

func (s *Server) handleGetInfoComenzi(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetInfoComenzi()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]infoComenziJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 9)
		items = append(items, infoComenziJSON{
			IDPartener:  f[0],
			DataComanda: f[1],
			NrComanda:   f[2],
			CodArticol:  f[3],
			Cantitate:   f[4],
			Pret:        f[5],
			Livrat:      f[6],
			Facturat:    f[7],
			Acceptat:    f[8],
		})
	}
	Success(w, items)
}

// handleGetCmdSubunitActive — GetCmdSubunitActive (structure unknown, always empty).
func (s *Server) handleGetCmdSubunitActive(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetCmdSubunitActive")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// artComandaInternaJSON — GetArtComenziInterne.
// 12 fields: idIntern;nrComanda;dataComanda;gestiune;codArticol;denArticol;cantitate;cantProdusa;pret;field9;field10;field11
type artComandaInternaJSON struct {
	IDIntern    string `json:"idIntern"`
	NrComanda   string `json:"nrComanda"`
	DataComanda string `json:"dataComanda"`
	Gestiune    string `json:"gestiune"`
	CodArticol  string `json:"codArticol"`
	DenArticol  string `json:"denArticol"`
	Cantitate   string `json:"cantitate"`
	CantProdusa string `json:"cantProdusa"`
	Pret        string `json:"pret"`
	Camp9       string `json:"camp9,omitempty"`
	Camp10      string `json:"camp10,omitempty"`
	Camp11      string `json:"camp11,omitempty"`
}

func (s *Server) handleGetArtComenziInterne(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetArtComenziInterne")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]artComandaInternaJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 12)
		items = append(items, artComandaInternaJSON{
			IDIntern:    f[0],
			NrComanda:   f[1],
			DataComanda: f[2],
			Gestiune:    f[3],
			CodArticol:  f[4],
			DenArticol:  f[5],
			Cantitate:   f[6],
			CantProdusa: f[7],
			Pret:        f[8],
			Camp9:       f[9],
			Camp10:      f[10],
			Camp11:      f[11],
		})
	}
	Success(w, items)
}

// handleGetStadiuComanda — GetStadiuComanda (structure unknown, returns splitRows).
func (s *Server) handleGetStadiuComanda(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	nrCmd := queryParamInt(r, "nrCmd", 0)
	rows, err := s.wm.RawQuery("GetStadiuComanda", nrCmd)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// handleGetMatComenziInterne — GetMatComenziInterne (structure unknown, returns splitRows).
func (s *Server) handleGetMatComenziInterne(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetMatComenziInterne")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// SetInclusivCmdFurn — void method
func (s *Server) handleSetInclusivCmdFurn(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	flag := queryParamInt(r, "flag", 1)
	if err := s.wm.SetInclusivCmdFurn(flag); err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetInclusivCmdFurn", "flag": flag, "applied": true})
}

// infoIesiriJSON — GetInfoIesiri (outgoing documents per day).
// 17 fields: pozitie;nrDoc;data;denPartener;camp4;camp5;observatii;marcaAgent;localitate;judet;tara;adresa;valoare;idPartener;telefon;codPostal;camp16
type infoIesiriJSON struct {
	Pozitie     string `json:"pozitie"`
	NrDoc       string `json:"nrDoc"`
	Data        string `json:"data"`
	DenPartener string `json:"denPartener"`
	Camp4       string `json:"camp4,omitempty"`
	Camp5       string `json:"camp5,omitempty"`
	Observatii  string `json:"observatii"`
	MarcaAgent  string `json:"marcaAgent"`
	Localitate  string `json:"localitate"`
	Judet       string `json:"judet"`
	Tara        string `json:"tara"`
	Adresa      string `json:"adresa"`
	Valoare     string `json:"valoare"`
	IDPartener  string `json:"idPartener"`
	Telefon     string `json:"telefon"`
	CodPostal   string `json:"codPostal"`
	Camp16      string `json:"camp16,omitempty"`
}

func (s *Server) handleGetInfoIesiri(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	zi := queryParamInt(r, "zi", 1)
	rows, err := s.wm.GetInfoIesiri(zi)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]infoIesiriJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 17)
		items = append(items, infoIesiriJSON{
			Pozitie:     f[0],
			NrDoc:       f[1],
			Data:        f[2],
			DenPartener: f[3],
			Camp4:       f[4],
			Camp5:       f[5],
			Observatii:  f[6],
			MarcaAgent:  f[7],
			Localitate:  f[8],
			Judet:       f[9],
			Tara:        f[10],
			Adresa:      f[11],
			Valoare:     f[12],
			IDPartener:  f[13],
			Telefon:     f[14],
			CodPostal:   f[15],
			Camp16:      f[16],
		})
	}
	Success(w, items)
}

// infoIesiriExtJSON — GetInfoIesiriExt (line detail for an outgoing document).
// 9 fields: pozitie;denArticol;cantitate;denUM;camp4;pretVanzare;codArticol;nrDoc;camp8
type infoIesiriExtJSON struct {
	Pozitie     string `json:"pozitie"`
	DenArticol  string `json:"denArticol"`
	Cantitate   string `json:"cantitate"`
	DenUM       string `json:"denUM"`
	Camp4       string `json:"camp4,omitempty"`
	PretVanzare string `json:"pretVanzare"`
	CodArticol  string `json:"codArticol"`
	NrDoc       string `json:"nrDoc"`
	Camp8       string `json:"camp8,omitempty"`
}

func (s *Server) handleGetInfoIesiriExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	nrDoc := queryParamInt(r, "nrDoc", 0)
	zi := queryParamInt(r, "zi", 1)
	rows, err := s.wm.GetInfoIesiriExt(nrDoc, zi)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]infoIesiriExtJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 9)
		items = append(items, infoIesiriExtJSON{
			Pozitie:     f[0],
			DenArticol:  f[1],
			Cantitate:   f[2],
			DenUM:       f[3],
			Camp4:       f[4],
			PretVanzare: f[5],
			CodArticol:  f[6],
			NrDoc:       f[7],
			Camp8:       f[8],
		})
	}
	Success(w, items)
}

func (s *Server) handleSetReceivingList(w http.ResponseWriter, r *http.Request) {
	s.variantArrayHandler(w, r, "SetReceivingList")
}

func (s *Server) handleGetReceivingStatus(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	partID := queryParam(r, "partId", "")
	serieDoc := queryParam(r, "serieDoc", "")
	nrDoc := queryParamInt(r, "nrDoc", 0)
	rows, err := s.wm.RawQuery("GetReceivingStatus", partID, serieDoc, nrDoc)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// inventoryOrderJSON — GetInventoryOrders (inventory order list per warehouse).
// 2 fields: nrInventar;dataInventar
type inventoryOrderJSON struct {
	NrInventar   string `json:"nrInventar"`
	DataInventar string `json:"dataInventar"`
}

func (s *Server) handleGetInventoryOrders(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	gestID := queryParam(r, "gestiune", "")
	rows, err := s.wm.GetInventoryOrders(gestID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]inventoryOrderJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 2)
		items = append(items, inventoryOrderJSON{
			NrInventar:   f[0],
			DataInventar: f[1],
		})
	}
	Success(w, items)
}

func (s *Server) handleSetInventoryOrders(w http.ResponseWriter, r *http.Request) {
	s.variantArrayHandler(w, r, "SetInventoryOrders")
}

// dispozitiiLivrareJSON — GetDispozitiiDeLivrare (delivery dispositions per warehouse).
// 6 fields: serieNrDoc;idPartener;denPartener;codArticol;cantitate;denUM
type dispozitiiLivrareJSON struct {
	SerieNrDoc  string `json:"serieNrDoc"`
	IDPartener  string `json:"idPartener"`
	DenPartener string `json:"denPartener"`
	CodArticol  string `json:"codArticol"`
	Cantitate   string `json:"cantitate"`
	DenUM       string `json:"denUM"`
}

func (s *Server) handleGetDispozitiiDeLivrare(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	gestID := queryParam(r, "gestiune", "")
	rows, err := s.wm.GetDispozitiiDeLivrare(gestID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]dispozitiiLivrareJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 6)
		items = append(items, dispozitiiLivrareJSON{
			SerieNrDoc:  f[0],
			IDPartener:  f[1],
			DenPartener: f[2],
			CodArticol:  f[3],
			Cantitate:   f[4],
			DenUM:       f[5],
		})
	}
	Success(w, items)
}

func (s *Server) handleSetPickedList(w http.ResponseWriter, r *http.Request) {
	s.variantArrayHandler(w, r, "SetPickedList")
}

// deliveryOrderJSON — GetDeliveryOrders.
// 17 fields: nrComanda;idPartener;denPartener;dataComanda;dataLivrare;codArticol;cantitate;denUM;pretVanzare;stare;pozitieFact;serieDocument;sediuPartener;camp13;observatii;camp15;camp16
type deliveryOrderJSON struct {
	NrComanda      string `json:"nrComanda"`
	IDPartener     string `json:"idPartener"`
	DenPartener    string `json:"denPartener"`
	DataComanda    string `json:"dataComanda"`
	DataLivrare    string `json:"dataLivrare"`
	CodArticol     string `json:"codArticol"`
	Cantitate      string `json:"cantitate"`
	DenUM          string `json:"denUM"`
	PretVanzare    string `json:"pretVanzare"`
	Stare          string `json:"stare"`
	PozitieFact    string `json:"pozitieFact"`
	SerieDocument  string `json:"serieDocument"`
	SediuPartener  string `json:"sediuPartener"`
	PozitieComanda string `json:"pozitieComanda"`
	Observatii     string `json:"observatii"`
	CodTransport   string `json:"codTransport"`
	Camp16         string `json:"camp16,omitempty"`
}

func (s *Server) handleGetDeliveryOrders(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetDeliveryOrders()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]deliveryOrderJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 17)
		items = append(items, deliveryOrderJSON{
			NrComanda:      f[0],
			IDPartener:     f[1],
			DenPartener:    f[2],
			DataComanda:    f[3],
			DataLivrare:    f[4],
			CodArticol:     f[5],
			Cantitate:      f[6],
			DenUM:          f[7],
			PretVanzare:    f[8],
			Stare:          f[9],
			PozitieFact:    f[10],
			SerieDocument:  f[11],
			SediuPartener:  f[12],
			PozitieComanda: f[13],
			Observatii:     f[14],
			CodTransport:   f[15],
			Camp16:         f[16],
		})
	}
	Success(w, items)
}

func (s *Server) handleSetDeliveryList(w http.ResponseWriter, r *http.Request) {
	s.variantArrayHandler(w, r, "SetDeliveryList")
}

// handleGetReceptiiSubunitati — GetReceptiiIntrSubunit (structure unknown, always empty).
func (s *Server) handleGetReceptiiSubunitati(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetReceptiiIntrSubunit")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// tranzactieInCursJSON — GetTranzactiiInCurs.
// 13 fields: idPartener;tipDoc;nrDoc;dataDoc;sumaDoc;tipFactura;nrFactura;dataFactura;sumaFactura;dataScadenta;camp10;sediuPartener;dataIntrod
type tranzactieInCursJSON struct {
	IDPartener    string `json:"idPartener"`
	TipDoc        string `json:"tipDoc"`
	NrDoc         string `json:"nrDoc"`
	DataDoc       string `json:"dataDoc"`
	SumaDoc       string `json:"sumaDoc"`
	TipFactura    string `json:"tipFactura"`
	NrFactura     string `json:"nrFactura"`
	DataFactura   string `json:"dataFactura"`
	SumaFactura   string `json:"sumaFactura"`
	DataScadenta  string `json:"dataScadenta"`
	Camp10        string `json:"camp10,omitempty"`
	SediuPartener string `json:"sediuPartener"`
	DataIntrod    string `json:"dataIntroducere"`
}

func (s *Server) handleGetTranzactiiInCurs(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetTranzactiiInCurs()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]tranzactieInCursJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 13)
		items = append(items, tranzactieInCursJSON{
			IDPartener:    f[0],
			TipDoc:        f[1],
			NrDoc:         f[2],
			DataDoc:       f[3],
			SumaDoc:       f[4],
			TipFactura:    f[5],
			NrFactura:     f[6],
			DataFactura:   f[7],
			SumaFactura:   f[8],
			DataScadenta:  f[9],
			Camp10:        f[10],
			SediuPartener: f[11],
			DataIntrod:    f[12],
		})
	}
	Success(w, items)
}

// SetDescarcareAutomata — void method
func (s *Server) handleSetDescarcareAutomata(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	flag := queryParamInt(r, "flag", 1)
	if err := s.wm.SetDescarcareAutomata(flag); err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "SetDescarcareAutomata", "flag": flag, "applied": true})
}

// splitF splits a semicolon-separated record into exactly n fields (padding with empty strings).
func splitF(record string, n int) []string {
	parts := strings.Split(record, ";")
	if len(parts) >= n {
		return parts[:n]
	}
	// Pad with empty strings
	for len(parts) < n {
		parts = append(parts, "")
	}
	return parts
}
