package api

import (
	"encoding/json"
	"net/http"
)

type importDocRequest struct {
	Lines []string `json:"lines"`
}

// handleImportGeneric handles POST /api/import/{docType}.
func (s *Server) handleImportGeneric(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	docType := r.PathValue("docType")
	validateOnly := queryParamBool(r, "validateOnly")

	var req importDocRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON body")
		return
	}
	if len(req.Lines) == 0 {
		ErrorBadRequest(w, "lines array is required")
		return
	}

	validateMethod, importMethod := getDocTypeMethods(docType)
	if validateMethod == "" {
		ErrorBadRequest(w, "Unknown document type: "+docType)
		return
	}

	// Step 1: SetDocsData
	if err := s.wm.SetDocsData(req.Lines); err != nil {
		ErrorInternal(w, err)
		return
	}

	// Step 2: Validate
	isValid, err := s.callValidate(validateMethod)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	if !isValid {
		errors := s.getComErrors()
		Error(w, http.StatusBadRequest, errors...)
		return
	}

	if validateOnly {
		Success(w, map[string]interface{}{"isValid": true, "docType": docType})
		return
	}

	// Step 3: Import
	count, err := s.callImport(importMethod)
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	errors := s.getComErrors()
	Success(w, map[string]interface{}{
		"isValid":       true,
		"importedCount": count,
		"docType":       docType,
		"errors":        errors,
	})
}

func (s *Server) callValidate(method string) (bool, error) {
	// Validate methods return Integer: 1=valid, 0=invalid
	// Must use CallMethodInt (vtable) — not RawQuery (IDispatch fails on DocImpServer)
	result, err := s.wm.CallMethodIntExported(method)
	if err != nil {
		return false, err
	}
	return result == 1, nil
}

func (s *Server) callImport(method string) (int, error) {
	// Import methods return Integer: count of imported documents
	result, err := s.wm.CallMethodIntExported(method)
	if err != nil {
		return 0, err
	}
	return result, nil
}

func getDocTypeMethods(docType string) (string, string) {
	switch docType {
	case "facturi-iesire":
		return "DateValide", "ImportaFacturi"
	case "facturi-intrare":
		return "FactIntrareValida", "ImportaFactIntrare"
	case "comenzi":
		return "ComenziValide", "ImportaComenzi"
	case "comenzi-ext":
		return "ComenziValideExt", "ImportaComenziExt"
	case "comenzi-furnizori":
		return "ComenziFurnValide", "ImportaComenziFurn"
	case "bonuri-consum":
		return "BonuriConsumValide", "ImportaBonuriConsum"
	case "transferuri":
		return "TransferuriValide", "ImportaTransferuri"
	case "monetare":
		return "MonetareValide", "ImportaMonetare"
	case "incasari":
		return "IncasariValideExt", "ImportaIncasariExt"
	case "plati":
		return "PlatiValideExt", "ImportaPlatiExt"
	case "note-contabile":
		return "NCValide", "ImportaNoteContabile"
	case "modificari-pret":
		return "ModifPretValide", "ImportaModifPret"
	case "incasari-ext":
		return "IncasariValideExt", "ImportaIncasariExt"
	case "plati-ext":
		return "PlatiValideExt", "ImportaPlatiExt"
	case "reglare-inventar":
		return "ReglareInventarValida", "ImportaReglareInventar"
	default:
		return "", ""
	}
}

// Specific handlers that delegate to handleImportGeneric-like logic
func (s *Server) handleImportWithType(w http.ResponseWriter, r *http.Request, docType string, validateOnly bool) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var req importDocRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON body")
		return
	}
	if len(req.Lines) == 0 {
		ErrorBadRequest(w, "lines array is required")
		return
	}

	validateMethod, importMethod := getDocTypeMethods(docType)

	if err := s.wm.SetDocsData(req.Lines); err != nil {
		ErrorInternal(w, err)
		return
	}

	isValid, err := s.callValidate(validateMethod)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	if !isValid {
		errors := s.getComErrors()
		Error(w, http.StatusBadRequest, errors...)
		return
	}

	if validateOnly {
		Success(w, map[string]interface{}{"isValid": true, "docType": docType})
		return
	}

	count, err := s.callImport(importMethod)
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	errors := s.getComErrors()
	Success(w, map[string]interface{}{
		"isValid": true, "importedCount": count, "docType": docType, "errors": errors,
	})
}

