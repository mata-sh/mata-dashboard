# Extension Points & Hooks

This document describes the extension points and hooks available in MATA Dashboard for customization and integration.

## Overview

MATA Dashboard is designed with extensibility in mind. While the core is opinionated and focused, several extension points allow customization without modifying core code.

### Extension Categories

1. **Interface-Based Extensions**: Implement interfaces to replace core functionality
2. **Event System**: Hook into event logging and alarm processing
3. **Renderer Extensions**: Custom view rendering logic
4. **Service Layer**: Replace or extend service implementations
5. **Configuration**: JSON-based configuration for customization

---

## Interface-Based Extensions

### ServerDataCollectorInterface

Replace or extend how the dashboard collects data from nodes.

**Location:** `src/Services/Collect/ServerDataCollectorInterface.php`

**Use Cases:**
- Mock data for testing/demos
- Alternative API protocols
- Local data collection (no remote nodes)
- Enhanced data transformation

**Implementation Example:**

```php
<?php

namespace Your\Namespace;

use Mata\Services\Collect\ServerDataCollectorInterface;

class CustomServerDataCollector implements ServerDataCollectorInterface
{
    public function getServerSystemData(string $cacheType = 'serverData'): ?array
    {
        // Custom implementation
        // Could fetch from alternative source, database, etc.
        return [
            'cpu_usage' => $this->getCustomCpuData(),
            'memory_used' => $this->getCustomMemoryData(),
            // ... other required fields
        ];
    }

    public function getApps(string $cacheType = 'apps'): ?array
    {
        // Custom apps data source
        return $this->fetchAppsFromDatabase();
    }

    // Implement all interface methods...
}
```

**Integration:**

Replace in controller constructor:
```php
// In DashboardController or ModalController
$this->serverDataCollector = new CustomServerDataCollector(/* dependencies */);
```

---

### AppReachabilityMonitorInterface

Customize application availability checking and health monitoring.

**Location:** `src/Services/Monitor/AppReachabilityMonitorInterface.php`

**Use Cases:**
- Custom ping mechanisms
- Enhanced health checks (deep checks, database connectivity)
- Integration with external monitoring services
- Custom latency measurement

**Required Methods:**

```php
public function ping(
    string $url,
    int $timeout = 2,
    string $cacheType = CacheTypes::PING
): array;

public function multiPing(
    array $urls,
    int $timeout = 2,
    string $cacheType = CacheTypes::PING,
    bool $async = false
): array;
```

**Implementation Example:**

```php
<?php

namespace Your\Namespace;

use Mata\Services\Monitor\AppReachabilityMonitorInterface;

class EnhancedAppReachabilityMonitor implements AppReachabilityMonitorInterface
{
    public function ping(string $url, int $timeout = 2, string $cacheType = 'ping'): array
    {
        // Enhanced ping with additional checks
        $httpCheck = $this->checkHttpStatus($url, $timeout);
        $databaseCheck = $this->checkDatabaseConnectivity($url);
        $serviceCheck = $this->checkCriticalServices($url);

        return [
            'reachable' => $httpCheck['reachable'] && $databaseCheck && $serviceCheck,
            'httpCode' => $httpCheck['httpCode'],
            'latency_ms' => $httpCheck['latency_ms'],
            'checks' => [
                'http' => $httpCheck['reachable'],
                'database' => $databaseCheck,
                'services' => $serviceCheck,
            ]
        ];
    }

    public function multiPing(array $urls, int $timeout = 2, string $cacheType = 'ping', bool $async = false): array
    {
        // Parallel health checks
        return $this->performParallelHealthChecks($urls, $timeout);
    }
}
```

---

### DatabaseInterface

Replace database implementation for alternative storage backends.

**Location:** `src/Core/DatabaseInterface.php`

**Use Cases:**
- PostgreSQL instead of MySQL
- NoSQL backends (MongoDB, etc.)
- Cloud-native databases (Aurora, Cloud SQL)
- Read replicas for scaling

**Required Methods:**

```php
public function runQuery(string $sql, array $params = []): array|int;
public function beginTransaction(): void;
public function commit(): void;
public function rollback(): void;
```

**Integration:**

Configure in application bootstrap or dependency injection container.

---

## Event System Hooks

### EventRecorderService Extension

Hook into event persistence and alarm cooldown tracking. See [Server Metrics Pipeline](../../architecture/server-metrics-pipeline.md) for how `EventManager` produces events/alarms upstream of this service.

