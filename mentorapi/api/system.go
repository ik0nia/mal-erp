package api

import (
	"encoding/json"
	"log"
	"net/http"
	"runtime"
	"time"
)

// handleHealth returns service status (no auth required).
func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	Success(w, map[string]interface{}{
		"status":       "running",
		"comConnected": s.connected,
		"uptime":       time.Since(s.startTime).String(),
		"version":      Version,
		"goVersion":    runtime.Version(),
		"platform":     runtime.GOOS + "/" + runtime.GOARCH,
	})
}

// handleDiagnostics returns COM diagnostics (no auth required).
func (s *Server) handleDiagnostics(w http.ResponseWriter, r *http.Request) {
	vtMethods := map[string]int{}
	if s.wm != nil {
		vtMethods = s.wm.VTableMethods()
	}
	Success(w, map[string]interface{}{
		"comProgId":      s.cfg.ComProgId,
		"comConnected":   s.connected,
		"comIdleTimeout": s.cfg.ComIdleTimeoutSeconds,
		"cacheMinutes":   s.cfg.CacheDurationMinutes,
		"port":           s.cfg.Port,
		"vtableMethods":  vtMethods,
	})
}

// handleGetErori calls GetListaErori().
func (s *Server) handleGetErori(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	errs, err := s.wm.GetListaErori()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, errs)
}

// handleGetVersiuni calls GetVersiuni.
func (s *Server) handleGetVersiuni(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	resultCode, verMentor, verServer, err := s.wm.GetVersiuni()
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	Success(w, map[string]interface{}{
		"resultCode": resultCode,
		"verMentor":  verMentor,
		"verServer":  verServer,
	})
}

// handleGetConstanta calls GetStringConstanta(Id, Simbol).
func (s *Server) handleGetConstanta(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	id := queryParamInt(r, "id", 0)
	simbol := queryParam(r, "simbol", "")

	result, err := s.wm.GetStringConstanta(id, simbol)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, result)
}

// handlePotIntroduceDoc calls PotIntroduceDoc.
func (s *Server) handlePotIntroduceDoc(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	an := queryParamInt(r, "an", 2026)
	luna := queryParamInt(r, "luna", 5)
	result, err := s.wm.PotIntroduceDoc(an, luna)
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	Success(w, map[string]interface{}{
		"canIntroduce": result == 1,
		"result":       result,
	})
}

// handleCheckDocument calls CheckDocument.
func (s *Server) handleCheckDocument(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	tipDoc := queryParam(r, "tipDoc", "")
	prefixDoc := queryParam(r, "prefixDoc", "")
	nrDoc := queryParamInt(r, "nrDoc", 0)
	result, err := s.wm.CheckDocument(tipDoc, prefixDoc, nrDoc)
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	Success(w, map[string]interface{}{
		"result": result,
	})
}

// handleLogOn calls LogOn(user, password).
func (s *Server) handleLogOn(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var req struct {
		User     string `json:"user"`
		Password string `json:"password"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON body")
		return
	}
	if req.User == "" {
		ErrorBadRequest(w, "user is required")
		return
	}

	rows, err := s.wm.RawQuery("LogOn", req.User, req.Password)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": "LogOn", "result": rows})
}

// handleComReset closes the current COM connection and forces a fresh reconnect.
func (s *Server) handleComReset(w http.ResponseWriter, r *http.Request) {
	log.Println("[COM] Reset requested via API")

	s.comMu.Lock()
	if s.wm != nil {
		s.wm.Close()
		s.wm = nil
		s.connected = false
		log.Println("[COM] Old connection closed")
	}
	s.comMu.Unlock()

	if err := s.EnsureConnected(); err != nil {
		Error(w, http.StatusInternalServerError, "COM reconnect failed: "+err.Error())
		return
	}

	log.Println("[COM] Reconnected successfully")
	Success(w, map[string]interface{}{
		"reset":        true,
		"comConnected": s.connected,
	})
}
