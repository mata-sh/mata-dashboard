#!/bin/bash
set -e

# Development entrypoint script
# Fixes file permissions for mounted volumes to allow www-data to write

echo "🔧 Setting up development environment..."

# Ensure directories exist and have correct permissions
mkdir -p \
    /var/www/html/temp/api_cache \
    /var/www/html/temp/sessions \
    /var/www/html/temp/templates_cache \
    /var/www/html/logs \
    /var/www/html/config

# Fix permissions for ALL mounted files (source code, vendor, etc.)
# In dev mode, entire /var/www/html is mounted from host with host user ownership
# We need to ensure www-data can read everything and write to specific dirs
echo "📁 Fixing permissions for mounted source code..."

# Make all files readable by www-data (keep host user as owner for editing)
chmod -R a+rX /var/www/html

# Fix ownership and permissions for writable directories
echo "📁 Setting up writable directories..."
chown -R www-data:www-data /var/www/html/temp /var/www/html/logs /var/www/html/config
chmod -R 775 /var/www/html/temp /var/www/html/logs
chmod -R 755 /var/www/html/config

# Secure users.json files (must be 600 for security)
echo "🔒 Securing users.json files..."
find /var/www/html/config -name "users.json" -type f -exec chown www-data:www-data {} \; -exec chmod 600 {} \; 2>/dev/null || true

# If vendor doesn't exist, run composer install
if [ ! -d "/var/www/html/vendor" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

echo "✅ Development environment ready!"

# Start cron and Apache
exec sh -c "cron && apache2-foreground"
