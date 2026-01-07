# Deployment

This guide covers deploying MATA Dashboard to production environments.

!!! warning "Disclaimer"
    The configurations in this guide are provided as starting points and have not been fully tested in all environments. You should review, test, and adapt them to your specific infrastructure before deploying to production. Always test thoroughly in a staging environment first.

## Web Server Configuration

!!! danger "Critical: Document Root"
    The web server document root **must** point to the `public/` directory for security and proper functionality.

Setting the document root to `public/` prevents unauthorized access to:

- `config/` - Configuration files and API keys
- `vendor/` - Third-party dependencies
- `src/` - Application source code
- `.env` files - Environment secrets
- `composer.json` - Dependency information

## Apache Configuration

### Production Setup (Recommended)

For production environments, configure URL rewriting at the server level for better performance.

Create `/etc/apache2/sites-available/mata.conf`:

```apache
<VirtualHost *:80>
    ServerName mata.yourdomain.com
    DocumentRoot /path/to/mata-dashboard/public

    <Directory /path/to/mata-dashboard/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php

        # URL Rewriting for Bramus Router
        # Routes all requests to index.php unless they match an actual file or directory
        RewriteEngine On
        RewriteBase /
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]

        # Security headers
        Header always set X-Content-Type-Options nosniff
        Header always set X-Frame-Options DENY
        Header always set X-XSS-Protection "1; mode=block"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/mata-error.log
    CustomLog ${APACHE_LOG_DIR}/mata-access.log combined
</VirtualHost>
```

!!! note "Why AllowOverride None?"
    Setting `AllowOverride None` disables `.htaccess` file processing, which improves performance significantly. All necessary rewrite rules are configured directly in the virtual host configuration.

### Enable and Activate

```bash
# Enable the site
sudo a2ensite mata

# Enable required Apache modules
sudo a2enmod rewrite headers

# Test configuration
sudo apache2ctl configtest

# Reload Apache
sudo systemctl reload apache2
```

### SSL/TLS Configuration

```apache
<VirtualHost *:443>
    ServerName mata.yourdomain.com
    DocumentRoot /path/to/mata-dashboard/public

    SSLEngine on
    SSLCertificateFile /path/to/certificate.crt
    SSLCertificateKeyFile /path/to/private.key
    SSLCertificateChainFile /path/to/chain.crt

    <Directory /path/to/mata-dashboard/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php

        # URL Rewriting for Bramus Router
        RewriteEngine On
        RewriteBase /
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]

        # Security headers
        Header always set X-Content-Type-Options nosniff
        Header always set X-Frame-Options DENY
        Header always set X-XSS-Protection "1; mode=block"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/mata-ssl-error.log
    CustomLog ${APACHE_LOG_DIR}/mata-ssl-access.log combined
</VirtualHost>
```

### Shared Hosting (with .htaccess)

If you're on shared hosting without access to Apache's main configuration, you can use `.htaccess` instead:

Create `public/.htaccess`:

```apache
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

!!! warning "Performance Impact"
    Using `.htaccess` has a performance cost as Apache must check for these files on every request. Use server-level configuration when possible.

## Nginx Configuration

Nginx is a high-performance alternative to Apache, commonly used in production.

### Server Block Setup

Create `/etc/nginx/sites-available/mata`:

```nginx
server {
    listen 80;
    server_name mata.yourdomain.com;
    root /path/to/mata-dashboard/public;
    index index.php index.html;

    # URL Rewriting for Bramus Router
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM configuration
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Deny access to sensitive directories
    location ~ /(config|vendor|src|temp|cron|logs) {
        deny all;
    }

    # Security headers
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
}
```

### Enable and Activate

```bash
# Create symbolic link
sudo ln -s /etc/nginx/sites-available/mata /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t

# Reload Nginx
sudo systemctl reload nginx
```

### SSL/TLS Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name mata.yourdomain.com;
    root /path/to/mata-dashboard/public;
    index index.php index.html;

    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # URL Rewriting for Bramus Router
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Deny access to sensitive directories
    location ~ /(config|vendor|src|temp|cron|logs) {
        deny all;
    }

    # Security headers
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
}
```

## Caddy Configuration

Caddy is a modern web server with automatic HTTPS via Let's Encrypt.

### Caddyfile Setup

Create `/etc/caddy/Caddyfile`:

```caddy
mata.yourdomain.com {
    root * /path/to/mata-dashboard/public
    encode gzip

    # URL Rewriting for Bramus Router
    # Try files first, then directories, finally route to index.php
    @notStatic {
        not file
        not path */
    }
    rewrite @notStatic /index.php

    # PHP-FPM handler
    php_fastcgi unix//var/run/php/php8.3-fpm.sock

    # Deny access to sensitive directories
    @sensitive {
        path /config/* /vendor/* /src/* /temp/* /cron/* /logs/*
    }
    respond @sensitive 403

    # Security headers
    header {
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
    }

    # File server for static assets
    file_server
}
```

### Enable and Start

```bash
# Validate configuration
sudo caddy validate --config /etc/caddy/Caddyfile

# Reload Caddy
sudo systemctl reload caddy
```

!!! success "Automatic HTTPS"
    Caddy automatically obtains and renews SSL/TLS certificates from Let's Encrypt. No manual certificate configuration needed!

## PHP Built-in Development Server

For development and testing only:

```bash
cd /path/to/mata-dashboard
php -S 0.0.0.0:8001 -t public/
```

Access at: `http://localhost:8001/`

