<a id="readme-top"></a>

<!-- PROJECT LOGO -->
<div align="center">
  <a href="https://github.com/mata-sh/mata-dashboard">
    <img src="docs/images/logo.svg" alt="Logo" width="200" height="200">
  </a>

<h3 align="center">MATA Dashboard</h3>

  <p align="center">

`Monitoring Anomalies & Tracking Alerts`
    <br>

**MATA** is a PHP-based server monitoring solution that provides centralized oversight of multiple servers and their hosted applications. Built with security automation principles, it offers real-time monitoring, intelligent alerting, and comprehensive event logging for enterprise-grade infrastructure management.

<br>
<a href="https://github.com/mata-sh/mata-dashboard/issues/new?labels=bug&template=bug-report---.md">Report Bug</a>
&middot;
<a href="https://github.com/mata-sh/mata-dashboard/issues/new?labels=enhancement&template=feature-request---.md">Request Feature</a>
  </p>

[![Contributors][contributors-shield]][contributors-url]
[![Stargazers][stars-shield]][stars-url]
[![Issues][issues-shield]][issues-url]
[![License][license-shield]][license-url]
[![PHP][PHP-shield]][PHP.net]

</div>


<!-- ABOUT THE PROJECT -->
## What is MATA?

MATA is an enterprise-grade server monitoring solution that combines a centralized Dashboard with lightweight Node applications to provide comprehensive infrastructure oversight. The architecture enables secure, API-based monitoring of multiple servers and their hosted PHP applications through encrypted communications and intelligent alerting.

<div align="center">

![Product Name Screen Shot][product-screenshot]

</div>

### Core Features

🖥️ **Server Monitoring:** Real-time tracking of CPU usage, RAM consumption, disk space, and system processes<br>
🌐 **Application Health:** HTTP ping monitoring, error log analysis, and session tracking for web applications<br>
🚨 **Intelligent Alerting:** Threshold-based alarm system with email notifications and event aggregation<br>
🔒 **Security-First:** Encrypted API communications, rate limiting, and read-only node operations<br>
📊 **Event Logging:** Comprehensive audit trail with configurable retention and severity levels<br>
⚙️ **Configuration Management:** Centralized threshold management and server-specific customization

### Architecture Overview

- **Dashboard (Server):** Central monitoring hub with web interface and alerting system
- **Nodes (Clients):** Lightweight API endpoints deployed on monitored servers
- **Secure Communication:** Encrypted API keys with timestamp validation
- **Database Logging:** Dual-table event system for monitoring and alarm management

## Why MATA?

- **Multi-Server Visibility:** Monitor entire infrastructure from a single dashboard
- **Proactive Alerting:** Detect issues before they impact users or operations  
- **Security Automation:** Built-in protection against common attack vectors
- **Compliance Ready:** Comprehensive audit trails and event logging
- **Scalable Design:** Lightweight nodes with minimal performance impact
- **Developer Friendly:** Modern PHP architecture with clean separation of concerns

**🎯 MATA** bridges the gap between basic monitoring tools and enterprise solutions, providing the security automation capabilities essential for modern infrastructure management.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Getting Started

### Prerequisites

- **PHP 8.3+** with required extensions
- **MySQL/MariaDB** for event logging and configuration storage
- **Composer** for dependency management
- **Web server** (Apache/Nginx) with PHP support

### Installation

#### 1. Clone Repository

```bash
git clone https://github.com/mata-sh/mata-dashboard.git
cd mata-dashboard
```

#### 2. Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install Node.js dependencies for CSS building
npm install

# Copy HTMX to assets
cp node_modules/htmx.org/dist/htmx.min.js public/assets/js/
```

#### 3. Build Assets

```bash
# Build CSS from SCSS
npm run build-bulma

# Optimize CSS for production (optional)
npx postcss public/assets/css/bulma.css -o public/assets/css/bulma.purged.min.css
```

#### 4. Configure Environment

```bash
# Copy example configuration files
cp config/config.env.example config/config.env
cp config/mail.config.env.example config/mail.config.env
cp config/servers.json.example config/servers.json

# Edit configuration files
nano config/config.env
nano config/mail.config.env
nano config/servers.json
```

Key configuration values:
- **config.env**: Database connection, application settings, security secrets
- **mail.config.env**: SMTP settings for email alerts and notifications
- **servers.json**: Server endpoints, API keys, and monitoring configuration
- **mysql.mata.env** (optional): Dedicated database configuration file

#### 5. Set Permissions

```bash
chmod 755 config/ public/
chmod 644 .htaccess public/.htaccess public/index.php
```

### Configuration

#### User Configuration 

```bash
#1. Copy example files:
cp config/users.json.example config/users.json

#2. Set secure file permissions:
chmod 600 config/users.json

