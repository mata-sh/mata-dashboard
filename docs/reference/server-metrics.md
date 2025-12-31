# Server Metrics Reference

Complete catalog of all server metrics collected and monitored by MATA Dashboard.

## Overview

MATA Dashboard collects comprehensive server metrics through its Node API endpoints. These metrics provide real-time visibility into server health, resource utilization, and system performance.

## Metric Categories

### System Resources
- **CPU Usage**: Processor utilization and load
- **Memory (RAM)**: Memory consumption and availability
- **Disk Space**: Storage usage and free space
- **File System**: File count and inode usage

### Process Monitoring
- **Total Processes**: All active system processes
- **PHP Processes**: PHP-FPM/CLI processes
- **SQL Processes**: Database server processes

### Application Resources
- **Session Count**: Active user sessions
- **Log File Size**: Application log file growth
- **Error Counts**: PHP errors, warnings, and notices

---

## CPU Metrics

### CPU Usage Percentage
Real-time processor utilization across all cores.

| Field | Description |
|-------|-------------|
| **Metric Name** | `cpu_usage_percent` |
| **Data Type** | Float (0.00 - 100.00) |
| **Unit** | Percentage |
| **Update Frequency** | Every 5 minutes (via cron) |
| **Alerting** | Threshold-based |

**Interpretation:**
- **0-50%**: Normal operation
- **50-70%**: Moderate load
- **70-85%**: High load - investigate processes
- **85-100%**: Critical - performance degradation likely

**Example Response:**
```json
{
  "cpu_usage_percent": 45.2
}
```

### CPU Load Average
System load average over different time periods.

| Field | Description |
|-------|-------------|
| **Metric Name** | `load_average` |
| **Data Type** | Object with 1m, 5m, 15m values |
| **Unit** | Load units (relative to CPU cores) |
| **Update Frequency** | Real-time |

**Example Response:**
```json
{
  "load_average": {
    "1min": 1.5,
    "5min": 1.2,
    "15min": 0.8
  }
}
```

**Interpretation:**
For a 4-core system:
- Load < 4.0: System has capacity
- Load = 4.0: System at full capacity
- Load > 4.0: Processes waiting for CPU time

---

## Memory (RAM) Metrics

### Memory Usage
Current RAM consumption and availability.

| Field | Description |
|-------|-------------|
| **Metric Name** | `ram_used`, `ram_free`, `ram_total` |
| **Data Type** | Integer (bytes) |
| **Unit** | Bytes (converted to MB/GB in UI) |
| **Update Frequency** | Every 5 minutes |
| **Alerting** | Threshold-based on percentage |

**Example Response:**
```json
{
  "ram_total": 16777216000,
  "ram_used": 12582912000,
  "ram_free": 4194304000,
  "ram_usage_percent": 75.0
}
```

**Interpretation:**
- **0-60%**: Normal operation
- **60-80%**: Monitor for memory leaks
- **80-90%**: High memory pressure
- **90-100%**: Critical - swapping likely

### Swap Usage
Virtual memory (swap space) utilization.

| Field | Description |
|-------|-------------|
| **Metric Name** | `swap_used`, `swap_free`, `swap_total` |
| **Data Type** | Integer (bytes) |
| **Unit** | Bytes |
| **Update Frequency** | Every 5 minutes |

**Warning Signs:**
- Heavy swap usage indicates RAM exhaustion
- Constant swapping causes severe performance degradation
- Monitor swap usage trends over time

---

## Disk Space Metrics

### Disk Usage
Storage utilization for all mounted filesystems.

| Field | Description |
|-------|-------------|
| **Metric Name** | `disk_space_usage_percent` |
| **Data Type** | Float (0.00 - 100.00) |
| **Unit** | Percentage |
| **Update Frequency** | Every 5 minutes |
| **Alerting** | Configurable thresholds |
| **Default Thresholds** | Good: <60%, Warn: 70%, Critical: 80%, Alarm: 90% |

**Example Response:**
```json
{
  "disk_total": 1073741824000,
  "disk_used": 751619276800,
  "disk_free": 322122547200,
  "disk_space_usage_percent": 70.0
}
```

