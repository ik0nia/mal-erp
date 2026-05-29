package api

import (
	"encoding/json"
	"net/http"
)

// handleGetFirme calls GetListaFirme().
func (s *Server) handleGetFirme(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	cached, ok := s.cache.Get("firme")
	if ok && !queryParamBool(r, "refresh") {
		Success(w, cached)
		return
	}

	firme, err := s.wm.GetListaFirme()
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	s.cache.Set("firme", firme)
	Success(w, firme)
}

// handleGetLuni calls GetListaLuni(NumeFirma).
func (s *Server) handleGetLuni(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	name := r.PathValue("name")
	if name == "" {
		ErrorBadRequest(w, "firma name is required")
		return
	}

	luni, err := s.wm.GetListaLuni(name)
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	Success(w, luni)
}

// handleSelectFirma calls SetNumeFirma + SetLunaLucru.
func (s *Server) handleSelectFirma(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var req struct {
		Firma string `json:"firma"`
		An    int    `json:"an"`
		Luna  int    `json:"luna"`
	}

	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON body")
		return
	}

	if req.Firma == "" {
		ErrorBadRequest(w, "firma is required")
		return
	}
	if req.An < 2000 || req.An > 2100 {
		ErrorBadRequest(w, "an must be between 2000 and 2100")
		return
	}
	if req.Luna < 1 || req.Luna > 12 {
		ErrorBadRequest(w, "luna must be between 1 and 12")
		return
	}

	// Step 1: SetNumeFirma
	if err := s.wm.SetNumeFirma(req.Firma); err != nil {
		Error(w, http.StatusBadRequest, err.Error())
		return
	}

	// Step 2: SetLunaLucru
	if err := s.wm.SetLunaLucru(req.An, req.Luna); err != nil {
		Error(w, http.StatusBadRequest, err.Error())
		return
	}

	// Invalidate cache on firma/luna change
	s.cache.Flush()

	Success(w, map[string]interface{}{
		"firma":    req.Firma,
		"an":       req.An,
		"luna":     req.Luna,
		"selected": true,
	})
}
