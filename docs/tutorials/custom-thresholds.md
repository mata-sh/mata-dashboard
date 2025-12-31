# Configuring Custom Alert Thresholds

This tutorial explains how to configure custom alert thresholds for server monitoring and application health checks in MATA Dashboard.

## Overview

MATA Dashboard uses a sophisticated threshold-based alerting system with four severity levels. This tutorial will teach you how to:

- Understand the threshold level system
- Configure server monitoring thresholds
- Set up application-specific thresholds
- Configure alarm behavior and notification intervals
- Test threshold violations
- Implement best practices for production environments

**Time Required:** 20-30 minutes

## Prerequisites

Before you begin, ensure you have:

- ✅ MATA Dashboard installed with at least one monitored server
- ✅ Basic understanding of server metrics (CPU, RAM, disk usage)
- ✅ Access to the `config/` directory on the Dashboard server
- ✅ Text editor for JSON configuration files

---

## Understanding Threshold Levels

MATA uses a four-level threshold system to categorize metric severity:

```
┌──────────────────────────────────────────────────┐
│  Status Level    │  Color   │  UI Indicator      │
├──────────────────────────────────────────────────┤
│  GOOD            │  Green   │  ● Normal          │
│  WARN            │  Yellow  │  ▲ Warning         │
│  CRITICAL        │  Orange  │  ⚠ Critical        │
│  ALARM           │  Red     │  🔔 Alarm          │
└──────────────────────────────────────────────────┘
```

### Level Descriptions

| Level | Purpose | Dashboard Behavior | Notifications |
|-------|---------|-------------------|---------------|
| **GOOD** | Normal operation | Green indicators | None |
| **WARN** | Early warning | Yellow indicators | Optional (configurable) |
| **CRITICAL** | Requires attention | Orange indicators, logged event | Optional (configurable) |
| **ALARM** | Immediate action needed | Red indicators, logged alarm | Email notification sent |

### How Thresholds Work

Thresholds define the boundary **values** at which a metric transitions from one level to the next:

```
Example: Disk Space Usage (%)

0%──────────60%───────70%───────80%─────────100%
         │         │         │
       GOOD      WARN    CRITICAL
                                  ALARM (above 80%)
```

- **0-60%**: Status = GOOD (green)
- **60-70%**: Status = WARN (yellow)
- **70-80%**: Status = CRITICAL (orange)
- **80%+**: Status = ALARM (red, triggers notification)

---

## Step 1: Locate and Backup Configuration

### 1.1 Find thresholds.json

Navigate to your Dashboard's config directory:

```bash
cd /path/to/mata-dashboard/config
```

Check if `thresholds.json` exists:

```bash
ls -la thresholds.json
```

### 1.2 Create from Example (if needed)

If the file doesn't exist, copy from the example:

```bash
cp example/thresholds.json.example thresholds.json
```

### 1.3 Create Backup

Always backup before making changes:

```bash
cp thresholds.json thresholds.json.backup.$(date +%Y%m%d_%H%M%S)
```

Example: `thresholds.json.backup.20251102_153045`

---

## Step 2: Understanding the Configuration Structure

### 2.1 Configuration File Overview

Open `thresholds.json`:

```bash
nano thresholds.json
```

The file is organized into three main sections:

```json
{
    "server_data": {
        // Server-level metrics (disk, logs, file counts)
    },
    "app_data": {
        // Application-specific metrics (sessions, exports)
    },
    "sql": {
        // Database metrics (connections, queries)
    },
    "alarms": {
        // Alarm behavior configuration
    }
}
```

### 2.2 Threshold Definition Structure

Each metric follows this pattern:

```json
"metric_name": {
    "good": 60,      // Green status below this value
    "warn": 70,      // Yellow status between good and warn
    "critical": 80,  // Orange status between warn and critical
    "alarm": 90      // Red status at or above alarm (triggers notification)
}
```

> **Important:** Values should always be in ascending order: `good < warn < critical < alarm`

---

## Step 3: Configure Server Data Thresholds

### 3.1 Disk Space Usage

Configure when to alert on disk space:

```json
"server_data": {
    "disk_space_usage_percent": {
        "good": 60,
        "warn": 70,
        "critical": 80,
        "alarm": 90
    }
}
```

