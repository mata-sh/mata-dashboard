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
	@echo "  $(COLOR_GREEN)make setup$(COLOR_RESET)           - Full dev setup (install git hooks + composer deps) [$(COLOR_MAGENTA)composer, git$(COLOR_RESET)]"
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
	@echo "    $(COLOR_GREEN)make dev$(COLOR_RESET)           - Start full Docker environment"
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
# Development Setup
# ============================================

# Full development environment setup (composer + git hooks + docker)
setup: install-hooks
	@echo "$(COLOR_BLUE)📦 Installing Composer dependencies...$(COLOR_RESET)"
	@if [ -f composer.json ]; then \
		composer install; \
	else \
		echo "$(COLOR_RED)❌ composer.json not found$(COLOR_RESET)"; \
		exit 1; \
	fi
	@echo "$(COLOR_GREEN)✅ Full development environment ready!$(COLOR_RESET)"
	@echo "$(COLOR_CYAN)Run 'make dev' to start Docker containers$(COLOR_RESET)"

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
		echo "$(COLOR_RED)❌ Docker containers not running. Start with: make dev$(COLOR_RESET)"; \
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

# First-time development environment setup
docker-setup:
	@echo "$(COLOR_BLUE)🔧 Setting up development environment...$(COLOR_RESET)"
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		sed -i.bak 's/APP_ENV=.*/APP_ENV=dev/' .env && rm -f .env.bak || \
		sed -i '' 's/APP_ENV=.*/APP_ENV=dev/' .env 2>/dev/null || true; \
		echo "$(COLOR_GREEN)✅ .env created with dev defaults$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_GREEN)✅ .env already exists$(COLOR_RESET)"; \
	fi
	@mkdir -p temp/api_cache temp/sessions temp/templates_cache logs config/dev
	@if [ ! -f config/dev/users.json ]; then \
		echo '{"dev":{"username":"dev","password":"$$2y$$10$$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi","role":"admin"},"admin":{"username":"admin","password":"$$2y$$10$$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi","role":"admin"}}' > config/dev/users.json; \
		echo "$(COLOR_GREEN)✅ config/dev/users.json created (dev/password, admin/password)$(COLOR_RESET)"; \
	fi
	@echo "$(COLOR_GREEN)✅ Development environment ready!$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_CYAN)Next: make dev$(COLOR_RESET)"

## Development (Docker)

# Start development environment
dev:
	@echo "$(COLOR_BLUE)🚀 Starting development environment...$(COLOR_RESET)"
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
prod:
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
	@echo "  $(COLOR_GREEN)make dev$(COLOR_RESET)             - Start development environment"
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