**Location:** `src/Services/Event/EventRecorderService.php`

**Extension Example:**

```php
<?php

namespace Your\Namespace;

use Mata\Models\Dto\EventRecordResult;
use Mata\Services\Event\EventRecorderService;

class CustomEventRecorder extends EventRecorderService
{
    public function recordEvent(
        string $type,
        string $message,
        string $context = '',
        string $level = 'info',
        int|string|null $server = null,
        int|string|null $app = null,
        bool $forceAlarm = false,
        ?string $cooldownHash = null,
        int $cooldownMinutes = 0,
    ): EventRecordResult {
        if ($level === 'critical') {
            $this->sendToSlack($message);
        }

        return parent::recordEvent(
            $type, $message, $context, $level, $server, $app,
            $forceAlarm, $cooldownHash, $cooldownMinutes
        );
    }
}
```

**Integration:** inject the custom recorder via `EventManager::setEventRecorder()`.

---

## View Renderer Extensions

### Custom Renderers

Extend base renderer classes to customize HTML output.

**Base Class:** `src/Views/BaseRenderer.php`

**Available Renderers:**
- `ServerRenderer` - Server card and container rendering
- `DatabaseRenderer` - Database card rendering
- `LibrariesRenderer` - Libraries comparison rendering
- `ModalRenderer` - Modal content rendering
- `LogRenderer` - Log analysis rendering

**Extension Example:**

```php
<?php

namespace Your\Namespace;

use Mata\Views\ServerRenderer;

class CustomServerRenderer extends ServerRenderer
{
    /**
     * Override server card rendering with custom template
     */
    public function renderServerCard(array $serverRenderData): string
    {
        $serverKey = (string) ($serverRenderData['server'] ?? 'unknown');

        // Add custom data processing
        $enrichedData = $this->enrichServerData($serverRenderData);

        // Use custom template
        return $this->twig->render('custom/server-card.html.twig', [
            'server' => $enrichedData,
            'server_key' => $serverKey,
            'custom_metrics' => $this->getCustomMetrics($serverKey),
        ]);
    }

    /**
     * Add custom metrics
     */
    private function getCustomMetrics(string $serverKey): array
    {
        return [
            'network_throughput' => $this->calculateNetworkThroughput($serverKey),
            'security_score' => $this->calculateSecurityScore($serverKey),
            'cost_per_hour' => $this->calculateHourlyCost($serverKey),
        ];
    }
}
```

**Integration:**

Replace in controller:
```php
$this->serverRenderer = new CustomServerRenderer($twig, $logger, $tools, $app, $endpoints, $logRenderer);
```

---

## Service Layer Extensions

### EventManager Customization

Extend alarm evaluation and threshold management.

**Location:** `src/Services/Event/EventManager.php`

**Use Cases:**
- Custom threshold logic
- Machine learning-based anomaly detection
- Dynamic thresholds based on time/load

**Extension Example:**

```php
<?php

namespace Your\Namespace;

use Mata\Services\Event\EventManager;

class SmartEventManager extends EventManager
{
    /**
     * Enhanced threshold evaluation with ML-based anomaly detection
     */
    public function getServerDataThresholdLevel(int $value, string $name): string
    {
        $baseline = $this->mlModel->getBaseline($name);

        if ($this->mlModel->detectAnomaly($value, $baseline)) {
            return 'alarm';
        }

        return parent::getServerDataThresholdLevel($value, $name);
    }
}
```

---

### NotificationManager

`Mata\Services\Notification\NotificationManager` is `final` — it manages the in-app notification center (create/read/dismiss) and is not an extension point.

**Location:** `src/Services/Notification/NotificationManager.php`

**Key methods:** `addNotification()`, `getActiveNotifications()`, `markAsRead()`, `deactivateNotification()`.

Alarm delivery routing (email, throttling, direct/emergency dispatch) is handled by `Mata\Services\Alarm\AlarmDirectProcessor` and the `ALARM_DECISION_DRIVER` (`opa`/`builtin`) config — see [Server Metrics Pipeline](../../architecture/server-metrics-pipeline.md).

---

## Configuration-Based Extensions

### Custom Thresholds

Extend threshold configuration with custom metrics.

**File:** `config/thresholds.json`

**Standard Structure:**

```json
{
  "server_data": {
    "cpu_usage": {
      "good": 50,
      "warn": 70,
      "critical": 85,
      "alarm": 90
    }
  },
  "app_data": {
    "session_count": {
      "good": 100,
      "warn": 1000,
      "critical": 5000,
      "alarm": 10000
    }
  }
}
```

