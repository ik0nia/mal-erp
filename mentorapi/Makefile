BINARY_NAME=mentorapi
VERSION=1.0.0

.PHONY: build-windows build-linux clean

# Cross-compile for Windows (from Linux)
build-windows:
	GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o $(BINARY_NAME).exe .

# Build for Linux (testing only, COM won't work)
build-linux:
	go build -o $(BINARY_NAME) .

# Clean build artifacts
clean:
	rm -f $(BINARY_NAME) $(BINARY_NAME).exe

# Run vet and check for issues
vet:
	go vet ./...
