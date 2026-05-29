//go:build windows

package main

import (
	"fmt"
	"log"
	"net/http"
	"time"

	"golang.org/x/sys/windows/svc"

	"mentorapi/api"
	"mentorapi/config"
)

type mentorService struct {
	cfg *config.Config
}

func (m *mentorService) Execute(args []string, r <-chan svc.ChangeRequest, changes chan<- svc.Status) (bool, uint32) {
	changes <- svc.Status{State: svc.StartPending}

	server := api.NewServer(m.cfg.MentorAPI)
	handler := server.SetupRoutes()

	addr := fmt.Sprintf("%s:%d", m.cfg.MentorAPI.BindAddress, m.cfg.MentorAPI.Port)

	srv := &http.Server{
		Addr:           addr,
		Handler:        handler,
		ReadTimeout:    30 * time.Second,
		WriteTimeout:   60 * time.Second,
		IdleTimeout:    120 * time.Second,
		MaxHeaderBytes: 1 << 20,
	}

	go func() {
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Printf("Server error: %v", err)
		}
	}()

	changes <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}
	log.Printf("MentorAPI service running on %s", addr)

	for c := range r {
		switch c.Cmd {
		case svc.Stop, svc.Shutdown:
			changes <- svc.Status{State: svc.StopPending}
			srv.Close()
			server.Close()
			return false, 0
		case svc.Interrogate:
			changes <- c.CurrentStatus
		}
	}

	return false, 0
}

func runAsService(cfg *config.Config) error {
	return svc.Run("MentorAPI", &mentorService{cfg: cfg})
}

func isRunningAsService() bool {
	inService, err := svc.IsWindowsService()
	if err != nil {
		return false
	}
	return inService
}
