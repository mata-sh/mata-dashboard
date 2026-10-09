# Event System Reference

Complete reference for the MATA Dashboard event logging and alarm management system.

## Overview

MATA maintains a comprehensive event logging system that tracks all monitoring activities, threshold violations, and system events. The dual-table architecture separates detailed event logging from actionable alarm management.

## Architecture

### Dual-Table System

MATA uses two database tables for event management:

1. **`events`** - Comprehensive event history
2. **`alarms`** - Alarms raised from events (one per event, `event_id` unique)
3. **`alarm_deliveries`** - Per-channel delivery state for each alarm

This separation enables:
- Detailed audit trails without clutter
- Efficient alarm management
- Historical event analysis
- Intelligent deduplication
- Configurable retention policies

---

## Events Table

### Purpose
Records all monitoring events, threshold violations, and system activities for audit and analysis.

### Schema

Source of truth: `migrations/20251024230337_create_dashboard_log_tables.php`
(later migrations add `alarm_decision` and alarm trigger evidence columns).

```sql
CREATE TABLE events (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id BIGINT UNSIGNED NULL,       -- FK servers.id, SET NULL on delete
    application_id BIGINT UNSIGNED NULL,  -- FK applications.id, SET NULL on delete
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    context TEXT NULL,                    -- JSON serialized context data
    level ENUM('info', 'warning', 'error', 'critical') NOT NULL,
    datetime DATETIME NOT NULL,
    evaluated_at DATETIME NULL
);
```

### Event Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | Integer | Auto-incrementing primary key |
| `server_id` | Integer | Reference to `servers.id` (nullable) |
| `application_id` | Integer | Reference to `applications.id` (nullable) |
| `type` | String(50) | Event type identifier (see Event Types) |
| `message` | Text | Human-readable event description |
| `context` | Text (JSON) | Metric, threshold, actual value, and additional data |
| `level` | Enum | `info`, `warning`, `error`, `critical` |
| `datetime` | Datetime | When the event occurred |
| `evaluated_at` | Datetime | When alarm rules last evaluated this event (NULL = pending) |

### Event Retention

**Default Retention:**
- **Info Events**: 30 days
- **Warning Events**: 90 days
- **Critical Events**: 365 days

**Configuration:**
Edit `config/data_retention.json`:

```json
{
    "event_retention_days": {
        "info": 30,
        "warning": 90,
        "critical": 365
    }
}
```

**Automated Cleanup:**
```bash
# Run daily via cron
0 2 * * * /usr/bin/php /path/to/mata-dashboard/cron/cleanup_events.php
```

---

## Alarms Table

### Purpose
Manages active alarms that require attention or notification.

### Schema

```sql
CREATE TABLE alarms (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL UNIQUE,  -- FK events.id, RESTRICT on delete
    created_at DATETIME NOT NULL,
    acknowledged_at DATETIME NULL,
    acknowledged_by VARCHAR(50) NULL
);
```

Severity, server, application, and message live on the linked event, not on
the alarm. Alarm state is "unacknowledged" (`acknowledged_at IS NULL`) or
"acknowledged"; there is no resolved flag or event counter in the schema.

### Alarm Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | Integer | Auto-incrementing primary key |
| `event_id` | Integer | Unique reference to the triggering event |
| `created_at` | Datetime | When the alarm was raised |
| `acknowledged_at` | Datetime | When acknowledged (NULL = open) |
| `acknowledged_by` | String(50) | User who acknowledged |

### Alarm Deliveries Table

One row per alarm and channel (`uq_alarm_deliveries_alarm_channel`). `status`
is one of `pending`, `processing`, `sent`, `retry`, `failed`, `throttled`, or
`discarded`. Deleting an alarm cascades to its deliveries.

---

## Event Types

### Server Connectivity Events

#### `server_unreachable`
Server API endpoint not responding.

**Severity:** Critical

**Details:**
```json
{
    "server_id": "production-server",
    "api_url": "https://server.com/mata-node/api",
    "error": "Connection timeout",
    "last_successful": "2025-11-02T14:00:00Z"
}
```

**Common Causes:**
- Server offline or rebooted
- Network connectivity issues
- Firewall blocking requests
- API service not running
- DNS resolution failure

