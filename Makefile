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

# A pseudo-terminal (some agents, CI runners) looks like a person: NONINTERACTIVE=1 or CI=true
# turns every prompt into its default or an error naming the variable to pass.
NONINTERACTIVE_ON := $(filter 1 y yes true on Y YES TRUE ON,$(NONINTERACTIVE) $(CI))
INTERACTIVE := { [ -t 0 ] && [ -z "$(NONINTERACTIVE_ON)" ]; }
SUDO := sudo$(if $(NONINTERACTIVE_ON), -n)

comma := ,

DOCKER_COMPOSE ?= docker compose
# Stamped into prod images, whose build context has no .git.
MATA_VERSION ?= $(shell sh scripts/version.sh)
COMPOSE_ENV_FILES = --env-file config/db.config.env --env-file .env
DEV_COMPOSE = APP_ENV=$(ENV) $(DOCKER_COMPOSE) $(COMPOSE_ENV_FILES) -f docker-compose.yml -f docker-compose.dev.yml
PROD_COMPOSE = APP_ENV=$(ENV) MATA_VERSION=$(MATA_VERSION) $(DOCKER_COMPOSE) $(COMPOSE_ENV_FILES) -f docker-compose.yml -f docker-compose.prod.yml
SELECTED_COMPOSE = $(if $(filter prod,$(ENV)),$(PROD_COMPOSE),$(DEV_COMPOSE))

# Reusable operational checks. MODE is selected, never auto-detected.
define REQUIRE_SELECTION
case "$(MODE)" in native|docker) ;; *) echo "$(COLOR_RED)❌ MODE must be native or docker. Run: make setup$(COLOR_RESET)"; exit 1 ;; esac; \
case "$(ENV)" in dev|demo|prod) ;; *) echo "$(COLOR_RED)❌ ENV must be dev, demo, or prod. Run: make setup$(COLOR_RESET)"; exit 1 ;; esac
endef

# Usage: $(call GET_ENV_VALUE,file,key)
define GET_ENV_VALUE
$$(awk -v key='$(2)' '$$0 ~ "^" key "=" { value = substr($$0, length(key) + 2); sub(/^[[:space:]]+/, "", value); sub(/[[:space:]]+$$/, "", value); if (value ~ /^".*"$$/) { sub(/^"/, "", value); sub(/"$$/, "", value) } } END { print value }' "$(1)" 2>/dev/null)
endef

DOCKER_DAEMON_CHECKED ?= 0

define REQUIRE_DOCKER_DAEMON
if [ "$(DOCKER_DAEMON_CHECKED)" != "1" ]; then \
	if ! docker_error=$$(docker info 2>&1 >/dev/null); then \
		case "$$docker_error" in \
			*"permission denied"*) echo "$(COLOR_RED)❌ $$(id -un) may not use Docker. Either add the account to the docker group (root-equivalent), then log in again:$(COLOR_RESET)"; \
				echo "   sudo usermod -aG docker $$(id -un)"; \
				echo "   or run make as root." ;; \
			*) echo "$(COLOR_RED)❌ Docker daemon is unavailable. Start it, e.g. sudo systemctl start docker.$(COLOR_RESET)" ;; \
		esac; \
		exit 1; \
	fi; \
fi
endef

define REQUIRE_DOCKER_AVAILABLE
$(REQUIRE_SELECTION); \
if [ "$(MODE)" != "docker" ]; then echo "$(COLOR_RED)❌ This command is Docker-only (current mode: $(MODE)).$(COLOR_RESET)"; exit 1; fi; \
command -v docker >/dev/null 2>&1 || { echo "$(COLOR_RED)❌ Docker is not installed.$(COLOR_RESET)"; exit 1; }; \
docker compose version >/dev/null 2>&1 || { echo "$(COLOR_RED)❌ Docker Compose plugin is unavailable.$(COLOR_RESET)"; exit 1; }; \
$(REQUIRE_DOCKER_DAEMON)
endef

define REQUIRE_DOCKER_MODE
$(REQUIRE_DOCKER_AVAILABLE); \
[ -f config/db.config.env ] && [ -f .env ] || { echo "$(COLOR_RED)❌ Docker environment files are missing. Run: make setup$(COLOR_RESET)"; exit 1; }
endef

define REQUIRE_DEV_ENV
case "$(ENV)" in dev|demo) ;; *) echo "$(COLOR_RED)❌ This command requires ENV=dev or ENV=demo.$(COLOR_RESET)"; exit 1 ;; esac
endef

define REQUIRE_WEB_RUNNING
if ! $(SELECTED_COMPOSE) ps --services --filter status=running | grep -qx mata-web; then \
	echo "$(COLOR_RED)❌ mata-web is not running for $(ENV).$(COLOR_RESET)"; exit 1; \
fi
endef

define REQUIRE_DB_RUNNING
if ! $(SELECTED_COMPOSE) ps --services --filter status=running | grep -qx mata-db; then \
	echo "$(COLOR_RED)❌ mata-db is not running for $(ENV).$(COLOR_RESET)"; exit 1; \
fi
endef

# ALLOW_DELETE="DELETE <env>" skips the prompt, e.g. to tear down a throwaway install.
define CONFIRM_DELETE
confirmation="$(ALLOW_DELETE)"; \
if [ "$$confirmation" != "DELETE $(ENV)" ]; then \
	$(INTERACTIVE) || { echo "$(COLOR_RED)❌ No terminal to confirm; pass ALLOW_DELETE='DELETE $(ENV)'.$(COLOR_RESET)"; exit 1; }; \
	printf "Type 'DELETE $(ENV)' to continue: "; read -r confirmation; \
fi; \
[ "$$confirmation" = "DELETE $(ENV)" ] || { echo "$(COLOR_YELLOW)Cancelled.$(COLOR_RESET)"; exit 1; }
endef

define CONFIRM_PRODUCTION_WRITE
if [ "$(ENV)" = "prod" ] && [ "$(ALLOW_PRODUCTION)" != "CONFIRM_PRODUCTION" ]; then \
	echo "$(COLOR_RED)⚠️  Production write: $(1)$(COLOR_RESET)"; \
	$(INTERACTIVE) || { echo "$(COLOR_RED)❌ No terminal to confirm; pass ALLOW_PRODUCTION=CONFIRM_PRODUCTION.$(COLOR_RESET)"; exit 1; }; \
	printf "Type 'CONFIRM PRODUCTION' to continue: "; read -r confirmation; \
	[ "$$confirmation" = "CONFIRM PRODUCTION" ] || { echo "Cancelled."; exit 1; }; \
