# Cron Jobs

This guide covers developing, deploying, and monitoring cron jobs for MATA Dashboard.

## Overview

MATA Dashboard uses cron jobs for automated data collection and processing:

- **Data Collection**: Gather metrics from monitored servers
- **Data Aggregation**: Process raw data into time-series summaries
- **Maintenance**: Cleanup old data, health checks

## Existing Cron Jobs

### collect_server_metrics.php

Collects raw server metrics every 5 minutes.

**Schedule:** Every 5 minutes

```bash
*/5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/collect_server_metrics.php
```

**What it does:**

1. Loads all configured servers from `config/servers.json`
2. Calls ServerDataCollector to fetch metrics
3. Stores raw metrics in database
4. Evaluates thresholds
5. Generates events/alerts if needed

**Output:** Logs to `temp/logs/cron-collect.log`

### aggregate_server_metrics.php

Aggregates raw metrics into hourly and daily summaries.

**Schedule:** Hourly (at 5 minutes past the hour)

```bash
5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/aggregate_server_metrics.php
```

**What it does:**

1. Queries raw metrics from past hour
2. Calculates averages, min, max values
3. Stores hourly aggregates
4. Daily aggregation (if appropriate time)
5. Cleanup old raw data per retention policy

**Output:** Logs to `temp/logs/cron-aggregate.log`

## Cron Job Architecture

### Base Class

All cron scripts extend the `Cronjob` utility class:

```php
<?php

use IDS\Utils\Cronjob;

require_once __DIR__ . '/../cron/bootstrap.php';

class MyCustomCron extends Cronjob
{
    public function run(): void
    {
        // Implementation
        $this->log('Starting custom cron job');

        // Do work

        $this->log('Cron job completed');
    }
}

$cron = new MyCustomCron();
$cron->execute();
```

### Bootstrap

All cron scripts use `cron/bootstrap.php` for initialization:

```php
<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment configuration
$config = \IDS\Config\EnvConfigLoader::load();

// Initialize database
$database = new \IDS\Core\Database(
    $config->dbHost,
    $config->dbName,
    $config->dbUser,
    $config->dbPass
);

// Initialize logger
$logger = \IDS\Utils\LogHandler::create('cron');

// Make available to cron scripts
return [
    'config' => $config,
    'database' => $database,
    'logger' => $logger
];
```

## Creating Custom Cron Jobs

### Step 1: Create Cron Script

Create a new file in `cron/` directory:

```php
<?php
// cron/my_custom_job.php

use IDS\Utils\Cronjob;
use IDS\Core\Database;

require_once __DIR__ . '/bootstrap.php';

/**
 * Custom cron job description
 */
class MyCustomJob extends Cronjob
{
    private Database $database;

    public function __construct()
    {
        parent::__construct();

        // Get database from bootstrap
        $this->database = $GLOBALS['database'];
    }

    /**
     * Execute the cron job
     */
    public function run(): void
    {
        $this->log('Starting custom job');

        try {
            // Your logic here
            $result = $this->performTask();

            $this->log("Task completed: {$result}");
        } catch (\Exception $e) {
            $this->logError('Task failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Perform the actual task
     */
    private function performTask(): string
    {
        // Implementation
        return 'success';
    }
}

// Execute
$job = new MyCustomJob();
$job->execute();
```

### Step 2: Add Crontab Entry

```bash
# Edit crontab
crontab -e

# Add entry (example: run every 15 minutes)
*/15 * * * * /usr/bin/php /path/to/mata-dashboard/cron/my_custom_job.php >> /path/to/mata-dashboard/temp/logs/cron-custom.log 2>&1
```

### Step 3: Test Manually

```bash
# Test execution
php cron/my_custom_job.php

# Check output
cat temp/logs/cron-custom.log
```

## Cron Job Naming Convention

Follow action-first naming:

- **`collect_*.php`**: Gather data from external sources
- **`aggregate_*.php`**: Process/summarize existing data
- **`cleanup_*.php`**: Remove old data
- **`monitor_*.php`**: Health checks and monitoring
- **`sync_*.php`**: Synchronize data between systems

**Examples:**

```
collect_server_metrics.php
aggregate_server_metrics.php
cleanup_old_events.php
monitor_cron_health.php
sync_config_to_database.php
```

## Logging

### Using Built-in Logging

