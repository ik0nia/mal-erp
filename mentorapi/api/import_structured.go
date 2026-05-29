package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
)

// ═══════════════════════════════════════════════════════════════════════════════
// Shared types
// ═══════════════════════════════════════════════════════════════════════════════

type infoPachet struct {
	AnLucru  int    `json:"anLucru"`
	LunaLucru int   `json:"lunaLucru"`
	Logon    string `json:"logon,omitempty"`
}

func (ip infoPachet) logon() string {
	if ip.Logon != "" {
		return ip.Logon
	}
	return "Master"
}

// ═══════════════════════════════════════════════════════════════════════════════
// 1. COMENZI FURNIZORI
// ═══════════════════════════════════════════════════════════════════════════════

type comenziFurnizoriRequest struct {
	infoPachet
	Comenzi []comandaFurnizorInput `json:"comenzi"`
}

type comandaFurnizorInput struct {
	NrDoc        string `json:"nrDoc"`
	SimbolCarnet string `json:"simbolCarnet,omitempty"`
	Operatie     string `json:"operatie,omitempty"`
	Data         string `json:"data"`
	DataLivrare  string `json:"dataLivrare"`
	CodFurnizor  string `json:"codFurnizor"`
	Locatie      string `json:"locatie,omitempty"`
	Moneda       string `json:"moneda,omitempty"`
	Observatii   string `json:"observatii,omitempty"`
	Scadenta     string `json:"scadenta,omitempty"`
	Agent        string `json:"agent,omitempty"`
	Items        []comandaFurnizorItem `json:"items"`
}

type comandaFurnizorItem struct {
	CodArticol string `json:"codArticol"`
	UM         string `json:"um"`
	Cantitate  string `json:"cantitate"`
	Pret       string `json:"pret"`
	Discount   string `json:"discount,omitempty"`
	Data       string `json:"data,omitempty"`
}

