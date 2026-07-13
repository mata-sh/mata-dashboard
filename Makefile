# ============================================
# Colors (using git config for portability)
# ============================================

# Define colors using git's color system
COLOR_RESET := $(shell git config --get-color "" "reset")
COLOR_BOLD := $(shell git config --get-color "" "bold")
COLOR_RED := $(shell git config --get-color "" "red")
COLOR_GREEN := $(shell git config --get-color "" "green")
COLOR_YELLOW := $(shell git config --get-color "" "yellow")
COLOR_BLUE := $(shell git config --get-color "" "blue")
COLOR_CYAN := $(shell git config --get-color "" "cyan")
COLOR_MAGENTA := $(shell git config --get-color "" "magenta")

# Local setup selection. Command-line values override this generated file.
SETUP_CONFIG := .mata.mk
-include $(SETUP_CONFIG)
MODE ?=
ENV ?=

# ============================================
# OPA (Open Policy Agent)
# ============================================

OPA_BASE_URL := https://openpolicyagent.org/downloads/latest
OPA_BIN_DIR := bin
OPA_BIN := $(OPA_BIN_DIR)/opa
OPA_SERVICE_TEMPLATE := scripts/systemd/opa.service

# ============================================
# Help
# ============================================

# Default target - show concise help
.DEFAULT_GOAL := help

help:
	@echo "$(COLOR_BOLD)$(COLOR_CYAN)MATA Dashboard$(COLOR_RESET)"
	@echo "$(COLOR_CYAN)==============$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_BOLD)START HERE$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make setup$(COLOR_RESET)          Guided native or Docker setup"
	@echo "  $(COLOR_GREEN)make doctor$(COLOR_RESET)         Validate the selected installation"
	@echo ""
	@if [ -n "$(MODE)" ]; then \
		echo "$(COLOR_BOLD)CURRENT INSTALLATION$(COLOR_RESET)"; \
		echo "  Mode:        $(MODE)"; \
		echo "  Environment: $(ENV)"; \
		echo ""; \
	else \
		echo "$(COLOR_YELLOW)No installation mode selected. Run: make setup$(COLOR_RESET)"; \
		echo ""; \
	fi
	@echo "$(COLOR_BOLD)COMMON OPERATIONS$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make users-create$(COLOR_RESET)   Create a user"
	@echo "  $(COLOR_GREEN)make users-list$(COLOR_RESET)     List users"
	@echo "  $(COLOR_GREEN)make users-passwd$(COLOR_RESET)   Change a password"
	@echo "  $(COLOR_GREEN)make db-migrate$(COLOR_RESET)     Run database migrations (Docker)"
	@echo "  $(COLOR_GREEN)make db-status$(COLOR_RESET)      Show migration status (Docker)"
	@echo ""
	@echo "$(COLOR_BOLD)OPA$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make opa-start$(COLOR_RESET)      Start OPA for the selected mode"
	@echo "  $(COLOR_GREEN)make opa-status$(COLOR_RESET)     Show OPA status"
	@echo "  $(COLOR_GREEN)make opa-stop$(COLOR_RESET)       Stop OPA"
	@echo ""
	@echo "$(COLOR_BOLD)DEVELOPMENT$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make test$(COLOR_RESET)           Run tests"
	@echo "  $(COLOR_GREEN)make docs-serve$(COLOR_RESET)     Serve documentation"
	@echo "  $(COLOR_GREEN)make docs-ai-update$(COLOR_RESET) Update documentation using AI"
	@echo "  $(COLOR_GREEN)make changelog$(COLOR_RESET)      Generate the changelog"
	@echo ""
	@echo "$(COLOR_BOLD)MORE$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make help-all$(COLOR_RESET)       Show all command groups"
	@echo "  $(COLOR_GREEN)make docker-help$(COLOR_RESET)    Show Docker commands"

help-all: help
	@echo ""
	@echo "$(COLOR_BOLD)SETUP AND UTILITIES$(COLOR_RESET)"
	@echo "  make setup MODE=local ENV=prod"
	@echo "  make setup MODE=docker ENV=dev"
	@echo "  make install-hooks"
	@echo "  make setup-opa"
	@echo "  make opa-install-service"
	@echo "  make docs-deploy"
	@echo ""
	@echo "$(COLOR_BOLD)USER MANAGEMENT$(COLOR_RESET)"
	@echo "  make users-list | users-create | users-unlock | users-delete | users-passwd"
	@echo "  Override selection with MODE=local|docker ENV=dev|demo|prod"
	@echo ""
	@echo "$(COLOR_BOLD)DATABASE$(COLOR_RESET)"
	@echo "  make db-migrate | db-rollback | db-status | db-check"
	@echo "  make db-backup | db-restore FILE=backup.sql | db-hash-password"
	@echo ""
	@echo "$(COLOR_BOLD)DOCKER LIFECYCLE$(COLOR_RESET)"
	@echo "  make dev-start | dev-stop | dev-restart | dev-recreate | dev-rebuild"
	@echo "  make prod-start | prod-stop | prod-rebuild"
	@echo "  make logs | logs-web | logs-db | ps | shell | shell-www | db-shell"

