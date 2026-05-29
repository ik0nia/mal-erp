package config

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

type Config struct {
	MentorAPI MentorAPIConfig `json:"MentorAPI"`
}

type MentorAPIConfig struct {
	Port                  int    `json:"Port"`
	HttpsPort             int    `json:"HttpsPort"`
	BindAddress           string `json:"BindAddress"`
	ApiKey                string `json:"ApiKey"`
	ComProgId             string `json:"ComProgId"`
	ComIdleTimeoutSeconds int    `json:"ComIdleTimeoutSeconds"`
	CacheDurationMinutes  int    `json:"CacheDurationMinutes"`
	AllowGenericCalls     bool   `json:"AllowGenericCalls"`
	LogLevel              string `json:"LogLevel"`
	LogFile               string `json:"LogFile"`
	MaxPageSize           int    `json:"MaxPageSize"`
	DefaultPageSize       int    `json:"DefaultPageSize"`
}

func DefaultConfig() *Config {
	return &Config{
		MentorAPI: MentorAPIConfig{
			Port:                  9500,
			HttpsPort:             9501,
			BindAddress:           "0.0.0.0",
			ApiKey:                "",
			ComProgId:             "DocImpServer.DocImpObject",
			ComIdleTimeoutSeconds: 300,
			CacheDurationMinutes:  5,
			AllowGenericCalls:     true,
			LogLevel:              "info",
			LogFile:               "mentorapi.log",
			MaxPageSize:           10000,
			DefaultPageSize:       100,
		},
	}
}

func Load(path string) (*Config, error) {
	cfg := DefaultConfig()

	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			cfg.MentorAPI.ApiKey = generateApiKey()
			if saveErr := Save(cfg, path); saveErr != nil {
				return nil, fmt.Errorf("failed to create default config: %w", saveErr)
			}
			fmt.Printf("Created default config at %s\n", path)
			return cfg, nil
		}
		return nil, fmt.Errorf("failed to read config: %w", err)
	}

	if err := json.Unmarshal(data, cfg); err != nil {
		return nil, fmt.Errorf("failed to parse config: %w", err)
	}

	if cfg.MentorAPI.ApiKey == "" {
		cfg.MentorAPI.ApiKey = generateApiKey()
		if saveErr := Save(cfg, path); saveErr != nil {
			fmt.Printf("Warning: could not save generated API key: %v\n", saveErr)
		}
	}

	return cfg, nil
}

func Save(cfg *Config, path string) error {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0755); err != nil {
		return err
	}

	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}

	return os.WriteFile(path, data, 0644)
}

func generateApiKey() string {
	b := make([]byte, 24)
	if _, err := rand.Read(b); err != nil {
		panic(fmt.Sprintf("failed to generate API key: %v", err))
	}
	return hex.EncodeToString(b)
}
