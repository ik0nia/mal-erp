//go:build !windows

package main

import "mentorapi/config"

func runAsService(cfg *config.Config) error {
	return nil
}

func isRunningAsService() bool {
	return false
}
