package api

import (
	"crypto/subtle"
	"log"
	"net/http"
	"time"
)

// AuthMiddleware checks the API key on all routes except health/diagnostics.
func AuthMiddleware(apiKey string, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Public endpoints — no auth required
		path := r.URL.Path
		if path == "/api/health" || path == "/api/diagnostics" ||
			path == "/" || path == "/docs" || path == "/api/docs" || path == "/api/openapi.json" {
			next.ServeHTTP(w, r)
			return
		}

		// Check API key from header or query
		key := r.Header.Get("X-API-Key")
		if key == "" {
			key = r.URL.Query().Get("api_key")
		}

		if len(key) == 0 || subtle.ConstantTimeCompare([]byte(key), []byte(apiKey)) != 1 {
			ErrorUnauthorized(w)
			return
		}

		next.ServeHTTP(w, r)
	})
}

// LoggingMiddleware logs each request.
func LoggingMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		sw := &statusWriter{ResponseWriter: w, status: 200}

		next.ServeHTTP(sw, r)

		log.Printf("[%s] %s %s %d %v",
			r.Method,
			r.URL.Path,
			r.RemoteAddr,
			sw.status,
			time.Since(start),
		)
	})
}

// RecoveryMiddleware recovers from panics and returns 500.
func RecoveryMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if err := recover(); err != nil {
				log.Printf("[PANIC] %s %s: %v", r.Method, r.URL.Path, err)
				Error(w, http.StatusInternalServerError, "Internal server error")
			}
		}()
		next.ServeHTTP(w, r)
	})
}

// BodyLimitMiddleware limits request body to 10MB.
func BodyLimitMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		r.Body = http.MaxBytesReader(w, r.Body, 10*1024*1024) // 10 MB
		next.ServeHTTP(w, r)
	})
}

// CORSMiddleware adds CORS headers.
func CORSMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, DELETE, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type, X-API-Key")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		next.ServeHTTP(w, r)
	})
}

type statusWriter struct {
	http.ResponseWriter
	status int
}

func (w *statusWriter) WriteHeader(status int) {
	w.status = status
	w.ResponseWriter.WriteHeader(status)
}
