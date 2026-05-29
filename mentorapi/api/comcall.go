package api

import (
	"net/http"
)

// handleComCall is the generic COM call endpoint.
// POST /api/com/call { "method": "GetInfoBon", "params": [123] }
func (s *Server) handleComCall(w http.ResponseWriter, r *http.Request) {
	if !s.cfg.AllowGenericCalls {
		Error(w, http.StatusForbidden, "Generic COM calls are disabled")
		return
	}
	s.genericComCall(w, r)
}

// handleComMethods returns the list of known COM methods.
func (s *Server) handleComMethods(w http.ResponseWriter, r *http.Request) {
	// Same as before — static list
	methods := []map[string]string{
		{"name": "GetListaFirme", "params": "none"},
		{"name": "GetListaLuni", "params": "NumeFirma: string"},
		{"name": "SetNumeFirma", "params": "NumeFirma: string"},
		{"name": "SetLunaLucru", "params": "An: int, Luna: int"},
		{"name": "GetListaErori", "params": "none"},
		{"name": "GetVersiuni", "params": "out params"},
		{"name": "GetNomenclatorArticole", "params": "out Error: int"},
		{"name": "GetStocArticole", "params": "out Error: int"},
		{"name": "GetListaParteneri", "params": "out Error: int"},
		{"name": "GetVanzariLuna", "params": "none"},
		{"name": "GetVanzariExt", "params": "out Error: int"},
		{"name": "GetListaGestiuni", "params": "out Error: int"},
		{"name": "GetListaPersonal", "params": "none"},
		{"name": "GetListaBanci", "params": "out Error: int"},
		{"name": "GetOferte", "params": "out Error: int"},
		{"name": "GetSolduri", "params": "out Error: int"},
		{"name": "GetSolduriExt", "params": "out Error: int"},
		{"name": "GetSolduriFurn", "params": "none"},
		{"name": "GetComenziNefacturate", "params": "none"},
		{"name": "GetSuppliersOrders", "params": "undocumented"},
		{"name": "AddProduct", "params": "Info: string"},
		{"name": "ModiProduct", "params": "Info: string"},
		{"name": "AdaugaPartener", "params": "Info: string"},
		{"name": "ModificaPartener", "params": "Info: string"},
	}

	Success(w, map[string]interface{}{
		"totalMethods": len(methods),
		"methods":      methods,
		"note":         "Use POST /api/com/call to test any method",
	})
}
