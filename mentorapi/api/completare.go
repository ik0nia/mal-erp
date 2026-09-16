package api

import (
	"net/http"
	"strconv"
)

// completare.go — endpoint-uri adăugate 2026-09-15 pentru acoperirea completă a
// type library-ului DocImpServer (semnături extrase din DocImpServer.tlb oficial).
// Cele 6 metode de mai jos existau în DLL dar nu erau expuse prin API.

// handleGetInfoBonConsum returns the lines of a consumption note (bon de consum).
// COM: GetInfoBonConsum(Numar: Integer; Serie: WideString; out Error: Integer): OleVariant
// GET /api/documente/bon-consum?numar=123&serie=ABC
func (s *Server) handleGetInfoBonConsum(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	numar := queryParamInt(r, "numar", 0)
	if numar == 0 {
		ErrorBadRequest(w, "numar is required")
		return
	}
	serie := queryParam(r, "serie", "")
	rows, err := s.wm.GetInfoBonConsum(numar, serie)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, rows)
}

// handleGetPretVanzareBrut returns the gross (pre-discount) selling price for an article/partner.
// COM: GetPretVanzareBrut(ArtID: WideString; PartID: WideString; out Error: Integer): OleVariant
// GET /api/preturi/vanzare-brut?articol=ART&partener=PART
func (s *Server) handleGetPretVanzareBrut(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	artID := queryParam(r, "articol", "")
	partID := queryParam(r, "partener", "")
	if artID == "" {
		ErrorBadRequest(w, "articol is required")
		return
	}
	rows, err := s.wm.GetPretVanzareBrut(artID, partID)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, rows)
}

// handleExistaMonetarul checks whether a cash-register report (monetar) exists.
// COM: ExistaMonetarul(Numar: Integer; Serie: WideString): Integer
// GET /api/documente/monetar/exista?numar=12&serie=ABC
func (s *Server) handleExistaMonetarul(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	numar := queryParamInt(r, "numar", 0)
	if numar == 0 {
		ErrorBadRequest(w, "numar is required")
		return
	}
	serie := queryParam(r, "serie", "")
	code, err := s.wm.ExistaMonetarul(numar, serie)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"exista": code != 0, "cod": code})
}

// handleGetValoriAtribut returns the possible values of a product attribute by its code.
// COM: GetValoriAtribut(CodAtribut: Integer; out Error: Integer): OleVariant
// GET /api/atribute/{cod}/valori
func (s *Server) handleGetValoriAtribut(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	cod := queryParamInt(r, "cod", 0)
	if cod == 0 {
		// also accept path form /api/atribute/{cod}/valori
		if p := r.PathValue("cod"); p != "" {
			cod, _ = strconv.Atoi(p)
		}
	}
	if cod == 0 {
		ErrorBadRequest(w, "cod is required")
		return
	}
	rows, err := s.wm.GetValoriAtribut(cod)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, rows)
}

// handleGetListaFirmeExt returns the extended company list.
// COM: GetListaFirmeExt(): OleVariant
// GET /api/firme/ext
func (s *Server) handleGetListaFirmeExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetListaFirmeExt()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, rows)
}
