# Installation

This guide walks you through installing MATA Dashboard from scratch.

## Prerequisites

Before installing MATA Dashboard, ensure you have the following:

- **PHP 8.3+** with required extensions
- **MySQL/MariaDB** for event logging and configuration storage
- **Composer** for dependency management
- **Node.js and npm** for building CSS assets
- **Web server** (Apache/Nginx) with PHP support

## Installation Steps

### 1. Clone Repository

```bash
git clone https://github.com/xarcdotdev/mata-dashboard.git
cd mata-dashboard
```

### 2. Install Dependencies

#### PHP Dependencies

```bash
composer install
```

#### Node.js Dependencies

```bash
# Install Node.js dependencies for CSS building
npm install

# Copy HTMX to assets directory
cp node_modules/htmx.org/dist/htmx.min.js public/assets/js/
```

### 3. Build Assets

Build the CSS from SCSS source files:

```bash
# Build CSS from SCSS
npm run build-bulma

# Optional: Optimize CSS for production
npx postcss public/assets/css/bulma.css -o public/assets/css/bulma.purged.min.css
```

### 4. Configure Environment

Copy the example configuration files:

```bash
# Copy example configuration files
cp config/config.env.example config/config.env
cp config/mail.config.env.example config/mail.config.env
cp config/servers.json.example config/servers.json
```

Edit each configuration file with your settings:

```bash
# Edit main configuration
nano config/config.env

# Edit mail configuration
nano config/mail.config.env

# Edit servers configuration
nano config/servers.json
```

**Key configuration values:**

- **config.env**: Database connection, application settings, security secrets
- **mail.config.env**: SMTP settings for email alerts and notifications
- **servers.json**: Server endpoints, API keys, and monitoring configuration
- **mysql.mata.env** (optional): Dedicated database configuration file

See the [Configuration Guide](configuration.md) for detailed information about each configuration file.

### 5. Set Permissions

```bash
chmod 755 config/ public/
chmod 644 .htaccess public/.htaccess public/index.php
```

### 6. User Configuration

Create user accounts for dashboard access:

```bash
# Copy example users file
cp config/users.json.example config/users.json

# Set secure file permissions
chmod 600 config/users.json

# Generate password hashes for users
php scripts/hash-password.php
```

### 7. Database Setup

Run the database setup script to create the necessary tables:

```bash
php scripts/setup-database.php
```

## Next Steps

- [Quick Start Guide](quickstart.md) - Get up and running quickly
- [Configuration Guide](configuration.md) - Detailed configuration options
- [Deployment Guide](deployment.md) - Deploy to production

## Troubleshooting

If you encounter issues during installation, see the [Troubleshooting Guide](../tutorials/troubleshooting.md).
