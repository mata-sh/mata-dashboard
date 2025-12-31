# Security

This guide covers security features and best practices for MATA Dashboard.

## Security Architecture

MATA Dashboard implements multiple layers of security:

```
┌─────────────────────────────────────┐
│         User Authentication         │  Session-based
├─────────────────────────────────────┤
│         Rate Limiting               │  IP-based throttling
├─────────────────────────────────────┤
│      API Key Authentication         │  Node communication
├─────────────────────────────────────┤
│    Input Validation & Sanitization  │  XSS/SQL injection prevention
├─────────────────────────────────────┤
│      File System Protection         │  htaccess & permissions
└─────────────────────────────────────┘
```

## API Security

### API Key Authentication

Node communication uses encrypted API keys with timestamp validation.

**Generate secure API keys:**

```bash
openssl rand -hex 32
```

**Configure in `config/servers.json`:**

```json
{
  "server-id": {
    "api_key": "64_character_hexadecimal_api_key_here"
  }
}
```

### API Key Best Practices

- **Minimum 32 characters** (64 hex characters = 256 bits)
- **Unique per server** - Never reuse keys
- **Rotate regularly** - Change every 90 days
- **Secure storage** - Never commit to version control
- **Transmission security** - Always use HTTPS

### Rate Limiting

Protect against brute force and DoS attacks:

**Configure in `config.env`:**

```bash
RATE_LIMIT_ENABLED=true
RATE_LIMIT_MAX_REQUESTS=60
RATE_LIMIT_WINDOW=60  # seconds
```

**Customization options:**

- Adjust limits per IP address
- Configure different limits per endpoint
- Whitelist trusted IPs

### Read-Only Operations

Node endpoints expose only read-only operations:

- Cannot modify server configuration
- Cannot execute commands
- Cannot write files
- Only exposes whitelisted metrics

## Application Security

### Session Management

**Session configuration in `config.env`:**

```bash
SESSION_NAME=mata_session
SESSION_LIFETIME=3600  # 1 hour
SESSION_SECURE=true    # HTTPS only
SESSION_HTTPONLY=true  # No JavaScript access
SESSION_SAMESITE=Strict
```

**Session security features:**

- Bcrypt password hashing
- Session regeneration on login
- Automatic session expiration
- Secure cookie flags (HttpOnly, Secure, SameSite)

### Authentication

**User password requirements:**

- Minimum 12 characters recommended
- Use bcrypt hashing (cost factor 10+)
- Account lockout after failed attempts
- Timing attack prevention

**Generate password hashes:**

```bash
php scripts/hash-password.php
```

### Input Validation

All user inputs are validated and sanitized:

- **XSS Prevention**: HTML entity encoding
- **SQL Injection**: Prepared statements with PDO
- **Path Traversal**: Strict path validation
- **Command Injection**: No shell command execution from user input

### File Protection

**htaccess protection** for sensitive directories:

```apache
# Deny all access to config directory
<Directory /path/to/mata-dashboard/config>
    Require all denied
</Directory>

# Deny access to vendor directory
<Directory /path/to/mata-dashboard/vendor>
    Require all denied
</Directory>
```

## Deployment Security

### File Permissions

**Recommended permissions:**

```bash
# Application root
chmod 755 /path/to/mata-dashboard

# Public directory (web accessible)
chmod 755 /path/to/mata-dashboard/public
chmod 644 /path/to/mata-dashboard/public/index.php

# Configuration directory (restricted)
chmod 750 /path/to/mata-dashboard/config
chmod 640 /path/to/mata-dashboard/config/*.env
chmod 640 /path/to/mata-dashboard/config/*.json

# Writable directories
chmod 775 /path/to/mata-dashboard/temp
chmod 775 /path/to/mata-dashboard/temp/cache
chmod 775 /path/to/mata-dashboard/temp/logs

# Ownership
chown -R www-data:www-data /path/to/mata-dashboard
```

### Web Server Security

**Apache security headers:**

```apache
# Security headers
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Content-Security-Policy "default-src 'self'"

# Disable directory listing
Options -Indexes

# Disable server signature
ServerSignature Off
ServerTokens Prod
```

**Nginx security headers:**

```nginx
# Security headers
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Content-Security-Policy "default-src 'self'" always;

# Disable server signature
server_tokens off;
```

### SSL/TLS Configuration

**Minimum TLS version:**

```apache
# Apache
SSLProtocol -all +TLSv1.2 +TLSv1.3
SSLCipherSuite HIGH:!aNULL:!MD5
```

```nginx
# Nginx
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers HIGH:!aNULL:!MD5;
ssl_prefer_server_ciphers on;
```

**Certificate best practices:**

- Use certificates from trusted CAs
- Enable HTTP Strict Transport Security (HSTS)
- Configure OCSP stapling
- Implement certificate pinning for critical deployments

