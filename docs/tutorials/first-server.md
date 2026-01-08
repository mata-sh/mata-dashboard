# Adding Your First Monitored Server

This tutorial walks you through the complete process of adding your first server to MATA Dashboard for monitoring.

## Overview

In this tutorial, you'll learn how to:
- Set up the MATA Node on your server
- Configure the Dashboard to connect to your server
- Test connectivity and verify data collection
- View server metrics in the dashboard
- Troubleshoot common connection issues

**Time Required:** 15-30 minutes

## Prerequisites

Before you begin, ensure you have:

- ✅ MATA Dashboard installed and running
- ✅ SSH or direct access to the server you want to monitor
- ✅ PHP 8.3+ installed on the monitored server
- ✅ Web server (Apache/Nginx) configured on the monitored server
- ✅ Basic command line familiarity

## Architecture Overview

MATA uses a client-server architecture:

```
┌─────────────────────┐          API Request           ┌──────────────────────┐
│                     │    (with HMAC Token auth)      │                      │
│  MATA Dashboard     │───────────────────────────────>│   MATA Node          │
│  (Central Server)   │<───────────────────────────────│   (Monitored Server) │
│                     │     JSON Response              │                      │
└─────────────────────┘                                └──────────────────────┘
```

The Dashboard periodically requests server metrics from the Node via HTTPS API.

---

## Step 1: Install MATA Node on Your Server

### 1.1 Download and Extract the Node

SSH into the server you want to monitor:

```bash
ssh user@your-server.com
cd /var/www  # or your web root directory
```

Clone or download the MATA Node repository:

```bash
# Option 1: Using git
git clone https://github.com/xarcdotdev/mata-node.git

# Option 2: Manual download
wget https://github.com/xarcdotdev/mata-node/archive/main.zip
unzip main.zip
mv mata-node-main mata-node
```

### 1.2 Install Dependencies

```bash
cd mata-node
make setup
```

### 1.3 Configure the Node

Generate a secure API key (32+ characters):

```bash
openssl rand -hex 32
```

**Example output:** `8f3a9b2c1d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0`

Copy and save this API key - you'll need it in both the Node configuration and Dashboard configuration.

Edit the Node's configuration file:

```bash
cp config/config.env.example config/config.env
nano config/config.env
```

Update the API key in `config.env`:

> **Security Note:** Always restrict API access to specific IP addresses in production environments.

### 1.4 Set Up Web Server Access

Configure your web server to serve the Node API.

**For Apache:**

```apache
<VirtualHost *:443>
    ServerName your-server.com
    DocumentRoot /var/www

    <Directory /var/www/mata-node>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem
</VirtualHost>
```

**For Nginx:**

```nginx
server {
    listen 443 ssl;
    server_name your-server.com;
    root /var/www;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location /mata-node {
        try_files $uri $uri/ /mata-node/index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Restart your web server:

```bash
# Apache
sudo systemctl restart apache2

# Nginx
sudo systemctl restart nginx
```

### 1.5 Test the Node API

Verify the Node is accessible:

```bash
curl -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" \
     https://your-server.com/mata-node/api/health
```

**Expected Response:**

```json
{
  "status": "ok",
  "timestamp": "2025-11-02T15:30:45+00:00",
  "version": "1.0.0"
}
```

If you see this response, the Node is correctly configured and ready to accept requests from the Dashboard.

---

## Step 2: Configure MATA Dashboard

Now that the Node is running, configure the Dashboard to connect to it.

### 2.1 Edit servers.json

On your Dashboard server, navigate to the config directory:

```bash
cd /path/to/mata-dashboard/config
```

If `servers.json` doesn't exist, copy from the example:

```bash
cp example/servers.json.example servers.json
```

Edit `servers.json`:

```bash
nano servers.json
```

### 2.2 Add Your Server Configuration

Remove the example entries and add your server:

```json
{
    "_README": {
        "description": "MATA Dashboard Server Configuration",
        "security_note": "NEVER commit servers.json to version control"
    },

    "production-web-server": {
        "title": "Production Web Server",
        "active": true,
        "url": "https://your-server.com/",
        "api_url": "https://your-server.com/mata-node/api",
        "api_key": "8f3a9b2c1d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0",
        "primary_color": "#28a745",
        "secondary_color": "#ffffff",
        "info": "Production Web Server - Main Application",
        "check_disk_space": "on"
    }
}
```

**Configuration Fields Explained:**

| Field | Required | Description |
|-------|----------|-------------|
| `title` | Yes | Display name shown in the dashboard |
| `active` | No | Set to `false` to temporarily disable monitoring (defaults to `true`) |
| `url` | Yes | Base URL of your server (with trailing slash) |
| `api_url` | Yes | Full path to the Node API endpoint |
| `api_key` | Yes | Must match the key configured in the Node |
| `primary_color` | Yes | Hex color for visual identification in dashboard |
| `secondary_color` | Yes | Secondary color for theming |
| `info` | No | Human-readable description of the server's purpose |
| `check_disk_space` | No | Enable disk space monitoring (`on`/`off`) |

### 2.3 Set Proper Permissions

Protect your configuration file:

```bash
chmod 600 servers.json
```

This ensures only the web server user can read the file containing API keys.

---

## Step 3: Test Dashboard Connectivity

### 3.1 Manual API Test from Dashboard

From the Dashboard server, test the connection:

```bash
curl -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" \
     https://your-server.com/mata-node/api/server-data