# ============================================
# Setup
# ============================================

# Guided setup. MODE and ENV can be supplied to skip the first prompts.
setup:
	@MODE="$(MODE)" ENV="$(ENV)" START_SERVICES="$(START_SERVICES)" RUN_MIGRATIONS="$(RUN_MIGRATIONS)" INSTALL_OPA="$(INSTALL_OPA)" INSTALL_OPA_SERVICE="$(INSTALL_OPA_SERVICE)" CREATE_USER="$(CREATE_USER)" MAKE_CMD="make" ./scripts/setup.sh

# Native prerequisites and webserver configuration, called by the setup wizard.
setup-local:
	@echo "$(COLOR_BLUE)📋 Creating config files from examples...$(COLOR_RESET)"
	@mkdir -p config temp/api-cache temp/sessions temp/templates-cache logs
	@for file in config.env db.config.env mail.config.env; do \
		if [ ! -f "config/$$file" ]; then \
			cp "config/example/$${file}.example" "config/$$file"; \
			chmod 600 "config/$$file"; \
			echo "$(COLOR_GREEN)✅ Created config/$$file$(COLOR_RESET)"; \
		else \
			echo "$(COLOR_YELLOW)⚠️  config/$$file already exists, skipping$(COLOR_RESET)"; \
		fi; \
	done
	@echo ""
	@echo "$(COLOR_CYAN)📋 Webserver Configuration$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)Which webserver will you use?$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)1)$(COLOR_RESET) Apache (shared hosting) - .htaccess"
	@echo "  $(COLOR_GREEN)2)$(COLOR_RESET) Apache (VirtualHost) - apache-vhost.conf"
	@echo "  $(COLOR_GREEN)3)$(COLOR_RESET) Nginx - nginx-site.conf"
	@echo "  $(COLOR_GREEN)4)$(COLOR_RESET) Caddy - Caddyfile"
	@echo "  $(COLOR_GREEN)5)$(COLOR_RESET) Skip (I'll configure manually)"
	@read -p "$(COLOR_CYAN)Select option [1-5]: $(COLOR_RESET)" webserver; \
	case $$webserver in \
		1) \
			if [ ! -f "public/.htaccess" ]; then \
				cp docs/examples/webserver-configs/.htaccess public/.htaccess; \
				echo "$(COLOR_GREEN)✅ Created public/.htaccess$(COLOR_RESET)"; \
				echo "$(COLOR_BLUE)ℹ️  .htaccess is ready. Ensure mod_rewrite is enabled.$(COLOR_RESET)"; \
			else \
				echo "$(COLOR_YELLOW)⚠️  public/.htaccess already exists, skipping$(COLOR_RESET)"; \
			fi; \
			;; \
		2) \
			echo "$(COLOR_BLUE)ℹ️  Copy example config to a new site file:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo cp docs/examples/webserver-configs/apache-vhost.conf /etc/apache2/sites-available/mata-dashboard.conf$(COLOR_RESET)"; \
			echo "$(COLOR_BLUE)   Then enable and reload:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo a2ensite mata-dashboard && sudo systemctl reload apache2$(COLOR_RESET)"; \
			;; \
		3) \
			echo "$(COLOR_BLUE)ℹ️  Copy example config to a new site file:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo cp docs/examples/webserver-configs/nginx-site.conf /etc/nginx/sites-available/mata-dashboard$(COLOR_RESET)"; \
			echo "$(COLOR_BLUE)   Then enable and reload:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo ln -s /etc/nginx/sites-available/mata-dashboard /etc/nginx/sites-enabled/$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo systemctl reload nginx$(COLOR_RESET)"; \
			;; \
		4) \
			echo "$(COLOR_BLUE)ℹ️  Copy example config (review before overwriting existing Caddyfile):$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo cp docs/examples/webserver-configs/Caddyfile /etc/caddy/Caddyfile$(COLOR_RESET)"; \
			echo "$(COLOR_BLUE)   Or append to existing Caddyfile, then reload:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo systemctl reload caddy$(COLOR_RESET)"; \
			echo "$(COLOR_YELLOW)   Note: Caddy will automatically obtain SSL certificates$(COLOR_RESET)"; \
			;; \
		5) \
			echo "$(COLOR_YELLOW)⚠️  Skipped. See docs/examples/webserver-configs/README.md for manual setup$(COLOR_RESET)"; \
			;; \
		*) \
			echo "$(COLOR_RED)❌ Invalid option. See docs/examples/webserver-configs/README.md$(COLOR_RESET)"; \
			;; \
	esac
	@echo ""
		@echo "$(COLOR_BLUE)📦 Installing Composer dependencies...$(COLOR_RESET)"
	@if [ ! -f composer.json ]; then \
		echo "$(COLOR_RED)❌ composer.json not found$(COLOR_RESET)"; \
		exit 1; \
	fi
	@if command -v composer >/dev/null 2>&1; then \
		composer install --no-dev; \
	elif [ -f "$$HOME/.composer/composer" ]; then \
		$$HOME/.composer/composer install --no-dev; \
	else \
		echo "$(COLOR_RED)❌ Composer not found in PATH$(COLOR_RESET)"; \
		echo "$(COLOR_YELLOW)Please install Composer or run manually:$(COLOR_RESET)"; \
		echo "  $(COLOR_CYAN)composer install$(COLOR_RESET)"; \
		echo ""; \
		echo "$(COLOR_YELLOW)Or use Docker instead:$(COLOR_RESET)"; \
		echo "  $(COLOR_CYAN)make docker-setup$(COLOR_RESET)"; \
		exit 1; \
	fi
	@echo "$(COLOR_GREEN)✅ Native prerequisites ready.$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)Review config/config.env, config/db.config.env, and config/mail.config.env.$(COLOR_RESET)"

