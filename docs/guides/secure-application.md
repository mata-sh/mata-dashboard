# Secure Application

## Database Configuration

Database credentials are defined in `config/db.config.env`


## Application Configuration

`config/<env>/config.env`

1. **Disable debug mode in production:**

```bash
DEBUG_MODE=false
```

2. **Enable all security features:**

```bash
# redis rate limiting with filesystem fallback
RATE_LIMIT_ENABLED=true

# restrict access to dashboard to certain ips
IP_WHITELIST_ENABLED=ON
ALLOWED_IPS=127.0.0.1,::1

# check if accessing user is allowed
CLI_STRICT_USER_CHECK=ON

# whitelist of allowed users comma separated: user1,user2..
CLI_ALLOWED_USERS=www-data

# browser security headers (CSP, HSTS, clickjacking protection)
SECURITY_HEADERS_ENABLED=ON
```

`ON`/`OFF` values are valid for this application's `.env` parser. Set `SECURITY_HEADERS_ENABLED=OFF` only for local browser-integration debugging.


## API Key Authentication

Nodes->Dashboard communication uses HMAC-signed tokens with timestamp validation.

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

## Rate Limiting

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
- Recommended: Use Redis if available


## Monitoring & Logging

**Enable logging:**

```bash
# Application logs
LOG_LEVEL=warning
LOG_FILE=/path/to/mata-dashboard/temp/logs/application.log
```

## Server Config

Apply web-access restrictions, HTTPS/TLS, and request filtering at your web server or ingress layer according to your platform standards.

- Only the `public/` directory should be web-accessible.
- Point your (sub)domain’s document root to `…/public/` for both dashboard and nodes.
- Deny direct access to configuration and internal paths (`config/`, `storage/`, `vendor/`, logs, etc.).
- Keep `config.env` and secrets **outside the web root** and readable only by the web user.
- Use strong, unique secrets per node and keep file permissions tight.

No system-level agents, daemons, or elevated privileges are required.


### File Permissions

Use least-privilege filesystem access:

- Application code and configuration must not be writable by the web server user.
- Only runtime directories (cache, logs, temp, and the dedicated `MATA_USERS_FILE` directory) should be writable by the application process.
- Provision the authentication-data directory as described in the [User Management guide](user-management.md#persistent-authentication-data); do not use `config/<env>/` for production authentication data.
- Validate ownership and permissions as part of the deployment process.

<!-- ## Next Steps -->

<!-- - [Deployment Guide](deployment.md) - Secure production deployment -->
<!-- - [Configuration Guide](configuration.md) - Security configuration options -->
<!-- - [Troubleshooting](../tutorials/troubleshooting.md) - Security-related issues -->