#### `api_authentication_failed`
API key authentication failure.

**Severity:** Critical

**Details:**
```json
{
    "server_id": "production-server",
    "api_url": "https://server.com/mata-node/api",
    "error": "Invalid API key"
}
```

**Common Causes:**
- Mismatched API keys
- API key rotation not completed
- Configuration file mismatch

---

### Resource Threshold Events

#### `cpu_usage_too_high`
CPU usage exceeded the alarm threshold.

**Event Type:** `cpu_usage_too_high`

**Severity:** Warning or Critical (based on threshold)

**Thresholds:**
- Warning: 70%
- Critical: 85%
- Alarm: 90%

**Details:**
```json
{
    "server_id": "production-server",
    "cpu_usage_percent": 87.5,
    "threshold_level": "critical",
    "threshold_value": 85,
    "duration_minutes": 15
}
```

#### `memory_usage_high`
RAM usage exceeded threshold.

**Severity:** Warning or Critical

**Details:**
```json
{
    "server_id": "production-server",
    "ram_usage_percent": 92.3,
    "ram_used_mb": 15360,
    "ram_total_mb": 16384,
    "threshold_level": "critical"
}
```

#### `disk_space_low`
Disk space below threshold.

**Severity:** Critical

**Thresholds:**
- Warning: 70% used
- Critical: 80% used
- Alarm: 90% used

**Event Type:** `remaining_diskspace_too_low`

**Details:**
```json
{
    "server_id": "production-server",
    "disk_usage_percent": 85.2,
    "disk_free_gb": 45.8,
    "disk_total_gb": 500,
    "partition": "/dev/sda1",
    "threshold_level": "critical"
}
```

#### `filecount_too_large`
System file count exceeded threshold.

**Severity:** Warning or Critical

**Thresholds:**
- Good: < 500,000 files
- Warning: 700,000 files
- Critical: 850,000 files
- Alarm: 900,000 files

**Details:**
```json
{
    "server_id": "production-server",
    "total_system_filecount": 876543,
    "threshold_level": "critical",
    "threshold_value": 850000,
    "inode_usage_percent": 78.2
}
```

---

### Application Error Events

#### `fatal_errors_occurring`
PHP fatal errors detected in application.

**Severity:** Critical

**Event Type:** `fatal_errors_occurring`

**Details:**
```json
{
    "server_id": "production-server",
    "app_name": "main-app",
    "error_count": 3,
    "error_type": "PHP Fatal error",
    "recent_errors": [
        {
            "timestamp": "2025-11-02T14:30:15Z",
            "message": "Class 'App\\Models\\User' not found",
            "file": "/var/www/app/app/Http/Controllers/UserController.php",
            "line": 42
        }
    ]
}
```

**Configuration:**
```json
{
    "alarms": {
        "fatal_errors_occurring": {
            "alarm_threshold_event_count": 1,
            "alarm_threshold_window_minutes": 720,
            "min_alarm_interval_minutes": 1440
        }
    }
}
```

#### `app_unreachable`
Application not responding to HTTP ping.

**Severity:** Critical

**Event Type:** `app_unreachable`

**Details:**
```json
{
    "server_id": "production-server",
    "app_name": "main-app",
    "app_url": "https://app.example.com",
    "http_code": 0,
    "error": "Connection timeout",
    "uptime_percent_24h": 94.2
}
```

**Configuration:**
```json
{
    "alarms": {
        "app_unreachable": {
            "alarm_threshold_event_count": 3,
            "alarm_threshold_window_minutes": 15,
            "min_alarm_interval_minutes": 60
        }
    }
}
```

#### `logfile_too_large`
Application log file exceeded size threshold.

**Severity:** Warning or Critical

**Event Type:** `logfile_too_large`

**Thresholds:**
- Good: < 10 MB
- Warning: 100 MB
- Critical: 250 MB
- Alarm: 500 MB

**Details:**
```json
{
    "server_id": "production-server",
    "app_name": "main-app",
    "log_filesize_kb": 262144,
    "log_filesize_mb": 256,
    "log_file_path": "/var/www/app/storage/logs/laravel.log",
    "threshold_level": "critical"
}
```

---

### Configuration Events