**Threshold Levels:**

| Level | Percentage | Action Required |
|-------|------------|-----------------|
| **Good** | 0-60% | Normal monitoring |
| **Warning** | 60-70% | Review disk usage trends |
| **Critical** | 70-80% | Plan cleanup or expansion |
| **Alarm** | 80-90% | Immediate action required |
| **Emergency** | 90-100% | Service interruption risk |

**Best Practices:**
- Monitor disk growth rate
- Set up automated cleanup for logs and temp files
- Plan capacity expansion when reaching 70%
- Keep 20-30% free space for optimal performance

### Disk Free Space
Absolute free space available.

| Field | Description |
|-------|-------------|
| **Metric Name** | `disk_free_bytes`, `disk_free_gb` |
| **Data Type** | Integer (bytes), Float (GB) |
| **Unit** | Bytes, Gigabytes |
| **Update Frequency** | Every 5 minutes |

**Critical Thresholds:**
- **< 10GB**: Warning state
- **< 5GB**: Critical state
- **< 1GB**: Emergency state

---

## File System Metrics

### Total File Count
Total number of files across the entire filesystem.

| Field | Description |
|-------|-------------|
| **Metric Name** | `total_system_filecount` |
| **Data Type** | Integer |
| **Unit** | File count |
| **Update Frequency** | Hourly (expensive operation) |
| **Alerting** | Threshold-based |
| **Default Thresholds** | Good: <500k, Warn: 700k, Critical: 850k, Alarm: 900k |

**Example Response:**
```json
{
  "total_system_filecount": 678543
}
```

**Threshold Configuration:**

| Level | File Count | Interpretation |
|-------|------------|----------------|
| **Good** | < 500,000 | Normal filesystem usage |
| **Warning** | 500,000 - 700,000 | Monitor file growth |
| **Critical** | 700,000 - 850,000 | Review file storage strategy |
| **Alarm** | 850,000 - 900,000 | High inode usage risk |
| **Emergency** | > 900,000 | Inode exhaustion possible |

**Impact of High File Counts:**
- Slower filesystem operations
- Backup duration increases
- Inode exhaustion risk (even with free disk space)
- Directory traversal performance degradation

**Common Causes:**
- Log file fragmentation
- Temporary file accumulation
- Cache file buildup
- Session file retention
- Email queue files

**Remediation Strategies:**
- Implement log rotation
- Clean temp directories regularly
- Archive or compress old files
- Use database storage for sessions instead of files
- Review application file creation patterns

### Inode Usage
Filesystem inode utilization (if available).

| Field | Description |
|-------|-------------|
| **Metric Name** | `inode_usage_percent` |
| **Data Type** | Float (0.00 - 100.00) |
| **Unit** | Percentage |
| **Update Frequency** | Hourly |

**Critical Note:**
A filesystem can run out of inodes (file descriptors) even with plenty of free disk space. This prevents creating new files.

---

## Process Metrics

### Process Count by Type
Count of active processes categorized by type.

| Field | Description |
|-------|-------------|
| **Metric Name** | `process_count_total`, `process_count_php`, `process_count_sql` |
| **Data Type** | Integer |
| **Unit** | Process count |
| **Update Frequency** | Every 5 minutes |

**Example Response:**
```json
{
  "process_count_total": 342,
  "process_count_php": 15,
  "process_count_sql": 8
}
```

**Process Categories:**

#### Total Processes
All active system processes.

**Interpretation:**
- **< 200**: Light server load
- **200-500**: Normal operation
- **500-1000**: High activity
- **> 1000**: Investigate process accumulation

#### PHP Processes
Active PHP-FPM workers and CLI processes.

**Monitoring Points:**
- Compare against PHP-FPM pool configuration
- Check for zombie PHP processes
- Monitor process lifetime and memory growth
- Watch for stuck CLI processes

**Typical Configuration:**
```ini
; PHP-FPM pool configuration
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
```

#### SQL Processes
Database server connections and processes.

**Monitoring Points:**
- Compare against `max_connections` setting
- Check for long-running queries
- Monitor connection pool usage
- Watch for connection leaks

---

## Application-Level Metrics

