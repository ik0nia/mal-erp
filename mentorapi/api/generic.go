package api

import (
	"encoding/json"
	"log"
	"net/http"
	"strings"
)

// rawQueryHandler is a generic handler that calls RawQuery and returns results.
// DEPRECATED: use typed handlers instead for structured JSON output.
func (s *Server) rawQueryHandler(w http.ResponseWriter, r *http.Request, method string, args ...interface{}) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	rows, err := s.wm.RawQuery(method, args...)
	if err != nil {
		log.Printf("[ERROR] %s: %v", method, err)
		ErrorInternal(w, err)
		return
	}
	Success(w, rows)
}

// typedListHandler fetches a typed list, applies search filtering and returns JSON.
// searchFn receives an item and the lowercase search term, returns true if matching.
func typedListHandler[T any](w http.ResponseWriter, items []T, search string, searchFn func(T, string) bool) {
	if search != "" {
		searchLower := strings.ToLower(search)
		filtered := make([]T, 0)
		for _, item := range items {
			if searchFn(item, searchLower) {
				filtered = append(filtered, item)
			}
		}
		items = filtered
	}
	Success(w, items)
}

// typedPaginatedHandler fetches a typed list, applies search, paginates and returns JSON.
func typedPaginatedHandler[T any](w http.ResponseWriter, r *http.Request, defaultPageSize, maxPageSize int, items []T, search string, searchFn func(T, string) bool) {
	page, pageSize := parsePageParams(r, defaultPageSize, maxPageSize)

	if search != "" {
		searchLower := strings.ToLower(search)
		filtered := make([]T, 0)
		for _, item := range items {
			if searchFn(item, searchLower) {
				filtered = append(filtered, item)
			}
		}
		items = filtered
	}

	total := len(items)
	totalPages := 0
	if total > 0 {
		totalPages = (total + pageSize - 1) / pageSize
	}
	start := (page - 1) * pageSize
	if start > total {
		start = total
	}
	end := start + pageSize
	if end > total {
		end = total
	}
	hasNext := end < total

	SuccessPaginated(w, items[start:end], page, pageSize, totalPages, hasNext)
}

// genericComCall handles POST /api/com/call and undocumented methods.
func (s *Server) genericComCall(w http.ResponseWriter, r *http.Request, fixedMethod ...string) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}

	var method string
	var params []interface{}

	if len(fixedMethod) > 0 {
		method = fixedMethod[0]
	} else {
		var req struct {
			Method string        `json:"method"`
			Params []interface{} `json:"params"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			ErrorBadRequest(w, "Invalid JSON body")
			return
		}
		if req.Method == "" {
			ErrorBadRequest(w, "method is required")
			return
		}
		method = req.Method
		params = req.Params
	}

	// Convert float64 params to int where appropriate (JSON numbers)
	for i, p := range params {
		if v, ok := p.(float64); ok && v == float64(int(v)) {
			params[i] = int(v)
		}
	}

	log.Printf("[COM] Generic call: %s (params: %v)", method, params)

	rows, err := s.wm.RawQuery(method, params...)
	if err != nil {
		comErrors := s.getComErrors()
		log.Printf("[ERROR] %s: %v (COM errors: %v)", method, err, comErrors)

		Success(w, map[string]interface{}{
			"method": method,
			"error":  err.Error(),
			"errors": comErrors,
		})
		return
	}

	Success(w, map[string]interface{}{
		"method": method,
		"data":   rows,
		"count":  len(rows),
	})
}

// variantArrayHandler handles POST endpoints that take a JSON body with {"lines": [...]}
// and call a COM method that expects OleVariant (SAFEARRAY of BSTR) + returns Integer.
func (s *Server) variantArrayHandler(w http.ResponseWriter, r *http.Request, method string) {
	if err := s.EnsureConnected(); err != nil {
		ErrorInternal(w, err)
		return
	}
	var req struct {
		Lines []string `json:"lines"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		ErrorBadRequest(w, "Invalid JSON body")
		return
	}
	if len(req.Lines) == 0 {
		ErrorBadRequest(w, "lines array is required")
		return
	}
	result, err := s.wm.CallWithVariantArray(method, req.Lines)
	if err != nil {
		ErrorInternal(w, err)
		return
	}
	Success(w, map[string]interface{}{"method": method, "result": result})
}

// splitRows splits each raw semicolon-separated row into a string array.
// Used for endpoints with unknown field structure.
func splitRows(rows []string) [][]string {
	result := make([][]string, 0, len(rows))
	for _, row := range rows {
		result = append(result, strings.Split(row, ";"))
	}
	return result
}

// paginateAndReturn paginates string slice results with optional search.
func paginateAndReturn(w http.ResponseWriter, rows []string, search string, page, pageSize int) {
	if search != "" {
		searchLower := strings.ToLower(search)
		filtered := make([]string, 0)
		for _, row := range rows {
			if strings.Contains(strings.ToLower(row), searchLower) {
				filtered = append(filtered, row)
			}
		}
		rows = filtered
	}

	total := len(rows)
	totalPages := 0
	if total > 0 {
		totalPages = (total + pageSize - 1) / pageSize
	}
	start := (page - 1) * pageSize
	if start > total {
		start = total
	}
	end := start + pageSize
	if end > total {
		end = total
	}
	hasNext := end < total

	SuccessPaginated(w, rows[start:end], page, pageSize, totalPages, hasNext)
}

