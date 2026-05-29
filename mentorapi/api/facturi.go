package api

import "net/http"

func (s *Server) handleExistaFactura(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	nr := mustAtoi(r.PathValue("nr"))
	result, err := s.wm.ExistaFactura(nr)
	if err != nil {
		ErrorInternal(w, err)
		return
	}

	status := "unknown"
	switch result {
	case -1:
		status = "error"
	case 0:
		status = "not_found"
	case 1:
		status = "posted"
	case 2:
		status = "unposted"
	}

	Success(w, map[string]interface{}{
		"numar": nr, "exists": result > 0, "status": status, "code": result,
	})
}

func (s *Server) handleExistaFacturaExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	numar := mustAtoi(r.PathValue("nr"))
	serie := r.PathValue("prefix")
	result, err := s.wm.ExistaFacturaExt(numar, serie)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{
		"numar": numar, "serie": serie, "exists": result > 0, "code": result,
	})
}

func (s *Server) handleExistaFacturaIntrare(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	partID := queryParam(r, "partId", "")
	serie := queryParam(r, "serie", "")
	numar := queryParamInt(r, "numar", 0)
	result, err := s.wm.ExistaFacturaIntrare(partID, serie, numar)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{
		"partId": partID, "serie": serie, "numar": numar, "exists": result > 0, "code": result,
	})
}

func (s *Server) handleGetNumarFactura(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	carnet := r.PathValue("carnet")
	result, err := s.wm.GetNumarFactura(carnet)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"carnet": carnet, "numar": result})
}

// carnetJSON is the JSON for document books.
type carnetJSON struct {
	Simbol      string `json:"simbol"`
	TipDocument string `json:"tipDocument"`
	Denumire    string `json:"denumire"`
}

// handleGetCarnete returns structured JSON for document books.
func (s *Server) handleGetCarnete(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	carnete, err := s.wm.GetListaCarnete()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]carnetJSON, len(carnete))
	for i, c := range carnete {
		items[i] = carnetJSON{
			Simbol:      c.Simbol,
			TipDocument: c.TipDocument,
			Denumire:    c.Denumire,
		}
	}
	Success(w, items)
}

// handleGetCarneteExt — GetListaCarneteExt (always empty in observed data).
func (s *Server) handleGetCarneteExt(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetListaCarneteExt")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, splitRows(rows))
}

// intrareJSON is the JSON representation for incoming entries.
type intrareJSON struct {
	IDPartener string `json:"idPartener"`
	Data       string `json:"data"`
	NrDoc      string `json:"nrDoc"`
	CodArticol string `json:"codArticol"`
	Cant       string `json:"cant"`
	DenUM      string `json:"denUM"`
	Pret       string `json:"pret"`
	DenGest    string `json:"denGest"`
	Camp8      string `json:"camp8,omitempty"`
	Flag       string `json:"flag"`
	Camp10     string `json:"camp10,omitempty"`
}

// handleGetIntrari returns structured JSON for incoming entries.
func (s *Server) handleGetIntrari(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	intrari, err := s.wm.GetIntrari()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]intrareJSON, len(intrari))
	for i, intr := range intrari {
		items[i] = intrareJSON{
			IDPartener: intr.IDPartener,
			Data:       intr.Data,
			NrDoc:      intr.NrDoc,
			CodArticol: intr.CodArticol,
			Cant:       intr.Cant,
			DenUM:      intr.DenUM,
			Pret:       intr.Pret,
			DenGest:    intr.DenGest,
			Camp8:      intr.Unknown8,
			Flag:       intr.Flag,
			Camp10:     intr.Unknown10,
		}
	}
	Success(w, items)
}