doctor:
	@failed=0; \
	echo "$(COLOR_BOLD)MATA installation check$(COLOR_RESET)"; \
	echo "  Mode:        $${MODE:-$(MODE)}"; \
	echo "  Environment: $${ENV:-$(ENV)}"; \
	case "$(ENV)" in dev|demo|prod) ;; *) echo "$(COLOR_RED)  ✗ Invalid or missing environment$(COLOR_RESET)"; failed=1 ;; esac; \
	for file in config/config.env config/db.config.env config/mail.config.env; do \
		if [ -f "$$file" ]; then echo "$(COLOR_GREEN)  ✓ $$file$(COLOR_RESET)"; else echo "$(COLOR_RED)  ✗ $$file missing$(COLOR_RESET)"; failed=1; fi; \
	done; \
	if [ "$(MODE)" = "local" ]; then \
		command -v php >/dev/null 2>&1 && echo "$(COLOR_GREEN)  ✓ PHP available$(COLOR_RESET)" || { echo "$(COLOR_RED)  ✗ PHP missing$(COLOR_RESET)"; failed=1; }; \
		[ -f vendor/autoload.php ] && echo "$(COLOR_GREEN)  ✓ Composer dependencies installed$(COLOR_RESET)" || { echo "$(COLOR_RED)  ✗ vendor/autoload.php missing$(COLOR_RESET)"; failed=1; }; \
		if systemctl is-active --quiet opa.service 2>/dev/null; then echo "$(COLOR_GREEN)  ✓ OPA running$(COLOR_RESET)"; else echo "$(COLOR_YELLOW)  ! OPA not running$(COLOR_RESET)"; fi; \
	elif [ "$(MODE)" = "docker" ]; then \
		command -v docker >/dev/null 2>&1 && echo "$(COLOR_GREEN)  ✓ Docker available$(COLOR_RESET)" || { echo "$(COLOR_RED)  ✗ Docker missing$(COLOR_RESET)"; failed=1; }; \
		command -v docker-compose >/dev/null 2>&1 && echo "$(COLOR_GREEN)  ✓ Docker Compose available$(COLOR_RESET)" || { echo "$(COLOR_RED)  ✗ docker-compose missing$(COLOR_RESET)"; failed=1; }; \
		[ -f .env ] && echo "$(COLOR_GREEN)  ✓ .env present$(COLOR_RESET)" || { echo "$(COLOR_RED)  ✗ .env missing$(COLOR_RESET)"; failed=1; }; \
	else \
		echo "$(COLOR_RED)  ✗ No mode selected; run make setup$(COLOR_RESET)"; failed=1; \
	fi; \
	exit $$failed

# Install Composer dependencies and git hooks
install-hooks:
	@echo "$(COLOR_BLUE)🪝 Installing git hooks...$(COLOR_RESET)"
	@if [ -f .githooks/pre-commit ]; then \
		ln -sf ../../.githooks/pre-commit .git/hooks/pre-commit; \
		chmod +x .git/hooks/pre-commit; \
		echo "$(COLOR_GREEN)✅ Git pre-commit hook installed$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)⚠️  .githooks/pre-commit not found, skipping$(COLOR_RESET)"; \
	fi
	@echo "$(COLOR_BLUE)🔧 Making scripts executable...$(COLOR_RESET)"
	@chmod +x scripts/build/generate-changelog.sh scripts/docs/ai-update.sh 2>/dev/null || true
	@echo "$(COLOR_GREEN)✅ Scripts are now executable$(COLOR_RESET)"

# ============================================
# OPA (Open Policy Agent)
# ============================================

