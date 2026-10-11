# Threshold Configuration Reference

Complete reference for monitoring thresholds and alarm configuration in MATA Dashboard.

## Overview

MATA Dashboard uses threshold-based monitoring to detect and alarm on resource usage, application errors, and system anomalies. Thresholds are configured in `config/thresholds.json` and control when warnings, critical alarms, and alarms are triggered.

## Threshold Levels

### Threshold Hierarchy

Metrics may configure any non-empty subset of these four levels. Configured values must be numeric and ascend in this order: `good < warn < critical < alarm`.

Each level defines a monitoring state:

| Level | Purpose | Color | Action |
|-------|---------|-------|--------|
| **Good** | Normal operation baseline | Green | No action required |
| **Warn** | Early warning indicator | Yellow | Monitor trend |
| **Critical** | Significant concern | Orange | Investigate and plan |
| **Alarm** | Immediate attention required | Red | Take action now |

### Threshold Evaluation

The resolver evaluates only configured levels and returns the highest configured level whose threshold the value exceeds.

**Ascending Metrics** (higher is worse):
- CPU usage percentage
- Memory usage percentage
- Disk usage percentage
- Error counts
- Session counts

```
value <= good: Green
good < value <= warn: Yellow
warn < value <= critical: Orange
critical < value <= alarm: Red
```

**Descending Metrics** (lower is worse):
- Free disk space
- Uptime percentage
- Response time (inverse)

---

## Configuration File

### File Location
```
config/thresholds.json
```

### Setup
```bash
# Use defaults (no file needed)
# OR create custom configuration:
cp config.example/thresholds.json.example config/thresholds.json
```

### Schema Structure

Each metric object may contain any non-empty subset of `good`, `warn`, `critical`, and `alarm`.

```json
{
    "server_data": {
        "metric_name": {
            "good": number,
            "warn": number,
            "critical": number,
            "alarm": number
        }
    },
    "app_data": {
        "metric_name": {
            "good": number,
            "warn": number,
            "critical": number,
            "alarm": number
        }
    },
    "alarms": {
        "alarm_type": {
            "alarm_threshold_event_count": number,
            "alarm_threshold_window_minutes": number,
            "min_alarm_interval_minutes": number
        }
    }
}
```

---

## Server Data Thresholds

### log_filesize_kb
Application log file size threshold.

**Metric:** Log file size in kilobytes

**Default Configuration:**
```json
{
    "log_filesize_kb": {
        "good": 10240,
        "warn": 102400,
        "critical": 256000,
        "alarm": 512000
    }
}
```

**Threshold Values:**

| Level | Size (KB) | Size (MB) | Interpretation |
|-------|-----------|-----------|----------------|
| **Good** | ≤ 10,240 | ≤ 10 MB | Normal log size |
| **Warn** | 10,241 - 102,400 | 10 - 100 MB | Review log rotation |
| **Critical** | 102,401 - 256,000 | 100 - 250 MB | Implement rotation immediately |
| **Alarm** | > 256,000 | > 250 MB | Disk space and performance risk |

**Recommendations:**
- **Production:** Good=10MB, Warn=50MB, Critical=100MB, Alarm=250MB
- **Development:** Good=50MB, Warn=100MB, Critical=250MB, Alarm=500MB
- **High-traffic:** Good=50MB, Warn=200MB, Critical=500MB, Alarm=1000MB

**Configuration Example:**
```json
{
    "log_filesize_kb": {
        "good": 10240,
        "warn": 51200,
        "critical": 102400,
        "alarm": 256000
    }
}
```

---

### total_system_filecount
Total number of files on the filesystem.

**Metric:** File count across entire system

**Default Configuration:**
```json
{
    "total_system_filecount": {
        "good": 500000,
        "warn": 750000,
        "critical": 850000,
        "alarm": 900000
    }
}
```

**Threshold Values:**