// receptieJSON — GetReceptii.
// 22 fields: denGestiune;simbolGestiune;nrNIR;dataNIR;denFurnizor;idFurnizor;nrFactura;dataFactura;
//   codArticol;denArticol;cont;denUM;cantitate;cantReceptionata;pretAchizitie;pretVanzare;cotaTVA;
//   nrDoc;camp18;camp19;idIntern;camp21
type receptieJSON struct {
	DenGestiune      string `json:"denGestiune"`
	SimbolGestiune   string `json:"simbolGestiune"`
	NrNIR            string `json:"nrNIR"`
	DataNIR          string `json:"dataNIR"`
	DenFurnizor      string `json:"denFurnizor"`
	IDFurnizor       string `json:"idFurnizor"`
	NrFactura        string `json:"nrFactura"`
	DataFactura      string `json:"dataFactura"`
	CodArticol       string `json:"codArticol"`
	DenArticol       string `json:"denArticol"`
	Cont             string `json:"cont"`
	DenUM            string `json:"denUM"`
	Cantitate        string `json:"cantitate"`
	CantReceptionata string `json:"cantReceptionata"`
	PretAchizitie    string `json:"pretAchizitie"`
	PretVanzare      string `json:"pretVanzare"`
	CotaTVA          string `json:"cotaTVA"`
	NrDoc            string `json:"nrDoc"`
	Operat           string `json:"operat"`
	OperatFact       string `json:"operatFact"`
	IDIntern         string `json:"idIntern"`
	Camp21           string `json:"camp21,omitempty"`
}

func (s *Server) handleGetReceptii(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetReceptii()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]receptieJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 22)
		items = append(items, receptieJSON{
			DenGestiune:      f[0],
			SimbolGestiune:   f[1],
			NrNIR:            f[2],
			DataNIR:          f[3],
			DenFurnizor:      f[4],
			IDFurnizor:       f[5],
			NrFactura:        f[6],
			DataFactura:      f[7],
			CodArticol:       f[8],
			DenArticol:       f[9],
			Cont:             f[10],
			DenUM:            f[11],
			Cantitate:        f[12],
			CantReceptionata: f[13],
			PretAchizitie:    f[14],
			PretVanzare:      f[15],
			CotaTVA:          f[16],
			NrDoc:            f[17],
			Operat:           f[18],
			OperatFact:       f[19],
			IDIntern:         f[20],
			Camp21:           f[21],
		})
	}
	Success(w, items)
}

// handleGetReceptiiNeoperate — GetReceptiiNeoperate (same structure as GetReceptii, 22 fields).
func (s *Server) handleGetReceptiiNeoperate(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.RawQuery("GetReceptiiNeoperate")
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]receptieJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 22)
		items = append(items, receptieJSON{
			DenGestiune:      f[0],
			SimbolGestiune:   f[1],
			NrNIR:            f[2],
			DataNIR:          f[3],
			DenFurnizor:      f[4],
			IDFurnizor:       f[5],
			NrFactura:        f[6],
			DataFactura:      f[7],
			CodArticol:       f[8],
			DenArticol:       f[9],
			Cont:             f[10],
			DenUM:            f[11],
			Cantitate:        f[12],
			CantReceptionata: f[13],
			PretAchizitie:    f[14],
			PretVanzare:      f[15],
			CotaTVA:          f[16],
			NrDoc:            f[17],
			Operat:           f[18],
			OperatFact:       f[19],
			IDIntern:         f[20],
			Camp21:           f[21],
		})
	}
	Success(w, items)
}

// nirAtributJSON — GetNirAtribute.
// 5 fields: data;simbolGestiune;codArticol;camp3;cantitate
type nirAtributJSON struct {
	Data           string `json:"data"`
	SimbolGestiune string `json:"simbolGestiune"`
	CodArticol     string `json:"codArticol"`
	Atribut        string `json:"atribut"`
	Cantitate      string `json:"cantitate"`
}

func (s *Server) handleGetNirAtribute(w http.ResponseWriter, r *http.Request) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	rows, err := s.wm.GetNirAtribute()
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	items := make([]nirAtributJSON, 0, len(rows))
	for _, row := range rows {
		f := splitF(row, 5)
		items = append(items, nirAtributJSON{
			Data:           f[0],
			SimbolGestiune: f[1],
			CodArticol:     f[2],
			Atribut:        f[3],
			Cantitate:      f[4],
		})
	}
	Success(w, items)
}
