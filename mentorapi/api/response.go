package api

import (
	"encoding/json"
	"log"
	"net/http"
	"time"
)

// Response is the uniform response format for all endpoints.
type Response struct {
	Success   bool        `json:"success"`
	Data      interface{} `json:"data"`
	Errors    []string    `json:"errors"`
	Timestamp string      `json:"timestamp"`
}

// PaginatedData wraps paginated results.
type PaginatedData struct {
	Items       interface{} `json:"items"`
	Page        int         `json:"page"`
	PageSize    int         `json:"pageSize"`
	TotalPages  int         `json:"totalPages"`
	HasNextPage bool        `json:"hasNextPage"`
}

func writeJSON(w http.ResponseWriter, statusCode int, resp Response) {
	resp.Timestamp = time.Now().UTC().Format(time.RFC3339)
	if resp.Errors == nil {
		resp.Errors = []string{}
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(statusCode)
	json.NewEncoder(w).Encode(resp)
}

func Success(w http.ResponseWriter, data interface{}) {
	writeJSON(w, http.StatusOK, Response{
		Success: true,
		Data:    data,
	})
}

func SuccessPaginated(w http.ResponseWriter, items interface{}, page, pageSize, totalPages int, hasNext bool) {
	writeJSON(w, http.StatusOK, Response{
		Success: true,
		Data: PaginatedData{
			Items:       items,
			Page:        page,
			PageSize:    pageSize,
			TotalPages:  totalPages,
			HasNextPage: hasNext,
		},
	})
}

func Error(w http.ResponseWriter, statusCode int, errors ...string) {
	writeJSON(w, statusCode, Response{
		Success: false,
		Data:    nil,
		Errors:  errors,
	})
}

func ErrorInternal(w http.ResponseWriter, err error) {
	log.Printf("[ERROR] %v", err)
	Error(w, http.StatusInternalServerError, err.Error())
}

func ErrorBadRequest(w http.ResponseWriter, msg string) {
	Error(w, http.StatusBadRequest, msg)
}

func ErrorNotFound(w http.ResponseWriter, msg string) {
	Error(w, http.StatusNotFound, msg)
}

func ErrorUnauthorized(w http.ResponseWriter) {
	Error(w, http.StatusUnauthorized, "Invalid or missing API key")
}