The `Cronjob` base class provides logging methods:

```php
class MyCronJob extends Cronjob
{
    public function run(): void
    {
        // Info logging
        $this->log('Processing started');

        // Error logging
        $this->logError('Something went wrong');

        // Debug logging (only if debug mode enabled)
        $this->logDebug('Debug information');
    }
}
```

### Log Levels

- **INFO**: Normal operation messages
- **ERROR**: Error conditions
- **DEBUG**: Detailed debugging information

### Log Output

Logs are written to:

```
temp/logs/cron-{jobname}.log
```

**Log format:**

```
[2025-11-02 14:30:00] INFO: Starting server metrics collection
[2025-11-02 14:30:05] INFO: Collected data from 5 servers
[2025-11-02 14:30:06] INFO: Metrics collection completed
```

## Error Handling

### Exception Handling

Always wrap risky operations in try-catch:

```php
public function run(): void
{
    foreach ($servers as $server) {
        try {
            $this->processServer($server);
        } catch (\Exception $e) {
            // Log error but continue processing other servers
            $this->logError("Failed to process {$server['id']}: {$e->getMessage()}");
            continue;
        }
    }
}
```

### Graceful Degradation

Cron jobs should continue running even if some operations fail:

```php
public function collectMetrics(): void
{
    $servers = $this->getServers();
    $successCount = 0;
    $failCount = 0;

    foreach ($servers as $server) {
        try {
            $this->collect($server);
            $successCount++;
        } catch (\Exception $e) {
            $this->logError("Server {$server['id']} failed: {$e->getMessage()}");
            $failCount++;
        }
    }

    $this->log("Completed: {$successCount} success, {$failCount} failed");
}
```

## Performance Considerations

### Execution Time

Long-running cron jobs should:

1. **Process in batches** to avoid timeouts
2. **Log progress** regularly
3. **Handle interruptions** gracefully

```php
public function processBatch(): void
{
    $batchSize = 100;
    $offset = 0;

    while (true) {
        $items = $this->fetchBatch($offset, $batchSize);

        if (empty($items)) {
            break;
        }

        foreach ($items as $item) {
            $this->processItem($item);
        }

        $offset += $batchSize;
        $this->log("Processed {$offset} items");
    }
}
```

### Memory Management

For large datasets:

```php
// ✅ Good - Process one at a time
foreach ($this->getServersIterator() as $server) {
    $this->process($server);
    unset($server); // Free memory
}

// ❌ Bad - Load everything into memory
$servers = $this->getAllServers();
foreach ($servers as $server) {
    $this->process($server);
}
```

### Database Connections

Reuse connections efficiently:

```php
class MyCronJob extends Cronjob
{
    private Database $database;

    public function __construct()
    {
        parent::__construct();
        // Single connection for entire job
        $this->database = $GLOBALS['database'];
    }
}
```

## Monitoring Cron Jobs

### Health Checks

Monitor cron job execution:

```php
// Create health check endpoint
public function cronHealthCheck(): array
{
    $lastRun = $this->database->query(
        "SELECT MAX(timestamp) as last_run
         FROM cron_executions
         WHERE job_name = 'collect_server_metrics'"
    );

    $minutesSinceLastRun = (time() - strtotime($lastRun['last_run'])) / 60;

    return [
        'job' => 'collect_server_metrics',
        'last_run' => $lastRun['last_run'],
        'minutes_ago' => $minutesSinceLastRun,
        'healthy' => $minutesSinceLastRun < 10 // Should run every 5 min
    ];
}
```

### Tracking Execution

Log execution start/end:

```php
public function execute(): void
{
    $this->recordStart();

    try {
        $this->run();
        $this->recordSuccess();
    } catch (\Exception $e) {
        $this->recordFailure($e);
        throw $e;
    }
}

private function recordStart(): void
{
    $this->database->execute(
        "INSERT INTO cron_executions (job_name, started_at) VALUES (?, NOW())",
        [static::class]
    );
}
```

### Email Notifications

Send email on failure:

```php
public function execute(): void
{
    try {
        $this->run();
    } catch (\Exception $e) {
        $this->sendFailureNotification($e);
        throw $e;
    }
}

private function sendFailureNotification(\Exception $e): void
{
    $notification = new NotificationManager();
    $notification->send(
        'admin@example.com',
        'Cron Job Failed',
        "Job: " . static::class . "\n" .
        "Error: " . $e->getMessage()
    );
}
```