setup-opa:
	@set -e; \
	echo "$(COLOR_BLUE)⬇️  Downloading OPA binary...$(COLOR_RESET)"; \
	mkdir -p "$(OPA_BIN_DIR)"; \
	OS=$$(uname -s | tr '[:upper:]' '[:lower:]'); \
	ARCH=$$(uname -m); \
	case "$$ARCH" in \
		x86_64|amd64) ARCH=amd64 ;; \
		aarch64|arm64) ARCH=arm64 ;; \
	esac; \
	if [ "$$OS" = "linux" ]; then \
		FILENAME="opa_linux_$${ARCH}_static"; \
	elif [ "$$OS" = "darwin" ]; then \
		FILENAME="opa_darwin_$${ARCH}"; \
	else \
		echo "$(COLOR_RED)❌ Unsupported OS: $$OS$(COLOR_RESET)"; \
		exit 1; \
	fi; \
	URL="$(OPA_BASE_URL)/$$FILENAME"; \
	echo "$(COLOR_BLUE)🌐 Fetching $$URL$(COLOR_RESET)"; \
	curl -fsSL "$$URL" -o "$(OPA_BIN).tmp"; \
	curl -fsSL "$$URL.sha256" -o "$(OPA_BIN).sha256"; \
	CHECKSUM=$$(awk '{print $$1}' "$(OPA_BIN).sha256"); \
	if command -v sha256sum >/dev/null 2>&1; then \
		echo "$$CHECKSUM  $(OPA_BIN).tmp" | sha256sum -c -; \
	elif command -v shasum >/dev/null 2>&1; then \
		echo "$$CHECKSUM  $(OPA_BIN).tmp" | shasum -a 256 -c -; \
	else \
		echo "$(COLOR_RED)❌ sha256sum or shasum not found$(COLOR_RESET)"; \
		rm -f "$(OPA_BIN).tmp" "$(OPA_BIN).sha256"; \
		exit 1; \
	fi; \
	chmod +x "$(OPA_BIN).tmp"; \
	mv "$(OPA_BIN).tmp" "$(OPA_BIN)"; \
	rm -f "$(OPA_BIN).sha256"; \
	echo "$(COLOR_GREEN)✅ OPA installed at $(OPA_BIN)$(COLOR_RESET)"

opa-install-service:
	@if [ ! -x "$(OPA_BIN)" ]; then \
		echo "$(COLOR_RED)❌ $(OPA_BIN) not found. Run: make setup-opa$(COLOR_RESET)"; \
		exit 1; \
	fi
	@echo "$(COLOR_BLUE)🛠️  Installing systemd unit...$(COLOR_RESET)"
	@sudo sh -c 'sed -e "s|@@OPA_BIN@@|$(abspath $(OPA_BIN))|g" -e "s|@@OPA_WORKDIR@@|$(CURDIR)|g" -e "s|@@OPA_POLICY_DIR@@|$(CURDIR)/policy|g" "$(OPA_SERVICE_TEMPLATE)" > /etc/systemd/system/opa.service'
	@sudo systemctl daemon-reload
	@sudo systemctl enable --now opa.service
	@echo "$(COLOR_GREEN)✅ OPA service installed and started$(COLOR_RESET)"

opa-docker-start:
	@set -e; \
	if docker ps -a --format '{{.Names}}' | grep -q '^mata-opa$$'; then \
		echo "$(COLOR_YELLOW)⚠️  Removing existing mata-opa container...$(COLOR_RESET)"; \
		docker rm -f mata-opa >/dev/null; \
	fi; \
	echo "$(COLOR_BLUE)🐳 Starting OPA container...$(COLOR_RESET)"; \
	docker run -d --name mata-opa \
		-p 127.0.0.1:3320:3320 \
		-v "$(CURDIR)/policy:/policies" \
		openpolicyagent/opa:latest \
		run --server --watch /policies >/dev/null; \
	echo "$(COLOR_GREEN)✅ OPA running at http://localhost:3320 (container: mata-opa)$(COLOR_RESET)"

# Start OPA according to the mode selected by make setup.
opa-start:
	@if [ "$(MODE)" = "local" ]; then \
		if [ ! -f /etc/systemd/system/opa.service ]; then \
			echo "$(COLOR_RED)❌ OPA service is not installed. Run: make opa-install-service$(COLOR_RESET)"; \
			exit 1; \
		fi; \
		sudo systemctl start opa.service; \
	elif [ "$(MODE)" = "docker" ]; then \
		if [ "$(ENV)" = "prod" ]; then \
			docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d mata-opa; \
		else \
			docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d mata-opa; \
		fi; \
	else \
		echo "$(COLOR_RED)❌ No installation mode selected. Run: make setup$(COLOR_RESET)"; \
		exit 1; \
	fi