#3. Generate Password Hashes for Users
php scripts/hash-password.php
```

#### Server Configuration (`config/servers.json`)

Define the servers and applications to monitor. Copy from the example file:

```bash
cp config/servers.json.example config/servers.json
```

Example server configuration:

```json
{
  "production-web-server": {
    "title": "Production Server",
    "url": "https://your-production-server.com/",
    "api_url": "https://your-production-server.com/mata-node/api",
    "api_key": "your_secure_api_key_here_32_characters",
    "primary_color": "#28a745",
    "secondary_color": "#ffffff",
    "info": "Production Web Server - Main Application",
    "check_disk_space": "on"
  }
}
```

**Security Note**: Generate secure API keys using `openssl rand -hex 32` and never commit the actual `servers.json` file to version control.

#### Monitoring Thresholds (`config/thresholds.json`)

Configure alert thresholds for different metrics:

```json
{
  "server_data": {
    "total_system_filecount": {"good": 500000, "warn": 700000, "critical": 850000, "alarm": 900000},
    "disk_space_usage_percent": {"good": 70, "warn": 80, "critical": 85, "alarm": 90}
  },
  "app_data": {
    "session_count": {"good": 100, "warn": 1000, "critical": 5000, "alarm": 10000},
    "log_filesize_kb": {"good": 10240, "warn": 51200, "critical": 256000, "alarm": 512000}
  }
}
```

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Monitoring Capabilities

### Server Metrics

| Metric | Description | Alerting |
|--------|-------------|----------|
| **CPU Usage** | Real-time processor utilization | Threshold-based |
| **Memory Usage** | RAM consumption and availability | Configurable limits |
| **Disk Space** | Storage usage and free space | Critical space warnings |
| **File Count** | Total system file count monitoring | Large filesystem alerts |
| **Process Count** | Active processes (total, PHP, SQL) | Resource monitoring |

### Application Monitoring

| Feature | Description | Benefits |
|---------|-------------|----------|
| **HTTP Ping** | Application reachability testing | Uptime monitoring |
| **Error Log Analysis** | PHP error and warning detection | Proactive issue detection |
| **Session Tracking** | Active user session monitoring | Performance insights |
| **Maintenance Mode** | Application status detection | Planned downtime awareness |
| **Environment Detection** | Development vs production identification | Environment safety |

### Event System

MATA maintains comprehensive event logs across two database tables:

#### Event Types Logged
- **Server Connectivity** - Node communication failures
- **Resource Thresholds** - CPU, memory, disk space violations  
- **Application Errors** - PHP fatal errors and warnings
- **Configuration Issues** - Missing or invalid configuration files
- **Security Events** - Rate limiting and authentication failures

#### Alarm Management
- **Intelligent Deduplication** - Prevents notification spam
- **Configurable Intervals** - Different thresholds for different event types
- **Email Notifications** - Formatted alerts with context information
- **Severity Levels** - Info, Warning, Critical classifications
- **Automatic Cleanup** - Configurable retention periods

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Security Features

### API Security
- **Encrypted Communication** - API keys with timestamp validation
- **Rate Limiting** - IP-based request throttling
- **Read-Only Operations** - Nodes only expose read-only endpoints
- **Whitelist Configuration** - Only approved data transmission

### Application Security
- **Session Management** - PHP session-based authentication
- **File Protection** - htaccess protection for sensitive directories
- **Input Validation** - Sanitization of all user inputs
- **Configuration Security** - Encrypted storage of sensitive settings

### Deployment Security
- **Directory Permissions** - Restrictive file system permissions
- **Web Server Protection** - htaccess and htpasswd configuration
- **Database Security** - Prepared statements and input sanitization

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Development

### Code Quality Tools

```bash
# PHP CodeSniffer - Code standards
vendor/bin/phpcs

# PHP Mess Detector - Code quality analysis  
vendor/bin/phpmd src/ text ruleset.xml

# PHPStan - Static analysis
vendor/bin/phpstan analyse
```

### Project Structure

```
mata-dashboard/
├── src/
│   ├── Controllers/     # HTTP request handlers
│   ├── Models/         # Data models and database access
│   ├── Utils/          # Utility classes and helpers
│   └── Watchers/       # Monitoring logic and API clients
├── config/             # Configuration files
├── templates/          # Twig-style templates
├── public/             # Web-accessible files
├── cron/              # Automated data collection scripts
└── temp/              # Cache and temporary files
```

### Architecture Components

- **WatchController** - Main dashboard logic and API endpoints
- **EventRecorder** - Event logging and alarm management  
- **AppMonitor** - Core monitoring logic with threshold evaluation
- **AppApi** - Individual server API communication
- **Database** - MySQL wrapper with connection management
- **TemplateEngine** - Custom template rendering system

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Deployment

### Web Server Configuration

**⚠️ CRITICAL:** The web server document root **must** point to the `public/` directory for security and proper functionality.

Setting the document root to `public/` prevents unauthorized access to:
- `config/` - Configuration files and API keys
- `vendor/` - Third-party dependencies
- `src/` - Application source code
- `.env` files - Environment secrets
- `composer.json` - Dependency information

#### Apache Configuration

**Virtual Host Example:**

```apache
<VirtualHost *:80>
    ServerName mata.yourdomain.com
    DocumentRoot /path/to/mata-dashboard/public

    <Directory /path/to/mata-dashboard/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/mata-error.log
    CustomLog ${APACHE_LOG_DIR}/mata-access.log combined
