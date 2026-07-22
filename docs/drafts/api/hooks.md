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

use Watcher\Services\Collect\ServerDataCollectorInterface;

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

use Watcher\Services\Monitor\AppReachabilityMonitorInterface;

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
public function getConnection(): PDO;
public function runQuery(string $sql, array $params = []): PDOStatement|false;
public function fetchAll(string $sql, array $params = []): array;
public function fetchOne(string $sql, array $params = []): mixed;
public function lastInsertId(): string|false;
```

**Integration:**

Configure in application bootstrap or dependency injection container.

---

## Event System Hooks

### EventRecorder Extension

Hook into event logging and alarm processing.

**Location:** `src/Models/EventRecorder.php`

**Available Hook Points:**

#### 1. Custom Event Processing

Extend EventRecorder to add custom logic:

```php
<?php

namespace Your\Namespace;

use Watcher\Models\EventRecorder;

class CustomEventRecorder extends EventRecorder
{
    public function logEvent(
        string $type,
        string $message,
        string $context = '',
        string $level = 'info',
        string|int|null $server = null,
        string|int|null $app = null,
        string $channel = 'default',
        bool $isAlarm = false,
        ?int $serverId = null,
        ?int $applicationId = null
    ): void {
        // Pre-processing hook
        $this->beforeLogEvent($type, $message, $level);

        // Call parent implementation
        parent::logEvent($type, $message, $context, $level, $server, $app, $channel, $isAlarm, $serverId, $applicationId);

        // Post-processing hook
        $this->afterLogEvent($type, $message, $level);
    }

    protected function beforeLogEvent(string $type, string $message, string $level): void
    {
        // Custom logic before logging
        // - Enrich event data
        // - Send to external logging service
        // - Trigger webhooks
        if ($level === 'critical') {
            $this->sendToSlack($message);
            $this->createPagerDutyIncident($message);
        }
    }

    protected function afterLogEvent(string $type, string $message, string $level): void
    {
        // Custom logic after logging
        // - Update metrics
        // - Trigger automation
    }
}
```

#### 2. Custom Email Notifications

Override email sending behavior:

```php
public function emailEvent(string $message, string $subject): void
{
    // Add custom email templates
    $htmlBody = $this->renderCustomEmailTemplate($message, $subject);

    // Add attachments
    $email = (new Email())
        ->from("{$this->mailCfg['from_name']} <{$this->mailCfg['from_email']}>")
        ->to(...$this->mailCfg['recipients'])
        ->subject($subject)
        ->html($htmlBody)
        ->attach($this->generateReportPdf());

    $this->mailer->send($email);
}
```

#### 3. Event Filtering

Filter which events are logged:

```php
protected function shouldLogEvent(string $type, string $level): bool
{
    // Custom filtering logic
    if ($this->isMaintenanceWindow() && $level !== 'critical') {
        return false; // Suppress non-critical events during maintenance
    }

    if ($this->isDuplicateEvent($type)) {
        return false; // Deduplicate events
    }

    return true;
}
```

---

## View Renderer Extensions

### Custom Renderers

Extend base renderer classes to customize HTML output.

**Base Class:** `src/Views/BaseRenderer.php`

**Available Renderers:**
- `ServerRenderer` - Server card and container rendering
- `DatabaseRenderer` - Database card rendering
- `LibrariesRenderer` - Libraries comparison rendering
- `CronjobRenderer` - Cron job status rendering
- `ModalRenderer` - Modal content rendering
- `LogRenderer` - Log analysis rendering

**Extension Example:**

```php
<?php

namespace Your\Namespace;

use Watcher\Views\ServerRenderer;