**Recommendations by Server Type:**

| Server Type | GOOD | WARN | CRITICAL | ALARM |
|-------------|------|------|----------|-------|
| Database Server | 50% | 65% | 75% | 85% |
| Web Server | 60% | 70% | 80% | 90% |
| File Server | 70% | 80% | 85% | 90% |
| Development | 70% | 80% | 90% | 95% |

**Example: Production Database Server (conservative thresholds)**

```json
"disk_space_usage_percent": {
    "good": 50,
    "warn": 65,
    "critical": 75,
    "alarm": 85
}
```

### 3.2 Log File Size

Set thresholds for application log file sizes (in KB):

```json
"log_filesize_kb": {
    "good": 10240,      // 10 MB
    "warn": 102400,     // 100 MB
    "critical": 256000, // 250 MB
    "alarm": 512000     // 500 MB
}
```

**Conversion Reference:**

| Size | KB Value | JSON Value |
|------|----------|------------|
| 1 MB | 1,024 KB | `1024` |
| 10 MB | 10,240 KB | `10240` |
| 100 MB | 102,400 KB | `102400` |
| 250 MB | 256,000 KB | `256000` |
| 500 MB | 512,000 KB | `512000` |
| 1 GB | 1,048,576 KB | `1048576` |

**Example: High-Traffic Application**

```json
"log_filesize_kb": {
    "good": 51200,      // 50 MB (higher baseline)
    "warn": 204800,     // 200 MB
    "critical": 512000, // 500 MB
    "alarm": 1048576    // 1 GB
}
```

### 3.3 System File Count

Configure thresholds for total filesystem inodes/files:

```json
"total_system_filecount": {
    "good": 500000,
    "warn": 750000,
    "critical": 850000,
    "alarm": 900000
}
```

> **Note:** This is particularly important for servers that generate many temporary files or have intensive logging.

---

## Step 4: Configure Application Thresholds

### 4.1 Session Count

Set thresholds for active user sessions:

```json
"app_data": {
    "session_count": {
        "good": 100,
        "warn": 500,
        "critical": 2500,
        "alarm": 5000
    }
}
```

**Example Configurations:**

**Small Business Site (< 1,000 daily visitors):**
```json
"session_count": {
    "good": 50,
    "warn": 100,
    "critical": 250,
    "alarm": 500
}
```

**Medium E-commerce Site (1,000-10,000 daily visitors):**
```json
"session_count": {
    "good": 500,
    "warn": 1000,
    "critical": 2500,
    "alarm": 5000
}
```

**Large Application (10,000+ concurrent users):**
```json
"session_count": {
    "good": 5000,
    "warn": 10000,
    "critical": 25000,
    "alarm": 50000
}
```

### 4.2 Order Export Age

For e-commerce applications, monitor stale order exports (in minutes):

```json
"order_export_age_minutes": {
    "good": 10,
    "warn": 60,
    "critical": 120,
    "alarm": 180
}
```

**Business Logic Example:**

If your system exports orders to a fulfillment center:
- **GOOD**: Exports processed within 10 minutes (normal)
- **WARN**: Exports delayed 1 hour (investigate)
- **CRITICAL**: Exports delayed 2 hours (delayed fulfillment)
- **ALARM**: Exports delayed 3+ hours (business impact)

---

## Step 5: Configure Database Thresholds

### 5.1 Maximum SQL Connections

Set thresholds for database connection pool usage (as percentage):

```json
"sql": {
    "max_connections_percent": {
        "good": 10,
        "warn": 30,
        "critical": 50,
        "alarm": 75
    }
}
```

**Example: High-Traffic Database**

```json
"max_connections_percent": {
    "good": 20,      // Higher baseline acceptable
    "warn": 40,
    "critical": 60,
    "alarm": 80
}
```

> **Why percentage matters:** If `max_connections` in MySQL is 100, an alarm at 75% means 75 active connections. Reaching 100% will cause connection refusals.

---

## Step 6: Configure Alarm Behavior

The `alarms` section controls notification frequency and event counting.

### 6.1 Alarm Configuration Structure

Each alarm type has two settings:

```json
"alarm_name": {
    "alarm_threshold_event_count": 3,  // Number of occurrences before alarm
    "min_alarm_interval_minutes": 60   // Minimum time between notifications
}
```

### 6.2 Event Count Thresholds

**Purpose:** Prevents alarm fatigue from transient issues.

```json
"app_unreachable": {
    "alarm_threshold_event_count": 3,
    "min_alarm_interval_minutes": 60
}
```

This means:
- First occurrence: Logged as WARNING
- Second occurrence: Logged as CRITICAL
- **Third occurrence: Sends email alarm**
- Must occur within the interval window

### 6.3 Notification Intervals

**Purpose:** Prevents notification spam.

```json
"remaining_disk_space_too_low": {
    "alarm_threshold_event_count": 1,
    "min_alarm_interval_minutes": 180
}
```

This means:
- Email sent immediately on first detection (event_count = 1)
- No additional emails for 180 minutes (3 hours)
- Even if disk space remains critical

### 6.4 Complete Alarm Configuration

```json
"alarms": {
    "app_unreachable": {
        "alarm_threshold_event_count": 3,
        "min_alarm_interval_minutes": 60
    },
    "orders_export_stale": {
        "alarm_threshold_event_count": 1,
        "min_alarm_interval_minutes": 60
    },
    "cronjob_heartbeat_missing": {
        "alarm_threshold_event_count": 1,
        "min_alarm_interval_minutes": 360
    },
    "filecount_too_large": {
        "alarm_threshold_event_count": 1,
        "min_alarm_interval_minutes": 60
    },
    "logfile_too_large": {
        "alarm_threshold_event_count": 1,
        "min_alarm_interval_minutes": 720
    },
    "too_many_sql_connections": {
        "alarm_threshold_event_count": 1,
        "min_alarm_interval_minutes": 60
    },
    "servers_config_not_found": {
        "alarm_threshold_event_count": 1,
        "min_alarm_interval_minutes": 1440
    },
    "remaining_disk_space_too_low": {
        "alarm_threshold_event_count": 1,
        "min_alarm_interval_minutes": 180
    },
    "fatal_errors_occuring": {
        "alarm_threshold_event_count": 1,
        "min_alarm_interval_minutes": 1440
    }
}
```

**Recommended Intervals:**

| Issue Type | Event Count | Interval | Reasoning |
|------------|-------------|----------|-----------|
| Transient network issues | 3 | 60 min | Avoid false alarms |
| Disk space critical | 1 | 180 min | Immediate alert, infrequent reminders |
| Fatal application errors | 1 | 1440 min (24h) | Critical but one notification per day |
| Missing cron heartbeat | 1 | 360 min (6h) | Check for missed jobs |

---

## Step 7: Validate and Apply Configuration

### 7.1 Validate JSON Syntax

Before saving, validate your JSON:

```bash
php -r "json_decode(file_get_contents('config/thresholds.json')) ?: exit(1);" && echo "✓ Valid JSON" || echo "✗ Invalid JSON"
```

Or use a JSON validator:

```bash
# Using jq (install with: apt install jq)
jq empty config/thresholds.json && echo "✓ Valid JSON"
```

### 7.2 Check Value Logic

Verify threshold order:

```bash
# Simple grep check
grep -A 4 '"disk_space_usage_percent"' config/thresholds.json
```

Ensure: `good < warn < critical < alarm`

### 7.3 Apply Changes

Configuration changes take effect immediately (no restart required). However, to force re-evaluation:

```bash
# Clear cache to force fresh evaluation
rm -rf temp/api_cache/*
```

### 7.4 Monitor Application Logs

Watch for configuration errors:

```bash
tail -f logs/app.log
```

Look for entries like:

```
[2025-11-02 15:45:00] INFO: Loaded thresholds configuration
[2025-11-02 15:45:00] DEBUG: disk_space_usage_percent thresholds: good=60, warn=70, critical=80, alarm=90
```

---

## Step 8: Test Threshold Violations

### 8.1 Simulate High Disk Usage (Test Environment Only)

**WARNING: Only do this on test/development servers**

Create a large file to trigger disk alerts:

```bash
# Create a 1GB test file
dd if=/dev/zero of=/tmp/test_disk_fill.img bs=1M count=1000

# Check disk usage
df -h /

# Clean up when done
rm /tmp/test_disk_fill.img
```

### 8.2 Monitor Dashboard Response

1. Refresh the dashboard
2. Navigate to the test server card
3. Observe status changes:
   - Disk usage indicator color changes (green → yellow → orange → red)
   - Alert notification appears if alarm threshold exceeded

### 8.3 Verify Event Logging

Check the events table in the database:

```sql
SELECT * FROM events
WHERE event_type = 'remaining_disk_space_too_low'
ORDER BY created_at DESC
LIMIT 5;
```

Or via Dashboard UI:
- Navigate to **Events** page
- Filter by **Server** and **Event Type**
- Check event severity and timestamps

### 8.4 Test Email Notifications

If configured, check for email alerts:

1. Verify `config/mail.config.env` is properly configured
2. Check `MAIL_ALERT_RECIPIENTS` contains your email
3. Look for email with subject: `[MATA ALARM] Disk Space Critical - server-name`

---

## Step 9: Configure Email Alerts

### 9.1 Email Configuration File

Edit mail configuration:

```bash
nano config/mail.config.env
```

Set alert recipients:

```bash
# Alert recipients (receives threshold violation notifications)
MAIL_ALERT_RECIPIENTS=alerts@your-domain.com,sysadmin@your-domain.com,oncall@your-domain.com
```

### 9.2 SMTP Settings

Configure your email provider:

```bash
# Symfony Mailer DSN (recommended for production)
SYM_MAILER_DSN=smtp://username:password@smtp.mailprovider.com:587
SYM_FROM_EMAIL=noreply@your-domain.com
SYM_FROM_NAME=MATA Dashboard Alerts
```

**Common SMTP Providers:**

**Gmail:**
```bash
SYM_MAILER_DSN=gmail://username%40gmail.com:app-password@default
```

**SendGrid:**
```bash
SYM_MAILER_DSN=sendgrid://YOUR_API_KEY@default
```

**Postmark:**
```bash
SYM_MAILER_DSN=postmark://YOUR_TOKEN@default
```

### 9.3 Test Email Configuration

Test email sending:

```bash
php /path/to/mata-dashboard/scripts/test_email.php
```

Or trigger a test alert through the Dashboard UI:
- Navigate to **Settings** → **Notifications**
- Click **Send Test Alert**

---

## Best Practices

### 1. Start Conservative, Then Adjust

Begin with **stricter thresholds** and relax them based on real-world data:

```json
// Initial (conservative)
"disk_space_usage_percent": {
    "good": 50,
    "warn": 60,
    "critical": 70,
    "alarm": 80
}

// After 2 weeks of data (adjusted)
"disk_space_usage_percent": {
    "good": 60,
    "warn": 70,
    "critical": 80,
    "alarm": 90
}
```

### 2. Align with Business Impact

Threshold severity should match business consequences:

| Severity | Business Impact | Response Time |
|----------|----------------|---------------|
| GOOD | No impact | No action |
| WARN | Potential future impact | Review next business day |
| CRITICAL | Minor service degradation | Review within 4 hours |
| ALARM | Service outage or data loss risk | Immediate response |

### 3. Consider Time-of-Day Patterns

For applications with predictable traffic:

**Peak Hours (9 AM - 5 PM):**
```json
"session_count": {
    "good": 1000,   // Higher baseline
    "warn": 2000,
    "critical": 3500,
    "alarm": 5000
}
```

**Off-Peak Hours (Midnight - 6 AM):**
```json
"session_count": {
    "good": 100,    // Lower baseline
    "warn": 500,
    "critical": 1000,
    "alarm": 2000
}
```

> **Note:** MATA currently uses single threshold sets. Consider implementing multiple profiles for different time periods in future versions.

### 4. Document Custom Thresholds

Maintain a changelog in your repository:

```markdown
# thresholds-changelog.md

## 2025-11-02 - Production Web Server
- Increased disk alarm threshold from 80% to 90%
- Reason: Frequent false alarms, 85% is normal operational level
- Approved by: SysAdmin Team

## 2025-10-15 - Database Server
- Decreased SQL connection alarm from 80% to 75%
- Reason: Connection pool exhaustion at 80% causing timeouts
- Approved by: Database Team
```