func (r *comenziFurnizoriRequest) toLines() []string {
	lines := []string{
		"[InfoPachet]",
		fmt.Sprintf("AnLucru=%d", r.AnLucru),
		fmt.Sprintf("LunaLucru=%d", r.LunaLucru),
		"Tipdocument=COMANDA FURNIZOR",
		fmt.Sprintf("TotalComenzi=%d", len(r.Comenzi)),
		fmt.Sprintf("Logon=%s", r.logon()),
		"",
	}
	for i, cmd := range r.Comenzi {
		n := i + 1
		op := cmd.Operatie
		if op == "" {
			op = "A"
		}
		moneda := cmd.Moneda
		if moneda == "" {
			moneda = "LEI"
		}
		lines = append(lines,
			fmt.Sprintf("[Comanda_%d]", n),
			fmt.Sprintf("NrDoc=%s", cmd.NrDoc),
			fmt.Sprintf("SimbolCarnet=%s", cmd.SimbolCarnet),
			fmt.Sprintf("Operatie=%s", op),
			fmt.Sprintf("Data=%s", cmd.Data),
			fmt.Sprintf("DataLivrare=%s", cmd.DataLivrare),
			fmt.Sprintf("CodFurnizor=%s", cmd.CodFurnizor),
			fmt.Sprintf("Locatie=%s", cmd.Locatie),
			fmt.Sprintf("Moneda=%s", moneda),
			fmt.Sprintf("TotalArticole=%d", len(cmd.Items)),
			fmt.Sprintf("Observatii=%s", cmd.Observatii),
		)
		if cmd.Scadenta != "" {
			lines = append(lines, fmt.Sprintf("Scadenta=%s", cmd.Scadenta))
		}
		if cmd.Agent != "" {
			lines = append(lines, fmt.Sprintf("Agent=%s", cmd.Agent))
		}
		lines = append(lines, "", fmt.Sprintf("[Items_%d]", n))
		for j, item := range cmd.Items {
			lines = append(lines, fmt.Sprintf("Item_%d=%s;%s;%s;%s;%s;%s;",
				j+1, item.CodArticol, item.UM, item.Cantitate, item.Pret, item.Discount, item.Data))
		}
	}
	return lines
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2. FACTURI INTRARE
// ═══════════════════════════════════════════════════════════════════════════════

type facturiIntrareRequest struct {
	infoPachet
	Facturi []facturaIntrareInput `json:"facturi"`
}

type facturaIntrareInput struct {
	NrDoc       string `json:"nrDoc"`
	SerieDoc    string `json:"serieDoc,omitempty"`
	Operatie    string `json:"operatie,omitempty"`
	Data        string `json:"data"`
	DataNir     string `json:"dataNir"`
	NrNir       string `json:"nrNir"`
	CodFurnizor string `json:"codFurnizor"`
	Moneda      string `json:"moneda,omitempty"`
	Observatii  string `json:"observatii,omitempty"`
	Scadenta    string `json:"scadenta,omitempty"`
	Agent       string `json:"agent,omitempty"`
	Items       []facturaIntrareItem `json:"items"`
}

type facturaIntrareItem struct {
	CodArticol string `json:"codArticol"`
	UM         string `json:"um"`
	Cantitate  string `json:"cantitate"`
	Pret       string `json:"pret"`
	Gestiune   string `json:"gestiune"`
	Data       string `json:"data,omitempty"`
}

func (r *facturiIntrareRequest) toLines() []string {
	lines := []string{
		"[InfoPachet]",
		fmt.Sprintf("AnLucru=%d", r.AnLucru),
		fmt.Sprintf("LunaLucru=%d", r.LunaLucru),
		"Tipdocument=FACTURA INTRARE",
		fmt.Sprintf("TotalFacturi=%d", len(r.Facturi)),
		fmt.Sprintf("Logon=%s", r.logon()),
		"",
	}
	for i, f := range r.Facturi {
		n := i + 1
		op := f.Operatie
		if op == "" {
			op = "A"
		}
		moneda := f.Moneda
		if moneda == "" {
			moneda = "LEI"
		}
		lines = append(lines,
			fmt.Sprintf("[Factura_%d]", n),
			fmt.Sprintf("Operatie=%s", op),
			fmt.Sprintf("NrDoc=%s", f.NrDoc),
			fmt.Sprintf("SerieDoc=%s", f.SerieDoc),
			fmt.Sprintf("Data=%s", f.Data),
			fmt.Sprintf("DataNir=%s", f.DataNir),
			fmt.Sprintf("NrNir=%s", f.NrNir),
			fmt.Sprintf("CodFurnizor=%s", f.CodFurnizor),
			fmt.Sprintf("Moneda=%s", moneda),
			fmt.Sprintf("TotalArticole=%d", len(f.Items)),
			fmt.Sprintf("Observatii=%s", f.Observatii),
		)
		if f.Scadenta != "" {
			lines = append(lines, fmt.Sprintf("Scadenta=%s", f.Scadenta))
		}
		if f.Agent != "" {
			lines = append(lines, fmt.Sprintf("Agent=%s", f.Agent))
		}
		lines = append(lines, "", fmt.Sprintf("[Items_%d]", n))
		for j, item := range f.Items {
			lines = append(lines, fmt.Sprintf("Item_%d=%s;%s;%s;%s;%s;%s;",
				j+1, item.CodArticol, item.UM, item.Cantitate, item.Pret, item.Gestiune, item.Data))
		}
	}
	return lines
}

// ═══════════════════════════════════════════════════════════════════════════════
// 3. FACTURI IESIRE
// ═══════════════════════════════════════════════════════════════════════════════

type facturiIesireRequest struct {
	infoPachet
	Facturi []facturaIesireInput `json:"facturi"`
}

type facturaIesireInput struct {
	NrDoc        string `json:"nrDoc"`
	SimbolCarnet string `json:"simbolCarnet"`
	Operatie     string `json:"operatie,omitempty"`
	Data         string `json:"data"`
	CodClient    string `json:"codClient"`
	Moneda       string `json:"moneda,omitempty"`
	Observatii   string `json:"observatii,omitempty"`
	Scadenta     string `json:"scadenta,omitempty"`
	TVAInclus    string `json:"tvaInclus,omitempty"`
	Agent        string `json:"agent,omitempty"`
	Locatie      string `json:"locatie,omitempty"`
	Items        []facturaIesireItem `json:"items"`
}

type facturaIesireItem struct {
	CodArticol string `json:"codArticol"`
	UM         string `json:"um"`
	Cantitate  string `json:"cantitate"`
	Pret       string `json:"pret"`
	Gestiune   string `json:"gestiune"`
}

func (r *facturiIesireRequest) toLines() []string {
	lines := []string{
		"[InfoPachet]",
		fmt.Sprintf("AnLucru=%d", r.AnLucru),
		fmt.Sprintf("LunaLucru=%d", r.LunaLucru),
		"Tipdocument=FACTURA IESIRE",
		fmt.Sprintf("TotalFacturi=%d", len(r.Facturi)),
		fmt.Sprintf("Logon=%s", r.logon()),
		"",
	}
	for i, f := range r.Facturi {
		n := i + 1
		op := f.Operatie
		if op == "" {
			op = "A"
		}
		moneda := f.Moneda
		if moneda == "" {
			moneda = "LEI"
		}
		lines = append(lines,
			fmt.Sprintf("[Factura_%d]", n),
			fmt.Sprintf("Operatie=%s", op),
			fmt.Sprintf("NrDoc=%s", f.NrDoc),
			fmt.Sprintf("SimbolCarnet=%s", f.SimbolCarnet),
			fmt.Sprintf("Data=%s", f.Data),
			fmt.Sprintf("CodClient=%s", f.CodClient),
			fmt.Sprintf("Moneda=%s", moneda),
			fmt.Sprintf("TotalArticole=%d", len(f.Items)),
			fmt.Sprintf("Observatii=%s", f.Observatii),
		)
		if f.Scadenta != "" {
			lines = append(lines, fmt.Sprintf("Scadenta=%s", f.Scadenta))
		}
		if f.TVAInclus != "" {
			lines = append(lines, fmt.Sprintf("TVAInclus=%s", f.TVAInclus))
		}
		if f.Agent != "" {
			lines = append(lines, fmt.Sprintf("Agent=%s", f.Agent))
		}
		if f.Locatie != "" {
			lines = append(lines, fmt.Sprintf("Locatie=%s", f.Locatie))
		}
		lines = append(lines, "", fmt.Sprintf("[Items_%d]", n))
		for j, item := range f.Items {
			lines = append(lines, fmt.Sprintf("Item_%d=%s;%s;%s;%s;%s;",
				j+1, item.CodArticol, item.UM, item.Cantitate, item.Pret, item.Gestiune))
		}
	}
	return lines
}

// ═══════════════════════════════════════════════════════════════════════════════
// 4. COMENZI (client)
// ═══════════════════════════════════════════════════════════════════════════════

type comenziRequest struct {
	infoPachet
	Comenzi []comandaInput `json:"comenzi"`
}

type comandaInput struct {
	NrDoc        string `json:"nrDoc"`
	SimbolCarnet string `json:"simbolCarnet,omitempty"`
	Operatie     string `json:"operatie,omitempty"`
	Data         string `json:"data"`
	CodClient    string `json:"codClient"`
	Moneda       string `json:"moneda,omitempty"`
	Observatii   string `json:"observatii,omitempty"`
	Scadenta     string `json:"scadenta,omitempty"`
	Agent        string `json:"agent,omitempty"`
	Items        []comandaItem `json:"items"`
}

type comandaItem struct {
	CodArticol string `json:"codArticol"`
	UM         string `json:"um"`
	Cantitate  string `json:"cantitate"`
	Pret       string `json:"pret"`
	Discount   string `json:"discount,omitempty"`
	Data       string `json:"data,omitempty"`
}

func (r *comenziRequest) toLines() []string {
	lines := []string{
		"[InfoPachet]",
		fmt.Sprintf("AnLucru=%d", r.AnLucru),
		fmt.Sprintf("LunaLucru=%d", r.LunaLucru),
		"Tipdocument=COMANDA",
		fmt.Sprintf("TotalComenzi=%d", len(r.Comenzi)),
		fmt.Sprintf("Logon=%s", r.logon()),
		"",
	}
	for i, cmd := range r.Comenzi {
		n := i + 1
		op := cmd.Operatie
		if op == "" {
			op = "A"
		}
		moneda := cmd.Moneda
		if moneda == "" {
			moneda = "LEI"
		}
		lines = append(lines,
			fmt.Sprintf("[Comanda_%d]", n),
			fmt.Sprintf("NrDoc=%s", cmd.NrDoc),
			fmt.Sprintf("SimbolCarnet=%s", cmd.SimbolCarnet),
			fmt.Sprintf("Operatie=%s", op),
			fmt.Sprintf("Data=%s", cmd.Data),
			fmt.Sprintf("CodClient=%s", cmd.CodClient),
			fmt.Sprintf("Moneda=%s", moneda),
			fmt.Sprintf("TotalArticole=%d", len(cmd.Items)),
			fmt.Sprintf("Observatii=%s", cmd.Observatii),
		)
		if cmd.Scadenta != "" {
			lines = append(lines, fmt.Sprintf("Scadenta=%s", cmd.Scadenta))
		}
		if cmd.Agent != "" {
			lines = append(lines, fmt.Sprintf("Agent=%s", cmd.Agent))
		}
		lines = append(lines, "", fmt.Sprintf("[Items_%d]", n))
		for j, item := range cmd.Items {
			lines = append(lines, fmt.Sprintf("Item_%d=%s;%s;%s;%s;%s;%s;",
				j+1, item.CodArticol, item.UM, item.Cantitate, item.Pret, item.Discount, item.Data))
		}
	}
	return lines
}

// ═══════════════════════════════════════════════════════════════════════════════
// 5. TRANSFERURI
// ═══════════════════════════════════════════════════════════════════════════════

type transferuriRequest struct {
	infoPachet
	Transferuri []transferInput `json:"transferuri"`
}

type transferInput struct {
	NrDoc   string `json:"nrDoc"`
	Operatie string `json:"operatie,omitempty"`
	Data    string `json:"data"`
	GestDest string `json:"gestDest"`
	Agent   string `json:"agent,omitempty"`
	Items   []transferItem `json:"items"`
}

type transferItem struct {
	CodArticol string `json:"codArticol"`
	UM         string `json:"um"`
	Cantitate  string `json:"cantitate"`
	Pret       string `json:"pret"`
	GestSursa  string `json:"gestSursa"`
	Data       string `json:"data,omitempty"`
}

func (r *transferuriRequest) toLines() []string {
	lines := []string{
		"[InfoPachet]",
		fmt.Sprintf("AnLucru=%d", r.AnLucru),
		fmt.Sprintf("LunaLucru=%d", r.LunaLucru),
		"Tipdocument=TRANSFER",
		fmt.Sprintf("TotalTransferuri=%d", len(r.Transferuri)),
		fmt.Sprintf("Logon=%s", r.logon()),
		"",
	}
	for i, t := range r.Transferuri {
		n := i + 1
		op := t.Operatie
		if op == "" {
			op = "A"
		}
		lines = append(lines,
			fmt.Sprintf("[Transfer_%d]", n),
			fmt.Sprintf("Operatie=%s", op),
			fmt.Sprintf("NrDoc=%s", t.NrDoc),
			fmt.Sprintf("Data=%s", t.Data),
			fmt.Sprintf("GestDest=%s", t.GestDest),
			fmt.Sprintf("TotalArticole=%d", len(t.Items)),
		)
		if t.Agent != "" {
			lines = append(lines, fmt.Sprintf("Agent=%s", t.Agent))
		}
		lines = append(lines, "", fmt.Sprintf("[Items_%d]", n))
		for j, item := range t.Items {
			lines = append(lines, fmt.Sprintf("Item_%d=%s;%s;%s;%s;%s;%s;",
				j+1, item.CodArticol, item.UM, item.Cantitate, item.Pret, item.GestSursa, item.Data))
		}
	}
	return lines
}

// ═══════════════════════════════════════════════════════════════════════════════
// 6. BONURI CONSUM
// ═══════════════════════════════════════════════════════════════════════════════

type bonuriConsumRequest struct {
	infoPachet
	Bonuri []bonConsumInput `json:"bonuri"`
}

type bonConsumInput struct {
	NrDoc      string `json:"nrDoc"`
	Operatie   string `json:"operatie,omitempty"`
	Data       string `json:"data"`
	GestConsum string `json:"gestConsum"`
	Items      []bonConsumItem `json:"items"`
}

type bonConsumItem struct {
	CodArticol string `json:"codArticol"`
	UM         string `json:"um"`
	Cantitate  string `json:"cantitate"`
	Pret       string `json:"pret"`
	GestSursa  string `json:"gestSursa"`
	Data       string `json:"data,omitempty"`
}

func (r *bonuriConsumRequest) toLines() []string {
	total := len(r.Bonuri)
	lines := []string{
		"[InfoPachet]",
		fmt.Sprintf("AnLucru=%d", r.AnLucru),
		fmt.Sprintf("LunaLucru=%d", r.LunaLucru),
		"Tipdocument=BON DE CONSUM",
		fmt.Sprintf("TotalDocumente=%d", total),
		fmt.Sprintf("TotalBonuri=%d", total),
		fmt.Sprintf("Logon=%s", r.logon()),
		"",
	}
	for i, b := range r.Bonuri {
		n := i + 1
		op := b.Operatie
		if op == "" {
			op = "A"
		}
		lines = append(lines,
			fmt.Sprintf("[Bon_%d]", n),
			fmt.Sprintf("Operatie=%s", op),
			fmt.Sprintf("NrDoc=%s", b.NrDoc),
			fmt.Sprintf("Data=%s", b.Data),
			fmt.Sprintf("GestConsum=%s", b.GestConsum),
			fmt.Sprintf("TotalArticole=%d", len(b.Items)),
			"",
			fmt.Sprintf("[Items_%d]", n),
		)
		for j, item := range b.Items {
			lines = append(lines, fmt.Sprintf("Item_%d=%s;%s;%s;%s;%s;%s;",
				j+1, item.CodArticol, item.UM, item.Cantitate, item.Pret, item.GestSursa, item.Data))
		}
	}
	return lines
}

// ═══════════════════════════════════════════════════════════════════════════════
// 7. MODIFICARI PRET
// ═══════════════════════════════════════════════════════════════════════════════

type modificariPretRequest struct {
	infoPachet
	ProceseVerbale []procesVerbalInput `json:"proceseVerbale"`
}

type procesVerbalInput struct {
	NrDoc    string `json:"nrDoc"`
	Operatie string `json:"operatie,omitempty"`
	Data     string `json:"data"`
	Items    []modificarePretItem `json:"items"`
}

type modificarePretItem struct {
	CodArticol string `json:"codArticol"`
	UM         string `json:"um"`
	Cantitate  string `json:"cantitate"`
	PretNou    string `json:"pretNou"`
	Gestiune   string `json:"gestiune"`
}

func (r *modificariPretRequest) toLines() []string {
	lines := []string{
		"[InfoPachet]",
		fmt.Sprintf("AnLucru=%d", r.AnLucru),
		fmt.Sprintf("LunaLucru=%d", r.LunaLucru),
		"Tipdocument=MODIFICARE PRET",
		fmt.Sprintf("TotalModifPret=%d", len(r.ProceseVerbale)),
		fmt.Sprintf("Logon=%s", r.logon()),
		"",
	}
	for i, pv := range r.ProceseVerbale {
		n := i + 1
		op := pv.Operatie
		if op == "" {
			op = "A"
		}
		lines = append(lines,
			fmt.Sprintf("[PV_%d]", n),
			fmt.Sprintf("Operatie=%s", op),
			fmt.Sprintf("NrDoc=%s", pv.NrDoc),
			fmt.Sprintf("Data=%s", pv.Data),
			fmt.Sprintf("TotalArticole=%d", len(pv.Items)),
			"",
			fmt.Sprintf("[Items_%d]", n),
		)
		for j, item := range pv.Items {
			lines = append(lines, fmt.Sprintf("Item_%d=%s;%s;%s;%s;%s;",
				j+1, item.CodArticol, item.UM, item.Cantitate, item.PretNou, item.Gestiune))
		}
	}
	return lines
}

// ═══════════════════════════════════════════════════════════════════════════════
// 8. REGLARE INVENTAR
// ═══════════════════════════════════════════════════════════════════════════════

type reglareInventarRequest struct {
	infoPachet
	Reglari []reglareInput `json:"reglari"`
}

type reglareInput struct {
	NrDoc    string `json:"nrDoc"`
	Operatie string `json:"operatie,omitempty"`
	Data     string `json:"data"`
	Gestiune string `json:"gestiune"`
	Items    []reglareItem `json:"items"`
}

type reglareItem struct {
	CodArticol string `json:"codArticol"`
	UM         string `json:"um"`
	Cantitate  string `json:"cantitate"`
	Pret       string `json:"pret"`
}

func (r *reglareInventarRequest) toLines() []string {
	lines := []string{
		"[InfoPachet]",
		fmt.Sprintf("AnLucru=%d", r.AnLucru),
		fmt.Sprintf("LunaLucru=%d", r.LunaLucru),
		"Tipdocument=REGLARE INVENTAR",
		fmt.Sprintf("TotalReglari=%d", len(r.Reglari)),
		fmt.Sprintf("Logon=%s", r.logon()),
		"",
	}
	for i, reg := range r.Reglari {
		n := i + 1
		op := reg.Operatie
		if op == "" {
			op = "A"
		}
		lines = append(lines,
			fmt.Sprintf("[Reglare_%d]", n),
			fmt.Sprintf("Operatie=%s", op),
			fmt.Sprintf("NrDoc=%s", reg.NrDoc),
			fmt.Sprintf("Data=%s", reg.Data),
			fmt.Sprintf("Gestiune=%s", reg.Gestiune),
			fmt.Sprintf("TotalArticole=%d", len(reg.Items)),
			"",
			fmt.Sprintf("[Items_%d]", n),
		)
		for j, item := range reg.Items {
			lines = append(lines, fmt.Sprintf("Item_%d=%s;%s;%s;%s;",
				j+1, item.CodArticol, item.UM, item.Cantitate, item.Pret))
		}
	}
	return lines
}

// ═══════════════════════════════════════════════════════════════════════════════
// Generic structured handler — decodes JSON, builds INI, calls validate/import
// ═══════════════════════════════════════════════════════════════════════════════

type linesBuilder interface {
	toLines() []string
}

func (s *Server) handleStructuredImport(w http.ResponseWriter, r *http.Request, docType string, req linesBuilder, validateOnly bool) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	lines := req.toLines()

	validateMethod, importMethod := getDocTypeMethods(docType)
	if validateMethod == "" {
		ErrorBadRequest(w, "Unknown document type: "+docType)
		return
	}

	// SetDocsData
	if err := s.wm.SetDocsData(lines); err != nil {
		ErrorInternal(w, err)
		return
	}

	// Validate
	isValid, err := s.callValidate(validateMethod)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	if !isValid {
		errors := s.getComErrors()
		// Include generated lines in error response for debugging
		Error(w, http.StatusBadRequest, errors...)
		return
	}

	if validateOnly {
		Success(w, map[string]interface{}{
			"isValid": true,
			"docType": docType,
			"lines":   lines,
		})
		return
	}

	// Import
	count, err := s.callImport(importMethod)
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	errors := s.getComErrors()
	// Split errors into warnings (non-error info from WinMentor) and actual errors
	var warnings []string
	if len(errors) > 0 {
		warnings = errors
	}

	Success(w, map[string]interface{}{
		"isValid":       true,
		"importedCount": count,
		"docType":       docType,
		"warnings":      warnings,
	})
}

