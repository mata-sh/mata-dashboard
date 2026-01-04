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

# ============================================
# Help
# ============================================

# Default target - show help
.DEFAULT_GOAL := help

# Show all available commands
help:
	@echo "$(COLOR_CYAN)=====================================$(COLOR_RESET)"
	@echo "$(COLOR_BOLD)$(COLOR_CYAN)MATA Dashboard - Makefile Commands$(COLOR_RESET)"
	@echo "$(COLOR_CYAN)=====================================$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)ℹ️ Most commands require Docker. Run 'make setup' first.$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)ℹ️ For more commands: $(COLOR_CYAN)make docker-help$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_BOLD)Local General (Git Hooks, Docs, Build):$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make setup$(COLOR_RESET)           - Full setup (composer deps + configs) [$(COLOR_MAGENTA)composer, git$(COLOR_RESET)]"
	@echo "  $(COLOR_GREEN)make install-hooks$(COLOR_RESET)   - Install git hooks [$(COLOR_MAGENTA)git$(COLOR_RESET)]"
	@echo "  $(COLOR_GREEN)make changelog$(COLOR_RESET)       - Generate changelog [$(COLOR_MAGENTA)claude code$(COLOR_RESET)]"
	@echo ""
	@echo "  $(COLOR_GREEN)make docs-serve$(COLOR_RESET)      - Serve docs locally on [$(COLOR_MAGENTA)mkdocs$(COLOR_RESET)]"
	@echo "  $(COLOR_GREEN)make docs-deploy$(COLOR_RESET)     - Deploy docs to GitHub Pages [$(COLOR_MAGENTA)mkdocs$(COLOR_RESET)]"
	@echo "  $(COLOR_GREEN)make docs-ai-update$(COLOR_RESET)  - AI-powered docs update [$(COLOR_MAGENTA)claude code$(COLOR_RESET)]"
	@echo ""
	@echo "$(COLOR_BOLD)Docker Dev Environment:$(COLOR_RESET)"
	@echo "  $(COLOR_CYAN)Setup:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make docker-setup$(COLOR_RESET)  - Docker environment setup only"
	@echo "  $(COLOR_CYAN)Development:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make dev-start$(COLOR_RESET)           - Start full Docker environment"
	@echo "    $(COLOR_GREEN)make dev-logs$(COLOR_RESET)      - Start dev and follow logs"
	@echo "    $(COLOR_GREEN)make dev-stop$(COLOR_RESET)      - Stop development"
	@echo "    $(COLOR_GREEN)make shell$(COLOR_RESET)         - Access web container"
	@echo "  $(COLOR_CYAN)Testing:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make test$(COLOR_RESET)          - Run tests with PHPUnit"
	@echo "    $(COLOR_GREEN)make test-coverage$(COLOR_RESET) - Run tests with coverage"
	@echo "  $(COLOR_CYAN)Database:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make db-migrate$(COLOR_RESET)    - Run database migrations"
	@echo "    $(COLOR_GREEN)make db-rollback$(COLOR_RESET)   - Rollback last migration"
	@echo "    $(COLOR_GREEN)make db-status$(COLOR_RESET)     - Show migration status"
	@echo "    $(COLOR_GREEN)make db-check$(COLOR_RESET)      - Check database integrity"
	@echo "    $(COLOR_GREEN)make db-backup$(COLOR_RESET)     - Backup database to backups/"
	@echo "    $(COLOR_GREEN)make db-restore FILE=backup.sql$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make db-hash-password$(COLOR_RESET) - Generate password hash"
	@echo "  $(COLOR_CYAN)User Management:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make users-list$(COLOR_RESET)    - List all users"
	@echo "    $(COLOR_GREEN)make users-create$(COLOR_RESET)  - Create new user (interactive)"
	@echo "    $(COLOR_GREEN)make users-unlock$(COLOR_RESET)  - Unlock user account"
	@echo "    $(COLOR_GREEN)make users-delete$(COLOR_RESET)  - Delete user"
	@echo "    $(COLOR_GREEN)make users-passwd$(COLOR_RESET)  - Change user password"
	@echo "  $(COLOR_CYAN)Monitoring:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make logs$(COLOR_RESET)          - Follow all container logs"
	@echo "    $(COLOR_GREEN)make ps$(COLOR_RESET)            - Show container status"
	@echo "    $(COLOR_GREEN)make down$(COLOR_RESET)          - Stop all containers"
	@echo "    $(COLOR_GREEN)make logs-app$(COLOR_RESET)      - Follow application log"
	@echo ""
	@echo "$(COLOR_BOLD)Docker Production Environment:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make prod$(COLOR_RESET)            - Deploy production"
	@echo "  $(COLOR_GREEN)make prod-stop$(COLOR_RESET)       - Stop production"
	@echo "  $(COLOR_RED)make clean$(COLOR_RESET)           - Remove everything (WARNING!)"
	@echo ""
	@echo "For more details: $(COLOR_CYAN)make docker-help$(COLOR_RESET)"