#### `servers_config_not_found`
servers.json configuration file missing or unreadable.

**Severity:** Critical

**Event Type:** `servers_config_not_found`

**Details:**
```json
{
    "config_file": "config/servers.json",
    "error": "File not found",
    "checked_paths": [
        "/var/www/mata/config/servers.json",
        "/var/www/mata/config/demo/servers.json"
    ]
}
```

This is a direct configuration-failure alarm. It is dispatched directly by the configuration loader and does not use OPA policy, alarm thresholds, or alarm cooldown intervals.

#### `config_validation_failed`
Configuration file failed validation.

**Severity:** Critical

**Details:**
```json
{
    "config_file": "config/servers.json",
    "validation_errors": [
        "Server 'prod-server' missing required field 'api_key'",
        "Invalid primary_color format for server 'dev-server'"
    ]
}
```

---

### Database Events

#### `too_many_sql_connections`
Database connection count exceeded threshold.

**Severity:** Critical

**Event Type:** `too_many_sql_connections`

**Thresholds:**
- Good: < 10% of max_connections
- Warning: 30% of max_connections
- Critical: 50% of max_connections
- Alarm: 75% of max_connections

**Details:**
```json
{
    "server_id": "production-server",
    "current_connections": 180,
    "max_connections": 200,
    "connection_percent": 90,
    "threshold_level": "alarm"
}
```

**Configuration:**
```json
{
    "sql": {
        "max_connections_percent": {
            "good": 10,
            "warn": 30,
            "critical": 50,
            "alarm": 75
        }
    }
}
```

---

### Security Events

#### `rate_limit_exceeded`
Request rate limit exceeded.

**Severity:** Warning

**Details:**
```json
{
    "ip_address": "192.168.1.100",
    "endpoint": "/api/refresh",
    "request_count": 150,
    "time_window": "60 seconds",
    "rate_limit": 100
}
```

#### `authentication_failed`
Dashboard login authentication failure.

**Severity:** Warning (multiple failures: Critical)

**Details:**
```json
{
    "username": "admin",
    "ip_address": "192.168.1.100",
    "attempt_count": 3,
    "lockout_duration": "15 minutes"
}
```

---

### Cronjob Events

#### `cronjobs_failing`
Expected cronjob execution not detected.

**Severity:** Warning or Critical

**Event Type:** `cronjobs_failing`

**Details:**
```json
{
    "cronjob_name": "collect_server_metrics",
    "expected_interval": "5 minutes",
    "last_execution": "2025-11-02T13:45:00Z",
    "time_since_last": "45 minutes",
    "status": "overdue"
}
```

**Configuration:**
```json
{
    "alarms": {
        "cronjobs_failing": {
            "alarm_threshold_event_count": 1,
            "alarm_threshold_window_minutes": 30,
            "min_alarm_interval_minutes": 360
        }
    }
}
```

---

## Severity Levels

### Info
Informational events that don't require action.

**Examples:**
- Successful data collection
- Configuration reload
- Scheduled maintenance completion
- Normal threshold transitions (critical → warning)

**Characteristics:**
- No immediate action required
- Used for audit trail
- Helps with troubleshooting
- Short retention period (30 days)

### Warning
Events requiring attention but not immediate action.

**Examples:**
- Resource usage approaching thresholds
- Log file size increasing
- Elevated error rates
- Non-critical configuration issues

**Characteristics:**
- Monitor and investigate
- May escalate to critical
- Medium retention period (90 days)
- Aggregated notifications (hourly/daily)

### Critical
Urgent events requiring immediate attention.

**Examples:**
- Server unreachable
- Disk space critically low
- Fatal application errors
- Security violations
- Configuration failures

**Characteristics:**
- Immediate notification
- Requires action within hours
- Long retention period (365 days)
- Real-time alarms

---

## Alarm Management

### Alarm Lifecycle

```
┌─────────────────┐
│  Event Occurs   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Check Threshold │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Create Alarm   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Send Alarm      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Acknowledge    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│    Resolve      │
└─────────────────┘
```

### Alarm States

| State | Description | Next State |
|-------|-------------|------------|
| **New** | Just created, not acknowledged | Acknowledged or Resolved |
| **Acknowledged** | Seen by administrator | Resolved |
| **Resolved** | Issue fixed, alarm closed | Archived |
| **Recurring** | Same issue occurring repeatedly | Acknowledged |

