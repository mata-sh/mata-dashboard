# Troubleshooting Common Issues

This comprehensive guide helps you diagnose and resolve common issues with MATA Dashboard and monitored server nodes.

## Overview

This troubleshooting guide covers:

- Server connectivity and communication issues
- Metrics collection and display problems
- Alarm and notification failures
- Cron job execution issues
- Database connection problems
- Permission and access errors
- API authentication failures
- Performance and caching issues

**Quick Navigation:**

- [Server Shows as Disconnected](#server-shows-as-disconnected)
- [Metrics Not Updating](#metrics-not-updating)
- [Alarms Not Received](#alarms-not-received)
- [Cron Jobs Not Running](#cron-jobs-not-running)
- [Database Connection Issues](#database-connection-issues)
- [Permission Errors](#permission-errors)
- [API Errors](#api-errors)
- [Performance Issues](#performance-issues)

---

## Server Shows as Disconnected

### Symptoms

- Server card displays "Disconnected" or "Unreachable" status
- Red indicator with no metrics shown
- Error message: "Unable to connect to server"
- Last updated timestamp shows "Never" or is very old

### Diagnostic Steps

#### 1. Verify Node API is Running

Test the Node API directly from the Dashboard server:

```bash
curl -v -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" https://your-server.com/mata-node/api/health
```

**Expected Response:**
```json
{
  "status": "ok",
  "timestamp": "2025-11-02T15:30:45+00:00",
  "version": "1.0.0"
}
```

**If you get connection refused:**

```bash
# Check if web server is running on the Node server
ssh user@your-server.com
sudo systemctl status apache2  # or nginx

# Check if PHP-FPM is running
sudo systemctl status php8.3-fpm
```

#### 2. Verify DNS Resolution

Test if the Dashboard server can resolve the Node hostname:

```bash
# From Dashboard server
nslookup your-server.com
dig your-server.com

# Test direct connection
ping your-server.com
```

**If DNS fails:**

Edit `/etc/hosts` on Dashboard server:
```
203.0.113.50  your-server.com
```

#### 3. Check Firewall Rules

Verify the Node server accepts connections from the Dashboard:

```bash
# On Node server - check firewall
sudo ufw status verbose

# On Node server - check if port 443 is open
sudo netstat -tlnp | grep :443

# On Node server - check Apache/Nginx is listening
sudo lsof -i :443
```

**Open firewall if needed:**

```bash
# Allow Dashboard server IP
sudo ufw allow from 203.0.113.100 to any port 443

# Or allow all HTTPS (less secure)
sudo ufw allow 443/tcp
```

#### 4. Verify SSL Certificate

Check SSL certificate validity:

```bash
# From Dashboard server
openssl s_client -connect your-server.com:443 -servername your-server.com
```

Look for:
- `Verify return code: 0 (ok)` - Certificate is valid
- `Verify return code: 21 (unable to verify)` - Certificate issue

**If using self-signed certificate:**

You may need to disable SSL verification (not recommended for production):

```php
// In ServerDataCollector.php - only for development
$client = new \GuzzleHttp\Client([
    'verify' => false  // Disable SSL verification
]);
```

#### 5. Check servers.json Configuration

Verify Dashboard configuration:

```bash
cat config/servers.json | grep -A 10 "your-server-name"
```

Common issues:
- **Wrong URL:** Ensure URL ends with `/` and API URL ends with `/api`
- **Typo in hostname:** Double-check spelling
- **Wrong protocol:** Ensure `https://` not `http://` (or vice versa)

**Correct format:**
```json
{
    "production-server": {
        "url": "https://server.com/",
        "api_url": "https://server.com/mata-node/api",
        "api_key": "valid-32-character-key"
    }
}
```

#### 6. Test with curl from Dashboard Server

Complete API test:

```bash
curl -X GET \
  -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" \
  -H "Accept: application/json" \
  -v \
  https://your-server.com/mata-node/api/server-data
```

**Analyze response:**
- **200 OK:** Node is working, check Dashboard logs
- **401 Unauthorized:** API key mismatch (see [API Errors](#api-errors))
- **404 Not Found:** Wrong API URL path
- **500 Internal Server Error:** Node PHP error (check Node logs)
- **Connection timeout:** Firewall or network issue

### Solutions

#### Solution 1: Restart Node Web Server

```bash
# On Node server
sudo systemctl restart apache2  # or nginx
sudo systemctl restart php8.3-fpm
```

#### Solution 2: Fix servers.json URL

```bash
# On Dashboard server
nano config/servers.json
```

Ensure proper format:
```json
"api_url": "https://server.com/mata-node/api"
```

NOT:
```json
"api_url": "https://server.com/mata-node/api/"  // Wrong: trailing slash
"api_url": "https://server.com/mata-node"        // Wrong: missing /api
```

#### Solution 3: Clear Dashboard Cache

```bash
# On Dashboard server
rm -rf temp/api-cache/*
```

Refresh the Dashboard page.

#### Solution 4: Enable Verbose Logging

Temporarily enable debug logging on Dashboard:

```bash
# Edit config.env
nano config/config.env
```

Set:
```bash
LOG_LEVEL=notices
MONOLOG_LEVEL=debug
TEMPLATE_DEBUG_ENABLED=ON
```

Monitor logs:
```bash
tail -f logs/app.log
```

Attempt to refresh server data and check logs for detailed error messages.

---

## Metrics Not Updating

### Symptoms

- Dashboard shows stale data
- "Last updated" timestamp not changing
- Metrics frozen at old values
- Server shows as connected but data doesn't refresh

### Diagnostic Steps

#### 1. Check Cache Age

View cache files:

```bash
ls -lah temp/api-cache/
```

Check if cache files are being updated:

```bash
# Watch cache directory for changes
watch -n 5 'ls -lah temp/api-cache/ | tail -10'
```

#### 2. Verify Cron Jobs

Check if metric collection cron is running:

```bash
# View cron job logs
tail -f /var/log/mata/collect.log

# Check crontab is configured
crontab -l | grep mata
```

Expected cron entries:
```cron
*/5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/servers_metrics_collect.php
5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/servers_metrics_aggregate.php
```

#### 3. Manual Cron Execution

Run metric collection manually:

```bash
php /path/to/mata-dashboard/cron/servers_metrics_collect.php
```

**Expected output:**
```
[2025-11-02 15:40:00] INFO: Starting server metrics collection
[2025-11-02 15:40:01] INFO: Collecting metrics from production-web-server
[2025-11-02 15:40:02] SUCCESS: Collected 12 metrics from production-web-server
```

**Error output:**
```
[2025-11-02 15:40:00] ERROR: Failed to connect to production-web-server
[2025-11-02 15:40:00] ERROR: Connection timeout after 30 seconds
```

#### 4. Check TTL Configuration

Verify cache TTL settings:

```bash
cat config/ttl.json
```

Default values:
```json
{
    "ping": 360,
    "apps": 60,
    "serverData": 10,
    "databases": 10,
    "appLogs": 30
}
```

If values are too high (e.g., 3600 seconds = 1 hour), data will appear stale.

#### 5. Test Manual Refresh

Use Dashboard force-refresh button:

1. Navigate to server card
2. Click **Refresh** icon
3. Check browser Network tab (F12) for API calls
4. Look for `/api/refresh-server-data` endpoint

If manual refresh works but automatic doesn't, issue is with cron jobs.

### Solutions

#### Solution 1: Fix Cron Jobs

Install cron jobs properly:

```bash
crontab -e
```

Add:
```cron
# MATA Dashboard - Collect metrics every 5 minutes
*/5 * * * * /usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_collect.php >> /var/log/mata/collect.log 2>&1

# MATA Dashboard - Aggregate metrics hourly
5 * * * * /usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_aggregate.php >> /var/log/mata/aggregate.log 2>&1
```

Verify:
```bash
# Wait 5 minutes, then check logs
tail -f /var/log/mata/collect.log
```

#### Solution 2: Adjust TTL Values

Edit TTL configuration:

```bash
nano config/ttl.json
```

Reduce cache duration for more frequent updates:

```json
{
    "serverData": 5,      // 5 seconds (very fresh, higher API load)
    "apps": 30,           // 30 seconds
    "databases": 15,      // 15 seconds
    "processes": 20       // 20 seconds
}
```

> **Note:** Lower TTL = more API requests = higher server load

#### Solution 3: Clear and Rebuild Cache

```bash
# Stop web server to prevent partial writes
sudo systemctl stop apache2  # or nginx

# Clear all caches
rm -rf temp/api-cache/*
rm -rf temp/templates-cache/*

# Restart web server
sudo systemctl start apache2

# Force metric collection
php cron/servers_metrics_collect.php
```

#### Solution 4: Check Database for Stale Data

Query recent metrics:

```sql
-- Connect to database
mysql -u mata_user -p mata_dashboard

-- Check latest server metrics
SELECT server_identifier, metric_name, metric_value, created_at
FROM server_metrics_raw
WHERE server_identifier = 'production-web-server'
ORDER BY created_at DESC
LIMIT 20;
```

If no recent entries, cron jobs are not running or failing.

#### Solution 5: Enable Debug Mode

Temporarily enable debug for ServerDataCollector:

```bash
nano config/config.env
```

Set:
```bash
DISPLAY_ERRORS=1
LOG_LEVEL=notices
```

Check logs when accessing dashboard:
```bash
tail -f logs/app.log | grep -i "ServerDataCollector"
```

---

## Alarms Not Received

### Symptoms

- Threshold exceeded but no email received
- Events logged but notifications not sent
- Email recipients configured but inbox empty
- Dashboard shows alarms but no notifications

### Diagnostic Steps

#### 1. Verify Email Configuration

Check mail config file exists:

```bash
cat config/mail.config.env | grep MAIL_ALARM_RECIPIENTS
```

Expected:
```bash
MAIL_ALARM_RECIPIENTS=admin@domain.com,alarms@domain.com
```

#### 2. Test Email System

Send test email:

```bash
php scripts/test_email.php
```

Or via Dashboard UI:
- Navigate to **Settings** → **Notifications**
- Click **Send Test Email**

Check email inbox for test message.

#### 3. Check Alarm Configuration

Verify alarm settings in thresholds.json:

```bash
cat config/thresholds.json | grep -A 3 "remaining_diskspace_too_low"
```

Expected:
```json
"remaining_diskspace_too_low": {
    "alarm_threshold_event_count": 1,
    "min_alarm_interval_minutes": 180
}
```

**Key settings:**
- `alarm_threshold_event_count`: Number of events before alarm (if 3, need 3 violations)
- `min_alarm_interval_minutes`: Minimum time between notifications

#### 4. Check Event Log

Query events table:

```sql
SELECT * FROM events
WHERE event_type = 'remaining_diskspace_too_low'
  AND severity = 'alarm'
ORDER BY created_at DESC
LIMIT 10;
```

Check `notification_sent` column:
- `0`: Notification pending or failed
- `1`: Notification sent successfully

#### 5. Review Email Logs

Check email sending logs:

```bash
tail -f logs/mail.log
```

Look for:
```
[2025-11-02 15:45:00] INFO: Sending alarm email to admin@domain.com
[2025-11-02 15:45:01] SUCCESS: Email sent successfully
```

Or errors:
```
[2025-11-02 15:45:00] ERROR: SMTP connection failed
[2025-11-02 15:45:00] ERROR: Authentication failed for user@smtp.com
```

### Solutions

#### Solution 1: Configure Email Recipients

Edit mail configuration:

```bash
nano config/mail.config.env
```

Set recipients:
```bash
MAIL_ALARM_RECIPIENTS=admin@your-domain.com,oncall@your-domain.com
```

Save and test:
```bash
php scripts/test_email.php
```

#### Solution 2: Fix SMTP Configuration

Edit SMTP settings:

```bash
nano config/mail.config.env
```

**For Gmail:**
```bash
SYM_MAILER_DSN=gmail://username%40gmail.com:app-password@default
SYM_FROM_EMAIL=noreply@your-domain.com
```

**For Generic SMTP:**
```bash
SYM_MAILER_DSN=smtp://username:password@smtp.provider.com:587
SYM_FROM_EMAIL=noreply@your-domain.com
```

**For SendGrid:**
```bash
SYM_MAILER_DSN=sendgrid://YOUR_API_KEY@default
```

Test after changes:
```bash
php scripts/test_email.php
```

#### Solution 3: Adjust Alarm Threshold Event Count

If `alarm_threshold_event_count` is too high:

```bash
nano config/thresholds.json
```

Change:
```json
"remaining_diskspace_too_low": {
    "alarm_threshold_event_count": 1,  // Changed from 3 to 1
    "min_alarm_interval_minutes": 180
}
```

This will send email on first violation instead of third.

#### Solution 4: Reset Notification Interval

If within `min_alarm_interval_minutes` window:

```sql
-- Force reset notification sent flag
UPDATE events
SET notification_sent = 0,
    notification_sent_at = NULL
WHERE event_type = 'remaining_diskspace_too_low'
  AND severity = 'alarm'
ORDER BY created_at DESC
LIMIT 1;
```

Next alarm occurrence will trigger email.

#### Solution 5: Check Spam Folder

Search email spam/junk folder for:
- Subject: `[MATA ALARM]`
- From: `noreply@your-domain.com`

If found, add to whitelist/safe senders.

#### Solution 6: Enable Email Debugging

```bash
nano config/mail.config.env
```

Add:
```bash
# Log all emails to file for debugging
MAIL_LOG_ONLY=true
```

Check:
```bash
tail -f logs/mail.log
```

This will show email content without actually sending.

---

## Cron Jobs Not Running

### Symptoms

- Metrics not collecting automatically
- Logs show no recent cron execution
- Database has no new metric entries
- Cron log files empty or missing

### Diagnostic Steps

#### 1. Verify Crontab Configuration

Check if cron jobs are installed:

```bash
crontab -l
```

Expected output:
```cron
*/5 * * * * /usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_collect.php >> /var/log/mata/collect.log 2>&1
5 * * * * /usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_aggregate.php >> /var/log/mata/aggregate.log 2>&1
```

If empty, cron jobs are not installed.

#### 2. Check Cron Service

Verify cron daemon is running:

```bash
sudo systemctl status cron
```

Expected:
```
● cron.service - Regular background program processing daemon
   Active: active (running) since Mon 2025-11-02 10:00:00 UTC
```

#### 3. Test Manual Execution

Run cron script manually:

```bash
/usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_collect.php
```

**If successful:**
```
[2025-11-02 15:40:00] INFO: Starting server metrics collection
[2025-11-02 15:40:02] SUCCESS: Metrics collected
```

**If error:**
```
PHP Fatal error: Class 'Mata\...' not found
```

This indicates autoloader or path issues.

#### 4. Check File Permissions

Verify cron scripts are executable:

```bash
ls -la cron/*.php
```

Expected:
```
-rwxr-xr-x  cron/servers_metrics_collect.php
-rwxr-xr-x  cron/servers_metrics_aggregate.php
```

#### 5. Review Cron Logs

Check system cron log:

```bash
sudo tail -f /var/log/syslog | grep CRON
```

Expected:
```
Nov  2 15:40:01 server CRON[12345]: (www-data) CMD (/usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_collect.php)
```

Check application cron logs:

```bash
tail -f /var/log/mata/collect.log
tail -f logs/health/collect_server_metrics_health.log
```

### Solutions

#### Solution 1: Install Cron Jobs

Edit crontab:

```bash
crontab -e
```

Add entries:
```cron
# MATA Dashboard - Collect server metrics every 5 minutes
*/5 * * * * /usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_collect.php >> /var/log/mata/collect.log 2>&1

# MATA Dashboard - Aggregate server metrics hourly (at minute 5)
5 * * * * /usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_aggregate.php >> /var/log/mata/aggregate.log 2>&1

# MATA Dashboard - Fetch cronjob heartbeats every 15 minutes
*/15 * * * * /usr/bin/php /var/www/mata-dashboard/cron/fetch_cronjob_heartbeats.php >> /var/log/mata/heartbeats.log 2>&1
```

Save and exit. Verify:
```bash
crontab -l
```

#### Solution 2: Fix File Paths

Ensure absolute paths in crontab:

```bash
# Wrong (relative path)
*/5 * * * * php cron/servers_metrics_collect.php

# Correct (absolute paths)
*/5 * * * * /usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_collect.php
```

Find PHP path:
```bash
which php
# Output: /usr/bin/php
```

Find application path:
```bash
pwd
# Run from MATA dashboard root directory
```

#### Solution 3: Create Log Directories

```bash
# Create log directories
sudo mkdir -p /var/log/mata
sudo mkdir -p /var/www/mata-dashboard/logs/cron

# Set ownership
sudo chown -R www-data:www-data /var/log/mata
sudo chown -R www-data:www-data /var/www/mata-dashboard/logs

# Set permissions
sudo chmod -R 755 /var/log/mata
sudo chmod -R 755 /var/www/mata-dashboard/logs
```

#### Solution 4: Run Cron as Correct User

If running as root but web server runs as `www-data`:

```bash
# Install crontab for www-data user
sudo crontab -u www-data -e
```

Add cron entries, save, verify:
```bash
sudo crontab -u www-data -l
```

#### Solution 5: Enable Cron Email Notifications

Add to top of crontab:

```cron
MAILTO=admin@your-domain.com

# Your cron jobs below...
*/5 * * * * /usr/bin/php /var/www/mata-dashboard/cron/servers_metrics_collect.php
```

You'll receive email if cron job fails.

#### Solution 6: Restart Cron Service

```bash
sudo systemctl restart cron
sudo systemctl status cron
```

#### Solution 7: Debug Cron Environment

Create diagnostic cron job:

```cron
*/5 * * * * /usr/bin/env > /tmp/cron_env.txt 2>&1
```

Wait 5 minutes, then check:
```bash
cat /tmp/cron_env.txt
```

This shows environment variables available to cron (often limited compared to shell).

---

## Database Connection Issues

### Symptoms

- "Database connection failed" error
- "Access denied for user" error
- Dashboard shows blank page
- Error logs show MySQL connection errors

### Diagnostic Steps

#### 1. Verify Database Configuration

Check database config:

```bash
cat config/config.env | grep DB_
```

Or if using dedicated file:
```bash
cat config/db.config.env
```

Expected values:
```bash
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=mata_dashboard
DB_USERNAME=mata_user
DB_PASSWORD=secure_password
```

#### 2. Test MySQL Connection

Test connection from command line:

```bash
mysql -h localhost -P 3306 -u mata_user -p
# Enter password when prompted
```

**If successful:**
```
Welcome to the MySQL monitor.
mysql>
```

**If failed:**
```
ERROR 1045 (28000): Access denied for user 'mata_user'@'localhost' (using password: YES)
```

#### 3. Check MySQL Service

Verify MySQL is running:

```bash
sudo systemctl status mysql
# or
sudo systemctl status mariadb
```

Expected:
```
● mysql.service - MySQL Community Server
   Active: active (running)
```

#### 4. Verify Database Exists

Connect to MySQL:

```bash
mysql -u root -p
```

Check database:
```sql
SHOW DATABASES;
USE mata_dashboard;
SHOW TABLES;
```

#### 5. Check User Permissions

```sql
-- Connect as root
mysql -u root -p

-- Check user exists
SELECT user, host FROM mysql.user WHERE user = 'mata_user';

-- Check permissions
SHOW GRANTS FOR 'mata_user'@'localhost';
```

Expected grants:
```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON mata_dashboard.* TO 'mata_user'@'localhost'
```

### Solutions

#### Solution 1: Create Database and User

```sql
-- Connect as root
mysql -u root -p

-- Create database
CREATE DATABASE mata_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user
CREATE USER 'mata_user'@'localhost' IDENTIFIED BY 'secure_password';

-- Grant permissions
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON mata_dashboard.* TO 'mata_user'@'localhost';

-- Apply changes
FLUSH PRIVILEGES;

-- Exit
EXIT;
```

#### Solution 2: Reset User Password

```sql
-- Connect as root
mysql -u root -p

-- Reset password
ALTER USER 'mata_user'@'localhost' IDENTIFIED BY 'new_secure_password';

-- Apply changes
FLUSH PRIVILEGES;

-- Exit
EXIT;
```

Update config:
```bash
nano config/config.env
```

Set:
```bash
DB_PASSWORD=new_secure_password
```

#### Solution 3: Fix Database Configuration

Edit config file:

```bash
nano config/config.env
```

Verify settings:
```bash
DB_HOST=localhost          # Not 127.0.0.1 if user is 'user'@'localhost'
DB_PORT=3306
DB_DATABASE=mata_dashboard
DB_USERNAME=mata_user
DB_PASSWORD=your_actual_password  # No quotes, no extra spaces
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

> **Important:** MySQL distinguishes between `'user'@'localhost'` and `'user'@'127.0.0.1'`

#### Solution 4: Run Database Migrations

Initialize or update database schema:

```bash
# Run Phinx migrations
vendor/bin/phinx migrate -e production

# Or run setup script
php scripts/setup_database.php
```

#### Solution 5: Check MySQL Socket

If connecting via socket:

```bash
# Find socket location
mysql_config --socket
# Output: /var/run/mysqld/mysqld.sock

# Test connection with socket
mysql -u mata_user -p --socket=/var/run/mysqld/mysqld.sock
```

Update config if needed:
```bash
DB_HOST=/var/run/mysqld/mysqld.sock
```

#### Solution 6: Restart MySQL Service

```bash
sudo systemctl restart mysql
sudo systemctl status mysql
```

---

## Permission Errors

### Symptoms

- "Permission denied" when accessing files
- "Failed to write to cache" errors
- "Cannot create directory" errors
- "Unable to open log file" errors

### Diagnostic Steps

#### 1. Check Directory Ownership

```bash
ls -la /var/www/mata-dashboard/
```

Verify web server user owns necessary directories:

```bash
drwxr-xr-x www-data www-data temp/
drwxr-xr-x www-data www-data logs/
drwxr-xr-x www-data www-data config/
```

#### 2. Check Directory Permissions

Required permissions:

```bash
drwxr-xr-x (755)  - config/
drwxr-xr-x (755)  - temp/
drwxr-xr-x (755)  - temp/api-cache/
drwxr-xr-x (755)  - temp/templates-cache/
drwxr-xr-x (755)  - logs/
drwxr-xr-x (755)  - logs/cron/
-rw------- (600)  - config/servers.json
-rw-r--r-- (644)  - config/config.env
```

#### 3. Identify Web Server User

```bash
# Apache
ps aux | grep apache2 | grep -v root | head -1

# Nginx
ps aux | grep nginx | grep -v root | head -1

# Common users: www-data, apache, nginx, httpd
```

### Solutions

#### Solution 1: Fix Directory Ownership

```bash
# Change ownership to web server user
sudo chown -R www-data:www-data /var/www/mata-dashboard/temp
sudo chown -R www-data:www-data /var/www/mata-dashboard/logs
sudo chown -R www-data:www-data /var/www/mata-dashboard/config
```

#### Solution 2: Fix Directory Permissions

```bash
# Set correct permissions
sudo chmod -R 755 /var/www/mata-dashboard/temp
sudo chmod -R 755 /var/www/mata-dashboard/logs
sudo chmod 755 /var/www/mata-dashboard/config

# Protect sensitive config files
sudo chmod 600 /var/www/mata-dashboard/config/servers.json
sudo chmod 600 /var/www/mata-dashboard/config/config.env
sudo chmod 600 /var/www/mata-dashboard/config/mail.config.env
```

#### Solution 3: Create Missing Directories

```bash
# Create required directories
mkdir -p temp/api-cache
mkdir -p temp/templates-cache
mkdir -p logs/cron
mkdir -p logs/mail

# Set ownership
sudo chown -R www-data:www-data temp/
sudo chown -R www-data:www-data logs/

# Set permissions
chmod -R 755 temp/
chmod -R 755 logs/
```

#### Solution 4: SELinux Context (if applicable)

On RHEL/CentOS with SELinux:

```bash
# Check SELinux status
sestatus

# Set correct context
sudo chcon -R -t httpd_sys_rw_content_t /var/www/mata-dashboard/temp
sudo chcon -R -t httpd_sys_rw_content_t /var/www/mata-dashboard/logs

# Make permanent
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/mata-dashboard/temp(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/mata-dashboard/logs(/.*)?"
```

---

## API Errors

### Symptoms

- "401 Unauthorized" errors
- "403 Forbidden" errors
- "Invalid API key" messages
- "API request failed" errors

### Diagnostic Steps

#### 1. Verify API Key Match

Check Dashboard config:
```bash
grep api_key config/servers.json
```

Check Node config (on remote server):
```bash
grep api_key /var/www/mata-node/config/config.php
```

API keys must match exactly.

#### 2. Test API Key Authentication

```bash
curl -v -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" https://your-server.com/mata-node/api/health
```

**Success:**
```
< HTTP/1.1 200 OK
{"status":"ok"}
```

**Failure:**
```
< HTTP/1.1 401 Unauthorized
{"error":"Invalid API key"}
```

#### 3. Check IP Whitelist

On Node server:
```bash
cat /var/www/mata-node/config/config.php | grep allowed_ips
```

Ensure Dashboard IP is listed:
```php
'allowed_ips' => [
    '203.0.113.100',  // Dashboard server IP
],
```

Find Dashboard IP:
```bash
# From Node server
echo $SSH_CLIENT | awk '{print $1}'

# Or from Dashboard server
curl ifconfig.me
```

### Solutions

#### Solution 1: Regenerate API Key

Generate new key:
```bash
openssl rand -hex 32
```

Update Dashboard:
```bash
nano config/servers.json
```
```json
"api_key": "NEW_KEY_HERE"
```

Update Node:
```bash
ssh user@node-server
nano /var/www/mata-node/config/config.php
```
```php
'api_key' => 'NEW_KEY_HERE',
```

Test:
```bash
curl -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" https://node-server.com/mata-node/api/health
```

#### Solution 2: Add Dashboard IP to Whitelist

On Node server:
```bash
nano /var/www/mata-node/config/config.php
```

Add Dashboard IP:
```php
return [
    'api_key' => 'your-key',
    'allowed_ips' => [
        '203.0.113.100',  // Dashboard server
        '203.0.113.101',  // Backup Dashboard
    ],
];
```

#### Solution 3: Disable IP Restriction (Development Only)

**WARNING: Only for development/testing**

On Node server:
```bash
nano /var/www/mata-node/config/config.php
```

Set to empty array:
```php
'allowed_ips' => [],  // Allows all IPs
```

> **Never do this in production** - security risk

---

## Performance Issues

### Symptoms

- Dashboard loads slowly
- Timeout errors
- High server load
- Slow API responses

### Diagnostic Steps

#### 1. Check Cache Hit Rate

Monitor cache usage:

```bash
# Count cache files
ls -1 temp/api-cache/ | wc -l

# Check cache file ages
ls -lah temp/api-cache/ | head -20

# Monitor cache writes
watch -n 5 'ls -lah temp/api-cache/ | tail -10'
```

#### 2. Monitor API Response Times

Time API requests:

```bash
time curl -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" https://server.com/mata-node/api/server-data
```

Expected: < 2 seconds
Slow: > 5 seconds

#### 3. Check Database Query Performance

Enable MySQL slow query log:

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;  -- Log queries taking > 2 seconds
```

Monitor:
```bash
tail -f /var/log/mysql/mysql-slow.log
```

#### 4. Profile Application

Enable profiling:
```bash
nano config/config.env
```

Set:
```bash
DISPLAY_ERRORS=1
LOG_LEVEL=notices
```

Check execution time in logs:
```bash
tail -f logs/app.log | grep -i "execution time"
```

### Solutions

#### Solution 1: Optimize Cache TTL

Increase cache duration:

```bash
nano config/ttl.json
```

Adjust values:
```json
{
    "serverData": 30,     // Increased from 10 to 30 seconds
    "apps": 120,          // Increased from 60 to 120 seconds
    "databases": 60       // Increased from 10 to 60 seconds
}
```

This reduces API calls but shows older data.

#### Solution 2: Enable OPcache

Check if OPcache is enabled:

```bash
php -i | grep opcache.enable
```

Enable in php.ini:

```bash
sudo nano /etc/php/8.3/apache2/php.ini
# or
sudo nano /etc/php/8.3/fpm/php.ini
```

Add:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

Restart:
```bash
sudo systemctl restart apache2
# or
sudo systemctl restart php8.3-fpm
```

#### Solution 3: Add Database Indexes

```sql
-- Connect to database
mysql -u mata_user -p mata_dashboard

-- Add indexes for common queries
CREATE INDEX idx_server_created ON server_metrics_raw(server_identifier, created_at);
CREATE INDEX idx_event_type_created ON events(event_type, created_at);
CREATE INDEX idx_severity_created ON events(severity, created_at);
```

#### Solution 4: Increase PHP Memory Limit

```bash
sudo nano /etc/php/8.3/apache2/php.ini
```

Increase:
```ini
memory_limit = 256M  ; Increased from 128M
max_execution_time = 60  ; Increased from 30
```

Restart:
```bash
sudo systemctl restart apache2
```

#### Solution 5: Clean Old Data

Remove old cached data and logs:

```bash
# Remove cache older than 1 day
find temp/api-cache/ -type f -mtime +1 -delete

# Remove logs older than 30 days
find logs/ -name "*.log" -mtime +30 -delete

# Clean database (keep last 90 days)
mysql -u mata_user -p mata_dashboard -e "
DELETE FROM server_metrics_raw
WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
"
```

---

## Getting Further Help

If you're still experiencing issues after trying these solutions:

### 1. Collect Diagnostic Information

```bash
# System information
uname -a
php -v
mysql --version

# Application logs
tail -100 logs/app.log > mata_debug.txt
tail -100 /var/log/mata/collect.log >> mata_debug.txt

# Configuration (sanitized)
cat config/config.env | grep -v PASSWORD >> mata_debug.txt

# File permissions
ls -laR temp/ logs/ config/ >> mata_debug.txt
```

### 2. Create GitHub Issue

Visit: [https://github.com/xarcdotdev/mata-dashboard/issues](https://github.com/xarcdotdev/mata-dashboard/issues)

Include:
- MATA Dashboard version
- PHP version
- Operating system
- Problem description
- Steps to reproduce
- Relevant log excerpts (without sensitive data)

### 3. Community Resources

- **Documentation:** [https://github.com/xarcdotdev/mata-dashboard/docs](https://github.com/xarcdotdev/mata-dashboard/docs)
- **Example Configurations:** Check `config/example/` directory
- **Search Existing Issues:** Someone may have solved your problem

---

## Quick Reference

### Common Commands

```bash
# Test Node API
curl -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" https://server.com/mata-node/api/health

# Validate JSON config
php -r "json_decode(file_get_contents('config/servers.json'));"

# Clear all caches
rm -rf temp/api-cache/* temp/templates-cache/*

# View logs in real-time
tail -f logs/app.log

# Test email
php scripts/test_email.php

# Manual metric collection
php cron/servers_metrics_collect.php

# Check cron jobs
crontab -l

# Test database connection
mysql -h localhost -u mata_user -p mata_dashboard

# Fix permissions
sudo chown -R www-data:www-data temp/ logs/
sudo chmod -R 755 temp/ logs/
```

### Log Locations

| Log File | Purpose |
|----------|---------|
| `logs/app.log` | Application errors and debug info |
| `logs/mail.log` | Email sending log |
| `logs/cron/*.log` | Cron job execution logs |
| `/var/log/mata/collect.log` | Metric collection log |
| `/var/log/apache2/error.log` | Apache errors |
| `/var/log/nginx/error.log` | Nginx errors |
| `/var/log/mysql/error.log` | MySQL errors |

### Configuration Files

| File | Purpose |
|------|---------|
| `config/config.env` | Main application configuration |
| `config/servers.json` | Server endpoints and API keys |
| `config/thresholds.json` | Alarm thresholds |
| `config/ttl.json` | Cache TTL settings |
| `config/mail.config.env` | Email/SMTP configuration |

---

**Last Updated:** November 2025
**MATA Dashboard Version:** 1.0+