opa-stop:
	@if [ "$(MODE)" = "local" ]; then \
		sudo systemctl stop opa.service; \
	elif [ "$(MODE)" = "docker" ]; then \
		if [ "$(ENV)" = "prod" ]; then files="-f docker-compose.yml -f docker-compose.prod.yml"; else files="-f docker-compose.yml -f docker-compose.dev.yml"; fi; \
		docker-compose $$files stop mata-opa; \
	else \
		echo "$(COLOR_RED)❌ No installation mode selected. Run: make setup$(COLOR_RESET)"; \
		exit 1; \
	fi

opa-status:
	@if [ "$(MODE)" = "local" ]; then \
		systemctl --no-pager --full status opa.service; \
	elif [ "$(MODE)" = "docker" ]; then \
		if [ "$(ENV)" = "prod" ]; then files="-f docker-compose.yml -f docker-compose.prod.yml"; else files="-f docker-compose.yml -f docker-compose.dev.yml"; fi; \
		docker-compose $$files ps mata-opa; \
	else \
		echo "$(COLOR_RED)❌ No installation mode selected. Run: make setup$(COLOR_RESET)"; \
		exit 1; \
	fi

# ============================================
# Testing
# ============================================

# Run tests with PHPUnit (in Docker container)
test:
	@if docker-compose ps mata-web | grep -q "Up"; then \
		docker-compose exec mata-web vendor/bin/phpunit; \
	else \
		echo "$(COLOR_RED)❌ Docker containers not running. Start with: make dev-start$(COLOR_RESET)"; \
		exit 1; \
	fi

# Run tests with coverage (in Docker container)
test-coverage:
	docker-compose exec mata-web vendor/bin/phpunit --coverage-html coverage

# ============================================
# Database Management
# ============================================

# Run database migrations (in Docker container)
db-migrate:
	docker-compose exec mata-web php scripts/database/migrate.php migrate

# Rollback database migration (in Docker container)
db-rollback:
	docker-compose exec mata-web php scripts/database/migrate.php rollback

# Show migration status (in Docker container)
db-status:
	docker-compose exec mata-web php scripts/database/migrate.php status

# Check database integrity (in Docker container)
db-check:
	docker-compose exec mata-web php scripts/database/database-integrity.php

# Generate password hash (standalone - works without Docker)
db-hash-password:
	@if command -v php >/dev/null 2>&1; then \
		php scripts/database/hash-password.php; \
	else \
		docker-compose exec mata-web php scripts/database/hash-password.php; \
	fi

# Migrate password hashing format (in Docker container)
db-migrate-passwords:
	docker-compose exec mata-web php scripts/database/migrate-password.php

# ============================================
# User Management
# ============================================

# User commands follow MODE and ENV selected by make setup.
define RUN_USER_COMMAND
	@mode="$(MODE)"; env="$(ENV)"; action="$(1)"; \
	case "$$env" in dev|demo|prod) ;; *) echo "$(COLOR_RED)❌ ENV must be dev, demo, or prod. Run: make setup$(COLOR_RESET)"; exit 1 ;; esac; \
	if [ "$$mode" = "local" ]; then \
		if ! command -v php >/dev/null 2>&1; then echo "$(COLOR_RED)❌ PHP is not available.$(COLOR_RESET)"; exit 1; fi; \
		if [ ! -f vendor/autoload.php ]; then echo "$(COLOR_RED)❌ vendor/autoload.php is missing. Run: composer install$(COLOR_RESET)"; exit 1; fi; \
		path="config/$$env/users.json"; [ -e "$$path" ] || path="config/$$env"; [ -e "$$path" ] || path="config"; \
		owner=$$(stat -c '%U' "$$path" 2>/dev/null || stat -f '%Su' "$$path" 2>/dev/null || id -un); \
		current=$$(id -un); \
		echo "$(COLOR_BLUE)Mode: local | Environment: $$env | Operator: $$owner$(COLOR_RESET)"; \
		if [ "$$owner" != "$$current" ]; then \
			if ! command -v sudo >/dev/null 2>&1; then echo "$(COLOR_RED)❌ Run this command as $$owner.$(COLOR_RESET)"; exit 1; fi; \
			sudo -u "$$owner" php scripts/user-management/manage-users.php "$$action" "--env=$$env"; \
		else \
			php scripts/user-management/manage-users.php "$$action" "--env=$$env"; \
		fi; \
	elif [ "$$mode" = "docker" ]; then \
		if [ "$$env" = "prod" ]; then \
			files="-f docker-compose.yml -f docker-compose.prod.yml"; \
			echo "$(COLOR_BLUE)Mode: docker admin | Environment: $$env$(COLOR_RESET)"; \
			docker-compose $$files run --rm --no-deps -u root \
				-v "$(CURDIR)/config:/var/www/html/config" \
				-v "$(CURDIR)/logs:/var/www/html/logs" \
				-v "$(CURDIR)/docker/admin-entrypoint.sh:/usr/local/bin/mata-admin:ro" \
				--entrypoint /bin/sh mata-web /usr/local/bin/mata-admin "$$action" "$$env"; \
		else \
			files="-f docker-compose.yml -f docker-compose.dev.yml"; \
			if ! docker-compose $$files ps --services --filter status=running | grep -qx mata-web; then \
				echo "$(COLOR_RED)❌ Docker web service is not running. Run: make dev-start$(COLOR_RESET)"; exit 1; \
			fi; \
			echo "$(COLOR_BLUE)Mode: docker | Environment: $$env$(COLOR_RESET)"; \
			docker-compose $$files exec -u www-data mata-web php scripts/user-management/manage-users.php "$$action" "--env=$$env"; \
		fi; \
	else \
		echo "$(COLOR_RED)❌ No installation mode selected. Run: make setup$(COLOR_RESET)"; \
		exit 1; \
	fi