</VirtualHost>
```

**Enable the site:**
```bash
sudo a2ensite mata
sudo systemctl reload apache2
```

#### Nginx Configuration

**Server Block Example:**

```nginx
server {
    listen 80;
    server_name mata.yourdomain.com;
    root /path/to/mata-dashboard/public;
    index index.php index.html;

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
}
```

**Enable the site:**
```bash
sudo ln -s /etc/nginx/sites-available/mata /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

#### PHP Built-in Development Server

For development and testing:

```bash
cd /path/to/mata-dashboard
php -S 0.0.0.0:8001 -t public/
```

**Access the dashboard:**
```
http://localhost:8001/
```

#### Docker Deployment

**Quick Start:**

```bash
# Using PHP Apache image
docker run -d \
    --name mata-dashboard \
    -p 8001:80 \
    -v $(pwd):/var/www/html \
    -w /var/www/html \
    php:8.3-apache \
    sh -c "apt-get update && apt-get install -y libpng-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite && apache2-foreground"
```

**Docker Compose:**

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
```

### Cron Jobs

Set up automated data collection:

```bash
# Collect server metrics every 5 minutes
*/5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/fetch_servers_data.php

# Monitor cron job health every 15 minutes
*/15 * * * * /usr/bin/php /path/to/mata-dashboard/cron/fetch_cronjob_heartbeats.php
```

### Production Considerations

- **SSL/TLS** - Secure HTTPS communication for all API endpoints
- **Configuration Security** - Never commit `.env`, `servers.json`, or credential files
- **API Key Management** - Use strong keys (32+ characters) and rotate regularly
- **Database Optimization** - Index optimization for event tables
- **Cache Configuration** - API response caching for performance
- **Log Rotation** - Management of application and error logs
- **Backup Strategy** - Regular database and configuration backups
- **File Permissions** - Restrict config directory access (750 or 755 max)

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Design Philosophy

**Priority:** Simplicity, clarity, resilience  
**Target:** Real-world monitoring without unnecessary complexity

## Technology Choices

### Authentication: JSON File Storage
- **Why:** Dashboard stays up even if database fails
- **Security:** Bcrypt hashing, account lockout, timing attack prevention
- **Scale:** 1-20 users optimal, database migration path available

### Database: Lightweight PDO Wrapper
- **Why:** No ORM overhead or magic methods
- **Features:** Prepared statements, consistent interface
- **Best for:** Read-heavy monitoring queries

### Frontend: HTMX + Bulma CSS
- **HTMX:** Dynamic updates via HTML attributes, no JavaScript framework needed
- **Bulma:** Modern CSS framework (pre-compiled, committed to repo)
- **Benefits:** No runtime dependencies, no state management complexity

### Templates: Native PHP
- **Syntax:** Twig-like, familiar to developers
- **Security:** Automatic XSS protection
- **Dependencies:** Zero

### Caching: File-based
- **Purpose:** Reduce load on monitored servers
- **Capacity:** Handles < 50 concurrent users
- **Infrastructure:** None required

## Use Case Fit

✅ **Ideal for:**
- 1-20 monitored servers
- 1-20 team members
- Quick deployment
- Unreliable database environments

❌ **Not for:**
- Public dashboards
- 100+ concurrent users
- Complex permissions
- Sub-minute updates

## Scaling Path

1. **Start:** JSON auth + file cache (zero infrastructure)
2. **Growth:** Add database authentication  
3. **Scale:** Redis/Memcached
4. **Enterprise:** Load balancer + replicas

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the Branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## License

This project is licensed under the GNU Affero General Public License v3.0.
See `LICENSE.txt` for details.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Contact

xarc - [@xarcdev](https://x.com/xarcdev) - mata@xarc.dev

Project Link: [https://github.com/mata-sh/mata-dashboard](https://github.com/mata-sh/mata-dashboard)

<p align="right">(<a href="#readme-top">back to top</a>)</p>

<!-- MARKDOWN LINKS & IMAGES -->
[contributors-shield]: https://img.shields.io/github/contributors/mata-sh/mata-dashboard.svg?style=for-the-badge
[contributors-url]: https://github.com/mata-sh/mata-dashboard/graphs/contributors
[forks-shield]: https://img.shields.io/github/forks/mata-sh/mata-dashboard.svg?style=for-the-badge
[forks-url]: https://github.com/mata-sh/mata-dashboard/network/members
[stars-shield]: https://img.shields.io/github/stars/mata-sh/mata-dashboard.svg?style=for-the-badge
[stars-url]: https://github.com/mata-sh/mata-dashboard/stargazers
[issues-shield]: https://img.shields.io/github/issues/mata-sh/mata-dashboard.svg?style=for-the-badge
[issues-url]: https://github.com/mata-sh/mata-dashboard/issues
[license-shield]: https://img.shields.io/github/license/mata-sh/mata-dashboard.svg?style=for-the-badge
[license-url]: https://github.com/mata-sh/mata-dashboard/blob/master/LICENSE.txt
<!-- [product-screenshot]: docs/images/demo.gif -->
[product-screenshot]: docs/images/screenshot.png

[PHP.net]: https://php.net/
[PHP-shield]: https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white