// ═══════════════════════════════════════════════════════════════════════════════
// HTTP Handlers — one pair (validate + import) per document type
// ═══════════════════════════════════════════════════════════════════════════════

func decodeJSON[T any](w http.ResponseWriter, r *http.Request) (*T, bool) {
	var req T
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON: "+err.Error())
		return nil, false
	}
	return &req, true
}

// --- Comenzi Furnizori ---
func (s *Server) handleStructuredValidateComenziFurn(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[comenziFurnizoriRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "comenzi-furnizori", req, true)
}
func (s *Server) handleStructuredImportComenziFurn(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[comenziFurnizoriRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "comenzi-furnizori", req, false)
}

// --- Facturi Intrare ---
func (s *Server) handleStructuredValidateFacturiIntrare(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[facturiIntrareRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "facturi-intrare", req, true)
}
func (s *Server) handleStructuredImportFacturiIntrare(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[facturiIntrareRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "facturi-intrare", req, false)
}

// --- Facturi Iesire ---
func (s *Server) handleStructuredValidateFacturiIesire(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[facturiIesireRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "facturi-iesire", req, true)
}
func (s *Server) handleStructuredImportFacturiIesire(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[facturiIesireRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "facturi-iesire", req, false)
}

// --- Comenzi (client) ---
func (s *Server) handleStructuredValidateComenzi(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[comenziRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "comenzi", req, true)
}
func (s *Server) handleStructuredImportComenzi(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[comenziRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "comenzi", req, false)
}