class CustomServerRenderer extends ServerRenderer
{
    /**
     * Override server card rendering with custom template
     */
    public function renderServerCard(array $serverData, string $serverKey): string
    {
        // Add custom data processing
        $enrichedData = $this->enrichServerData($serverData);

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
$this->serverRenderer = new CustomServerRenderer($twig, $logger, $tools, $app, $logRenderer);
```

---

## Service Layer Extensions

### EventManager Customization

Extend alarm evaluation and threshold management.

**Location:** `src/Services/Foundation/EventManager.php`

**Use Cases:**
- Custom threshold logic
- Machine learning-based anomaly detection
- Dynamic thresholds based on time/load
- Multi-dimensional threshold evaluation

**Extension Example:**

```php
<?php

namespace Your\Namespace;

use Watcher\Services\Foundation\EventManager;

class SmartEventManager extends EventManager
{
    /**
     * Enhanced threshold evaluation with ML
     */
    public function evaluateMetric(string $metricName, float $value, string $context = ''): array
    {
        // Get baseline from ML model
        $baseline = $this->mlModel->getBaseline($metricName, $context);
        $anomalyScore = $this->mlModel->detectAnomaly($value, $baseline);

        // Dynamic threshold adjustment
        if ($this->isHighTrafficPeriod()) {
            $threshold = $this->adjustThresholdForHighTraffic($metricName);
        } else {
            $threshold = $this->getStaticThreshold($metricName);
        }

        // Evaluate with enhanced logic
        $result = parent::evaluateMetric($metricName, $value, $context);
        $result['anomaly_score'] = $anomalyScore;
        $result['dynamic_threshold'] = $threshold;

        return $result;
    }
}
```

---

### NotificationManager Extensions

Customize notification delivery channels.

**Location:** `src/Services/Foundation/NotificationManager.php`

**Use Cases:**
- Slack/Discord/Teams integration
- SMS notifications
- PagerDuty/Opsgenie integration
- Custom notification routing

**Extension Example:**

```php
<?php

namespace Your\Namespace;

use Watcher\Services\Foundation\NotificationManager;

class MultiChannelNotificationManager extends NotificationManager
{
    public function sendDelivery(array $alarm): void
    {
        // Send to multiple channels based on severity
        switch ($alarm['level']) {
            case 'critical':
                $this->sendToEmail($alarm);
                $this->sendToSMS($alarm);
                $this->sendToPagerDuty($alarm);
                break;

            case 'warning':
                $this->sendToEmail($alarm);
                $this->sendToSlack($alarm);
                break;

            case 'info':
                $this->sendToSlack($alarm);
                break;
        }
    }

    private function sendToSlack(array $alarm): void
    {
        // Slack webhook integration
        $this->slackClient->sendMessage([
            'channel' => $this->getSlackChannel($alarm),
            'text' => $alarm['message'],
            'attachments' => $this->formatSlackAttachment($alarm),
        ]);
    }

    private function sendToPagerDuty(array $alarm): void
    {
        // PagerDuty API integration
        $this->pagerDutyClient->createIncident([
            'title' => $alarm['subject'],
            'body' => $alarm['message'],
            'urgency' => 'high',
            'service_id' => $this->getPagerDutyService($alarm),
        ]);
    }
}
```

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

Add custom automated tasks by extending the Cronjob base class.

**Base Class:** `src/Utils/Cronjob.php`

**Example: Custom Metrics Collection**

```php
<?php
// cron/collect_custom_metrics.php

require __DIR__ . '/../cron/bootstrap.php';

use Watcher\Utils\Cronjob;

class CustomMetricsCollector extends Cronjob
{
    protected string $name = 'Custom Metrics Collector';
    protected int $lockTimeout = 300;

    protected function execute(): void
    {
        $this->log('Starting custom metrics collection');

        foreach ($this->servers as $serverId => $serverConfig) {
            $customData = $this->fetchCustomMetrics($serverId);
            $this->storeCustomMetrics($serverId, $customData);
        }

        $this->log('Custom metrics collection completed');
    }

    private function fetchCustomMetrics(string $serverId): array
    {
        // Custom data collection logic
        return [
            'network_throughput' => $this->measureNetworkThroughput($serverId),
            'security_score' => $this->calculateSecurityScore($serverId),
        ];
    }

    private function storeCustomMetrics(string $serverId, array $metrics): void
    {
        // Store in database or cache
        $this->db->runQuery(
            'INSERT INTO custom_metrics (server_id, metric_name, metric_value, collected_at) VALUES (?, ?, ?, NOW())',
            [$serverId, 'network_throughput', $metrics['network_throughput']]
        );
    }
}

$collector = new CustomMetricsCollector($logger, $db, $monitoringSettings);
$collector->run();
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