!!! warning "Not for Production"
    The PHP built-in server is not suitable for production use. Use Apache or Nginx for production deployments.

## Docker Deployment

### Quick Start with Docker

```bash
docker run -d \
    --name mata-dashboard \
    -p 8001:80 \
    -v $(pwd):/var/www/html \
    -w /var/www/html \
    php:8.3-apache \
    sh -c "apt-get update && \
           apt-get install -y libpng-dev && \
           docker-php-ext-install pdo pdo_mysql && \
           a2enmod rewrite && \
           apache2-foreground"
```

### Docker Compose

Create `docker-compose.yml`:

```yaml
version: '3.8'
services:
  mata:
    image: php:8.3-apache
    ports:
      - "8001:80"
    volumes:
      - .:/var/www/html
    working_dir: /var/www/html/public
    environment:
      - APACHE_DOCUMENT_ROOT=/var/www/html/public
    command: >
      sh -c "apt-get update &&
             apt-get install -y libpng-dev &&
             docker-php-ext-install pdo pdo_mysql &&
             a2enmod rewrite &&
             apache2-foreground"

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root_password
      MYSQL_DATABASE: mata_dashboard
      MYSQL_USER: mata_user
      MYSQL_PASSWORD: mata_password
    volumes:
      - mysql_data:/var/lib/mysql
    ports:
      - "3306:3306"

volumes:
  mysql_data:
```

Start services:

```bash
docker-compose up -d
```

## Cron Jobs Setup

Set up automated data collection:

### Production Cron Configuration

```bash
# Edit crontab
crontab -e

# Add these entries:

# Collect raw server metrics every 5 minutes
*/5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/servers_metrics_collect.php >> /path/to/mata-dashboard/temp/logs/cron-collect.log 2>&1

# Aggregate server metrics hourly (at 5 minutes past each hour)
5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/servers_metrics_aggregate.php >> /path/to/mata-dashboard/temp/logs/cron-aggregate.log 2>&1
```

### Verify Cron Jobs

```bash
# View current crontab
crontab -l

# Monitor cron execution
tail -f /path/to/mata-dashboard/temp/logs/cron-collect.log
```

### Cron Job Monitoring

Check the dashboard's cron job health endpoint to verify jobs are running:

```bash
curl https://mata.yourdomain.com/api/cron-health
```

## File Permissions

Set appropriate file permissions for production:

```bash
# Application directories
chmod 755 /path/to/mata-dashboard
chmod 755 /path/to/mata-dashboard/public

# Configuration directory (read-only for web server)
chmod 750 /path/to/mata-dashboard/config
chown -R www-data:www-data /path/to/mata-dashboard/config

# Configuration files
chmod 640 /path/to/mata-dashboard/config/*.env
chmod 640 /path/to/mata-dashboard/config/*.json

# Writable directories (cache, logs)
chmod 775 /path/to/mata-dashboard/temp
chmod 775 /path/to/mata-dashboard/temp/logs
chmod 775 /path/to/mata-dashboard/temp/cache
chown -R www-data:www-data /path/to/mata-dashboard/temp
```

## Production Checklist

Use this checklist before going live:

### Configuration

- [ ] All `.env` files configured with production values
- [ ] `DEBUG_MODE=false` in `config.env`
- [ ] Strong database passwords set
- [ ] API keys generated and secured
- [ ] SMTP settings configured and tested
- [ ] Users created with strong passwords

### Security

- [ ] Document root set to `public/` directory
- [ ] SSL/TLS certificate installed and configured
- [ ] File permissions set correctly
- [ ] Configuration files not accessible via web
- [ ] Rate limiting enabled
- [ ] Firewall rules configured

### Monitoring

- [ ] Cron jobs configured and running
- [ ] First server added and monitoring
- [ ] Email alerts tested and working
- [ ] Logs being written correctly

### Performance

- [ ] Composer dependencies optimized: `composer install --no-dev --optimize-autoloader`
- [ ] CSS assets built and minified
- [ ] Database indexes created
- [ ] Cache directory writable

### Backup

- [ ] Database backup strategy in place
- [ ] Configuration files backed up
- [ ] Backup restoration tested

## Performance Optimization

### Composer Optimization

```bash
# Production autoloader optimization
composer install --no-dev --optimize-autoloader
```

### PHP Configuration

Optimize `php.ini` for production:

```ini
; Production settings
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log

; Performance
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 60
```


## Monitoring Production

### Log Files

Monitor these log files:

```bash
# Application logs
tail -f temp/logs/application.log

# Web server logs
tail -f /var/log/apache2/mata-error.log
tail -f /var/log/nginx/error.log

# Cron job logs
tail -f temp/logs/cron-collect.log
```

## Troubleshooting

### Common Production Issues

**Issue: 500 Internal Server Error**

```bash
# Check web server error log
tail -f /var/log/apache2/error.log

# Check file permissions
ls -la /path/to/mata-dashboard/temp
```

**Issue: Cron Jobs Not Running**

```bash
# Verify crontab entry
crontab -l

# Check cron log
grep CRON /var/log/syslog

# Test manual execution
php /path/to/mata-dashboard/cron/servers_metrics_collect.php
```

**Issue: Database Connection Failed**

```bash
# Test database connection
mysql -h localhost -u mata_user -p mata_dashboard

# Check config.env settings
cat config/config.env | grep DB_
```

## Next Steps

- [Security Guide](security.md) - Harden your installation
- [Monitoring Guide](monitoring.md) - Use monitoring features effectively
- [Troubleshooting](../tutorials/troubleshooting.md) - Solve common issues