| Level | File Count | Interpretation |
|-------|------------|----------------|
| **Good** | ≤ 500,000 | Normal filesystem usage |
| **Warn** | 500,001 - 750,000 | Monitor file growth |
| **Critical** | 750,001 - 850,000 | Review file storage strategy |
| **Alarm** | > 850,000 | Inode exhaustion risk |

**System-Specific Adjustments:**

**Small Server (< 100GB):**
```json
{
    "total_system_filecount": {
        "good": 250000,
        "warn": 400000,
        "critical": 500000,
        "alarm": 600000
    }
}
```

**Medium Server (100-500GB):**
```json
{
    "total_system_filecount": {
        "good": 500000,
        "warn": 750000,
        "critical": 850000,
        "alarm": 900000
    }
}
```

**Large Server (> 500GB):**
```json
{
    "total_system_filecount": {
        "good": 1000000,
        "warn": 1500000,
        "critical": 2000000,
        "alarm": 2500000
    }
}
```

**Considerations:**
- Check inode limits: `df -i`
- File-based sessions increase count significantly
- Temporary files can accumulate quickly
- Log rotation reduces file count

---

### disk_space_usage_percent
Disk space utilization percentage.

**Metric:** Percentage of disk space used

**Default Configuration:**
```json
{
    "disk_space_usage_percent": {
        "good": 60,
        "warn": 70,
        "critical": 80,
        "alarm": 90
    }
}
```

**Threshold Values:**

| Level | Usage % | Free Space Required | Interpretation |
|-------|---------|---------------------|----------------|
| **Good** | ≤ 60% | 40% | Healthy headroom |
| **Warn** | 61-70% | 30% | Monitor growth rate |
| **Critical** | 71-80% | 20% | Plan expansion |
| **Alarm** | > 80% | < 20% | Immediate action required |

**Best Practices:**

**Production Servers:**
```json
{
    "disk_space_usage_percent": {
        "good": 60,
        "warn": 70,
        "critical": 80,
        "alarm": 90
    }
}
```

**Database Servers:**
```json
{
    "disk_space_usage_percent": {
        "good": 50,
        "warn": 60,
        "critical": 70,
        "alarm": 80
    }
}
```
*Lower thresholds for databases due to growth patterns*

**File/Backup Servers:**
```json
{
    "disk_space_usage_percent": {
        "good": 70,
        "warn": 80,
        "critical": 85,
        "alarm": 90
    }
}
```
*Higher thresholds acceptable for known storage usage*

**Development Servers:**
```json
{
    "disk_space_usage_percent": {
        "good": 70,
        "warn": 80,
        "critical": 90,
        "alarm": 95
    }
}
```

**Critical Considerations:**
- Some filesystems degrade performance above 80% usage
- Reserve 10-20% free space for optimal performance
- Monitor growth rate, not just absolute usage
- Plan expansion when reaching warn threshold

---

### cronjobs_failed_count
Number of failed cronjobs (`total - working`).

**Metric:** Failed cronjob count

**Configuration Example:**
```json
{
    "cronjobs_failed_count": {
        "good": -1,
        "alarm": 0
    }
}
```

Threshold comparison is strict (`>`): zero failed cronjobs resolves to `good`; one or more failed cronjobs resolves to `alarm`. An alarm-level result emits a critical `cronjobs_failing` event.

### Resource usage alarm producers
An alarm-level `cpu_usage` result emits a critical `cpu_usage_too_high` event. An alarm-level `ram_usage_percent` result emits a critical `ram_usage_too_high` event.

---

## Application Data Thresholds

### session_count
Active user session count for applications.

**Metric:** Number of active sessions

**Default Configuration:**
```json
{
    "session_count": {
        "good": 100,
        "warn": 500,
        "critical": 2500,
        "alarm": 50000
    }
}
```

**Threshold Values:**

| Level | Session Count | Interpretation |
|-------|---------------|----------------|
| **Good** | ≤ 100 | Low traffic, normal |
| **Warn** | 101 - 500 | Moderate traffic |
| **Critical** | 501 - 2,500 | High traffic |
| **Alarm** | > 2,500 | Very high traffic, scaling needed |

**Application-Specific Configurations:**

