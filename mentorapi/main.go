package main

import (
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"mentorapi/api"
	"mentorapi/config"
)

func main() {
	// Determine config path (same directory as executable)
	exePath, _ := os.Executable()
	exeDir := filepath.Dir(exePath)
	configPath := filepath.Join(exeDir, "appsettings.json")

	// Allow override via command line
	if len(os.Args) > 1 {
		configPath = os.Args[1]
	}

	// Load config
	cfg, err := config.Load(configPath)
	if err != nil {
		log.Fatalf("Failed to load config: %v", err)
	}

	// If running as Windows Service, delegate to service handler
	if isRunningAsService() {
		log.Printf("Starting MentorAPI v%s as Windows Service", api.Version)
		if err := runAsService(cfg); err != nil {
			log.Fatalf("Service failed: %v", err)
		}
		return
	}

	// Console mode
	fmt.Println("MentorAPI v" + api.Version + " — WinMentor DocImpServer REST Bridge")
	fmt.Println("========================================================")
	fmt.Printf("Config loaded from: %s\n", configPath)
	fmt.Printf("COM ProgID: %s\n", cfg.MentorAPI.ComProgId)
	fmt.Printf("API Key: %s...%s\n", cfg.MentorAPI.ApiKey[:4], cfg.MentorAPI.ApiKey[len(cfg.MentorAPI.ApiKey)-4:])
	fmt.Printf("Generic COM calls: %v\n", cfg.MentorAPI.AllowGenericCalls)

	// Create server
	server := api.NewServer(cfg.MentorAPI)
	defer server.Close()

	handler := server.SetupRoutes()

	addr := fmt.Sprintf("%s:%d", cfg.MentorAPI.BindAddress, cfg.MentorAPI.Port)
	fmt.Printf("\nListening on http://%s\n", addr)
	fmt.Println("Press Ctrl+C to stop")

	// Graceful shutdown
	go func() {
		sigChan := make(chan os.Signal, 1)
		signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
		<-sigChan
		fmt.Println("\nShutting down...")
		server.Close()
		os.Exit(0)
	}()

	srv := &http.Server{
		Addr:           addr,
		Handler:        handler,
		ReadTimeout:    30 * time.Second,
		WriteTimeout:   60 * time.Second,
		IdleTimeout:    120 * time.Second,
		MaxHeaderBytes: 1 << 20,
	}
	if err := srv.ListenAndServe(); err != nil {
		log.Fatalf("Server failed: %v", err)
	}
}