## Scheduling Best Practices

### Avoid Overlap

Prevent multiple instances from running simultaneously:

```php
public function execute(): void
{
    if (!$this->acquireLock()) {
        $this->log('Another instance is running, exiting');
        return;
    }

    try {
        $this->run();
    } finally {
        $this->releaseLock();
    }
}

private function acquireLock(): bool
{
    $lockFile = sys_get_temp_dir() . '/' . static::class . '.lock';
    $this->lockHandle = fopen($lockFile, 'w');
    return flock($this->lockHandle, LOCK_EX | LOCK_NB);
}
```

### Stagger Execution

Avoid all cron jobs running at once:

```bash
# Stagger job execution times
*/5 * * * * php cron/collect_server_metrics.php     # :00, :05, :10, etc.
2-59/5 * * * * php cron/collect_application_logs.php # :02, :07, :12, etc.
5 * * * * php cron/aggregate_server_metrics.php      # :05 each hour
```

### Time-based Logic

Different behavior based on time:

```php
public function run(): void
{
    $hour = (int)date('H');

    // Run cleanup only at night
    if ($hour >= 2 && $hour < 4) {
        $this->performCleanup();
    }

    // Always collect metrics
    $this->collectMetrics();
}
```

## Testing Cron Jobs

### Manual Execution

```bash
# Run directly
php cron/collect_server_metrics.php

# Check exit code
echo $?
```

### Dry Run Mode

Add a dry-run option:

```php
class MyCronJob extends Cronjob
{
    private bool $dryRun = false;

    public function __construct(bool $dryRun = false)
    {
        parent::__construct();
        $this->dryRun = $dryRun;
    }

    public function run(): void
    {
        if ($this->dryRun) {
            $this->log('[DRY RUN] Would process data');
            return;
        }

        $this->processData();
    }
}

// Usage
$job = new MyCronJob($dryRun = true);
$job->execute();
```

### Verify Output

```bash
# Check logs
tail -f temp/logs/cron-collect.log

# Verify database changes
mysql -u user -p -D mata_dashboard -e "SELECT COUNT(*) FROM server_metrics"
```

## Troubleshooting

### Cron Job Not Running

**Check crontab:**

```bash
crontab -l
```

**Check cron service:**

```bash
systemctl status cron
```

**Check logs:**

```bash
grep CRON /var/log/syslog
```

### Permission Errors

Ensure web server user can execute:

```bash
chmod +x cron/*.php
chown www-data:www-data cron/*.php
```

### PHP Path Issues

Use absolute path to PHP:

```bash
# Find PHP path
which php

# Use in crontab
*/5 * * * * /usr/bin/php /path/to/cron/script.php
```

## Example: Complete Cron Job

```php
<?php
// cron/cleanup_old_events.php

use IDS\Utils\Cronjob;
use IDS\Core\Database;
use IDS\Config\MonitoringSettingsLoader;

require_once __DIR__ . '/bootstrap.php';

/**
 * Cleanup old events based on retention policy
 */
class CleanupOldEvents extends Cronjob
{
    private Database $database;
    private int $retentionDays;

    public function __construct()
    {
        parent::__construct();

        $this->database = $GLOBALS['database'];

        $settings = MonitoringSettingsLoader::loadDataRetention();
        $this->retentionDays = $settings['event_retention_days'] ?? 90;
    }

    public function run(): void
    {
        $this->log('Starting event cleanup');
        $this->log("Retention policy: {$this->retentionDays} days");

        try {
            $deleted = $this->cleanupEvents();
            $this->log("Cleanup completed: {$deleted} events removed");
        } catch (\Exception $e) {
            $this->logError('Cleanup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function cleanupEvents(): int
    {
        $cutoffDate = date('Y-m-d', strtotime("-{$this->retentionDays} days"));

        $result = $this->database->execute(
            "DELETE FROM events WHERE timestamp < ?",
            [$cutoffDate]
        );

        return $result->rowCount();
    }
}

// Execute
$job = new CleanupOldEvents();
$job->execute();
```

## Next Steps

- [Code Quality](code-quality.md) - Code standards and tools
- [Project Structure](project-structure.md) - Code organization
- [Contributing](contributing.md) - Contribution guidelines