endef

users-list:
	$(call RUN_USER_COMMAND,list)

users-create:
	$(call RUN_USER_COMMAND,create)

users-unlock:
	$(call RUN_USER_COMMAND,unlock)

users-delete:
	$(call RUN_USER_COMMAND,delete)

users-passwd:
	$(call RUN_USER_COMMAND,passwd)

# ============================================
# Build & Release
# ============================================

# Generate changelog from git history
changelog:
	./scripts/build/generate-changelog.sh

# ============================================
# Documentation Targets
# ============================================

# Serve documentation locally
docs-serve:
	@echo "$(COLOR_YELLOW)ℹ️  Requires mkdocs in PATH (e.g., via pipx).$(COLOR_RESET)"
	mkdocs serve -a localhost:3330

# Deploy documentation to GitHub Pages
docs-deploy:
	mkdocs gh-deploy --force

# AI-powered documentation update based on code changes
docs-ai-update:
	./scripts/docs/ai-update.sh

# ============================================
# Docker Targets
# ============================================

## Setup & Installation

# First-time Docker development environment setup
docker-setup:
	@echo "$(COLOR_BLUE)🐳 Setting up Docker environment...$(COLOR_RESET)"
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		echo "$(COLOR_GREEN)✅ Created .env (Docker infrastructure settings)$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)⚠️  .env already exists, skipping$(COLOR_RESET)"; \
	fi
	@echo "$(COLOR_BLUE)🔌 Ensuring shared Docker network exists...$(COLOR_RESET)"
	@docker network inspect mata-network >/dev/null 2>&1 || docker network create mata-network >/dev/null
	@mkdir -p config config/dev config/demo config/prod temp/api-cache temp/sessions temp/templates-cache logs
	@echo "$(COLOR_BLUE)📋 Creating config files from examples...$(COLOR_RESET)"
	@for file in config.env db.config.env mail.config.env; do \
		if [ ! -f "config/$$file" ]; then \
			cp "config/example/$${file}.example" "config/$$file"; \
			chmod 600 "config/$$file"; \
			echo "$(COLOR_GREEN)✅ Created config/$$file$(COLOR_RESET)"; \
		else \
			echo "$(COLOR_YELLOW)⚠️  config/$$file already exists, skipping$(COLOR_RESET)"; \
		fi; \
	done
	@echo ""
	@echo "$(COLOR_GREEN)✅ Docker prerequisites ready.$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)No default user was created. The setup wizard can create one after startup.$(COLOR_RESET)"

## Development (Docker)

# Start development environment (auto-runs docker-setup if needed)
dev-start:
	@echo "$(COLOR_BLUE)🚀 Starting docker development environment...$(COLOR_RESET)"
	@# Check if setup is needed
	@if [ ! -f .env ] || [ ! -f config/config.env ] || [ ! -f config/db.config.env ] || [ ! -f config/mail.config.env ]; then \
		echo "$(COLOR_YELLOW)⚠️  Setup files missing. Running docker-setup first...$(COLOR_RESET)"; \
		echo ""; \
		$(MAKE) docker-setup; \
		echo ""; \
	fi
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
	@if [ "$(RUN_MIGRATIONS)" != "no" ]; then \
		echo "$(COLOR_BLUE)🗄️  Running database migrations...$(COLOR_RESET)"; \
		$(MAKE) db-migrate; \
	else \
		echo "$(COLOR_YELLOW)⚠️  Database migrations skipped.$(COLOR_RESET)"; \
	fi
	@echo ""
	@echo "$(COLOR_GREEN)✅ Development environment running!$(COLOR_RESET)"
	@# Read actual port from .env file or use default
	@PORT=$$(grep -E '^WEB_PORT=' .env 2>/dev/null | cut -d '=' -f2 || echo "3300"); \
	echo "$(COLOR_CYAN)🌐 Dashboard: http://localhost:$$PORT$(COLOR_RESET)"; \
	echo "$(COLOR_CYAN)🩺 Health: http://localhost:$$PORT/health$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)👤 No default login. Create one with: make users-create$(COLOR_RESET)"

# Start dev environment and follow logs
dev-logs:
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml logs -f