// --- Transferuri ---
func (s *Server) handleStructuredValidateTransferuri(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[transferuriRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "transferuri", req, true)
}
func (s *Server) handleStructuredImportTransferuri(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[transferuriRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "transferuri", req, false)
}

// --- Bonuri Consum ---
func (s *Server) handleStructuredValidateBonuriConsum(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[bonuriConsumRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "bonuri-consum", req, true)
}
func (s *Server) handleStructuredImportBonuriConsum(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[bonuriConsumRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "bonuri-consum", req, false)
}

// --- Modificari Pret ---
func (s *Server) handleStructuredValidateModificariPret(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[modificariPretRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "modificari-pret", req, true)
}
func (s *Server) handleStructuredImportModificariPret(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[modificariPretRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "modificari-pret", req, false)
}

// --- Reglare Inventar ---
func (s *Server) handleStructuredValidateReglareInventar(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[reglareInventarRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "reglare-inventar", req, true)
}
func (s *Server) handleStructuredImportReglareInventar(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeJSON[reglareInventarRequest](w, r)
	if !ok {
		return
	}
	s.handleStructuredImport(w, r, "reglare-inventar", req, false)
}

// ═══════════════════════════════════════════════════════════════════════════════
// Preview — returns generated INI lines without calling WinMentor (for debugging)
// ═══════════════════════════════════════════════════════════════════════════════

