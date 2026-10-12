#!/bin/bash
set -e

# Development entrypoint script
# Fixes file permissions for mounted volumes to allow www-data to write

echo "🔧 Setting up development environment..."

# Ensure directories exist and have correct permissions
mkdir -p \
    /var/www/html/temp/api-cache \
    /var/www/html/temp/sessions \
    /var/www/html/temp/templates-cache \
    /var/www/html/logs/cron \
    /var/www/html/config \
    /var/www/html/public/branding

# Copy config files from examples if they don't exist.
# config/ belongs to the host user and may be a git repository, so files created here
# get the directory's owner instead of root, and config/ is never re-owned below.
echo "📋 Checking for config files..."
config_owner=$(stat -c '%u:%g' /var/www/html/config)
for file in config.env db.config.env mail.config.env; do
    if [ ! -f "/var/www/html/config/$file" ]; then
        if [ -f "/var/www/html/config.example/${file}.example" ]; then
            cp "/var/www/html/config.example/${file}.example" "/var/www/html/config/$file"
            chown "$config_owner" "/var/www/html/config/$file"
            echo "✅ Created config/$file from example"
        else
            echo "⚠️  Warning: config.example/${file}.example not found"
        fi
    fi
done

# Fix permissions for ALL mounted files (source code, vendor, etc.)
# In dev mode, entire /var/www/html is mounted from host with host user ownership
# We need to ensure www-data can read everything and write to specific dirs
echo "📁 Fixing permissions for mounted source code..."

# Make all files readable by www-data (keep host user as owner for editing)
chmod -R a+rX /var/www/html

# Fix ownership and permissions for writable directories.
# config/ is read-only for the app (Docker keeps users.json on the mata_users volume).
echo "📁 Setting up writable directories..."
chown -R www-data:www-data /var/www/html/temp /var/www/html/logs /var/www/html/public/branding
chmod -R 770 /var/www/html/temp /var/www/html/logs
# Keep ignored writable roots traversable so host Git/Docker tooling can stat them.
chmod 775 /var/www/html/temp /var/www/html/logs
[ ! -e /var/www/html/logs/.gitkeep ] || chmod 644 /var/www/html/logs/.gitkeep
chmod -R 755 /var/www/html/public/branding

# The users volume is private to www-data, which bin/mata user commands run as.
install -d -o www-data -g www-data -m 0700 /var/lib/mata-dashboard

# Secure users.json files (must be 600 for security)
echo "🔒 Securing users.json files..."
find /var/www/html/config -name "users.json" -type f -exec chown www-data:www-data {} \; -exec chmod 600 {} \; 2>/dev/null || true

# If vendor doesn't exist, run composer install
if [ ! -d "/var/www/html/vendor" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

echo "✅ Development environment ready!"

# Cron has no inherited Docker environment. Materialize only DB settings for www-data.
if [ -x /usr/local/bin/mata-cron-setup ]; then
    /usr/local/bin/mata-cron-setup
else
    echo "❌ mata-cron-setup is missing; cron cannot safely start" >&2
    exit 1
fi

# Start cron (www-data crontab will run) and Apache
exec sh -c "cron && apache2-foreground"