**Custom Metrics Extension:**

```json
{
  "server_data": {
    "cpu_usage": { /* ... */ },
    "custom_network_throughput": {
      "good": 100,
      "warn": 500,
      "critical": 800,
      "alarm": 1000,
      "unit": "Mbps"
    },
    "custom_security_score": {
      "good": 90,
      "warn": 70,
      "critical": 50,
      "alarm": 30,
      "inverted": true
    }
  },
  "custom_app_metrics": {
    "api_response_time_ms": {
      "good": 100,
      "warn": 500,
      "critical": 1000,
      "alarm": 2000
    },
    "error_rate_percent": {
      "good": 1,
      "warn": 5,
      "critical": 10,
      "alarm": 20
    }
  }
}
```

---

### Custom Cache TTL

Configure cache behavior per data type.

**File:** `config/ttl.json`

**Extension Example:**

```json
{
  "ping": 360,
  "apps": 60,
  "serverData": 10,
  "databases": 10,
  "appLogs": 30,
  "serverProcesses": 30,
  "readme": 300,
  "errorlog": 60,

  "customMetric": 120,
  "externalApi": 600,
  "computedStats": 300
}
```

**Access in Code:**

```php
$ttl = $this->cacheManager->getTtl('customMetric');
```

---

## Twig Template Extensions

### Custom Twig Filters

Add custom filters for template rendering.

**Location:** Template initialization (e.g., `public/index.php` or bootstrap)

**Example:**

```php
$twig = new \Twig\Environment($loader, [
    'cache' => $cacheDir,
]);

// Add custom filters
$twig->addFilter(new \Twig\TwigFilter('format_bytes', function ($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
}));

$twig->addFilter(new \Twig\TwigFilter('time_ago', function ($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    return floor($diff / 86400) . ' days ago';
}));

$twig->addFilter(new \Twig\TwigFilter('status_badge', function ($status) {
    $badges = [
        'online' => '<span class="tag is-success">Online</span>',
        'offline' => '<span class="tag is-danger">Offline</span>',
        'warning' => '<span class="tag is-warning">Warning</span>',
    ];
    return $badges[$status] ?? $status;
}, ['is_safe' => ['html']]));
```

**Usage in Templates:**

```twig
{{ server.disk_usage | format_bytes }}
{{ event.datetime | time_ago }}
{{ server.status | status_badge }}
```

---

### Custom Twig Functions

Add global functions for templates.

```php
$twig->addFunction(new \Twig\TwigFunction('get_threshold_level', function ($value, $thresholds) {
    if ($value <= $thresholds['good']) return 'good';
    if ($value <= $thresholds['warn']) return 'warn';
    if ($value <= $thresholds['critical']) return 'critical';
    return 'alarm';
}));

$twig->addFunction(new \Twig\TwigFunction('icon', function ($name) {
    $icons = [
        'check' => '',
        'error' => '',
        'warning' => '�',
        'info' => '9',
    ];
    return $icons[$name] ?? '';
}));
```

---

## Middleware Extensions

### Custom Route Middleware

Add custom middleware for specific routes.

**Example: Audit Logging Middleware**

```php
// In RouterSetup.php

$this->router->before('GET|POST', '/watch/.*', function () {
    // Audit log all dashboard access
    $this->auditLogger->log([
        'user' => $this->app->auth->user(),
        'ip' => $_SERVER['REMOTE_ADDR'],
        'endpoint' => $_SERVER['REQUEST_URI'],
        'timestamp' => time(),
    ]);
});
```

**Example: Performance Monitoring**

```php
$this->router->before('GET|POST', '/.*', function () {
    $_SERVER['REQUEST_START_TIME'] = microtime(true);
});

$this->router->after('GET|POST', '/.*', function () {
    $duration = microtime(true) - $_SERVER['REQUEST_START_TIME'];
    $this->performanceMonitor->record($_SERVER['REQUEST_URI'], $duration);
});
```

---

## Cron Job Extensions

### Custom Cron Scripts

`Mata\Utils\CronJob` is used compositionally (not subclassed) — instantiate it via `cron/bootstrap.php`, which returns a ready `$cron` bound to the script's job name, lock file, and heartbeat file.

**Base Class:** `src/Utils/CronJob.php`

**Example: Custom Metrics Collection**