### 5. Set Up Alert Escalation

Configure multiple recipient groups:

```bash
# Tier 1: First responders (immediate)
MAIL_ALERT_RECIPIENTS=oncall@company.com,sysadmin@company.com

# Tier 2: Management (for persistent alarms)
MAIL_CRITICAL_ESCALATION=manager@company.com,director@company.com

# Tier 3: External monitoring service
MAIL_EXTERNAL_MONITORING=alerts@monitoring-service.com
```

### 6. Regular Review Cycle

Schedule quarterly threshold reviews:

```bash
# Generate metrics report
php scripts/generate_threshold_report.php --days=90 > reports/threshold_review_2025_Q4.txt
```

Review metrics:
- How often does each threshold trigger?
- Are alarms actionable or noise?
- Have traffic patterns changed?

---

## Common Threshold Scenarios

### Scenario 1: E-commerce Site

```json
{
    "server_data": {
        "disk_space_usage_percent": {
            "good": 65,
            "warn": 75,
            "critical": 85,
            "alarm": 92
        },
        "log_filesize_kb": {
            "good": 51200,      // 50 MB (transaction logs)
            "warn": 204800,     // 200 MB
            "critical": 512000,
            "alarm": 1048576    // 1 GB
        }
    },
    "app_data": {
        "session_count": {
            "good": 500,        // Normal shopping traffic
            "warn": 1500,       // Peak hours
            "critical": 3000,   // Black Friday levels
            "alarm": 5000       // Site strain
        },
        "order_export_age_minutes": {
            "good": 10,
            "warn": 30,         // Fulfillment SLA
            "critical": 60,
            "alarm": 120        // Customer impact
        }
    },
    "sql": {
        "max_connections_percent": {
            "good": 15,
            "warn": 35,
            "critical": 55,
            "alarm": 75
        }
    }
}
```

### Scenario 2: Internal Application Server

```json
{
    "server_data": {
        "disk_space_usage_percent": {
            "good": 70,         // More relaxed
            "warn": 80,
            "critical": 90,
            "alarm": 95
        },
        "log_filesize_kb": {
            "good": 10240,      // 10 MB
            "warn": 51200,      // 50 MB
            "critical": 102400,
            "alarm": 256000     // 250 MB
        }
    },
    "app_data": {
        "session_count": {
            "good": 50,         // Small team
            "warn": 100,
            "critical": 200,
            "alarm": 500
        }
    }
}
```

### Scenario 3: High-Traffic API Server

```json
{
    "server_data": {
        "disk_space_usage_percent": {
            "good": 60,
            "warn": 70,
            "critical": 80,
            "alarm": 85         // API servers need quick response
        },
        "log_filesize_kb": {
            "good": 102400,     // 100 MB (verbose API logs)
            "warn": 512000,     // 500 MB
            "critical": 1048576,// 1 GB
            "alarm": 2097152    // 2 GB
        }
    },
    "sql": {
        "max_connections_percent": {
            "good": 25,         // Higher baseline for API
            "warn": 45,
            "critical": 65,
            "alarm": 85
        }
    }
}
```

---

## Troubleshooting

### Thresholds Not Taking Effect

**Problem:** Changed thresholds but dashboard still shows old values.

**Solutions:**

1. **Validate JSON syntax:**
   ```bash
   php -r "json_decode(file_get_contents('config/thresholds.json'));" && echo "Valid" || echo "Invalid"
   ```

2. **Check file permissions:**
   ```bash
   ls -la config/thresholds.json
   # Should be readable by web server user
   ```

3. **Clear cache:**
   ```bash
   rm -rf temp/api_cache/*
   rm -rf temp/templates_cache/*
   ```

4. **Check logs for errors:**
   ```bash
   tail -f logs/app.log | grep -i threshold
   ```

### Alarm Emails Not Sending

**Problem:** Threshold exceeded but no email received.

**Solutions:**

1. **Verify mail configuration:**
   ```bash
   grep MAIL_ALERT_RECIPIENTS config/mail.config.env
   ```

