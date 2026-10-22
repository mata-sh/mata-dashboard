# Web Server Configuration Examples

This directory contains example configuration files for deploying MATA Dashboard with various web servers.

## ⚠️ Disclaimer

**These configurations are provided as starting points and have not been fully tested in all environments.** You should review, test, and adapt them to your specific setup before using in production. Always verify that:

- URL rewriting works correctly for your routes
- Security headers are appropriate for your use case
- File permissions and directory access restrictions function as expected
- SSL/TLS configurations meet your security requirements

Use at your own risk and test thoroughly in a staging environment first.

## Overview

MATA Dashboard uses the [Bramus Router](https://github.com/bramus/router) which requires URL rewriting to route all requests through `index.php`. Each web server has its own way of configuring this.

## Available Configurations

### Apache

**Production (Recommended):** `apache-vhost.conf`
- Server-level configuration for best performance
- Place in `/etc/apache2/sites-available/mata.conf`
- Requires `AllowOverride None` (no .htaccess needed)
- Enable with: `sudo a2ensite mata && sudo systemctl reload apache2`

**Shared Hosting:** `.htaccess`
- For environments where you can't modify Apache's main config
- Place in `public/.htaccess`
- Has performance overhead compared to vhost configuration

### Nginx

**File:** `nginx-site.conf`
- High-performance alternative to Apache
- Place in `/etc/nginx/sites-available/mata`
- Enable with: `sudo ln -s /etc/nginx/sites-available/mata /etc/nginx/sites-enabled/`

### Caddy

**File:** `Caddyfile`
- Modern web server with automatic HTTPS
- Place in `/etc/caddy/Caddyfile`
- Automatically obtains SSL certificates from Let's Encrypt
- No manual certificate configuration needed

## Docker Deployments

For Docker containers, the URL rewriting is configured in the Apache virtual host file:
- `docker/apache/000-default.conf`

This configuration:
- Uses `AllowOverride None` for better performance
- Includes all necessary rewrite rules
- Excludes `.htaccess` files from the Docker image (see `.dockerignore`)

## Common Requirements

All configurations must:

1. **Set document root to `public/`**
   - Critical for security - prevents direct access to application code

2. **Enable URL rewriting**
   - Routes requests to `index.php` unless they match actual files

3. **Deny access to sensitive directories**
   - `config/`, `vendor/`, `src/`, `temp/`, `logs/`

4. **Add security headers**
   - `X-Content-Type-Options`, `X-Frame-Options`, etc.

## Usage

1. Choose the configuration file for your web server
2. Copy it to the appropriate location on your server
3. Update paths and domain names to match your environment
4. Enable required modules (Apache: `rewrite`, `headers`)
5. Test and reload your web server


## Testing

After configuration, verify URL rewriting works:

```bash
# GET should return or redirect to login, not return 404
curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' http://your-domain/watch

# Static asset should return 200
curl -sS -o /dev/null -w '%{http_code}\n' http://your-domain/assets/css/main.css
```

Prefer server-level configuration over `.htaccess` when the host allows it.