### Session Count
Active user sessions for monitored applications.

| Field | Description |
|-------|-------------|
| **Metric Name** | `session_count` |
| **Data Type** | Integer |
| **Unit** | Session count |
| **Update Frequency** | Every minute |
| **Alerting** | Application-specific thresholds |
| **Default Thresholds** | Good: <100, Warn: 500, Critical: 2500, Alarm: 50000 |

**Example Response:**
```json
{
  "session_count": 1247,
  "session_count_last_hour": 1189,
  "session_count_trend": "increasing"
}
```

**Interpretation:**
- Tracks active user activity
- Indicates application load
- Helps with capacity planning
- Useful for performance correlation

**Session Storage Considerations:**

| Storage Method | File Count Impact | Performance |
|----------------|-------------------|-------------|
| **File-based** | 1 file per session | Impacts total_system_filecount |
| **Database** | No file impact | Better for high-traffic sites |
| **Redis/Memcached** | No file impact | Best performance |

### Log File Size
Size of application log files.

| Field | Description |
|-------|-------------|
| **Metric Name** | `log_filesize_kb` |
| **Data Type** | Integer |
| **Unit** | Kilobytes |
| **Update Frequency** | Every 5 minutes |
| **Alerting** | Threshold-based |
| **Default Thresholds** | Good: <10MB, Warn: 100MB, Critical: 250MB, Alarm: 500MB |

**Example Response:**
```json
{
  "log_filesize_kb": 102400,
  "log_filesize_mb": 100,
  "log_file_path": "/var/www/app/storage/logs/laravel.log"
}
```

**Threshold Configuration:**

| Level | Size | Action |
|-------|------|--------|
| **Good** | < 10 MB | Normal operation |
| **Warning** | 10-100 MB | Review log rotation |
| **Critical** | 100-250 MB | Implement rotation immediately |
| **Alarm** | 250-500 MB | Disk space risk |
| **Emergency** | > 500 MB | Performance impact likely |

**Log Management Best Practices:**
```bash
# Example logrotate configuration
/var/www/app/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
}
```

---

## Metric Collection Schedule

### Real-Time Metrics
Updated on every dashboard refresh (user-initiated):
- Current CPU usage
- Current memory usage
- Active process counts
- Application status

### Scheduled Collection (Cron)

#### Every 5 Minutes
```bash
*/5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/collect_server_metrics.php
```

Collects:
- CPU usage snapshots
- Memory utilization
- Disk space
- Process counts
- Application metrics

#### Hourly Aggregation
```bash
5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/aggregate_server_metrics.php
```

Processes:
- Hourly metric aggregation
- Statistical summaries (min, max, avg)
- Data retention enforcement

#### Daily Aggregation
Runs at 00:30 daily via aggregate script:
- Daily metric summaries
- Long-term trend data
- Historical archiving

---

## Metric Storage and Retention

### Database Tables

#### server_metrics_raw
Raw metrics collected every 5 minutes.

**Retention**: 30 days (configurable)