**Small Application (< 1,000 daily users):**
```json
{
    "session_count": {
        "good": 50,
        "warn": 100,
        "critical": 200,
        "alarm": 500
    }
}
```

**Medium Application (1,000 - 10,000 daily users):**
```json
{
    "session_count": {
        "good": 100,
        "warn": 500,
        "critical": 2500,
        "alarm": 5000
    }
}
```

**Large Application (> 10,000 daily users):**
```json
{
    "session_count": {
        "good": 1000,
        "warn": 5000,
        "critical": 10000,
        "alarm": 50000
    }
}
```

**E-commerce/High-Traffic:**
```json
{
    "session_count": {
        "good": 500,
        "warn": 2000,
        "critical": 5000,
        "alarm": 10000
    }
}
```

**Factors to Consider:**
- Session lifetime settings (longer = higher counts)
- Storage method (file vs database vs Redis)
- Peak traffic hours
- Server capacity
- Application architecture

---

### order_export_age_minutes
Age of last order export (application-specific).

**Metric:** Minutes since last successful export

**Default Configuration:**
```json
{
    "order_export_age_minutes": {
        "good": 10,
        "warn": 60,
        "critical": 120,
        "alarm": 180
    }
}
```

**Threshold Values:**

| Level | Age (minutes) | Interpretation |
|-------|---------------|----------------|
| **Good** | ≤ 10 | Exports running on schedule |
| **Warn** | 11 - 60 | Slight delay, monitor |
| **Critical** | 61 - 120 | Significant delay |
| **Alarm** | > 120 | Export process failed |

**Note:** This is an application-specific metric. Configure based on your business requirements.

---

## Database Thresholds

Key: `server_data.database_connections_percent` (an alarm raises `database_connections_too_high`).

### database_connections_percent
Database connection usage percentage.

**Metric:** Percentage of max_connections in use

**Default Configuration:**
```json
{
    "database_connections_percent": {
        "good": 10,
        "warn": 30,
        "critical": 50,
        "alarm": 75
    }
}
```

**Threshold Values:**

| Level | Usage % | Interpretation |
|-------|---------|----------------|
| **Good** | ≤ 10% | Normal connection usage |
| **Warn** | 11-30% | Moderate usage, monitor |
| **Critical** | 31-50% | High usage, investigate |
| **Alarm** | > 50% | Connection exhaustion risk |

**MySQL Configuration Example:**

If `max_connections = 200`:

| Level | Threshold % | Actual Connections |
|-------|-------------|-------------------|
| Good | 10% | ≤ 20 |
| Warn | 30% | 21 - 60 |
| Critical | 50% | 61 - 100 |
| Alarm | 75% | > 150 |

**Recommendations by Database Size:**

**Small Database (max_connections = 100):**
```json
{
    "database_connections_percent": {
        "good": 20,
        "warn": 40,
        "critical": 60,
        "alarm": 80
    }
}
```

**Medium Database (max_connections = 200-500):**
```json
{
    "database_connections_percent": {
        "good": 10,
        "warn": 30,
        "critical": 50,
        "alarm": 75
    }
}
```

**Large Database (max_connections = 500+):**
```json
{
    "database_connections_percent": {
        "good": 5,
        "warn": 20,
        "critical": 40,
        "alarm": 60
    }
}
```

**Common Causes of High Connection Usage:**
- Connection pool misconfiguration
- Connection leaks in application code
- Slow queries holding connections open
- Insufficient connection pool size
- Database performance issues

---

## Alarm Configuration

Alarm settings control when events trigger notifications and how deduplication works.

### Alarm Configuration Fields

Each alarm type has three configuration values:

| Field | Description |
|-------|-------------|
| `alarm_threshold_event_count` | Number of events required to trigger alarm |
| `alarm_threshold_window_minutes` | Lookback window for counting recent events |
| `min_alarm_interval_minutes` | Minimum time between alarm notifications |

---

### app_unreachable
Application HTTP ping failure alarm.

