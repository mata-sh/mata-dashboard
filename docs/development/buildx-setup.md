# Docker Buildx Setup Guide

This guide helps resolve the "buildx isn't installed" warning and optimizes your Docker build setup.

## Quick Fix Options

### Option 1: Use Simplified Compose (Recommended for immediate fix)
```bash
# Use the simplified version without buildx dependencies
docker-compose -f docker-compose.simple.yml up -d
```

### Option 2: Install Docker Buildx

#### **On Linux (Ubuntu/Debian):**
```bash
# Update Docker to latest version (includes buildx)
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Or install buildx plugin manually
mkdir -p ~/.docker/cli-plugins
curl -L "https://github.com/docker/buildx/releases/latest/download/buildx-v0.12.0.linux-amd64" \
  -o ~/.docker/cli-plugins/docker-buildx
chmod +x ~/.docker/cli-plugins/docker-buildx

# Verify installation
docker buildx version
```

#### **On macOS:**
```bash
# Update Docker Desktop (includes buildx)
brew install --cask docker

# Or install via Homebrew
brew install docker-buildx

# Verify installation
docker buildx version
```

#### **On Windows:**
```bash
# Update Docker Desktop (includes buildx)
# Download from: https://www.docker.com/products/docker-desktop

# Or install manually in PowerShell:
# Download buildx-v0.12.0.windows-amd64.exe
# Place in %USERPROFILE%\.docker\cli-plugins\docker-buildx.exe
```

### Option 3: Configure Docker Buildx
```bash
# Create and use a new builder instance
docker buildx create --name mybuilder --use
docker buildx inspect --bootstrap

# List available builders
docker buildx ls

# Set as default
docker buildx use mybuilder
```

## Build Options After Setup

### **Standard Build (without buildx):**
```bash
# Use simple compose file
docker-compose -f docker-compose.simple.yml build
docker-compose -f docker-compose.simple.yml up -d
```

### **With Buildx (multi-platform support):**
```bash
# Build all variants
docker buildx bake

# Build specific target
docker buildx bake web-optimized

# Build and push to registry
docker buildx bake --push
```

### **Manual Builds:**
```bash
# Standard build
docker build -t mata-dashboard .

# Optimized build
docker build -f Dockerfile.optimized -t mata-dashboard:optimized .

# Production build
docker build -f Dockerfile.production -t mata-dashboard:prod .

# Multi-platform build (requires buildx)
docker buildx build --platform linux/amd64,linux/arm64 -t mata-dashboard:latest .
```

## Verification Commands

### **Check Docker Setup:**
```bash
# Check Docker version
docker version

# Check if buildx is available
docker buildx version

# List Docker plugins
docker info | grep -i buildx
```

### **Test Build:**
```bash
# Simple test build
docker build -t test-mata .

# If successful, use your preferred compose file
docker-compose up -d
```

## Environment Variables Setup

Create your `.env` file:
```bash
# Copy template
cp .env.example .env

# Edit with your values
nano .env
```

Example `.env` content:
```env
# Build Configuration
BUILD_DATE=2024-01-01T00:00:00Z
VERSION=latest

# Network
WEB_PORT=3300

# Database
DB_DATABASE=mata_dashboard
DB_USERNAME=mata_user
DB_PASSWORD=your_secure_password_here
MYSQL_ROOT_PASSWORD=your_root_password_here

# Application
APP_ENV=production
DISPLAY_ERRORS=0
LOG_LEVEL=errors
TEMPLATE_DEBUG_ENABLED=false
```

## Troubleshooting

### **"buildx isn't installed" Warning:**
- Use `docker-compose.simple.yml` for immediate solution
- Update Docker to latest version
- Install buildx plugin manually

### **"no builder instances found" Error:**
```bash
docker buildx create --name mybuilder --use
docker buildx inspect --bootstrap
```

### **Multi-platform build fails:**
```bash
# Install emulation support
docker run --privileged --rm tonistiigi/binfmt --install all
```

### **Permission denied:**
```bash
# Add user to docker group (Linux)
sudo usermod -aG docker $USER
newgrp docker
```

## Recommended Approach

1. **For development**: Use `docker-compose.simple.yml`
2. **For production**: Install buildx and use `docker-compose.optimized.yml`
3. **For CI/CD**: Use `docker-bake.hcl` with buildx

This setup ensures maximum compatibility while providing advanced build features when available.