# Stop development environment
dev-stop:
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml down

# Restart development environment
dev-restart:
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml restart

# Recreate development containers to reload env files
dev-recreate:
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --force-recreate --no-build

# Rebuild development containers from scratch
dev-rebuild:
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml down
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml build --no-cache
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

## Production

# Deploy production environment
prod-start:
	@echo "$(COLOR_BLUE)🚀 Deploying production environment...$(COLOR_RESET)"
	@if [ ! -f .env ]; then \
		echo "$(COLOR_RED)❌ ERROR: .env file required for production$(COLOR_RESET)"; \
		exit 1; \
	fi
	@if grep -q "change_me" .env; then \
		echo "$(COLOR_RED)❌ ERROR: Update passwords in .env before deploying$(COLOR_RESET)"; \
		exit 1; \
	fi
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
	@if [ "$(RUN_MIGRATIONS)" != "no" ]; then \
		echo "$(COLOR_BLUE)🗄️  Running database migrations...$(COLOR_RESET)"; \
		$(MAKE) db-migrate; \
	else \
		echo "$(COLOR_YELLOW)⚠️  Database migrations skipped.$(COLOR_RESET)"; \
	fi
	@echo "$(COLOR_GREEN)✅ Production deployed!$(COLOR_RESET)"

# Stop production environment
prod-stop:
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml down

# Rebuild production from scratch
prod-rebuild:
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d

## Logs & Monitoring


# Follow all container logs
logs:
	docker-compose logs -f

# Follow web container logs only
logs-web:
	docker-compose logs -f mata-web

# Follow database logs only
logs-db:
	docker-compose logs -f mata-db

# Follow all container logs
logs-php:
	docker-compose exec -u www-data mata-web tail -fn 500 logs/php-error.log

# Follow all container logs
logs-app:
	docker-compose exec -u www-data mata-web tail -fn 500 logs/app.log
	
# Show container status
ps:
	docker-compose ps

## Shell Access

# Access web container shell
shell:
	docker-compose exec mata-web bash

# Access database shell
db-shell:
	docker-compose exec mata-db mysql -u root -p

# Access web container as www-data user
shell-www:
	docker-compose exec -u www-data mata-web bash

## Database Operations

# Backup database
db-backup:
	@mkdir -p backups
	@echo "$(COLOR_BLUE)📦 Creating database backup...$(COLOR_RESET)"
	docker-compose exec -T mata-db mysqldump -u root -p$${MYSQL_ROOT_PASSWORD} $${DB_DATABASE:-mata_dashboard} > backups/backup-$$(date +%Y%m%d-%H%M%S).sql
	@echo "$(COLOR_GREEN)✅ Backup saved to backups/$(COLOR_RESET)"

# Restore database from backup (usage: make db-restore FILE=backups/backup-xyz.sql)
db-restore:
	@if [ -z "$(FILE)" ]; then \
		echo "$(COLOR_RED)❌ Usage: make db-restore FILE=backups/backup-xyz.sql$(COLOR_RESET)"; \
		exit 1; \
	fi
	@echo "$(COLOR_YELLOW)⚠️  Restoring database from $(FILE)...$(COLOR_RESET)"
	docker-compose exec -T mata-db mysql -u root -p$${MYSQL_ROOT_PASSWORD} $${DB_DATABASE:-mata_dashboard} < $(FILE)
	@echo "$(COLOR_GREEN)✅ Database restored$(COLOR_RESET)"

## Cleanup

# Stop and remove all containers, networks
down:
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml down
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml down

# Remove everything including volumes (WARNING: deletes data!)
clean:
	@echo "$(COLOR_RED)⚠️  This will delete all containers, volumes, and database data!$(COLOR_RESET)"
	@read -p "Are you sure? [y/N]: " -n 1 -r; \
	echo; \
	if [ "$$REPLY" = "y" ] || [ "$$REPLY" = "Y" ]; then \
		docker-compose -f docker-compose.yml -f docker-compose.dev.yml down -v; \
		docker-compose -f docker-compose.yml -f docker-compose.prod.yml down -v; \
		echo "$(COLOR_GREEN)✅ Cleaned up$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)Cancelled$(COLOR_RESET)"; \
	fi

