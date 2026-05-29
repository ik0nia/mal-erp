package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
)

// ═══════════════════════════════════════════════════════════════════════════════
// AddProduct — JSON structurat → string cu 19 câmpuri ;
// ═══════════════════════════════════════════════════════════════════════════════

type addProductRequest struct {
	CodExtern           string `json:"codExtern"`           // 1  EAN/SKU — OBLIGATORIU
	Denumire            string `json:"denumire"`            // 2  Denumire produs — OBLIGATORIU
	UM                  string `json:"um"`                  // 3  Unitate de masura (Buc, Kg, ML)
	CotaTVA             string `json:"cotaTVA"`             // 4  Cota TVA (19, 9, 5, 0)
	TipSerie            string `json:"tipSerie"`            // 5  Tip serie (gol de obicei)
	CodExternProducator string `json:"codExternProducator"` // 6  Cod extern producator
	DenProducator       string `json:"denProducator"`       // 7  Denumire producator
	PretVanzare         string `json:"pretVanzare"`         // 8  Pret vanzare fara TVA
	PretMinim           string `json:"pretMinim"`           // 9  Pret minim
	CantImplicita       string `json:"cantImplicita"`       // 10 Cantitate implicita
	PretValutaRef       string `json:"pretValutaRef"`       // 11 Pret valuta referinta
	SimbolClasa         string `json:"simbolClasa"`         // 12 Simbol clasa articol
	UMSecundara         string `json:"umSecundara"`         // 13 UM secundara
	ParitateUM2         string `json:"paritateUM2"`         // 14 Paritate UM secundara
	Masa                string `json:"masa"`                // 15 Masa (kg)
	FlagServiciu        string `json:"flagServiciu"`        // 16 0=marfa, 1=serviciu
	CodVamal            string `json:"codVamal"`            // 17 Cod vamal
	Flag                string `json:"flag"`                // 18 Flag (gol de obicei)
	CodAlternativ       string `json:"codAlternativ"`       // 19 Cod alternativ
}

func (r *addProductRequest) toInfo() string {
	fields := []string{
		r.CodExtern, r.Denumire, r.UM, r.CotaTVA, r.TipSerie,
		r.CodExternProducator, r.DenProducator, r.PretVanzare, r.PretMinim,
		r.CantImplicita, r.PretValutaRef, r.SimbolClasa, r.UMSecundara,
		r.ParitateUM2, r.Masa, r.FlagServiciu, r.CodVamal, r.Flag, r.CodAlternativ,
	}
	return strings.Join(fields, ";")
}

func (s *Server) handleStructuredAddProduct(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var req addProductRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON: "+err.Error())
		return
	}
	if req.CodExtern == "" || req.Denumire == "" {
		ErrorBadRequest(w, "codExtern și denumire sunt obligatorii")
		return
	}

	info := req.toInfo()

	result, err := s.wm.AddProduct(info)
	if err != nil {
		errors := s.getComErrors()
		Error(w, http.StatusBadRequest, append([]string{err.Error()}, errors...)...)
		return
	}

	s.cache.InvalidatePrefix("articole")
	Success(w, map[string]interface{}{
		"result":  result,
		"info":    info,
		"message": "Produs adăugat",
	})
}

// ═══════════════════════════════════════════════════════════════════════════════
// ModiProduct — JSON structurat → string cu 7 câmpuri ;
// ═══════════════════════════════════════════════════════════════════════════════

type modiProductRequest struct {
	CodExtern     string `json:"codExtern"`     // 1  Identificator produs (EAN/SKU sau CodIntern — depinde de SetIDArtField)
	Denumire      string `json:"denumire"`      // 2  Denumire nouă
	UM            string `json:"um"`            // 3  UM nouă
	CotaTVA       string `json:"cotaTVA"`       // 4  Cota TVA nouă
	PretVanzare   string `json:"pretVanzare"`   // 5  Preț vânzare nou (fără TVA)
	SimbolClasa   string `json:"simbolClasa"`   // 6  Simbol clasă articol
	CodAlternativ string `json:"codAlternativ"` // 7  Cod alternativ
}

func (r *modiProductRequest) toInfo() string {
	fields := []string{
		r.CodExtern, r.Denumire, r.UM, r.CotaTVA,
		r.PretVanzare, r.SimbolClasa, r.CodAlternativ,
	}
	return strings.Join(fields, ";")
}

func (s *Server) handleStructuredModiProduct(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var req modiProductRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON: "+err.Error())
		return
	}
	if req.CodExtern == "" {
		ErrorBadRequest(w, "codExtern este obligatoriu (identificator produs)")
		return
	}

	info := req.toInfo()

	result, err := s.wm.ModiProduct(info)
	if err != nil {
		errors := s.getComErrors()
		Error(w, http.StatusBadRequest, append([]string{err.Error()}, errors...)...)
		return
	}

	s.cache.InvalidatePrefix("articole")
	s.cache.InvalidatePrefix("stocuri")
	Success(w, map[string]interface{}{
		"result":  result,
		"info":    info,
		"message": "Produs modificat",
	})
}

// ═══════════════════════════════════════════════════════════════════════════════
// Preview — returnează string-ul generat fără a apela WinMentor
// ═══════════════════════════════════════════════════════════════════════════════

func (s *Server) handlePreviewAddProduct(w http.ResponseWriter, r *http.Request) {
	var req addProductRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON: "+err.Error())
		return
	}
	info := req.toInfo()
	parts := strings.Split(info, ";")
	labels := []string{"CodExtern", "Denumire", "UM", "CotaTVA", "TipSerie",
		"CodExternProducator", "DenProducator", "PretVanzare", "PretMinim",
		"CantImplicita", "PretValutaRef", "SimbolClasa", "UMSecundara",
		"ParitateUM2", "Masa", "FlagServiciu", "CodVamal", "Flag", "CodAlternativ"}

	mapped := make([]map[string]string, 0, len(parts))
	for i, p := range parts {
		label := fmt.Sprintf("poz%d", i+1)
		if i < len(labels) {
			label = labels[i]
		}
		mapped = append(mapped, map[string]string{"pozitie": fmt.Sprintf("%d", i+1), "camp": label, "valoare": p})
	}

	Success(w, map[string]interface{}{
		"info":   info,
		"fields": mapped,
	})
}

func (s *Server) handlePreviewModiProduct(w http.ResponseWriter, r *http.Request) {
	var req modiProductRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON: "+err.Error())
		return
	}
	info := req.toInfo()
	labels := []string{"CodExtern", "Denumire", "UM", "CotaTVA", "PretVanzare", "SimbolClasa", "CodAlternativ"}
	parts := strings.Split(info, ";")

	mapped := make([]map[string]string, 0)
	for i, p := range parts {
		label := fmt.Sprintf("poz%d", i+1)
		if i < len(labels) {
			label = labels[i]
		}
		mapped = append(mapped, map[string]string{"pozitie": fmt.Sprintf("%d", i+1), "camp": label, "valoare": p})
	}

	Success(w, map[string]interface{}{
		"info":   info,
		"fields": mapped,
	})
}
