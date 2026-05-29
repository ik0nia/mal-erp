package api

import (
	_ "embed"
	"net/http"
)

//go:embed openapi-spec.json
var openAPISpec []byte

var swaggerHTML = `<!DOCTYPE html><html lang="ro"><head><meta charset="UTF-8"><title>MentorAPI v1.3.0</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css"></head><body><div id="swagger-ui"></div><script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script><script>SwaggerUIBundle({url:'/api/openapi.json',dom_id:'#swagger-ui',presets:[SwaggerUIBundle.presets.apis,SwaggerUIBundle.SwaggerUIStandalonePreset],layout:'BaseLayout',deepLinking:true,defaultModelsExpandDepth:-1});</script></body></html>`

func (s *Server) handleOpenAPISpec(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Write(openAPISpec)
}

func (s *Server) handleSwaggerUI(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Write([]byte(swaggerHTML))
}