# ============================================
# Setup
# ============================================

# Full environment setup (composer + config files)
setup:
	@echo "$(COLOR_BLUE)📋 Creating config files from examples...$(COLOR_RESET)"
	@mkdir -p config temp/api_cache temp/sessions temp/templates_cache logs
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
		composer install; \
	elif [ -f "$$HOME/.composer/composer" ]; then \
		$$HOME/.composer/composer install; \
	else \
		echo "$(COLOR_RED)❌ Composer not found in PATH$(COLOR_RESET)"; \
		echo "$(COLOR_YELLOW)Please install Composer or run manually:$(COLOR_RESET)"; \
		echo "  $(COLOR_CYAN)composer install$(COLOR_RESET)"; \
		echo ""; \
		echo "$(COLOR_YELLOW)Or use Docker instead:$(COLOR_RESET)"; \
		echo "  $(COLOR_CYAN)make docker-setup$(COLOR_RESET)"; \
		exit 1; \
	fi
	@echo "$(COLOR_GREEN)✅ Development environment ready!$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_CYAN)⚙️  NEXT STEPS:$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)1.$(COLOR_RESET) Edit $(COLOR_BOLD)config/config.env$(COLOR_RESET) - set DOMAIN, DB credentials, WATCHER_ENCRYPTION_SECRET"
	@echo "$(COLOR_YELLOW)2.$(COLOR_RESET) Edit $(COLOR_BOLD)config/mail.config.env$(COLOR_RESET) - configure email settings (optional)"
	@echo "$(COLOR_YELLOW)3.$(COLOR_RESET) Choose webserver config from $(COLOR_BOLD)docs/examples/webserver-configs/README.md$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)4.$(COLOR_RESET) Create first user: $(COLOR_CYAN)php scripts/user-management/manage-users.php create$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_BLUE)Or use Docker:$(COLOR_RESET) $(COLOR_CYAN)make docker-setup$(COLOR_RESET) then $(COLOR_CYAN)make dev-start$(COLOR_RESET)"


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

# List all users (in Docker container, run as www-data)
users-list:
	docker-compose exec -u www-data mata-web php scripts/user-management/manage-users.php list

# Create a new user (interactive, in Docker container, run as www-data)
users-create:
	docker-compose exec -u www-data mata-web php scripts/user-management/manage-users.php create

# Unlock a user account (in Docker container, run as www-data)
users-unlock:
	docker-compose exec -u www-data mata-web php scripts/user-management/manage-users.php unlock

# Delete a user (in Docker container, run as www-data)
users-delete:
	docker-compose exec -u www-data mata-web php scripts/user-management/manage-users.php delete