func (s *Server) handleValidateFacturiIesire(w http.ResponseWriter, r *http.Request)  { s.handleImportWithType(w, r, "facturi-iesire", true) }
func (s *Server) handleImportFacturiIesire(w http.ResponseWriter, r *http.Request)     { s.handleImportWithType(w, r, "facturi-iesire", false) }
func (s *Server) handleValidateFacturiIntrare(w http.ResponseWriter, r *http.Request)  { s.handleImportWithType(w, r, "facturi-intrare", true) }
func (s *Server) handleImportFacturiIntrare(w http.ResponseWriter, r *http.Request)     { s.handleImportWithType(w, r, "facturi-intrare", false) }
func (s *Server) handleValidateComenzi(w http.ResponseWriter, r *http.Request)          { s.handleImportWithType(w, r, "comenzi", true) }
func (s *Server) handleImportComenzi(w http.ResponseWriter, r *http.Request)            { s.handleImportWithType(w, r, "comenzi", false) }
func (s *Server) handleValidateComenziExt(w http.ResponseWriter, r *http.Request)       { s.handleImportWithType(w, r, "comenzi-ext", true) }
func (s *Server) handleImportComenziExt(w http.ResponseWriter, r *http.Request)         { s.handleImportWithType(w, r, "comenzi-ext", false) }
func (s *Server) handleValidateComenziFurn(w http.ResponseWriter, r *http.Request)      { s.handleImportWithType(w, r, "comenzi-furnizori", true) }
func (s *Server) handleImportComenziFurn(w http.ResponseWriter, r *http.Request)        { s.handleImportWithType(w, r, "comenzi-furnizori", false) }
func (s *Server) handleValidateBonuriConsum(w http.ResponseWriter, r *http.Request)     { s.handleImportWithType(w, r, "bonuri-consum", true) }
func (s *Server) handleImportBonuriConsum(w http.ResponseWriter, r *http.Request)       { s.handleImportWithType(w, r, "bonuri-consum", false) }
func (s *Server) handleValidateTransferuri(w http.ResponseWriter, r *http.Request)      { s.handleImportWithType(w, r, "transferuri", true) }
func (s *Server) handleImportTransferuri(w http.ResponseWriter, r *http.Request)        { s.handleImportWithType(w, r, "transferuri", false) }
func (s *Server) handleValidateMonetare(w http.ResponseWriter, r *http.Request)         { s.handleImportWithType(w, r, "monetare", true) }
func (s *Server) handleImportMonetare(w http.ResponseWriter, r *http.Request)           { s.handleImportWithType(w, r, "monetare", false) }
func (s *Server) handleValidateIncasari(w http.ResponseWriter, r *http.Request)         { s.handleImportWithType(w, r, "incasari", true) }
func (s *Server) handleImportIncasari(w http.ResponseWriter, r *http.Request)           { s.handleImportWithType(w, r, "incasari", false) }
func (s *Server) handleValidatePlati(w http.ResponseWriter, r *http.Request)            { s.handleImportWithType(w, r, "plati", true) }
func (s *Server) handleImportPlati(w http.ResponseWriter, r *http.Request)              { s.handleImportWithType(w, r, "plati", false) }
func (s *Server) handleValidateNoteContabile(w http.ResponseWriter, r *http.Request)    { s.handleImportWithType(w, r, "note-contabile", true) }
func (s *Server) handleImportNoteContabile(w http.ResponseWriter, r *http.Request)      { s.handleImportWithType(w, r, "note-contabile", false) }
func (s *Server) handleValidateModificariPret(w http.ResponseWriter, r *http.Request)   { s.handleImportWithType(w, r, "modificari-pret", true) }
func (s *Server) handleImportModificariPret(w http.ResponseWriter, r *http.Request)     { s.handleImportWithType(w, r, "modificari-pret", false) }
func (s *Server) handleValidateIncasariExt(w http.ResponseWriter, r *http.Request)      { s.handleImportWithType(w, r, "incasari-ext", true) }
func (s *Server) handleImportIncasariExt(w http.ResponseWriter, r *http.Request)        { s.handleImportWithType(w, r, "incasari-ext", false) }
func (s *Server) handleValidatePlatiExt(w http.ResponseWriter, r *http.Request)         { s.handleImportWithType(w, r, "plati-ext", true) }
func (s *Server) handleImportPlatiExt(w http.ResponseWriter, r *http.Request)           { s.handleImportWithType(w, r, "plati-ext", false) }
func (s *Server) handleValidateReglareInventar(w http.ResponseWriter, r *http.Request)  { s.handleImportWithType(w, r, "reglare-inventar", true) }
func (s *Server) handleImportReglareInventar(w http.ResponseWriter, r *http.Request)    { s.handleImportWithType(w, r, "reglare-inventar", false) }