### Intelligent Deduplication

MATA prevents notification spam through intelligent alarm deduplication.

**Deduplication Strategy:**

1. **Event Aggregation Window**
   - Group identical events within time window
   - Increment `event_count` instead of creating new alarm
   - Update `last_occurrence` timestamp

2. **Minimum Event Interval**
   - Suppress duplicate events within configured interval
   - Prevents log flooding from triggering multiple alarms

3. **Minimum Alarm Interval**
   - Limit alarm notifications to configured frequency
   - Prevents email notification spam

**Configuration Example:**

```json
{
    "alarms": {
        "app_unreachable": {
            "alarm_threshold_event_count": 3,
            "alarm_threshold_window_minutes": 15,
            "min_alarm_interval_minutes": 60
        }
    }
}
```

**Interpretation:**
- Must occur 3 times to trigger alarm
- Events within 15 minutes are aggregated
- Alarm notifications limited to once per 60 minutes

### Threshold-Based Alarm Triggering

**Algorithm:**

```python
def should_trigger_alarm(event_type, recent_events, config):
    threshold = config['alarm_threshold_event_count']
    threshold_window_minutes = config['alarm_threshold_window_minutes']

    # Count events within the threshold window
    recent_count = count_events_since(
        event_type,
        minutes_ago=threshold_window_minutes
    )

    # Trigger if threshold exceeded
    return recent_count >= threshold
```

**Example Scenarios:**

**Scenario 1: Single Critical Event**
```json
{
    "fatal_errors_occurring": {
        "alarm_threshold_event_count": 1,
        "alarm_threshold_window_minutes": 720,
        "min_alarm_interval_minutes": 1440
    }
}
```
- First fatal error triggers immediate alarm
- Additional events within 12 hours (720 min) are aggregated
- New alarm notification only after 24 hours (1440 min)

**Scenario 2: Repeated Connectivity Issues**
```json
{
    "app_unreachable": {
        "alarm_threshold_event_count": 3,
        "alarm_threshold_window_minutes": 15,
        "min_alarm_interval_minutes": 60
    }
}
```
- Requires 3 occurrences within 15 minutes
- Tolerates brief connection hiccups
- Limits notifications to hourly

### Alarm Acknowledgment

**Purpose:**
- Indicate alarm has been seen
- Prevent duplicate notifications
- Track who is handling the issue

**Workflow:**

```php
// Acknowledge alarm
AcknowledgeAlarm::execute([
    'alarm_id' => 123,
    'acknowledged_by' => 'admin@example.com',
    'notes' => 'Investigating disk space issue'
]);
```

**Database Update:**
```sql
UPDATE alarms
SET acknowledged_at = NOW(),
    acknowledged_by = 'admin@example.com'
WHERE id = 123;
```

### Alarm Resolution

> **Not implemented.** The `alarms` table has no resolved state. The example
> below describes a planned workflow, not current behaviour.

**Purpose:**
- Mark issue as fixed
- Close alarm
- Archive for historical analysis

**Planned workflow:**

```php
// Resolve alarm (planned)
ResolveAlarm::execute([
    'alarm_id' => 123,
    'resolved_by' => 'admin@example.com',
    'resolution_notes' => 'Cleared old log files, added log rotation'
]);
```

---

## Email Notifications

### Notification Triggers

Alarms trigger email notifications when:
1. Alarm threshold exceeded
2. Minimum alarm interval elapsed
3. Alarm not already acknowledged
4. Email notifications enabled

### Email Format

**Subject:**
```
[MATA Alarm] {severity} - {alarm_type} - {server_id}
```

**Body Template:**
```
MATA Dashboard Alarm

Severity: CRITICAL
Alarm Type: disk_space_low
Server: production-server
Application: N/A

Message:
Disk space critically low - 85.2% used (45.8 GB free)

Details:
- Disk Usage: 85.2%
- Free Space: 45.8 GB
- Total Space: 500 GB
- Threshold: 80%

Event Count: 1
First Occurrence: 2025-11-02 14:30:15
Last Occurrence: 2025-11-02 14:30:15

Action Required:
Review disk usage and clean up unnecessary files or expand storage.

Dashboard: https://mata.example.com/
View Alarm: https://mata.example.com/alarms/123
```