# Change user password (in Docker container, run as www-data)
users-passwd:
	docker-compose exec -u www-data mata-web php scripts/user-management/manage-users.php passwd

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
	mkdocs serve -a 127.0.0.1:8099

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
	@mkdir -p config temp/api_cache temp/sessions temp/templates_cache logs config/dev
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
	@if [ ! -f config/dev/users.json ]; then \
		echo '{"dev":{"username":"dev","password":"$$2y$$10$$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi","role":"admin"},"admin":{"username":"admin","password":"$$2y$$10$$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi","role":"admin"}}' > config/dev/users.json; \
		chmod 600 config/dev/users.json; \
		echo "$(COLOR_GREEN)✅ Created config/dev/users.json (user: dev|pw: password, user: admin|pw: password)$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)⚠️  config/dev/users.json already exists, skipping$(COLOR_RESET)"; \
	fi
	@echo ""
	@echo "$(COLOR_GREEN)✅ Docker environment ready!$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_CYAN)⚙️  NEXT STEPS:$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)1.$(COLOR_RESET) Edit $(COLOR_BOLD).env$(COLOR_RESET) - change DB_PASSWORD, MYSQL_ROOT_PASSWORD to secure values"
	@echo "$(COLOR_YELLOW)2.$(COLOR_RESET) Edit $(COLOR_BOLD)config/config.env$(COLOR_RESET) - set DOMAIN, APP_ENV, WATCHER_ENCRYPTION_SECRET"
	@echo "    $(COLOR_BLUE)Note:$(COLOR_RESET) DB_HOST is auto-set to 'mata-db' for Docker networking"
	@echo "$(COLOR_YELLOW)3.$(COLOR_RESET) Start containers: $(COLOR_CYAN)make dev-start$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)4.$(COLOR_RESET) Login at http://localhost:8080 with $(COLOR_CYAN)dev/password$(COLOR_RESET) or $(COLOR_CYAN)admin/password$(COLOR_RESET)"

## Development (Docker)

# Start development environment
dev-start:
	@echo "$(COLOR_BLUE)🚀 Starting docker development environment...$(COLOR_RESET)"
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
	@echo ""
	@echo "$(COLOR_GREEN)✅ Development environment running!$(COLOR_RESET)"
	@echo "$(COLOR_CYAN)🌐 http://localhost:$${WEB_PORT:-8080}$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)👤 Login: dev/password or admin/password$(COLOR_RESET)"

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
logs-app:
	docker-compose exec -u www-data mata-web tail -fn 500 logs/phperror.log

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
	@echo "  $(COLOR_GREEN)make docker-setup$(COLOR_RESET)    - First-time environment setup"
	@echo ""
	@echo "$(COLOR_BOLD)Development:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make dev-start$(COLOR_RESET)             - Start development environment"
	@echo "  $(COLOR_GREEN)make dev-logs$(COLOR_RESET)        - Start dev and follow logs"
	@echo "  $(COLOR_GREEN)make dev-stop$(COLOR_RESET)        - Stop development environment"
	@echo "  $(COLOR_GREEN)make dev-restart$(COLOR_RESET)     - Restart development containers"
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
	@echo "  $(COLOR_RED)make clean$(COLOR_RESET)           - Remove everything (including data!)"
	@echo "  $(COLOR_YELLOW)make docker-clean$(COLOR_RESET)    - Clean mata-dashboard Docker resources only"
	@echo "  $(COLOR_RED)make docker-clean-all$(COLOR_RESET) - System-wide Docker cleanup (ALL projects!)"

.PHONY: help setup install-hooks \
	test test-coverage \
	db-migrate db-rollback db-status db-check db-hash-password db-migrate-passwords \
	users-list users-create users-unlock users-delete users-passwd \
	changelog bootstrap \
	docs-serve docs-deploy docs-ai-update \
	docker-setup dev dev-logs dev-stop dev-restart dev-rebuild \
	prod prod-stop prod-rebuild \
	logs logs-web logs-db ps \
	shell db-shell shell-www \
	db-backup db-restore \
	down clean docker-clean docker-clean-all docker-help