**Default Configuration:**
```json
{
    "app_unreachable": {
        "alarm_threshold_event_count": 3,
        "alarm_threshold_window_minutes": 15,
        "min_alarm_interval_minutes": 60
    }
}
```

**Behavior:**
- Requires 3 ping failures within 15 minutes to trigger alarm
- Limits notifications to once per hour
- Tolerates brief connectivity issues

**Configuration Examples:**

**Critical Production Application:**
```json
{
    "app_unreachable": {
        "alarm_threshold_event_count": 1,
        "alarm_threshold_window_minutes": 5,
        "min_alarm_interval_minutes": 15
    }
}
```
*Immediate alarm on first failure*

**Development Environment:**
```json
{
    "app_unreachable": {
        "alarm_threshold_event_count": 5,
        "alarm_threshold_window_minutes": 30,
        "min_alarm_interval_minutes": 120
    }
}
```
*More tolerant of intermittent issues*

---

### fatal_errors_occurring
PHP fatal error detection alarm.

**Default Configuration:**
```json
{
    "fatal_errors_occurring": {
        "alarm_threshold_event_count": 1,
        "alarm_threshold_window_minutes": 720,
        "min_alarm_interval_minutes": 1440
    }
}
```

**Behavior:**
- First fatal error triggers immediate alarm
- Events within 12 hours are aggregated
- Notifications limited to once per 24 hours
- Prevents spam from recurring fatal errors

**Why These Values:**
- Fatal errors are always critical
- Single occurrence requires investigation
- 12-hour window catches intermittent errors
- 24-hour notification prevents alarm fatigue

---

### logfile_too_large
Log file size threshold alarm.

**Default Configuration:**
```json
{
    "logfile_too_large": {
        "alarm_threshold_event_count": 1,
        "alarm_threshold_window_minutes": 120,
        "min_alarm_interval_minutes": 720
    }
}
```

**Behavior:**
- Single occurrence triggers alarm
- Events within 2 hours aggregated
- Notifications once per 12 hours
- Allows time for log rotation implementation

---

### filecount_too_large
System file count threshold alarm.

**Default Configuration:**
```json
{
    "filecount_too_large": {
        "alarm_threshold_event_count": 1,
        "alarm_threshold_window_minutes": 720,
        "min_alarm_interval_minutes": 60
    }
}
```

**Behavior:**
- First occurrence triggers alarm
- Events within 12 hours aggregated
- Notifications hourly
- Urgent due to inode exhaustion risk

---

### remaining_diskspace_too_low
Low disk space alarm.

**Default Configuration:**
```json
{
    "remaining_diskspace_too_low": {
        "alarm_threshold_event_count": 1,
        "alarm_threshold_window_minutes": 60,
        "min_alarm_interval_minutes": 180
    }
}
```

**Behavior:**
- Immediate alarm on first detection
- Events within 1 hour aggregated
- Notifications every 3 hours
- Critical due to service interruption risk

**Configuration for Different Scenarios:**

**Production Database Server:**
```json
{
    "remaining_diskspace_too_low": {
        "alarm_threshold_event_count": 1,
        "alarm_threshold_window_minutes": 30,
        "min_alarm_interval_minutes": 60
    }
}
```
*More frequent notifications for critical systems*

**Development Server:**
```json
{
    "remaining_diskspace_too_low": {
        "alarm_threshold_event_count": 2,
        "alarm_threshold_window_minutes": 120,
        "min_alarm_interval_minutes": 360
    }
}
```
*Less urgent, longer intervals*

---

### database_connections_too_high
Database connections reached the alarm share of `max_connections` (`server_data.database_connections_percent`).

**Default Configuration:**
```json
{
    "database_connections_too_high": {
        "alarm_threshold_event_count": 1,
        "alarm_threshold_window_minutes": 720,
        "min_alarm_interval_minutes": 60
    }
}
```

**Behavior:**
- Single occurrence triggers alarm
- Events within 12 hours aggregated
- Hourly notifications
- Urgent due to connection exhaustion risk

---