fi
endef

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
	@echo "  $(COLOR_GREEN)make mata$(COLOR_RESET)           Run the operator CLI: ARGS=\"server add <name> --api-url <url>\""
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
	@echo "  $(COLOR_GREEN)make db-migrate$(COLOR_RESET)     Run database migrations"
	@echo "  $(COLOR_GREEN)make db-backup$(COLOR_RESET)      Create a database backup"
	@echo "  $(COLOR_GREEN)make status$(COLOR_RESET)         Show container/service status (node health: make mata ARGS=status)"
	@echo "  $(COLOR_GREEN)make logs$(COLOR_RESET)           Follow selected-mode logs"
	@echo ""
	@echo "$(COLOR_BOLD)OPERATOR CLI$(COLOR_RESET) (bin/mata; make mata runs it as the right user and container)"
	@echo "  $(COLOR_GREEN)make mata$(COLOR_RESET) $(COLOR_CYAN)ARGS=\"user create admin admin\"$(COLOR_RESET)   users: list, create, passwd, unlock, delete"
	@echo "  $(COLOR_GREEN)make mata$(COLOR_RESET) $(COLOR_CYAN)ARGS=\"server add <name> ...\"$(COLOR_RESET)     servers: list, add, test, remove"
	@echo "  $(COLOR_GREEN)make mata$(COLOR_RESET) $(COLOR_CYAN)ARGS=\"config validate\"$(COLOR_RESET)           also: db status|rollback|check, alarm test|explain"
	@echo "  $(COLOR_GREEN)make mata$(COLOR_RESET)                                  list every command; $(COLOR_CYAN)ARGS=\"<command> --help\"$(COLOR_RESET) for options"
	@echo ""
	@echo "$(COLOR_BOLD)OPA$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make opa-start$(COLOR_RESET)      Start OPA for the selected mode"
	@echo "  $(COLOR_GREEN)make opa-status$(COLOR_RESET)     Show OPA status"
	@echo "  $(COLOR_GREEN)make opa-stop$(COLOR_RESET)       Stop OPA"
	@echo "  $(COLOR_GREEN)make opa-test$(COLOR_RESET)       Validate OPA policies"
	@echo "  $(COLOR_GREEN)make opa-validate$(COLOR_RESET)    Validate example configs against OPA policies"
	@echo ""
	@echo "$(COLOR_BOLD)DEVELOPMENT$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make test$(COLOR_RESET)           Run tests"
	@echo "  $(COLOR_GREEN)make docs-serve$(COLOR_RESET)     Serve documentation"
	@echo ""
	@echo "$(COLOR_BOLD)MORE$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make help-all$(COLOR_RESET)       Show all command groups"
	@echo "  $(COLOR_GREEN)make docker-help$(COLOR_RESET)    Show Docker commands"

help-all: help
	@echo ""
	@echo "$(COLOR_BOLD)SETUP AND UTILITIES$(COLOR_RESET)"
	@echo "  make setup MODE=native ENV=prod"
	@echo "  make setup MODE=docker ENV=dev"
	@echo "  make setup MODE=docker ENV=demo ADMIN_USER=admin   (no prompts without a terminal)"
	@echo "  make install-hooks"
	@echo "  make setup-opa"
	@echo "  make opa-install-service"
	@echo "  make docs-deploy"
	@echo "  Override the selection with MODE=native|docker ENV=dev|demo|prod"
	@echo ""
	@echo "$(COLOR_BOLD)DATABASE$(COLOR_RESET)"
	@echo "  make db-migrate | db-shell"
	@echo "  make db-backup [FILE=backup.sql] | db-restore FILE=backup.sql"
	@echo ""
	@echo "$(COLOR_BOLD)DOCKER LIFECYCLE$(COLOR_RESET)"
	@echo "  make dev-start | dev-stop | dev-restart | dev-recreate | dev-rebuild"
	@echo "  make prod-start | prod-stop | prod-restart | prod-rebuild"
	@echo "  make logs-web | logs-db | ps | shell | shell-www"
	@echo "  make clean | uninstall          Delete the stack's data; uninstall also the database, logs, and temp files"
	@echo ""
	@echo "$(COLOR_BOLD)LOGS AND TESTS$(COLOR_RESET)"
	@echo "  make logs-app | logs-php        Follow the application or PHP error log"
	@echo "  make test-coverage              Run tests with coverage"

# ============================================
# Setup
# ============================================

# Guided setup. MODE and ENV can be supplied to skip the first prompts.
setup:
	@MODE="$(MODE)" ENV="$(ENV)" START_SERVICES="$(START_SERVICES)" RUN_MIGRATIONS="$(RUN_MIGRATIONS)" INSTALL_OPA="$(INSTALL_OPA)" INSTALL_OPA_SERVICE="$(INSTALL_OPA_SERVICE)" CREATE_USER="$(CREATE_USER)" ADMIN_USER="$(ADMIN_USER)" WEBSERVER="$(WEBSERVER)" NONINTERACTIVE="$(NONINTERACTIVE)" MAKE_CMD="make" ./scripts/setup.sh

# Native prerequisites and webserver configuration, called by the setup wizard.
setup-native:
	@echo "$(COLOR_BLUE)📋 Creating config files from examples...$(COLOR_RESET)"
	@mkdir -p config/demo temp/api-cache temp/sessions temp/templates-cache logs
	@for file in config.env db.config.env mail.config.env servers.json servers.secrets.json ttl.json thresholds.json intervals.json data_retention.json; do \
		if [ ! -f "config/$$file" ]; then \
			cp "config.example/$${file}.example" "config/$$file"; \
			case "$$file" in *.env|servers.secrets.json) chmod 600 "config/$$file" ;; *) chmod 644 "config/$$file" ;; esac; \
			echo "$(COLOR_GREEN)✅ Created config/$$file$(COLOR_RESET)"; \
		else \
			echo "$(COLOR_YELLOW)⚠️  config/$$file already exists, skipping$(COLOR_RESET)"; \
		fi; \
	done
	@echo ""
	@echo "$(COLOR_CYAN)📋 Webserver Configuration$(COLOR_RESET)"
	@webserver="$(WEBSERVER)"; \
	if [ -z "$$webserver" ] && $(INTERACTIVE); then \
		echo "$(COLOR_YELLOW)Which webserver will you use?$(COLOR_RESET)"; \
		echo "  $(COLOR_GREEN)1)$(COLOR_RESET) Apache (shared hosting) - .htaccess"; \
		echo "  $(COLOR_GREEN)2)$(COLOR_RESET) Apache (VirtualHost) - apache-vhost.conf"; \
		echo "  $(COLOR_GREEN)3)$(COLOR_RESET) Nginx - nginx-site.conf"; \
		echo "  $(COLOR_GREEN)4)$(COLOR_RESET) Caddy - Caddyfile"; \
		echo "  $(COLOR_GREEN)5)$(COLOR_RESET) Skip (I'll configure manually)"; \
		printf "$(COLOR_CYAN)Select option [1-5]: $(COLOR_RESET)"; read -r webserver; \
	fi; \
	case "$${webserver:-none}" in \
		1|htaccess) \
			if [ ! -f "public/.htaccess" ]; then \
				cp docs/examples/webserver-configs/.htaccess public/.htaccess; \
				echo "$(COLOR_GREEN)✅ Created public/.htaccess$(COLOR_RESET)"; \
				echo "$(COLOR_BLUE)ℹ️  .htaccess is ready. Ensure mod_rewrite is enabled.$(COLOR_RESET)"; \
			else \
				echo "$(COLOR_YELLOW)⚠️  public/.htaccess already exists, skipping$(COLOR_RESET)"; \
			fi; \
			;; \
		2|apache) \
			echo "$(COLOR_BLUE)ℹ️  Copy example config to a new site file:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo cp docs/examples/webserver-configs/apache-vhost.conf /etc/apache2/sites-available/mata-dashboard.conf$(COLOR_RESET)"; \
			echo "$(COLOR_BLUE)   Then enable and reload:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo a2ensite mata-dashboard && sudo systemctl reload apache2$(COLOR_RESET)"; \
			;; \
		3|nginx) \
			echo "$(COLOR_BLUE)ℹ️  Copy example config to a new site file:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo cp docs/examples/webserver-configs/nginx-site.conf /etc/nginx/sites-available/mata-dashboard$(COLOR_RESET)"; \
			echo "$(COLOR_BLUE)   Then enable and reload:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo ln -s /etc/nginx/sites-available/mata-dashboard /etc/nginx/sites-enabled/$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo systemctl reload nginx$(COLOR_RESET)"; \
			;; \
		4|caddy) \
			echo "$(COLOR_BLUE)ℹ️  Copy example config (review before overwriting existing Caddyfile):$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo cp docs/examples/webserver-configs/Caddyfile /etc/caddy/Caddyfile$(COLOR_RESET)"; \
			echo "$(COLOR_BLUE)   Or append to existing Caddyfile, then reload:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo systemctl reload caddy$(COLOR_RESET)"; \
			echo "$(COLOR_YELLOW)   Note: Caddy will automatically obtain SSL certificates$(COLOR_RESET)"; \
			;; \
		5|none) \
			echo "$(COLOR_YELLOW)⚠️  Skipped. See docs/examples/webserver-configs/README.md for manual setup$(COLOR_RESET)"; \
			;; \
		*) \
			echo "$(COLOR_RED)❌ Invalid webserver '$$webserver': use htaccess, apache, nginx, caddy, or none.$(COLOR_RESET)"; \
			exit 1; \
			;; \
	esac
	@echo ""
		@echo "$(COLOR_BLUE)📦 Installing Composer dependencies...$(COLOR_RESET)"
	@if [ ! -f composer.json ]; then \
		echo "$(COLOR_RED)❌ composer.json not found$(COLOR_RESET)"; \
		exit 1; \
	fi
	@composer_args=""; [ "$(ENV)" = "prod" ] && composer_args="--no-dev --optimize-autoloader"; \
	if command -v composer >/dev/null 2>&1; then \
		composer install $$composer_args; \
	elif [ -f "$$HOME/.composer/composer" ]; then \
		$$HOME/.composer/composer install $$composer_args; \
	else \
		echo "$(COLOR_RED)❌ Composer not found in PATH$(COLOR_RESET)"; \
		echo "$(COLOR_YELLOW)Please install Composer or run manually:$(COLOR_RESET)"; \
		echo "  $(COLOR_CYAN)composer install$(COLOR_RESET)"; \
		echo ""; \
		echo "$(COLOR_YELLOW)Or use Docker instead:$(COLOR_RESET)"; \
		echo "  $(COLOR_CYAN)make setup MODE=docker ENV=dev$(COLOR_RESET)"; \
		exit 1; \
	fi
	@echo "$(COLOR_GREEN)✅ Native prerequisites ready.$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)Review config/config.env, config/db.config.env, and config/mail.config.env.$(COLOR_RESET)"