### Database Security

**Database user permissions:**

```sql
-- Create dedicated user with minimal permissions
CREATE USER 'mata_user'@'localhost' IDENTIFIED BY 'strong_password';

-- Grant only required permissions
GRANT SELECT, INSERT, UPDATE, DELETE ON mata_dashboard.* TO 'mata_user'@'localhost';
FLUSH PRIVILEGES;
```

**Database security best practices:**

- Use strong passwords (20+ characters)
- Restrict to localhost when possible
- Enable SSL connections for remote databases
- Regular backup and encryption of backups
- Disable remote root access

### Configuration Security

**Never commit sensitive files:**

```bash
# .gitignore entries
config/config.env
config/mail.config.env
config/servers.json
config/users.json
config/mysql.mata.env
temp/
```

**Environment-specific secrets:**

- Development: Use test credentials
- Staging: Use staging-specific secrets
- Production: Use production secrets with rotation

## Security Hardening

### Application Hardening

1. **Disable debug mode in production:**

```bash
# config.env
DEBUG_MODE=false
```

2. **Enable all security features:**

```bash
RATE_LIMIT_ENABLED=true
SESSION_SECURE=true
SESSION_HTTPONLY=true
```

3. **Restrict file uploads** (if implemented):
   - Validate file types
   - Scan for malware
   - Store outside document root

### Server Hardening

1. **Keep software updated:**

```bash
# Update system packages
sudo apt update && sudo apt upgrade

# Update PHP
sudo apt install php8.3

# Update Composer dependencies
composer update
```

2. **Disable unused services:**

```bash
sudo systemctl disable <unused-service>
```

3. **Configure firewall:**

```bash
# Allow SSH
sudo ufw allow 22/tcp

# Allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Enable firewall
sudo ufw enable
```

4. **Fail2ban for brute force protection:**

```bash
# Install fail2ban
sudo apt install fail2ban

# Configure for web attacks
sudo nano /etc/fail2ban/jail.local
```

### Monitoring & Logging

**Enable comprehensive logging:**

```bash
# Application logs
LOG_LEVEL=warning
LOG_FILE=/path/to/mata-dashboard/temp/logs/application.log

# Access logs
tail -f /var/log/apache2/access.log
tail -f /var/log/nginx/access.log
```

**Monitor for suspicious activity:**

- Failed login attempts
- Rate limit violations
- Unusual API access patterns
- File permission changes
- Configuration file modifications

## Security Audit Checklist

Use this checklist for regular security audits:

### Configuration Audit

- [ ] Debug mode disabled in production
- [ ] Strong passwords for all users
- [ ] API keys are 32+ characters
- [ ] API keys rotated within 90 days
- [ ] Sensitive files not in version control
- [ ] SMTP credentials secured

### Access Control

- [ ] Session security enabled
- [ ] Rate limiting enabled
- [ ] File permissions set correctly
- [ ] Database user has minimal permissions
- [ ] Web server document root is `public/`

### Network Security

- [ ] HTTPS enabled with valid certificate
- [ ] TLS 1.2+ only
- [ ] Security headers configured
- [ ] Firewall rules in place
- [ ] Unnecessary ports closed

### Monitoring

- [ ] Application logs enabled
- [ ] Access logs monitored
- [ ] Failed authentication logged
- [ ] Intrusion detection configured
- [ ] Log retention policy defined

## Incident Response

### Security Breach Response

If you suspect a security breach:

1. **Immediate actions:**
   - Disable affected accounts
   - Rotate all API keys
   - Change all passwords
   - Review access logs

2. **Investigation:**
   - Check application logs
   - Review web server access logs
   - Examine database queries
   - Identify entry point

3. **Remediation:**
   - Patch vulnerabilities
   - Restore from clean backup if necessary
   - Update security policies
   - Notify affected users

4. **Post-incident:**
   - Document lessons learned
   - Update security procedures
   - Conduct security audit
   - Train team on new procedures

### Emergency Contacts

Maintain a list of emergency contacts:

- System administrator
- Database administrator
- Security team
- Hosting provider support
- Legal/compliance team (if applicable)

## Security Resources

### Tools

- **PHPStan**: Static analysis for PHP code
- **OWASP ZAP**: Web application security scanner
- **Nikto**: Web server scanner
- **SSL Labs**: SSL/TLS configuration testing

### Further Reading

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
- [CIS Apache Benchmark](https://www.cisecurity.org/benchmark/apache_http_server)
- [CIS MySQL Benchmark](https://www.cisecurity.org/benchmark/mysql)

## Next Steps

- [Deployment Guide](deployment.md) - Secure production deployment
- [Configuration Guide](configuration.md) - Security configuration options
- [Troubleshooting](../tutorials/troubleshooting.md) - Security-related issues