**Schema:**
```sql
CREATE TABLE server_metrics_raw (
    id INT PRIMARY KEY AUTO_INCREMENT,
    server_id INT NOT NULL,
    collected_at DATETIME NOT NULL,
    cpu_usage_percent DECIMAL(5,2),
    ram_usage_percent DECIMAL(5,2),
    disk_usage_percent DECIMAL(5,2),
    process_count_total INT,
    process_count_php INT,
    process_count_sql INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### server_metrics_hourly
Hourly aggregated metrics.

**Retention**: 90 days (configurable)

**Aggregation**: Min, Max, Average values per hour

#### server_metrics_daily
Daily aggregated metrics.

**Retention**: 365 days (configurable)

**Aggregation**: Min, Max, Average values per day

### Retention Configuration

Edit `config/data_retention.json`:

```json
{
    "raw_retention_days": 30,
    "aggregation": {
        "hourly": {
            "lag_minutes": 5,
            "catch_up_hours": 24
        },
        "daily": {
            "lag_minutes": 30
        }
    },
    "pruning": {
        "batch_limit": 5000
    }
}
```

---

## API Endpoints

### Get Server System Data
```
GET /api/server/system
```

**Response:**
```json
{
    "cpu_usage_percent": 45.2,
    "ram_total": 16777216000,
    "ram_used": 12582912000,
    "ram_free": 4194304000,
    "ram_usage_percent": 75.0,
    "disk_total": 1073741824000,
    "disk_used": 751619276800,
    "disk_free": 322122547200,
    "disk_space_usage_percent": 70.0,
    "total_system_filecount": 678543,
    "process_count_total": 342,
    "process_count_php": 15,
    "process_count_sql": 8,
    "collected_at": "2025-11-02T14:30:00Z"
}
```

### Get Server Processes
```
GET /api/server/processes
```

**Response:**
```json
{
    "total": 342,
    "php": 15,
    "sql": 8,
    "details": [
        {
            "pid": 1234,
            "type": "php-fpm",
            "cpu": 2.5,
            "memory": 45678,
            "uptime": "3h 24m"
        }
    ]
}
```

---

## Alerting on Metrics

### Threshold Evaluation
Metrics are evaluated against configured thresholds in `config/thresholds.json`.

**Evaluation Frequency:**
- Real-time: On each data collection
- Batch: Hourly aggregation review

### Alert Levels

| Level | Color | Action |
|-------|-------|--------|
| **Good** | Green | No action required |
| **Warning** | Yellow | Monitor trend |
| **Critical** | Orange | Investigate and plan |
| **Alarm** | Red | Immediate action required |

### Alert Notification Flow

1. **Metric Collection**: Data collected from node
2. **Threshold Check**: Compare against configured thresholds
3. **Event Recording**: Log threshold violation
4. **Deduplication**: Check against recent events
5. **Alarm Trigger**: Create alarm if threshold exceeded
6. **Notification**: Send email alert to configured recipients

---

## Performance Considerations

### Metric Collection Impact

| Metric | Collection Cost | Frequency Recommendation |
|--------|----------------|--------------------------|
| **CPU Usage** | Low | Every 1-5 minutes |
| **Memory Usage** | Low | Every 1-5 minutes |
| **Disk Space** | Medium | Every 5-15 minutes |
| **File Count** | High | Hourly or daily |
| **Process List** | Medium | Every 5-15 minutes |

### Optimization Tips

1. **File Count Collection:**
   - Cache results for 1 hour minimum
   - Consider sampling specific directories only
   - Run during off-peak hours

2. **Disk Space:**
   - Cache for 5-15 minutes
   - Monitor rate of change, not just absolute values

3. **Process Counts:**
   - Use count-only queries (avoid full process details)
   - Filter by relevant process types only

4. **Database Storage:**
   - Implement automatic retention policies
   - Use aggregation for historical data
   - Index by server_id and collected_at

---

## Troubleshooting

### Missing Metrics

**Problem:** Metrics not updating

**Check:**
1. Cron job status: `crontab -l`
2. Cron execution logs: `grep CRON /var/log/syslog`
3. Application logs: `tail -f storage/logs/info.log`
4. API connectivity: Test node API endpoint
5. Database connectivity: Check event_log table

### Inaccurate Metrics

**Problem:** Metrics seem incorrect

**Check:**
1. Node API response: `curl -H "X-API-Key: xxx" https://node/api/system`
2. Timezone settings: Server and application must match
3. Unit conversion: Verify bytes → MB/GB calculations
4. Caching: Force refresh to bypass cache

### High File Counts

**Problem:** total_system_filecount constantly increasing

**Investigation:**
```bash
# Find directories with most files
find /var/www -type d -exec sh -c 'echo "$(find "{}" -maxdepth 1 -type f | wc -l) {}"' \; | sort -rn | head -20

# Find large file accumulations
du -a /var/www | sort -rn | head -20

# Check session storage
ls -la /var/lib/php/sessions/ | wc -l
```

---

## Related Documentation

- [Application Monitoring Reference](./application-monitoring.md)
- [Threshold Configuration Reference](./thresholds.md)
- [Event System Reference](./event-system.md)
- [Configuration Reference](./configuration-reference.md)

---

**Last Updated:** 2025-11-02
**Version:** 1.0