# Docker cleanup for this project only (safe - won't affect other containers)
docker-clean:
	@echo "$(COLOR_YELLOW)🧹 Cleaning up mata-dashboard Docker resources...$(COLOR_RESET)"
	@echo "$(COLOR_CYAN)Stopping and removing containers...$(COLOR_RESET)"
	@docker-compose -f docker-compose.yml -f docker-compose.dev.yml down -v 2>/dev/null || true
	@docker-compose -f docker-compose.yml -f docker-compose.prod.yml down -v 2>/dev/null || true
	@echo "$(COLOR_CYAN)Removing mata-dashboard images...$(COLOR_RESET)"
	@docker images --filter "reference=mata-dashboard*" -q | xargs -r docker rmi -f 2>/dev/null || true
	@docker images --filter "reference=${COMPOSE_PROJECT_NAME:-mata-dashboard}*" -q | xargs -r docker rmi -f 2>/dev/null || true
	@echo "$(COLOR_CYAN)Removing orphaned containers...$(COLOR_RESET)"
	@docker ps -a --filter "name=mata-" -q | xargs -r docker rm -f 2>/dev/null || true
	@echo "$(COLOR_GREEN)✅ mata-dashboard Docker resources cleaned$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)💡 Other Docker projects are unaffected$(COLOR_RESET)"

# System-wide Docker cleanup (WARNING: affects ALL Docker resources)
docker-clean-all:
	@echo "$(COLOR_RED)⚠️  This will clean up ALL Docker resources (not just mata-dashboard)!$(COLOR_RESET)"
	@read -p "Are you sure? [y/N]: " -n 1 -r; \
	echo; \
	if [ "$$REPLY" = "y" ] || [ "$$REPLY" = "Y" ]; then \
		docker system prune -a; \
		echo "$(COLOR_GREEN)✅ System-wide cleanup complete$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)Cancelled$(COLOR_RESET)"; \
	fi

## Help

# Show available Docker commands
docker-help:
	@echo "$(COLOR_BOLD)$(COLOR_CYAN)Docker Development Commands:$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_BOLD)Setup:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make docker-setup$(COLOR_RESET)    - First-time environment setup (optional)"
	@echo ""
	@echo "$(COLOR_BOLD)Development:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make dev-start$(COLOR_RESET)       - Start development environment (auto-setup if needed)"
	@echo "  $(COLOR_GREEN)make dev-logs$(COLOR_RESET)        - Start dev and follow logs"
	@echo "  $(COLOR_GREEN)make dev-stop$(COLOR_RESET)        - Stop development environment"
	@echo "  $(COLOR_GREEN)make dev-restart$(COLOR_RESET)     - Restart development containers"
	@echo "  $(COLOR_GREEN)make dev-recreate$(COLOR_RESET)    - Recreate containers (reload env files)"
	@echo "  $(COLOR_GREEN)make dev-rebuild$(COLOR_RESET)     - Rebuild from scratch"
	@echo ""
	@echo "$(COLOR_BOLD)Production:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make prod$(COLOR_RESET)            - Deploy production environment"
	@echo "  $(COLOR_GREEN)make prod-stop$(COLOR_RESET)       - Stop production"
	@echo "  $(COLOR_GREEN)make prod-rebuild$(COLOR_RESET)    - Rebuild production"
	@echo ""
	@echo "$(COLOR_BOLD)Logs & Monitoring:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make logs$(COLOR_RESET)            - Follow all logs"
	@echo "  $(COLOR_GREEN)make logs-web$(COLOR_RESET)        - Follow web container logs"
	@echo "  $(COLOR_GREEN)make logs-db$(COLOR_RESET)         - Follow database logs"
	@echo "  $(COLOR_GREEN)make ps$(COLOR_RESET)              - Show container status"
	@echo ""
	@echo "$(COLOR_BOLD)Shell Access:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make shell$(COLOR_RESET)           - Access web container"
	@echo "  $(COLOR_GREEN)make shell-www$(COLOR_RESET)       - Access as www-data user"
	@echo "  $(COLOR_GREEN)make db-shell$(COLOR_RESET)        - Access MySQL shell"
	@echo ""
	@echo "$(COLOR_BOLD)Database:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make db-backup$(COLOR_RESET)       - Backup database"
	@echo "  $(COLOR_GREEN)make db-restore FILE=backup.sql$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_BOLD)Cleanup:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make down$(COLOR_RESET)            - Stop all containers"
	@echo "  $(COLOR_RED)make clean$(COLOR_RESET)             - Remove everything (including data!)"
	@echo "  $(COLOR_YELLOW)make docker-clean$(COLOR_RESET)   - Clean mata-dashboard Docker resources only"
	@echo "  $(COLOR_RED)make docker-clean-all$(COLOR_RESET)  - System-wide Docker cleanup (ALL projects!)"

.PHONY: help help-all setup setup-local doctor install-hooks setup-opa opa-install-service opa-docker-start opa-start opa-stop opa-status \
	test test-coverage \
	db-migrate db-rollback db-status db-check db-hash-password db-migrate-passwords \
	users-list users-create users-unlock users-delete users-passwd \
	changelog bootstrap \
	docs-serve docs-deploy docs-ai-update \
	docker-setup dev-start dev-logs dev-stop dev-restart dev-recreate dev-rebuild \
	prod-start prod-stop prod-rebuild \
	logs logs-web logs-db ps \
	shell db-shell shell-www \
	db-backup db-restore \
	down clean docker-clean docker-clean-all docker-help