doctor:
	@failed=0; \
	echo "$(COLOR_BOLD)MATA installation check$(COLOR_RESET)"; \
	echo "  Mode:        $(MODE)"; \
	echo "  Environment: $(ENV)"; \
	case "$(MODE)" in native|docker) ;; *) echo "$(COLOR_RED)  ✗ Invalid or missing mode$(COLOR_RESET)"; failed=1 ;; esac; \
	case "$(ENV)" in dev|demo|prod) ;; *) echo "$(COLOR_RED)  ✗ Invalid or missing environment$(COLOR_RESET)"; failed=1 ;; esac; \
	for file in config/config.env config/db.config.env config/mail.config.env; do \
		if [ -f "$$file" ]; then echo "$(COLOR_GREEN)  ✓ $$file$(COLOR_RESET)"; else echo "$(COLOR_RED)  ✗ $$file missing$(COLOR_RESET)"; failed=1; fi; \
	done; \
	config_env=$(call GET_ENV_VALUE,config/config.env,APP_ENV); \
	if [ "$$config_env" = "$(ENV)" ]; then echo "$(COLOR_GREEN)  ✓ config APP_ENV=$(ENV)$(COLOR_RESET)"; else echo "$(COLOR_RED)  ✗ config APP_ENV=$${config_env:-missing}, expected $(ENV)$(COLOR_RESET)"; failed=1; fi; \
	if [ "$(MODE)" = "native" ]; then \
		command -v php >/dev/null 2>&1 && echo "$(COLOR_GREEN)  ✓ PHP available$(COLOR_RESET)" || { echo "$(COLOR_RED)  ✗ PHP missing$(COLOR_RESET)"; failed=1; }; \
		[ -f vendor/autoload.php ] && echo "$(COLOR_GREEN)  ✓ Composer dependencies installed$(COLOR_RESET)" || { echo "$(COLOR_RED)  ✗ vendor/autoload.php missing$(COLOR_RESET)"; failed=1; }; \
		if systemctl is-active --quiet opa.service 2>/dev/null; then echo "$(COLOR_GREEN)  ✓ OPA running$(COLOR_RESET)"; else echo "$(COLOR_YELLOW)  ! OPA not running$(COLOR_RESET)"; fi; \
	elif [ "$(MODE)" = "docker" ]; then \
		command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1 && echo "$(COLOR_GREEN)  ✓ Docker Compose plugin available$(COLOR_RESET)" || { echo "$(COLOR_RED)  ✗ Docker or Compose plugin missing$(COLOR_RESET)"; failed=1; }; \
		if [ -f .env ]; then \
			docker_env=$(call GET_ENV_VALUE,.env,APP_ENV); \
			[ "$$docker_env" = "$(ENV)" ] && echo "$(COLOR_GREEN)  ✓ .env APP_ENV=$(ENV)$(COLOR_RESET)" || { echo "$(COLOR_RED)  ✗ .env APP_ENV=$${docker_env:-missing}, expected $(ENV)$(COLOR_RESET)"; failed=1; }; \
		else echo "$(COLOR_RED)  ✗ .env missing$(COLOR_RESET)"; failed=1; fi; \
	fi; \
	for dir in config logs temp; do \
		if [ -d "$$dir" ]; then owner=$$(stat -L -c '%U:%G' "$$dir" 2>/dev/null || stat -L -f '%Su:%Sg' "$$dir" 2>/dev/null || echo unknown); echo "$(COLOR_GREEN)  ✓ $$dir ($$owner)$(COLOR_RESET)"; else echo "$(COLOR_RED)  ✗ $$dir missing$(COLOR_RESET)"; failed=1; fi; \
	done; \
	if [ -L config ]; then echo "$(COLOR_GREEN)  ✓ config → $$(CDPATH= cd -- config && pwd -P) (symlink)$(COLOR_RESET)"; fi; \
	if [ -e config/.git ]; then \
		if changes=$$(git -C config status --porcelain 2>/dev/null); then \
			echo "$(COLOR_GREEN)  ✓ config is a git repository ($$(printf '%s' "$$changes" | grep -c .) uncommitted changes)$(COLOR_RESET)"; \
		else echo "$(COLOR_YELLOW)  ! config is a git repository that git cannot read as $$(id -un); check its owner or safe.directory$(COLOR_RESET)"; fi; \
	fi; \
	if [ -f config/servers.json ]; then \
		if [ ! -r config/servers.json ]; then echo "$(COLOR_YELLOW)  ! config/servers.json is not readable by $$(id -un); API key placement not checked$(COLOR_RESET)"; \
		elif grep -q '"api_key"[[:space:]]*:' config/servers.json; then echo "$(COLOR_YELLOW)  ! config/servers.json contains api_key; servers keeping it there are rejected, move their keys to config/servers.secrets.json$(COLOR_RESET)"; fi; \
		if [ -f config/servers.secrets.json ]; then echo "$(COLOR_GREEN)  ✓ config/servers.secrets.json$(COLOR_RESET)"; else echo "$(COLOR_RED)  ✗ config/servers.secrets.json missing$(COLOR_RESET)"; failed=1; fi; \
	fi; \
	if [ "$$failed" -ne 0 ]; then exit $$failed; fi; \
	echo "$(COLOR_BOLD)Application checks$(COLOR_RESET)"; \
	$(call RUN_MATA,doctor)

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

# Start OPA according to the mode selected by make setup.
opa-start:
	@$(REQUIRE_SELECTION); \
	if [ "$(MODE)" = "native" ]; then \
		[ -f /etc/systemd/system/opa.service ] || { echo "$(COLOR_RED)❌ OPA service is not installed. Run: make opa-install-service$(COLOR_RESET)"; exit 1; }; \
		sudo systemctl start opa.service; \
	else \
		$(REQUIRE_DOCKER_MODE); \
		$(SELECTED_COMPOSE) up -d mata-opa; \
	fi

