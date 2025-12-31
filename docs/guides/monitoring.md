# Monitoring

This guide covers how to use MATA Dashboard's monitoring features effectively.

## Overview

MATA Dashboard provides comprehensive monitoring for:

- **Server Metrics**: CPU, RAM, disk space, file count, processes
- **Application Health**: HTTP ping, error logs, session tracking
- **Event Logging**: Comprehensive audit trail with severity levels
- **Alert Management**: Intelligent alerting with email notifications

## Dashboard Interface

### Main Dashboard View

The main dashboard displays all monitored servers in card format:

```
┌─────────────────────────────────────┐
│  [Server Name]              [Color] │
│                                     │
│  CPU: 45%  RAM: 2.1GB/8GB          │
│  Disk: 120GB/500GB (24%)           │
│  Processes: 156 (12 PHP, 1 MySQL)  │
│  Files: 485,291                    │
│                                     │
│  [View Applications]                │
└─────────────────────────────────────┘
```

### Server Card Components

Each server card displays:

- **Server title** with custom color coding
- **Connection status** (green = connected, red = disconnected)
- **Key metrics** with threshold-based color indicators
- **Quick actions** to view applications and details

## Server Monitoring

### CPU Usage

**What it monitors:**

- Current CPU utilization percentage
- Multi-core CPU aggregate usage

**Interpretation:**

- **Green (< 70%)**: Normal operation
- **Yellow (70-80%)**: Warning - investigate high usage
- **Orange (80-90%)**: Critical - performance impact likely
- **Red (> 90%)**: Alarm - immediate attention required

**Troubleshooting high CPU:**

1. Check process list for resource-intensive processes
2. Review application logs for errors
3. Verify no runaway processes
4. Consider scaling resources

### Memory (RAM) Usage

**What it monitors:**

- Used RAM vs. total available RAM
- Memory consumption by processes

**Interpretation:**

- **Green**: Healthy memory availability
- **Yellow**: Memory usage increasing
- **Orange**: Low memory available
- **Red**: Memory exhaustion risk

**Troubleshooting memory issues:**

1. Identify memory-intensive processes
2. Check for memory leaks in applications
3. Review cache usage
4. Consider increasing RAM

### Disk Space

**What it monitors:**

- Used disk space vs. total capacity
- Disk usage percentage
- Free space available

**Interpretation:**

- **Green (< 70%)**: Adequate space
- **Yellow (70-80%)**: Monitor disk usage
- **Orange (80-90%)**: Free up space soon
- **Red (> 90%)**: Critical - immediate cleanup required

**Managing disk space:**

1. Identify large files: `du -sh /* | sort -h`
2. Clean old log files
3. Clear application caches
4. Archive/remove old backups

### File Count Monitoring

**What it monitors:**

- Total number of files on the system
- Useful for detecting filesystem issues

**Why it matters:**

- Large file counts can impact performance
- Indicates potential inode exhaustion
- May suggest logging issues

**Typical thresholds:**

- **Good**: < 500,000 files
- **Warning**: 700,000 - 850,000 files
- **Critical**: > 850,000 files

### Process Monitoring

**What it monitors:**

- Total process count
- PHP process count
- MySQL/database process count

**Use cases:**

- Detect runaway processes
- Monitor application scaling
- Identify resource bottlenecks

## Application Monitoring

### Application Status

Each monitored application displays:

- **HTTP Ping**: Reachability test
- **Error Logs**: PHP error/warning detection
- **Session Count**: Active user sessions
- **Maintenance Mode**: Planned downtime indicator
- **Environment**: Development vs. Production

### HTTP Ping Monitoring

**What it checks:**

- Application reachability via HTTP/HTTPS
- Response time
- HTTP status code

**Status indicators:**

- **Green**: Application responding (HTTP 200)
- **Orange**: Application in maintenance mode
- **Red**: Application unreachable or error

**Troubleshooting unreachable apps:**

1. Verify web server is running
2. Check application error logs
3. Test connectivity manually
4. Review firewall rules

### Error Log Analysis

**What it monitors:**

- PHP fatal errors
- PHP warnings
- PHP notices
- Log file size

**Alert triggers:**

- New fatal errors detected
- Log file exceeds size threshold
- Rapid error accumulation

**Best practices:**

1. Review errors regularly
2. Set appropriate log levels
3. Rotate logs to prevent growth
4. Fix recurring errors promptly

### Session Tracking

**What it monitors:**

- Active user session count
- Session file count
- Session growth trends

**Interpretation:**

- **Low session count**: Normal operation or low traffic
- **High session count**: High user activity
- **Sudden spike**: Potential issue or traffic surge

**Thresholds:**

- **Good**: < 100 sessions
- **Warning**: 1,000 - 5,000 sessions
- **Critical**: > 5,000 sessions

## Event System

### Event Types

MATA Dashboard logs these event types:

| Event Type | Description | Severity |
|------------|-------------|----------|
| **Server Connectivity** | Node communication failures | Critical |
| **Resource Thresholds** | CPU, RAM, disk violations | Warning/Critical |
| **Application Errors** | PHP errors and warnings | Critical |
| **Configuration Issues** | Missing/invalid config | Warning |
| **Security Events** | Rate limiting, auth failures | Warning/Critical |

### Event Severity Levels