Configuration-load failures for missing or malformed `servers.json` are direct-only alarms. They bypass OPA policy, alarm thresholds, and alarm cooldown intervals.

---

### cronjobs_failing
Missing cronjob execution alarm.

**Default Configuration:**
```json
{
    "cronjobs_failing": {
        "alarm_threshold_event_count": 1,
        "alarm_threshold_window_minutes": 30,
        "min_alarm_interval_minutes": 360
    }
}
```

**Behavior:**
- First missed execution triggers alarm
- Events within 30 minutes aggregated
- Notifications every 6 hours
- Balances urgency with tolerance for temporary issues

---

## Complete Configuration Example

### Production Configuration

```json
{
    "server_data": {
        "log_filesize_kb": {
            "good": 10240,
            "warn": 51200,
            "critical": 102400,
            "alarm": 256000
        },
        "total_system_filecount": {
            "good": 500000,
            "warn": 700000,
            "critical": 850000,
            "alarm": 900000
        },
        "disk_space_usage_percent": {
            "good": 60,
            "warn": 70,
            "critical": 80,
            "alarm": 90
        }
    },
    "app_data": {
        "session_count": {
            "good": 100,
            "warn": 500,
            "critical": 2500,
            "alarm": 50000
        }
    },
    "alarms": {
        "app_unreachable": {
            "alarm_threshold_event_count": 3,
            "alarm_threshold_window_minutes": 15,
            "min_alarm_interval_minutes": 60
        },
        "fatal_errors_occurring": {
            "alarm_threshold_event_count": 1,
            "alarm_threshold_window_minutes": 720,
            "min_alarm_interval_minutes": 1440
        },
        "logfile_too_large": {
            "alarm_threshold_event_count": 1,
            "alarm_threshold_window_minutes": 120,
            "min_alarm_interval_minutes": 720
        },
        "filecount_too_large": {
            "alarm_threshold_event_count": 1,
            "alarm_threshold_window_minutes": 720,
            "min_alarm_interval_minutes": 60
        },
        "remaining_diskspace_too_low": {
            "alarm_threshold_event_count": 1,
            "alarm_threshold_window_minutes": 60,
            "min_alarm_interval_minutes": 180
        },
        "database_connections_too_high": {
            "alarm_threshold_event_count": 1,
            "alarm_threshold_window_minutes": 720,
            "min_alarm_interval_minutes": 60
        },
        "cronjobs_failing": {
            "alarm_threshold_event_count": 1,
            "alarm_threshold_window_minutes": 30,
            "min_alarm_interval_minutes": 360
        }
    }
}
```

---

## Threshold Tuning Guide

### Baseline Establishment

**Step 1: Monitor Normal Operation**
- Run system for 7-14 days without alarms
- Collect baseline metrics
- Identify normal ranges

**Step 2: Analyze Patterns**
- Calculate average, min, max for each metric
- Identify peak usage times
- Document growth trends

**Step 3: Set Initial Thresholds**
- Good: Average usage
- Warn: Average + 20%
- Critical: Average + 40%
- Alarm: Average + 60%

**Step 4: Iterate and Refine**
- Review alarms weekly
- Adjust thresholds to reduce false positives
- Ensure critical events trigger alarms

### False Positive Reduction

**Symptoms:**
- Too many warning alarms
- Alarms during known peak times
- Alarms for normal operation

**Solutions:**

**1. Increase Good/Warn Thresholds:**
```json
// Before
"disk_space_usage_percent": {
    "good": 50,
    "warn": 60
}

// After
"disk_space_usage_percent": {
    "good": 60,
    "warn": 70
}
```

**2. Increase Event Count Threshold:**
```json
// Before
"app_unreachable": {
    "alarm_threshold_event_count": 1
}

// After
"app_unreachable": {
    "alarm_threshold_event_count": 3
}
```

**3. Increase Time Intervals:**
```json
// Before
"alarm_threshold_window_minutes": 5,
"min_alarm_interval_minutes": 15

// After
"alarm_threshold_window_minutes": 15,
"min_alarm_interval_minutes": 60
```