opa-stop:
	@$(REQUIRE_SELECTION); \
	if [ "$(MODE)" = "native" ]; then \
		sudo systemctl stop opa.service; \
	else \
		$(REQUIRE_DOCKER_MODE); \
		$(SELECTED_COMPOSE) stop mata-opa; \
	fi

opa-status:
	@$(REQUIRE_SELECTION); \
	echo "$(COLOR_BLUE)Mode: $(MODE) | Environment: $(ENV)$(COLOR_RESET)"; \
	if [ "$(MODE)" = "native" ]; then \
		systemctl --no-pager --full status opa.service; \
	else \
		$(REQUIRE_DOCKER_MODE); \
		$(SELECTED_COMPOSE) ps mata-opa; \
	fi

opa-test:
	@$(REQUIRE_SELECTION); \
	if [ "$(MODE)" = "native" ]; then \
		[ -x "$(OPA_BIN)" ] || { echo "$(COLOR_RED)❌ OPA binary missing. Run make setup-opa.$(COLOR_RESET)"; exit 1; }; \
		"$(OPA_BIN)" test policy; \
	else \
		$(REQUIRE_DOCKER_MODE); \
		$(SELECTED_COMPOSE) run --rm --no-deps --entrypoint /opa mata-opa test /policies; \
	fi

# Each example file is validated only against its own policy namespace; policies
# without a matching example (and users.json, which has no policy) are skipped.
POLICY_VALIDATE_PAIRS := \
	thresholds:config.thresholds \
	servers:config.servers \
	ttl:config.ttl \
	data_retention:config.data_retention \
	intervals:config.intervals

opa-validate:
	@if [ -x "$(OPA_BIN)" ]; then \
		OPA="$(OPA_BIN)"; \
	elif command -v opa >/dev/null 2>&1; then \
		OPA="$$(command -v opa)"; \
	elif command -v docker >/dev/null 2>&1; then \
		OPA="docker run --rm -v $(CURDIR):/project:ro -w /project openpolicyagent/opa:latest"; \
	else \
		echo "$(COLOR_RED)❌ OPA not found. Run: make setup-opa$(COLOR_RESET)"; \
		exit 1; \
	fi; \
	status=0; \
	for pair in $(POLICY_VALIDATE_PAIRS); do \
		file="config.example/$${pair%%:*}.json.example"; \
		ns="$${pair#*:}"; \
		echo "$(COLOR_BLUE)opa: $$file ($$ns)$(COLOR_RESET)"; \
		result=$$($$OPA eval --format raw --data policy --input "$$file" "data.$$ns.deny") || { status=1; continue; }; \
		if [ "$$result" != "[]" ]; then \
			echo "$(COLOR_RED)❌ $$file failed policy validation$(COLOR_RESET)"; \
			printf '%s\n' "$$result"; \
			status=1; \
		fi; \
	done; \
	exit $$status

# ============================================
# Testing
# ============================================

test:
	@$(REQUIRE_SELECTION); \
	if [ "$(ENV)" = "prod" ] && [ "$(ALLOW_PROD_TESTS)" != "CONFIRM_PRODUCTION" ]; then \
		echo "$(COLOR_RED)❌ Tests are blocked in production. Set ALLOW_PROD_TESTS=CONFIRM_PRODUCTION to override.$(COLOR_RESET)"; exit 1; \
	fi; \
	echo "$(COLOR_BLUE)Mode: $(MODE) | Environment: $(ENV)$(COLOR_RESET)"; \
	if [ "$(MODE)" = "native" ]; then \
		[ -x vendor/bin/phpunit ] || { echo "$(COLOR_RED)❌ vendor/bin/phpunit is missing. Run composer install.$(COLOR_RESET)"; exit 1; }; \
		APP_ENV="$(ENV)" vendor/bin/phpunit; \
	else \
		$(REQUIRE_DOCKER_MODE); $(REQUIRE_WEB_RUNNING); \
		$(SELECTED_COMPOSE) exec -e APP_ENV="$(ENV)" -u "$$(id -u):$$(id -g)" mata-web vendor/bin/phpunit; \
	fi

test-coverage:
	@$(REQUIRE_SELECTION); \
	if [ "$(ENV)" = "prod" ] && [ "$(ALLOW_PROD_TESTS)" != "CONFIRM_PRODUCTION" ]; then \
		echo "$(COLOR_RED)❌ Coverage is blocked in production.$(COLOR_RESET)"; exit 1; \
	fi; \
	if [ "$(MODE)" = "native" ]; then \
		[ -x vendor/bin/phpunit ] || { echo "$(COLOR_RED)❌ vendor/bin/phpunit is missing.$(COLOR_RESET)"; exit 1; }; \
		APP_ENV="$(ENV)" vendor/bin/phpunit --coverage-html coverage; \
	else \
		$(REQUIRE_DOCKER_MODE); $(REQUIRE_WEB_RUNNING); \
		$(SELECTED_COMPOSE) exec -e APP_ENV="$(ENV)" -u "$$(id -u):$$(id -g)" mata-web vendor/bin/phpunit --coverage-html coverage; \
	fi

# ============================================
# Database Management
# ============================================

define REQUIRE_NATIVE_PHP
command -v php >/dev/null 2>&1 || { echo "$(COLOR_RED)❌ PHP is unavailable.$(COLOR_RESET)"; exit 1; }; \
[ -f vendor/autoload.php ] || { echo "$(COLOR_RED)❌ Composer dependencies are missing.$(COLOR_RESET)"; exit 1; };
endef

MATA_NO_INTERACTION := $(if $(NONINTERACTIVE_ON),--no-interaction)

# Operator CLI (bin/mata) as the account the app runs as: www-data in Docker, natively the owner
# of logs/ (as for make logs). With as_owner=yes, as the owner of config/ instead; in Docker that
# is a one-off container with config/ writable, since production mounts it read-only into the
# web container. A terminal is passed through so prompts work; pipes and scripts get none.
define RUN_MATA
$(REQUIRE_SELECTION); \
if [ "$(MODE)" = "native" ]; then \
	$(REQUIRE_NATIVE_PHP) \
	dir=logs; [ "$${as_owner:-}" = yes ] && dir=config; \
	owner=$$(stat -L -c '%U' "$$dir" 2>/dev/null || stat -L -f '%Su' "$$dir" 2>/dev/null || id -un); \
	if [ "$$owner" = "$$(id -un)" ]; then php bin/mata $(1) $(MATA_NO_INTERACTION); \
	else \
		command -v sudo >/dev/null 2>&1 || { echo "$(COLOR_RED)❌ Run as $$owner, the owner of $$dir/.$(COLOR_RESET)"; exit 1; }; \
		$(SUDO) -u "$$owner" php bin/mata $(1) $(MATA_NO_INTERACTION); \
	fi; \
else \
	$(REQUIRE_DOCKER_MODE); $(REQUIRE_WEB_RUNNING); \
	tty=-T; $(INTERACTIVE) && [ -t 1 ] && tty=; \
	how="exec -u www-data"; \
	[ "$${as_owner:-}" = yes ] && how="-f docker-compose.config-write.yml run --rm --no-deps -u $$(id -u):$$(id -g)"; \
	$(SELECTED_COMPOSE) $$how $$tty mata-web php bin/mata $(1) $(MATA_NO_INTERACTION); \
fi
endef