- **Info**: Informational events, no action required
- **Warning**: Potential issues, monitor closely
- **Critical**: Issues requiring attention

### Viewing Events

Access event logs from the dashboard:

1. Navigate to **Events** page
2. Filter by server, severity, or date range
3. View detailed event information
4. Track event resolution

## Alert Management

### Alert Configuration

Alerts are configured through threshold settings in `config/thresholds.json`.

**Threshold levels:**

- **Good**: Normal operation (no alert)
- **Warning**: Monitor situation (no alert)
- **Critical**: Issue detected (no alert)
- **Alarm**: Send notification (email alert)

### Email Notifications

**When alerts are sent:**

- Metric exceeds alarm threshold
- Not sent within cooldown period
- Intelligent deduplication active

**Alert email contains:**

- Server name and affected metric
- Current value vs. threshold
- Timestamp of detection
- Link to dashboard

### Configuring Alert Intervals

Prevent notification spam with cooldown periods:

```json
{
  "alarm_intervals": {
    "disk_space": 3600,      // 1 hour
    "cpu_usage": 1800,       // 30 minutes
    "memory_usage": 1800,    // 30 minutes
    "error_log": 7200        // 2 hours
  }
}
```

### Alert Best Practices

1. **Set appropriate thresholds** for your environment
2. **Use cooldown periods** to prevent spam
3. **Review alerts regularly** and adjust thresholds
4. **Test alert delivery** periodically
5. **Document escalation procedures**

## Threshold Configuration

### Default Thresholds

See [Thresholds Reference](../reference/thresholds.md) for complete documentation.

### Customizing Thresholds

Edit `config/thresholds.json`:

```json
{
  "server_data": {
    "disk_space_usage_percent": {
      "good": 60,
      "warn": 75,
      "critical": 85,
      "alarm": 90
    }
  }
}
```

**Considerations:**

- Server capacity and normal usage
- Application requirements
- Growth projections
- Maintenance windows

## Cache Management

### Understanding Cache

MATA Dashboard caches API responses to reduce load on monitored servers.

**Default cache TTLs:**

| Data Type | TTL | Purpose |
|-----------|-----|---------|
| Server Data | 10s | Real-time metrics |
| Application List | 60s | App inventory |
| Error Logs | 60s | Log analysis |
| Process Info | 30s | Process monitoring |

### Force Refresh

Force refresh to bypass cache:

1. Click **Refresh** button on dashboard
2. Fetches fresh data immediately
3. Updates cache with new data

### Cache Invalidation

Cache is automatically invalidated on:

- Manual refresh requests
- TTL expiration
- Configuration changes

## Data Retention

### Metrics Retention

Server metrics are retained at different granularities:

| Granularity | Retention | Purpose |
|-------------|-----------|---------|
| **Raw (5-min)** | 7 days | Recent detailed data |
| **Hourly** | 30 days | Historical trends |
| **Daily** | 365 days | Long-term analysis |

Configure in `config/data_retention.json`:

```json
{
  "raw_metrics_days": 7,
  "hourly_metrics_days": 30,
  "daily_metrics_days": 365
}
```

### Event Retention

Events and alarms can be configured with retention policies:

- Automatic cleanup of old events
- Configurable retention by severity
- Archive important events before deletion

## Monitoring Best Practices

### Daily Operations

1. **Check dashboard** at start of day
2. **Review any alerts** received overnight
3. **Verify cron jobs** are running
4. **Check server connectivity** status

### Weekly Operations

1. **Review event logs** for patterns
2. **Analyze threshold violations**
3. **Adjust thresholds** if needed
4. **Clean up old logs** manually if needed

### Monthly Operations

1. **Review storage trends**
2. **Plan capacity upgrades** if needed
3. **Audit alert effectiveness**
4. **Update documentation**

### Proactive Monitoring

- Set up **external uptime monitoring** for the dashboard itself
- Configure **backup monitoring** to ensure backups run
- Implement **trend analysis** for capacity planning
- Create **runbooks** for common issues

## Troubleshooting Monitoring

### Server Shows as Disconnected

**Possible causes:**

- Node API endpoint unreachable
- API key mismatch
- Network connectivity issue
- Firewall blocking request

**Resolution steps:**

1. Verify API endpoint URL
2. Check API key in `servers.json`
3. Test connectivity: `curl https://server.com/mata-node/api`
4. Review error logs

### Metrics Not Updating

**Possible causes:**

- Cron jobs not running
- Cache not expiring
- Node returning stale data

**Resolution steps:**

1. Verify cron jobs: `crontab -l`
2. Check cron logs: `tail -f temp/logs/cron-collect.log`
3. Force refresh from dashboard
4. Clear cache manually if needed

### Alerts Not Received

**Possible causes:**

- SMTP configuration incorrect
- Email caught in spam filter
- Threshold not reached for alarm level
- Cooldown period active

**Resolution steps:**

1. Verify SMTP settings in `mail.config.env`
2. Test email sending manually
3. Check spam/junk folders
4. Review threshold configuration
5. Check alarm cooldown periods

## Next Steps

- [Configuration Guide](configuration.md) - Configure monitoring settings
- [Server Metrics Reference](../reference/server-metrics.md) - Detailed metrics documentation
- [Thresholds Reference](../reference/thresholds.md) - Complete threshold options
- [Troubleshooting](../tutorials/troubleshooting.md) - Solve monitoring issues