2. **Check alarm event count:**
   ```json
   // If set to 3, you need 3 violations before email
   "alarm_threshold_event_count": 3
   ```

3. **Verify notification interval:**
   ```json
   // If already sent within interval, no new email
   "min_alarm_interval_minutes": 60
   ```

4. **Test email system:**
   ```bash
   php scripts/test_email.php
   ```

5. **Check email logs:**
   ```bash
   tail -f logs/mail.log
   ```

### False Alarms

**Problem:** Receiving too many alarm notifications for non-critical issues.

**Solutions:**

1. **Increase alarm thresholds:**
   ```json
   // Before: Too sensitive
   "disk_space_usage_percent": { "alarm": 80 }

   // After: More realistic
   "disk_space_usage_percent": { "alarm": 90 }
   ```

2. **Increase event count requirement:**
   ```json
   // Before: Single event triggers alarm
   "alarm_threshold_event_count": 1

   // After: Requires 3 consecutive events
   "alarm_threshold_event_count": 3
   ```

3. **Lengthen notification interval:**
   ```json
   // Before: Alert every hour
   "min_alarm_interval_minutes": 60

   // After: Alert every 4 hours
   "min_alarm_interval_minutes": 240
   ```

### Missing Alarms

**Problem:** Critical issues not triggering alarms.

**Solutions:**

1. **Lower alarm thresholds:**
   ```bash
   # Review historical data
   php scripts/analyze_metrics.php --metric=disk_space --days=30
   ```

2. **Verify alarm is configured:**
   ```bash
   grep -A 2 "remaining_disk_space_too_low" config/thresholds.json
   ```

3. **Check event logging:**
   ```sql
   SELECT * FROM events WHERE event_type LIKE '%alarm%' ORDER BY created_at DESC LIMIT 10;
   ```

4. **Verify cron jobs are running:**
   ```bash
   # Check last collection time
   ls -lah logs/cron/collect_server_metrics_health.log
   ```

---

## Next Steps

You've successfully configured custom alert thresholds! Here's what to do next:

1. **Monitor for 1-2 weeks:** Observe actual threshold violations and adjust as needed
2. **Set up on-call rotation:** Configure PagerDuty, Opsgenie, or similar for alarm escalation
3. **Create runbooks:** Document response procedures for each alarm type
4. **Implement auto-remediation:** Script automatic responses to common issues
5. **Review historical trends:** Use collected data to predict capacity needs

### Related Documentation

- [Understanding Server Metrics](../reference/server-metrics.md)
- [Troubleshooting Common Issues](../tutorials/troubleshooting.md)
- [Event Logging Reference](../reference/event-system.md)

---

## Quick Reference

### Threshold Configuration Template

```json
{
    "server_data": {
        "disk_space_usage_percent": {"good": 60, "warn": 70, "critical": 80, "alarm": 90},
        "log_filesize_kb": {"good": 10240, "warn": 102400, "critical": 256000, "alarm": 512000},
        "total_system_filecount": {"good": 500000, "warn": 750000, "critical": 850000, "alarm": 900000}
    },
    "app_data": {
        "session_count": {"good": 100, "warn": 500, "critical": 2500, "alarm": 5000},
        "order_export_age_minutes": {"good": 10, "warn": 60, "critical": 120, "alarm": 180}
    },
    "sql": {
        "max_connections_percent": {"good": 10, "warn": 30, "critical": 50, "alarm": 75}
    },
    "alarms": {
        "app_unreachable": {"alarm_threshold_event_count": 3, "min_alarm_interval_minutes": 60},
        "remaining_disk_space_too_low": {"alarm_threshold_event_count": 1, "min_alarm_interval_minutes": 180}
    }
}
```

### Common Unit Conversions

| Metric | Display Unit | Config Unit | Example |
|--------|-------------|-------------|---------|
| Disk Space | Percentage | Percentage | `80` = 80% |
| Log Size | MB/GB | KB | `102400` = 100 MB |
| File Count | Count | Count | `500000` = 500k files |
| Sessions | Count | Count | `5000` = 5k sessions |
| Time | Minutes/Hours | Minutes | `180` = 3 hours |

---

**Last Updated:** November 2025
**MATA Dashboard Version:** 1.0+