# Everything the application does is a bin/mata command; this runs one for the selected
# installation, e.g. make mata ARGS="user create admin admin". Without ARGS it lists them.
# server add/remove write config/, which the web server can only read: they run as its owner,
# in Docker in a one-off container (about a second longer; every other command uses exec).
mata:
	@set -- $(ARGS); \
	case "$${1:-} $${2:-}" in \
		"server add"|"server remove"|"server:add "*|"server:remove "*) as_owner=yes ;; \
	esac; \
	$(call RUN_MATA,"$$@")

# Kept as a target because deployments run it; the confirmation happens here, not in the container.
db-migrate:
	@$(call CONFIRM_PRODUCTION_WRITE,database migration); \
	$(call RUN_MATA,db:migrate --yes)

db-backup:
	@$(REQUIRE_SELECTION); \
	echo "$(COLOR_BLUE)Mode: $(MODE) | Environment: $(ENV)$(COLOR_RESET)"; \
	if [ "$(MODE)" = "native" ]; then $(REQUIRE_NATIVE_PHP) fi; \
	file="$(FILE)"; [ -n "$$file" ] || file="backups/backup-$$(date +%Y%m%d-%H%M%S).sql"; \
	mkdir -p "$$(dirname "$$file")"; \
	echo "$(COLOR_BLUE)Backing up $(ENV) database to $$file$(COLOR_RESET)"; \
	if [ "$(MODE)" = "native" ]; then \
		APP_ENV="$(ENV)" php scripts/database/mysql-operation.php backup "$$file" >/dev/null || exit $$?; \
	else \
		$(REQUIRE_DOCKER_MODE); $(REQUIRE_DB_RUNNING); \
		tmp="$$file.$$$$.tmp"; trap 'rm -f "$$tmp"' EXIT INT TERM; \
		$(SELECTED_COMPOSE) exec -T mata-db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" exec mysqldump -u root --single-transaction --quick --skip-lock-tables --no-tablespaces "$$MYSQL_DATABASE"' > "$$tmp"; \
		chmod 600 "$$tmp"; mv "$$tmp" "$$file"; trap - EXIT INT TERM; \
	fi; \
	echo "$(COLOR_GREEN)✅ Backup saved: $$file$(COLOR_RESET)"

db-restore:
	@$(REQUIRE_SELECTION); \
	echo "$(COLOR_BLUE)Mode: $(MODE) | Environment: $(ENV)$(COLOR_RESET)"; \
	if [ "$(MODE)" = "native" ]; then $(REQUIRE_NATIVE_PHP) fi; \
	file="$(FILE)"; \
	[ -n "$$file" ] || { echo "$(COLOR_RED)❌ Usage: make db-restore FILE=backups/example.sql$(COLOR_RESET)"; exit 1; }; \
	[ -f "$$file" ] && [ -r "$$file" ] && [ -s "$$file" ] || { echo "$(COLOR_RED)❌ Backup must be a non-empty readable file: $$file$(COLOR_RESET)"; exit 1; }; \
	if [ "$(MODE)" = "native" ]; then \
		db_host=$$(awk -F= '/^DB_HOST=/{sub(/^[^=]*=/, ""); gsub(/["[:space:]]/, ""); print; exit}' config/db.config.env); \
		db_name=$$(awk -F= '/^DB_DATABASE=/{sub(/^[^=]*=/, ""); gsub(/["[:space:]]/, ""); print; exit}' config/db.config.env); \
		target="host=$$db_host, database=$$db_name"; \
	else \
		target="Compose service mata-db"; \
	fi; \
	echo "$(COLOR_RED)⚠️  Restore mode=$(MODE), environment=$(ENV), $$target, file=$$file$(COLOR_RESET)"; \
	if [ "$(ENV)" = "prod" ]; then expected="CONFIRM PRODUCTION RESTORE"; else expected="CONFIRM RESTORE"; fi; \
	if [ "$(ALLOW_RESTORE)" != "$$expected" ]; then \
		$(INTERACTIVE) || { echo "$(COLOR_RED)❌ No terminal to confirm; pass ALLOW_RESTORE='$$expected'.$(COLOR_RESET)"; exit 1; }; \
		printf "Type '$$expected' to continue: "; read -r confirmation; [ "$$confirmation" = "$$expected" ] || { echo "Cancelled."; exit 1; }; fi; \
	if [ "$(MODE)" = "native" ]; then \
		APP_ENV="$(ENV)" php scripts/database/mysql-operation.php restore "$$file"; \
	else \
		$(REQUIRE_DOCKER_MODE); $(REQUIRE_DB_RUNNING); \
		$(SELECTED_COMPOSE) exec -T mata-db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" exec mysql -u root "$$MYSQL_DATABASE"' < "$$file"; \
	fi; \
	echo "$(COLOR_GREEN)✅ Database restored.$(COLOR_RESET)"

db-shell:
	@$(REQUIRE_SELECTION); \
	echo "$(COLOR_BLUE)Mode: $(MODE) | Environment: $(ENV)$(COLOR_RESET)"; \
	if [ "$(MODE)" = "native" ]; then \
		$(REQUIRE_NATIVE_PHP) \
		APP_ENV="$(ENV)" php scripts/database/mysql-operation.php shell; \
	else \
		$(REQUIRE_DOCKER_MODE); $(REQUIRE_DB_RUNNING); \
		$(SELECTED_COMPOSE) exec mata-db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" exec mysql -u root "$$MYSQL_DATABASE"'; \
	fi

# ============================================
# Build & Release
# ============================================

# ============================================
# Documentation Targets
# ============================================

# Copy MATA Node reference pages into docs/node/
docs-node:
	@scripts/docs/sync-node-docs.sh

# Serve documentation locally
docs-serve: docs-node
	@echo "$(COLOR_YELLOW)ℹ️  Requires mkdocs in PATH (e.g., via pipx).$(COLOR_RESET)"
	mkdocs serve -a localhost:3330

# Deploy documentation to GitHub Pages
docs-deploy: docs-node
	mkdocs gh-deploy --force

# ============================================
# Docker Targets
# ============================================

## Setup & Installation

# Shared Docker initialization. Does not create .env, users, or services.
docker-init:
	@set -e; $(REQUIRE_DOCKER_AVAILABLE); \
	echo "$(COLOR_BLUE)🐳 Initializing Docker prerequisites...$(COLOR_RESET)"; \
	docker network inspect mata-network >/dev/null 2>&1 || docker network create mata-network >/dev/null; \
	php_version=$(call GET_ENV_VALUE,.env,PHP_VERSION); php_image="php:$${php_version:-8.3}-apache-trixie"; \
	if ! mkdir -p config/demo temp/api-cache temp/sessions temp/templates-cache logs 2>/dev/null; then \
		echo "$(COLOR_YELLOW)Writable paths belong to another user; preparing them through a scoped root container.$(COLOR_RESET)"; \
		docker run --rm -v "$(CURDIR)/config:/mata/config" -v "$(CURDIR)/temp:/mata/temp" -v "$(CURDIR)/logs:/mata/logs" "$$php_image" \
			sh -c 'mkdir -p /mata/config/demo /mata/temp/api-cache /mata/temp/sessions /mata/temp/templates-cache /mata/logs'; \
	fi; \
	for file in config.env db.config.env mail.config.env servers.json servers.secrets.json ttl.json thresholds.json intervals.json data_retention.json; do \
		if [ ! -f "config/$$file" ]; then \
			if cp "config.example/$${file}.example" "config/$$file" 2>/dev/null; then case "$$file" in *.env|servers.secrets.json) chmod 600 "config/$$file" ;; *) chmod 644 "config/$$file" ;; esac; \
			else docker run --rm -v "$(CURDIR)/config:/mata/config" -v "$(CURDIR)/config.example:/mata/config.example:ro" "$$php_image" sh -c "cp /mata/config.example/$${file}.example /mata/config/$$file && chown $$(id -u):$$(id -g) /mata/config/$$file && case '$$file' in *.env|servers.secrets.json) chmod 600 /mata/config/$$file ;; *) chmod 644 /mata/config/$$file ;; esac"; fi; \
			echo "$(COLOR_GREEN)✅ Created config/$$file$(COLOR_RESET)"; \
		fi; \
	done; \
	echo "$(COLOR_GREEN)✅ Docker prerequisites ready; no user was created.$(COLOR_RESET)"