### Email Configuration

**Recipients (mail.config.env):**
```env
# Alarm recipients
MAIL_ALARM_RECIPIENTS=alarms@example.com,admin@example.com
```

**Severity-Based Routing:**
```env
# Critical alarms
MAIL_CRITICAL_RECIPIENTS=oncall@example.com,admin@example.com

# Warning alarms
MAIL_WARNING_RECIPIENTS=team@example.com

# Info (disabled by default)
MAIL_INFO_RECIPIENTS=
```

### Notification Rate Limiting

**Prevent Email Flooding:**

```json
{
    "notification_settings": {
        "max_emails_per_hour": 10,
        "max_emails_per_day": 50,
        "batch_delay_minutes": 15
    }
}
```

**Batching Strategy:**
- Collect alarms over batch delay period
- Send single digest email with all alarms
- Prioritize critical alarms first

---

## Event Query API

### Get Recent Events
```sql
SELECT * FROM events
WHERE datetime > NOW() - INTERVAL 24 HOUR
ORDER BY datetime DESC
LIMIT 100;
```

### Get Events by Level
```sql
SELECT * FROM events
WHERE level = 'critical'
AND datetime > NOW() - INTERVAL 7 DAY
ORDER BY datetime DESC;
```

### Get Events by Server
```sql
SELECT e.* FROM events e
JOIN servers s ON s.id = e.server_id
WHERE s.id = 1
AND e.datetime > NOW() - INTERVAL 24 HOUR
ORDER BY e.datetime DESC;
```

### Get Unacknowledged Alarms
```sql
SELECT a.*, e.type, e.level, e.message
FROM alarms a
JOIN events e ON e.id = a.event_id
WHERE a.acknowledged_at IS NULL
ORDER BY e.level DESC, a.created_at DESC;
```

### Event Count by Type (Last 24h)
```sql
SELECT
    type,
    COUNT(*) AS event_count,
    MAX(datetime) AS last_occurrence
FROM events
WHERE datetime > NOW() - INTERVAL 24 HOUR
GROUP BY type
ORDER BY event_count DESC;
```

---

## Best Practices

### 1. Event Logging
- Log all significant monitoring events
- Include contextual details in JSON field
- Use appropriate severity levels
- Implement retention policies

### 2. Alarm Configuration
- Set thresholds based on baseline metrics
- Use deduplication to prevent spam
- Configure appropriate notification intervals
- Test alarm triggers in staging

### 3. Notification Management
- Configure severity-based routing
- Use rate limiting to prevent flooding
- Implement escalation for critical alarms
- Maintain on-call rotation

### 4. Alarm Response
- Acknowledge alarms promptly
- Document investigation notes
- Resolve alarms when fixed
- Review alarm history for patterns

### 5. Retention and Cleanup
- Archive old events to separate table
- Maintain critical events longer
- Purge resolved alarms after 90 days
- Export events for long-term analysis

---

## Troubleshooting

### No Alarms Being Created

**Check:**
1. Event logging enabled: `config/config.env` → `APP_LOG_PATH` and `MONOLOG_LEVEL` configured
2. Threshold configuration: `config/thresholds.json`
3. Database connectivity
4. Cron jobs running

### Alarm Notification Emails Not Sending

**Check:**
1. Email configuration: `config/mail.config.env`
2. SMTP connectivity
3. Email recipients configured
4. Mail service logs
5. Rate limiting settings

### Too Many Duplicate Alarms

**Solution:**
Adjust deduplication settings in `config/thresholds.json`:

```json
{
    "alarms": {
        "your_alarm_type": {
            "alarm_threshold_event_count": 5,
            "alarm_threshold_window_minutes": 60,
            "min_alarm_interval_minutes": 240
        }
    }
}
```

---

## Related Documentation

- [Server Metrics Reference](./server-metrics.md)
- [Application Monitoring Reference](./application-monitoring.md)
- [Threshold Configuration](./thresholds.md)
- [Configuration Reference](./configuration-reference.md)

---

**Last Updated:** 2025-11-02
**Version:** 1.0