func (s *Server) handlePreviewLines(w http.ResponseWriter, r *http.Request) {
	docType := r.PathValue("docType")

	var builder linesBuilder
	var err error

	switch docType {
	case "comenzi-furnizori":
		var req comenziFurnizoriRequest
		err = json.NewDecoder(r.Body).Decode(&req)
		builder = &req
	case "facturi-intrare":
		var req facturiIntrareRequest
		err = json.NewDecoder(r.Body).Decode(&req)
		builder = &req
	case "facturi-iesire":
		var req facturiIesireRequest
		err = json.NewDecoder(r.Body).Decode(&req)
		builder = &req
	case "comenzi":
		var req comenziRequest
		err = json.NewDecoder(r.Body).Decode(&req)
		builder = &req
	case "transferuri":
		var req transferuriRequest
		err = json.NewDecoder(r.Body).Decode(&req)
		builder = &req
	case "bonuri-consum":
		var req bonuriConsumRequest
		err = json.NewDecoder(r.Body).Decode(&req)
		builder = &req
	case "modificari-pret":
		var req modificariPretRequest
		err = json.NewDecoder(r.Body).Decode(&req)
		builder = &req
	case "reglare-inventar":
		var req reglareInventarRequest
		err = json.NewDecoder(r.Body).Decode(&req)
		builder = &req
	default:
		ErrorBadRequest(w, "Unknown document type: "+docType)
		return
	}

	if err != nil {
		ErrorBadRequest(w, "Invalid JSON: "+err.Error())
		return
	}

	lines := builder.toLines()
	Success(w, map[string]interface{}{
		"docType": docType,
		"lines":   lines,
		"preview": strings.Join(lines, "\n"),
	})
}