dev-setup: docker-init
	@$(REQUIRE_DEV_ENV)
	@if [ ! -f .env ]; then cp .env.example .env; chmod 600 .env; echo "$(COLOR_GREEN)✅ Created .env$(COLOR_RESET)"; fi
	@actual=$(call GET_ENV_VALUE,.env,APP_ENV); \
	config_actual=$(call GET_ENV_VALUE,config/config.env,APP_ENV); \
	[ "$$actual" = "$(ENV)" ] && [ "$$config_actual" = "$(ENV)" ] || { echo "$(COLOR_RED)❌ APP_ENV mismatch (.env=$${actual:-missing}, config=$${config_actual:-missing}, expected $(ENV)). Run make setup.$(COLOR_RESET)"; exit 1; }
	@$(DEV_COMPOSE) config --quiet

prod-setup: docker-init
	@[ -f .env ] || { echo "$(COLOR_RED)❌ Production requires an existing .env. Run make setup first.$(COLOR_RESET)"; exit 1; }
	@set -e; actual=$(call GET_ENV_VALUE,.env,APP_ENV); \
	config_actual=$(call GET_ENV_VALUE,config/config.env,APP_ENV); \
	[ "$(ENV)" = "prod" ] && [ "$$actual" = "prod" ] && [ "$$config_actual" = "prod" ] || { echo "$(COLOR_RED)❌ prod-setup requires ENV=prod and APP_ENV=prod in .env and config/config.env.$(COLOR_RESET)"; exit 1; }; \
	if grep -q 'change_me' .env config/db.config.env; then echo "$(COLOR_RED)❌ Replace placeholder database passwords before production startup.$(COLOR_RESET)"; exit 1; fi; \
	data_path=$$(awk -F= '/^DATA_PATH=/{sub(/^[^=]*=/, ""); gsub(/["[:space:]]/, ""); print; exit}' .env); data_path=$${data_path:-./data}; \
	case "$$data_path" in /*) ;; *) data_path="$(CURDIR)/$${data_path#./}" ;; esac; \
	mkdir -p "$$data_path/mysql" logs temp; \
	$(PROD_COMPOSE) config --quiet; \
	echo "$(COLOR_GREEN)✅ Production Docker prerequisites validated.$(COLOR_RESET)"

# Run after initial provisioning, or only to repair bind-mount permissions.
prod-fix-perms: prod-setup
	@$(REQUIRE_DOCKER_MODE); [ "$(ENV)" = prod ] || { echo "$(COLOR_RED)❌ prod-fix-perms requires ENV=prod.$(COLOR_RESET)"; exit 1; }
	@set -e; \
	data_path=$$(awk -F= '/^DATA_PATH=/{sub(/^[^=]*=/, ""); gsub(/["[:space:]]/, ""); print; exit}' .env); data_path=$${data_path:-./data}; \
	case "$$data_path" in /*) ;; *) data_path="$(CURDIR)/$${data_path#./}" ;; esac; \
	php_version=$$(awk -F= '/^PHP_VERSION=/{gsub(/["[:space:]]/, "", $$2); print $$2; exit}' .env); php_version=$${php_version:-8.3}; \
	docker run --rm \
		-v "$(CURDIR)/config:/mata/config" \
		-v "$(CURDIR)/logs:/mata/logs" \
		-v "$(CURDIR)/temp:/mata/temp" \
		"php:$${php_version}-apache-trixie" sh -c \
		'chgrp -R www-data /mata/config \
		&& find /mata/config -type d -exec chmod 0750 {} + \
		&& find /mata/config -type f -exec chmod 0640 {} + \
		&& chown -R www-data:www-data /mata/logs /mata/temp \
		&& find /mata/logs -type d -exec chmod 0775 {} + \
		&& find /mata/logs -type f -exec chmod 0660 {} + \
		&& { [ ! -e /mata/logs/.gitkeep ] || chmod 0644 /mata/logs/.gitkeep; } \
		&& chmod -R u+rwX,g+rwX,o-rwx /mata/temp \
		&& chmod 0775 /mata/temp'; \
	docker run --rm -v "$$data_path/mysql:/var/lib/mysql" mysql:8.0 sh -c 'chown -R mysql:mysql /var/lib/mysql && chmod 0750 /var/lib/mysql'; \
	echo "$(COLOR_GREEN)✅ Production bind-mount permissions repaired.$(COLOR_RESET)"

# Compatibility alias. Prefer guided setup or dev-setup.
docker-setup:
	@echo "$(COLOR_YELLOW)⚠️  docker-setup is deprecated; use dev-setup or prod-setup.$(COLOR_RESET)"
	@case "$(ENV)" in dev|demo) $(MAKE) dev-setup MODE=docker ENV=$(ENV) ;; *) echo "$(COLOR_RED)❌ docker-setup only supports dev/demo. Use prod-setup for production.$(COLOR_RESET)"; exit 1 ;; esac

# Diagnostic/recovery target; `up` relies on Compose depends_on, but `restart` ignores it.
wait-db:
	@$(REQUIRE_DOCKER_MODE); \
	container=$$($(SELECTED_COMPOSE) ps -q mata-db); [ -n "$$container" ] || { echo "$(COLOR_RED)❌ mata-db container does not exist.$(COLOR_RESET)"; exit 1; }; \
	echo "$(COLOR_BLUE)Waiting for MySQL health...$(COLOR_RESET)"; \
	attempt=0; while [ $$attempt -lt 60 ]; do \
		state=$$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$$container" 2>/dev/null || echo missing); \
		[ "$$state" = healthy ] && { echo "$(COLOR_GREEN)✅ MySQL is healthy.$(COLOR_RESET)"; exit 0; }; \
		attempt=$$((attempt + 1)); sleep 2; \
	done; \
	echo "$(COLOR_RED)❌ MySQL did not become healthy (last state: $$state).$(COLOR_RESET)"; docker inspect --format '{{json .State.Health}}' "$$container" 2>/dev/null || true; exit 1

## Development (Docker)

# Dev MySQL lives on tmpfs, so every target that (re)starts it gets an empty schema.
define DEV_MIGRATE
if [ "$(RUN_MIGRATIONS)" != "no" ]; then $(MAKE) db-migrate MODE=docker ENV=$(ENV) DOCKER_DAEMON_CHECKED=1; else echo "$(COLOR_YELLOW)⚠️  Database migrations skipped.$(COLOR_RESET)"; fi
endef

dev-start:
	@$(REQUIRE_DOCKER_MODE); $(REQUIRE_DEV_ENV)
	@$(MAKE) dev-setup MODE=docker ENV=$(ENV) DOCKER_DAEMON_CHECKED=1
	@$(DEV_COMPOSE) up -d --build
	@$(DEV_MIGRATE)
	@PORT=$$(awk -F= '/^WEB_PORT=/{print $$2; exit}' .env); PORT=$${PORT:-3300}; \
	echo "$(COLOR_GREEN)✅ $(ENV) environment running.$(COLOR_RESET)"; \
	echo "$(COLOR_CYAN)Dashboard: http://localhost:$$PORT$(COLOR_RESET)"; \
	echo "$(COLOR_YELLOW)No default login. Create one with: make mata ARGS=\"user create <name> admin\"$(COLOR_RESET)"

dev-logs:
	@$(REQUIRE_DOCKER_MODE); $(REQUIRE_DEV_ENV)
	@$(DEV_COMPOSE) up -d --build
	@$(DEV_COMPOSE) logs -f

dev-stop:
	@$(REQUIRE_DOCKER_MODE); $(REQUIRE_DEV_ENV)
	@$(DEV_COMPOSE) down

dev-restart:
	@$(REQUIRE_DOCKER_MODE); $(REQUIRE_DEV_ENV)
	@$(DEV_COMPOSE) restart
	@$(MAKE) wait-db MODE=docker ENV=$(ENV) DOCKER_DAEMON_CHECKED=1
	@$(DEV_MIGRATE)

dev-recreate:
	@$(REQUIRE_DOCKER_MODE); $(REQUIRE_DEV_ENV)
	@$(DEV_COMPOSE) up -d --force-recreate --no-build
	@$(DEV_MIGRATE)

dev-rebuild:
	@$(REQUIRE_DOCKER_MODE); $(REQUIRE_DEV_ENV)
	@$(DEV_COMPOSE) down
	@$(DEV_COMPOSE) build --no-cache --pull
	@$(DEV_COMPOSE) up -d
	@$(DEV_MIGRATE)

## Production (Docker)

prod-start:
	@$(REQUIRE_DOCKER_MODE); [ "$(ENV)" = prod ] || { echo "$(COLOR_RED)❌ prod-start requires ENV=prod.$(COLOR_RESET)"; exit 1; }
	@$(MAKE) prod-setup MODE=docker ENV=prod DOCKER_DAEMON_CHECKED=1
	@$(PROD_COMPOSE) up -d --build
	@if [ "$(RUN_MIGRATIONS)" != "no" ]; then \
		echo "$(COLOR_YELLOW)⚠️  Running production migrations without further confirmation (authorized by prod-start).$(COLOR_RESET)"; \
		$(MAKE) db-migrate MODE=docker ENV=prod ALLOW_PRODUCTION=CONFIRM_PRODUCTION DOCKER_DAEMON_CHECKED=1; \
	else \
		echo "$(COLOR_YELLOW)⚠️  Database migrations skipped.$(COLOR_RESET)"; \
	fi
	@echo "$(COLOR_GREEN)✅ Production deployed.$(COLOR_RESET)"

prod-stop:
	@$(REQUIRE_DOCKER_MODE); [ "$(ENV)" = prod ] || { echo "$(COLOR_RED)❌ prod-stop requires ENV=prod.$(COLOR_RESET)"; exit 1; }
	@$(PROD_COMPOSE) down

prod-restart:
	@$(REQUIRE_DOCKER_MODE); [ "$(ENV)" = prod ] || { echo "$(COLOR_RED)❌ prod-restart requires ENV=prod.$(COLOR_RESET)"; exit 1; }
	@$(PROD_COMPOSE) restart

prod-rebuild:
	@$(REQUIRE_DOCKER_MODE); [ "$(ENV)" = prod ] || { echo "$(COLOR_RED)❌ prod-rebuild requires ENV=prod.$(COLOR_RESET)"; exit 1; }
	@$(MAKE) prod-setup MODE=docker ENV=prod
	@$(PROD_COMPOSE) build --no-cache --pull
	@$(PROD_COMPOSE) up -d

## Logs & Monitoring

status:
	@$(REQUIRE_SELECTION); \
	echo "$(COLOR_BOLD)Mode: $(MODE) | Environment: $(ENV)$(COLOR_RESET)"; \
	if [ "$(MODE)" = "native" ]; then \
		command -v php >/dev/null 2>&1 && php -v | head -1 || true; \
		if systemctl is-active --quiet opa.service 2>/dev/null; then echo "OPA: running"; else echo "OPA: stopped or unmanaged"; fi; \
		app_log=$$(awk -F= '/^APP_LOG_PATH=/{sub(/^[^=]*=/, ""); gsub(/^"|"$$/, ""); print; exit}' config/config.env); app_log=$${app_log:-logs/app.log}; \
		for path in "$$app_log" logs/php-error.log; do [ -e "$$path" ] && echo "Log: $$path" || true; done; \
	else \
		$(REQUIRE_DOCKER_MODE); $(SELECTED_COMPOSE) ps; \
	fi

logs:
	@$(REQUIRE_SELECTION); \
	if [ "$(MODE)" = "native" ]; then \
		owner=$$(stat -c '%U' logs 2>/dev/null || stat -f '%Su' logs 2>/dev/null || id -un); \
		app_file=$$(awk -F= '/^APP_LOG_PATH=/{sub(/^[^=]*=/, ""); gsub(/^"|"$$/, ""); print; exit}' config/config.env); app_file=$${app_file:-logs/app.log}; \
		if [ -r logs ]; then \
			files=""; for file in "$$app_file" logs/php-error.log; do [ -f "$$file" ] && files="$$files $$file"; done; \
			[ -n "$$files" ] || { echo "$(COLOR_RED)❌ No application logs found.$(COLOR_RESET)"; exit 1; }; tail -n 500 -F $$files; \
		else \
			command -v sudo >/dev/null 2>&1 || { echo "$(COLOR_RED)❌ Run as $$owner to read logs.$(COLOR_RESET)"; exit 1; }; \
			sudo -u "$$owner" sh -c 'app_file=$$1; set --; [ -f "$$app_file" ] && set -- "$$@" "$$app_file"; [ -f logs/php-error.log ] && set -- "$$@" logs/php-error.log; [ "$$#" -gt 0 ] || { echo "No application logs found." >&2; exit 1; }; exec tail -n 500 -F "$$@"' sh "$$app_file"; \
		fi; \
	else \
		$(REQUIRE_DOCKER_MODE); $(SELECTED_COMPOSE) logs -f; \
	fi

logs-web:
	@$(REQUIRE_DOCKER_MODE); $(SELECTED_COMPOSE) logs -f mata-web

logs-db:
	@$(REQUIRE_DOCKER_MODE); $(SELECTED_COMPOSE) logs -f mata-db

logs-php:
	@$(REQUIRE_SELECTION); \
	if [ "$(MODE)" = native ]; then file=logs/php-error.log; owner=$$(stat -c '%U' logs 2>/dev/null || stat -f '%Su' logs 2>/dev/null || id -un); \
		if [ -r "$$file" ]; then tail -n 500 -F "$$file"; else sudo -u "$$owner" test -f "$$file" || { echo "$(COLOR_RED)❌ $$file not found.$(COLOR_RESET)"; exit 1; }; sudo -u "$$owner" tail -n 500 -F "$$file"; fi; \
	else $(REQUIRE_DOCKER_MODE); $(REQUIRE_WEB_RUNNING); $(SELECTED_COMPOSE) exec -u www-data mata-web tail -n 500 -F logs/php-error.log; fi

logs-app:
	@$(REQUIRE_SELECTION); \
	if [ "$(MODE)" = native ]; then file=$$(awk -F= '/^APP_LOG_PATH=/{sub(/^[^=]*=/, ""); gsub(/^"|"$$/, ""); print; exit}' config/config.env); file=$${file:-logs/app.log}; owner=$$(stat -c '%U' logs 2>/dev/null || stat -f '%Su' logs 2>/dev/null || id -un); \
		if [ -r "$$file" ]; then tail -n 500 -F "$$file"; else sudo -u "$$owner" test -f "$$file" || { echo "$(COLOR_RED)❌ $$file not found.$(COLOR_RESET)"; exit 1; }; sudo -u "$$owner" tail -n 500 -F "$$file"; fi; \
	else $(REQUIRE_DOCKER_MODE); $(REQUIRE_WEB_RUNNING); $(SELECTED_COMPOSE) exec -u www-data mata-web sh -c 'file=$$(awk -F= '\''/^APP_LOG_PATH=/{sub(/^[^=]*=/, ""); gsub(/^"|"$$/, ""); print; exit}'\'' config/config.env); exec tail -n 500 -F "$${file:-logs/app.log}"'; fi

ps:
	@$(REQUIRE_DOCKER_MODE); $(SELECTED_COMPOSE) ps

## Shell Access

shell:
	@$(REQUIRE_DOCKER_MODE); $(REQUIRE_WEB_RUNNING); $(SELECTED_COMPOSE) exec mata-web bash

shell-www:
	@$(REQUIRE_DOCKER_MODE); $(REQUIRE_WEB_RUNNING); $(SELECTED_COMPOSE) exec -u www-data mata-web bash

## Cleanup

# Stop the selected Docker stack.
down:
	@$(REQUIRE_DOCKER_MODE); $(SELECTED_COMPOSE) down

# Remove the selected stack including volumes (WARNING: deletes data!).
clean:
	@$(REQUIRE_DOCKER_MODE); \
	echo "$(COLOR_RED)⚠️  This deletes $(ENV) containers, volumes, and database data.$(COLOR_RESET)"; \
	$(CONFIRM_DELETE); \
	$(SELECTED_COMPOSE) down -v; echo "$(COLOR_GREEN)✅ Cleaned $(ENV).$(COLOR_RESET)"

# Remove the selected Docker install: containers, volumes, the built image, and the files the
# containers own (the production database in DATA_PATH/mysql, logs/, temp/), which the host
# account cannot delete itself. Leaves config/ and the checkout, both owned by that account.
uninstall:
	@$(REQUIRE_DOCKER_MODE); \
	data_path=$(call GET_ENV_VALUE,.env,DATA_PATH); data_path=$${data_path:-./data}; \
	case "$$data_path" in /*) ;; *) data_path="$(CURDIR)/$${data_path#./}" ;; esac; \
	php_version=$(call GET_ENV_VALUE,.env,PHP_VERSION); php_version=$${php_version:-8.3}; \
	echo "$(COLOR_RED)⚠️  This deletes the $(ENV) stack (containers, volumes, image), logs/$(if $(filter prod,$(ENV)),$(comma) temp/$(comma) and the database in $$data_path/mysql, and temp/).$(COLOR_RESET)"; \
	$(CONFIRM_DELETE); \
	set -e; \
	$(SELECTED_COMPOSE) down -v --rmi local --remove-orphans; \
	docker network rm mata-network >/dev/null 2>&1 || true; \
	mounts="-v $(CURDIR)/logs:/mata/logs -v $(CURDIR)/temp:/mata/temp"; \
	if [ "$(ENV)" = prod ] && [ -d "$$data_path" ]; then mounts="$$mounts -v $$data_path:/mata/data"; fi; \
	docker run --rm $$mounts "php:$$php_version-apache-trixie" sh -c \
		'rm -rf /mata/data/mysql; for dir in /mata/logs /mata/temp; do find "$$dir" -mindepth 1 -delete; chown "$$0" "$$dir"; done' \
		"$$(id -u):$$(id -g)"; \
	echo "$(COLOR_GREEN)✅ Uninstalled $(ENV). The rest belongs to $$(id -un): config/ (settings and secrets, keep it to reinstall) and this checkout.$(COLOR_RESET)"; \
	echo "   To delete them too: rm -rf $(CURDIR)"

# Docker cleanup for this project only (safe - won't affect other containers)
docker-clean:
	@echo "$(COLOR_YELLOW)🧹 Cleaning up mata-dashboard Docker resources...$(COLOR_RESET)"
	@echo "$(COLOR_CYAN)Stopping and removing containers...$(COLOR_RESET)"
	@APP_ENV=dev $(DOCKER_COMPOSE) -f docker-compose.yml -f docker-compose.dev.yml down -v 2>/dev/null || true
	@APP_ENV=prod $(DOCKER_COMPOSE) -f docker-compose.yml -f docker-compose.prod.yml down -v 2>/dev/null || true
	@echo "$(COLOR_CYAN)Removing mata-dashboard images...$(COLOR_RESET)"
	@docker images --filter "reference=mata-dashboard*" -q | xargs -r docker rmi -f 2>/dev/null || true
	@docker images --filter "reference=$${COMPOSE_PROJECT_NAME:-mata-dashboard}*" -q | xargs -r docker rmi -f 2>/dev/null || true
	@echo "$(COLOR_CYAN)Removing orphaned containers...$(COLOR_RESET)"
	@docker ps -a --filter "name=mata-" -q | xargs -r docker rm -f 2>/dev/null || true
	@echo "$(COLOR_GREEN)✅ mata-dashboard Docker resources cleaned$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)💡 Other Docker projects are unaffected$(COLOR_RESET)"

# System-wide Docker cleanup (WARNING: affects ALL Docker resources)
docker-clean-all:
	@echo "$(COLOR_RED)⚠️  This will clean up ALL Docker resources (not just mata-dashboard)!$(COLOR_RESET)"
	@$(INTERACTIVE) || { echo "$(COLOR_RED)❌ Needs a terminal to confirm.$(COLOR_RESET)"; exit 1; }; \
	read -p "Are you sure? [y/N]: " -n 1 -r; \
	echo; \
	if [ "$$REPLY" = "y" ] || [ "$$REPLY" = "Y" ]; then \
		docker system prune -a; \
		echo "$(COLOR_GREEN)✅ System-wide cleanup complete$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)Cancelled$(COLOR_RESET)"; exit 1; \
	fi

## Help

# Show available Docker commands
docker-help:
	@echo "$(COLOR_BOLD)$(COLOR_CYAN)Docker Development Commands:$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_BOLD)Setup:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make dev-setup$(COLOR_RESET)       - Validate development Docker prerequisites"
	@echo "  $(COLOR_GREEN)make prod-setup$(COLOR_RESET)      - Validate production Docker prerequisites"
	@echo "  $(COLOR_GREEN)make prod-fix-perms$(COLOR_RESET)  - Repair production bind-mount permissions"
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
	@echo "  $(COLOR_GREEN)make prod-start$(COLOR_RESET)      - Deploy production environment"
	@echo "  $(COLOR_GREEN)make prod-stop$(COLOR_RESET)       - Stop production"
	@echo "  $(COLOR_GREEN)make prod-restart$(COLOR_RESET)    - Restart production"
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
	@echo "  $(COLOR_RED)make clean$(COLOR_RESET)             - Remove containers and volumes (including data!)"
	@echo "  $(COLOR_RED)make uninstall$(COLOR_RESET)         - Also remove the image, database, logs, and temp files"
	@echo "  $(COLOR_YELLOW)make docker-clean$(COLOR_RESET)   - Clean mata-dashboard Docker resources only"
	@echo "  $(COLOR_RED)make docker-clean-all$(COLOR_RESET)  - System-wide Docker cleanup (ALL projects!)"

.PHONY: help help-all setup setup-native doctor mata install-hooks setup-opa opa-install-service opa-start opa-stop opa-status opa-test opa-validate \
	test test-coverage \
	db-migrate db-backup db-restore db-shell \
	docs-node docs-serve docs-deploy \
	docker-init docker-setup dev-setup prod-setup prod-fix-perms wait-db dev-start dev-logs dev-stop dev-restart dev-recreate dev-rebuild \
	prod-start prod-stop prod-restart prod-rebuild \
	status logs logs-web logs-db logs-php logs-app ps \
	shell shell-www \
	down clean uninstall docker-clean docker-clean-all docker-help