### False Negative Prevention

**Symptoms:**
- Critical issues not triggering alarms
- Alarms arriving too late
- Missing important events

**Solutions:**

**1. Lower Critical/Alarm Thresholds:**
```json
// Before
"disk_space_usage_percent": {
    "critical": 90,
    "alarm": 95
}

// After
"disk_space_usage_percent": {
    "critical": 80,
    "alarm": 90
}
```

**2. Reduce Event Count Threshold:**
```json
// Before
"fatal_errors_occurring": {
    "alarm_threshold_event_count": 3
}

// After
"fatal_errors_occurring": {
    "alarm_threshold_event_count": 1
}
```

**3. Reduce Time Intervals:**
```json
// Before
"min_alarm_interval_minutes": 360

// After
"min_alarm_interval_minutes": 60
```

---

## Environment-Specific Configurations

### Production Environment

**Characteristics:**
- Low tolerance for issues
- Immediate critical alarms
- Conservative thresholds

```json
{
    "server_data": {
        "disk_space_usage_percent": {
            "good": 60,
            "warn": 70,
            "critical": 80,
            "alarm": 90
        }
    },
    "alarms": {
        "app_unreachable": {
            "alarm_threshold_event_count": 2,
            "alarm_threshold_window_minutes": 10,
            "min_alarm_interval_minutes": 30
        }
    }
}
```

### Staging Environment

**Characteristics:**
- Moderate tolerance
- Aggregated alarms
- Balanced thresholds

```json
{
    "server_data": {
        "disk_space_usage_percent": {
            "good": 70,
            "warn": 80,
            "critical": 85,
            "alarm": 90
        }
    },
    "alarms": {
        "app_unreachable": {
            "alarm_threshold_event_count": 3,
            "alarm_threshold_window_minutes": 15,
            "min_alarm_interval_minutes": 120
        }
    }
}
```

### Development Environment

**Characteristics:**
- High tolerance for issues
- Infrequent alarms
- Relaxed thresholds

```json
{
    "server_data": {
        "disk_space_usage_percent": {
            "good": 75,
            "warn": 85,
            "critical": 90,
            "alarm": 95
        }
    },
    "alarms": {
        "app_unreachable": {
            "alarm_threshold_event_count": 5,
            "alarm_threshold_window_minutes": 30,
            "min_alarm_interval_minutes": 240
        }
    }
}
```

---

## Best Practices

### 1. Start Conservative
- Begin with strict thresholds
- Relax based on actual usage patterns
- Prefer false positives over missed critical events

### 2. Document Changes
- Keep change log for threshold adjustments
- Document reasons for changes
- Track impact on alarm frequency

### 3. Regular Review
- Review thresholds quarterly
- Adjust for business growth
- Update based on infrastructure changes

### 4. Test Alarms
- Manually trigger thresholds in staging
- Verify notification delivery
- Confirm alarm content and clarity

### 5. Monitor Alarm Fatigue
- Track alarm frequency
- Identify noisy alarms
- Adjust to maintain team engagement

---

## Troubleshooting

### No Alarms Triggering

**Check:**
1. Thresholds configuration file exists and is valid JSON
2. Thresholds are not set too high
3. Event logging is enabled
4. Database connection is working
5. Cron jobs are running

### Too Many Alarms

**Solutions:**
1. Increase threshold values (good, warn, critical, alarm)
2. Increase `alarm_threshold_event_count`
3. Increase `alarm_threshold_window_minutes`
4. Increase `min_alarm_interval_minutes`

### Alarms Not Reaching Email

**Check:**
1. Email configuration in `mail.config.env`
2. SMTP connectivity
3. `min_alarm_interval_minutes` not preventing notifications
4. Email rate limiting settings

---

## Related Documentation

- [Server Metrics Reference](./server-metrics.md)
- [Application Monitoring Reference](./application-monitoring.md)
- [Event System Reference](./event-system.md)
- [Configuration Reference](./configuration-reference.md)

---

**Last Updated:** 2025-11-02
**Version:** 1.0
