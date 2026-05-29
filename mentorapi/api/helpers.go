package api

import (
	"encoding/json"
	"net/http"
	"strconv"
)

// parsePageParams extracts page and pageSize from query string.
func parsePageParams(r *http.Request, defaultPageSize, maxPageSize int) (int, int) {
	page, _ := strconv.Atoi(r.URL.Query().Get("page"))
	if page < 1 {
		page = 1
	}

	pageSize, _ := strconv.Atoi(r.URL.Query().Get("pageSize"))
	if pageSize < 1 {
		pageSize = defaultPageSize
	}
	if pageSize > maxPageSize {
		pageSize = maxPageSize
	}

	return page, pageSize
}

// parseJSONBody decodes a JSON request body into the given struct.
func parseJSONBody(r *http.Request, v interface{}) error {
	defer r.Body.Close()
	return json.NewDecoder(r.Body).Decode(v)
}

// queryParam returns a query parameter with a default value.
func queryParam(r *http.Request, key, defaultVal string) string {
	v := r.URL.Query().Get(key)
	if v == "" {
		return defaultVal
	}
	return v
}

// queryParamInt returns an integer query parameter with a default value.
func queryParamInt(r *http.Request, key string, defaultVal int) int {
	v := r.URL.Query().Get(key)
	if v == "" {
		return defaultVal
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return defaultVal
	}
	return n
}

// queryParamBool returns a boolean query parameter.
func queryParamBool(r *http.Request, key string) bool {
	v := r.URL.Query().Get(key)
	return v == "true" || v == "1" || v == "yes"
}