```php
<?php
// cron/collect_custom_metrics.php

declare(strict_types=1);

$requiresDatabase = true;
extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Starting custom metrics collection');

try {
    foreach ($monitoringSettings['servers'] as $serverId => $serverConfig) {
        $cron->touchHeartbeat();

        $metrics = fetchCustomMetrics($serverId);
        $db->runQuery(
            'INSERT INTO custom_metrics (server_id, metric_name, metric_value, collected_at) VALUES (?, ?, ?, NOW())',
            [$serverId, 'network_throughput', $metrics['network_throughput']]
        );
    }

    $cron->log(sprintf('Completed in %.3fs (%s)', $cron->endTimer(), $cron->memoryUsage()));
} finally {
    $cron->releaseLock();
}

function fetchCustomMetrics(string $serverId): array
{
    return [
        'network_throughput' => measureNetworkThroughput($serverId),
        'security_score' => calculateSecurityScore($serverId),
    ];
}
```

**Crontab Entry:**

```bash
*/5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/collect_custom_metrics.php
```

---

## Integration Examples

### Slack Integration

```php
<?php

namespace Your\Namespace\Integrations;

class SlackNotifier
{
    private string $webhookUrl;

    public function __construct(string $webhookUrl)
    {
        $this->webhookUrl = $webhookUrl;
    }

    public function sendServerAlarm(array $serverData, string $alarmType): void
    {
        $message = $this->formatMessage($serverData, $alarmType);
        $this->send($message);
    }

    private function formatMessage(array $serverData, string $alarmType): array
    {
        return [
            'text' => "Server Alarm: {$alarmType}",
            'attachments' => [
                [
                    'color' => $this->getColor($alarmType),
                    'fields' => [
                        [
                            'title' => 'Server',
                            'value' => $serverData['title'],
                            'short' => true,
                        ],
                        [
                            'title' => 'CPU Usage',
                            'value' => $serverData['cpu_usage'] . '%',
                            'short' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function send(array $message): void
    {
        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);
    }

    private function getColor(string $alarmType): string
    {
        return match ($alarmType) {
            'critical' => 'danger',
            'warning' => 'warning',
            default => 'good',
        };
    }
}
```

---

### Webhook Integration

```php
<?php

namespace Your\Namespace\Integrations;

class WebhookDispatcher
{
    private array $webhooks;

    public function __construct(array $webhooks)
    {
        $this->webhooks = $webhooks;
    }

    public function dispatchEvent(string $eventType, array $data): void
    {
        foreach ($this->webhooks as $webhook) {
            if ($this->shouldDispatch($webhook, $eventType)) {
                $this->sendWebhook($webhook['url'], $eventType, $data);
            }
        }
    }

    private function shouldDispatch(array $webhook, string $eventType): bool
    {
        return in_array($eventType, $webhook['events'] ?? [], true);
    }

    private function sendWebhook(string $url, string $eventType, array $data): void
    {
        $payload = [
            'event' => $eventType,
            'timestamp' => time(),
            'data' => $data,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-MATA-Event: ' . $eventType,
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
```

---

## Best Practices

### Extension Development

1. **Follow Interface Contracts**: Always implement all required interface methods
2. **Maintain Backward Compatibility**: Don't break existing APIs
3. **Use Dependency Injection**: Accept dependencies via constructor
4. **Log Extensively**: Use PSR-3 logger for debugging
5. **Handle Errors Gracefully**: Never crash the application
6. **Test Thoroughly**: Unit test custom implementations
7. **Document Extensions**: Add PHPDoc comments

### Performance Considerations

1. **Cache Aggressively**: Use CacheManager for expensive operations
2. **Avoid N+1 Queries**: Batch database operations
3. **Lazy Load**: Only fetch data when needed
4. **Use Async Where Possible**: Parallel operations for multi-server monitoring
5. **Optimize Templates**: Minimize template complexity

### Security Guidelines

1. **Validate All Input**: Never trust user/external data
2. **Use Prepared Statements**: Prevent SQL injection
3. **Sanitize Output**: Prevent XSS attacks
4. **Encrypt Sensitive Data**: API keys, passwords, credentials
5. **Audit Access**: Log security-relevant actions
6. **Rate Limit**: Prevent abuse of custom endpoints

---

## Related Documentation

- [Node API Reference](node-api.md) - External node API specification
- [Dashboard Endpoints](dashboard-endpoints.md) - Internal dashboard API
- [Architecture Overview](../architecture/overview.md) - System architecture