```

You should receive JSON data containing server metrics:

```json
{
  "cpu_usage": 23.5,
  "ram_used": 4096,
  "ram_total": 16384,
  "disk_used": 45600,
  "disk_total": 102400,
  "uptime": 86400,
  "load_average": [0.45, 0.52, 0.48],
  "processes": 127,
  "timestamp": "2025-11-02T15:35:00+00:00"
}
```

### 3.2 Check Dashboard Logs

Monitor the Dashboard logs for any connection errors:

```bash
tail -f /path/to/mata-dashboard/logs/app.log
```

Look for entries related to your server connection attempts.

### 3.3 Verify Cache Directory

Ensure the cache directory exists and is writable:

```bash
ls -la /path/to/mata-dashboard/temp/api_cache/
```

If it doesn't exist:

```bash
mkdir -p /path/to/mata-dashboard/temp/api_cache
chmod 755 /path/to/mata-dashboard/temp/api_cache
chown www-data:www-data /path/to/mata-dashboard/temp/api_cache  # Adjust user as needed
```

---

## Step 4: View Server in Dashboard

### 4.1 Access the Dashboard

Open your web browser and navigate to:

```
https://your-dashboard-domain.com/
```

Log in with your credentials.

### 4.2 Force Data Refresh

Click the **Refresh** button to force an immediate data collection from your server. This bypasses the cache and fetches fresh metrics. Note: The Node on the server might provide cached data itself.

---

## Step 5: Configure Automated Data Collection

To enable continuous monitoring, set up the cron jobs on the Dashboard server. When running in docker, the crontab in `mata-dashboard/docker/cron/crontab` is used.

### 5.1 Edit Crontab manually

```bash
crontab -e
```

> **Important:** Replace `/path/to/mata-dashboard` with your actual installation path.

### 5.2 Create Log Directory

```bash
sudo mkdir -p /path/to/mata-dashboard/logs
sudo chown www-data:www-data /path/to/mata-dashboard/logs/cron/
```

### 5.3 Verify Cron Execution

Wait 5 minutes, then check if the cron job ran successfully:

```bash
# cronjob logs
tail -f /path/to/mata-dashboard/logs/cron/collect_server_metrics.log
# payload health log
tail -f /path/to/mata-dashboard/logs/cron/collect_server_metrics_health.log
```

---

## Troubleshooting

### Server Not Appearing in Dashboard

**Problem:** Your server doesn't show up on the dashboard after adding it to `servers.json`.

**Solutions:**

1. **Verify JSON syntax:**
   ```bash
   php -r "json_decode(file_get_contents('/path/to/mata-dashboard/config/servers.json'));" && echo "Valid JSON" || echo "Invalid JSON"
   ```

2. **Check file permissions:**
   ```bash
   ls -la /path/to/mata-dashboard/config/servers.json
   # Should be readable by web server user
   ```

3. **Clear application cache:**
   ```bash
   rm -rf /path/to/mata-dashboard/temp/api_cache/*
   ```

4. **Check error logs:**
   ```bash
   tail -f /path/to/mata-dashboard/logs/app.log
   tail -f /path/to/mata-dashboard/logs/php-error.log
   ```

### Connection Timeout Errors

**Problem:** Dashboard shows "Connection timeout" or "Unable to reach server".

**Solutions:**

1. **Verify Node is accessible:**
   ```bash
   curl -v https://your-server.com/mata-node/api/health
   ```

2. **Check firewall rules:**
   ```bash
   # On the monitored server
   sudo ufw status
   # Ensure port 443 is open from Dashboard IP
   ```

3. **Verify SSL certificate:**
   ```bash
   openssl s_client -connect your-server.com:443
   # Check for certificate errors
   ```

4. **Test from Dashboard server:**
   ```bash
   # SSH into Dashboard server
   curl -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" https://your-server.com/mata-node/api/server-data
   ```

### API Authentication Failures

**Problem:** "401 Unauthorized" or "Invalid API key" errors.

**Solutions:**

1. **Verify API keys match:**
   - Node configuration: `/var/www/mata-node/config/config.env`
   - Dashboard configuration: `/path/to/mata-dashboard/config/servers.json`

2. **Regenerate API key:**
   ```bash
   openssl rand -hex 32
   # Update both Node and Dashboard configurations
   ```

### No Metrics Data

**Problem:** Server appears in dashboard but shows no metrics or "No data available".

**Solutions:**

1. **Check Node API response:**
   ```bash
   curl -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" https://your-server.com/mata-node/api/server-data
   # Should return JSON with metrics
   ```

2. **Verify PHP extensions on Node:**
   ```bash
   php -m | grep -E "(json|curl|mbstring)"
   # All should be installed
   ```

3. **Check Node error logs:**
   ```bash
   tail -f /var/log/apache2/error.log  # or Nginx equivalent
    tail -f /path/to/mata-node/logs/app.log
   tail -f /path/to/mata-node/logs/php-error.log
   ```

4. **Test individual metrics:**
   ```bash
   # On the monitored server
   php -r "echo json_encode(['cpu' => sys_getloadavg()[0] * 100]);"
   ```

### Permission Denied Errors

**Problem:** "Permission denied" when accessing cache or log files.

**Solutions:**

1. **Fix cache directory permissions:**
   ```bash
   sudo chown -R www-data:www-data /path/to/mata-dashboard/temp
   sudo chmod -R 755 /path/to/mata-dashboard/temp
   ```

2. **Fix log directory permissions:**
   ```bash
   sudo chown -R www-data:www-data /path/to/mata-dashboard/logs
   sudo chmod -R 755 /path/to/mata-dashboard/logs
   ```

3. **Verify config file permissions:**
   ```bash
   ls -la /path/to/mata-dashboard/config/servers.json
   ls -la /path/to/mata-dashboard/config/config.env
   ls -la /path/to/mata-dashboard/config/db.config.env
   ls -la /path/to/mata-dashboard/config/mail.config.env

   # Should be 600 (readable by web server user)
   ```

---

## Next Steps

Here's what you can do next:

1. **Add More Servers:** Repeat this process to monitor additional servers
2. **Configure Alert Thresholds:** Set up custom thresholds for CPU, RAM, and disk usage
3. **Set Up Email Alerts:** Configure email notifications for critical events
4. **Explore Application Monitoring:** Add individual PHP applications within your server
5. **Review Historical Data:** Check metrics trends and server performance over time

### Related Tutorials

- [Configuring Custom Alert Thresholds](../tutorials/custom-thresholds.md)
- [Setting Up Email Notifications](../guides/configuration.md)
- [Understanding Server Metrics](../reference/server-metrics.md)

### Need Help?

- **Documentation:** [https://github.com/xarcdotdev/mata-dashboard/docs](https://github.com/xarcdotdev/mata-dashboard/docs)
- **Issues:** [Report a bug or request help](https://github.com/xarcdotdev/mata-dashboard/issues)
- **Community:** Check existing issues for similar problems

---

## Quick Reference

### Essential Commands

```bash
# Test Node API from Dashboard server
curl -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" https://server.com/mata-node/api/health

# Validate servers.json syntax
php -r "json_decode(file_get_contents('config/servers.json'));"

# Clear Dashboard cache
rm -rf /path/to/mata-dashboard/temp/api_cache/*

# View Dashboard logs
tail -f /path/to/mata-dashboard/logs/app.log

# Test cron job manually
php /path/to/mata-dashboard/cron/servers_metrics_collect.php
```

### Important File Locations

| File | Purpose |
|------|---------|
| `config/servers.json` | Server configuration and API keys |
| `temp/api_cache/` | Cached server metrics |
| `logs/app.log` | Application logs |
| `logs/cron/` | Cron job execution logs |

### API Endpoints (Node)

| Endpoint | Description |
|----------|-------------|
| `/api/health` | Health check (returns status) |
| `/api/server-data` | Server metrics (CPU, RAM, disk) |
| `/api/apps` | Application list and status |
| `/api/databases` | Database information |
| `/api/processes` | Running PHP processes |

---

**Last Updated:** January 2026
**MATA Dashboard Version:** 1.0+