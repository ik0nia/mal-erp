package api

import (
	"log"
	"strconv"
	"sync"
	"time"

	"mentorapi/cache"
	"mentorapi/config"

	"github.com/rayone121/libWMEdcom/winmentor"
)

const Version = "1.3.0"

// Server holds all dependencies for the HTTP API.
type Server struct {
	cfg       config.MentorAPIConfig
	wm        *winmentor.Client
	cache     *cache.Cache
	startTime time.Time
	connected bool
	comMu     sync.Mutex // protects wm + connected
}

func NewServer(cfg config.MentorAPIConfig) *Server {
	return &Server{
		cfg:       cfg,
		cache:     cache.New(cfg.CacheDurationMinutes),
		startTime: time.Now(),
	}
}

// EnsureConnected lazily connects to DocImpServer on first use.
// Uses a mutex so only one goroutine attempts the (slow) COM connection.
func (s *Server) EnsureConnected() error {
	s.comMu.Lock()
	defer s.comMu.Unlock()

	if s.connected && s.wm != nil {
		return nil
	}

	log.Println("[COM] Connecting to DocImpServer...")
	client, err := winmentor.NewClient()
	if err != nil {
		return err
	}

	s.wm = client
	s.connected = true
	log.Println("[COM] Connected successfully")
	return nil
}

func (s *Server) Close() {
	s.comMu.Lock()
	defer s.comMu.Unlock()
	s.cache.Stop()
	if s.wm != nil {
		s.wm.Close()
		s.wm = nil
		s.connected = false
	}
}

// getComErrors retrieves the error buffer from COM.
func (s *Server) getComErrors() []string {
	if s.wm == nil {
		return []string{"not connected"}
	}
	errs, err := s.wm.GetListaErori()
	if err != nil {
		return []string{err.Error()}
	}
	return errs
}

func mustAtoi(str string) int {
	n, _ := strconv.Atoi(str)
	return n
}
