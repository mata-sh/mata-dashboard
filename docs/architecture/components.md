# Architecture Components

This document provides a comprehensive breakdown of all components in the MATA Dashboard application, their responsibilities, and relationships.

## Table of Contents

- [Overview](#overview)
- [Controllers](#controllers)
- [Services](#services)
  - [Collection Services](#collection-services)
  - [Monitoring Services](#monitoring-services)
  - [Foundation Services](#foundation-services)
  - [Cache Services](#cache-services)
  - [View Services](#view-services)
  - [Validation Services](#validation-services)
- [Models](#models)
- [Views](#views)
- [Core Components](#core-components)
- [Configuration](#configuration)
- [HTTP Layer](#http-layer)
- [Utilities](#utilities)
- [Component Relationships](#component-relationships)

## Overview

MATA Dashboard follows a layered architecture with clear separation of concerns:

```
┌─────────────────────────────────────────────────────────┐
│                    HTTP Layer                           │
│                 (Routes, Security)                      │
└────────────────────┬────────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────────┐
│                  Controllers                            │
│         (Request Handling, Response)                    │
└────────────────────┬────────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────────┐
│                   Services                              │
│    (Business Logic, Data Collection, Monitoring)        │
└──────┬──────────────────────────────────────┬───────────┘
       │                                      │
┌──────▼──────┐                      ┌────────▼──────────┐
│   Models    │                      │      Views        │
│  (Data &    │                      │   (Rendering)     │
│   Persistence)                     │                   │
└──────┬──────┘                      └────────┬──────────┘
       │                                      │
┌──────▼──────────────────────────────────────▼──────────┐
│              Core & Utilities                          │
│     (Database, Auth, Logging, Path Resolution)         │
└────────────────────────────────────────────────────────┘
```

## Controllers

Controllers handle HTTP requests and coordinate between services, models, and views. They extend `BaseController` which provides common functionality.

### DashboardController

**Location:** `src/Controllers/DashboardController.php`

**Primary Responsibility:** Main dashboard logic and server monitoring endpoints

**Key Features:**
- Dashboard rendering with server and app status
- Real-time data refreshing via HTMX endpoints
- Server monitoring with threshold evaluation
- App availability checking and performance metrics
- Event and alarm management integration

**Dependencies:**
- `ServerDataCollector` - Fetches data from remote nodes
- `AppMonitor` - Monitors application health
- `ServerMonitor` - Monitors server metrics
- `CacheManager` - Handles API response caching
- `EventManager` - Manages alerts and notifications
- `EventRecorder` - Records events and alarms
- Multiple Renderer classes (Server, Database, Cronjob, etc.)

**Example Usage:**
```php
// Dashboard endpoint
public function index(): Response
{
    // Collect server data
    $serversData = $this->serverDataCollector->collectAllServersData();

    // Build view data
    $viewData = $this->serverViewBuilder->buildDashboardViewData($serversData);

    // Render dashboard
    return new HtmlResponse($this->twig->render('pages/dashboard.html.twig', $viewData));
}
```

### LoginController

**Location:** `src/Controllers/LoginController.php`

**Primary Responsibility:** Authentication handling

**Key Features:**
- User login processing
- Session management
- Authentication state validation
- Redirect handling after authentication

**Dependencies:**
- `Auth` - Core authentication service
- `Twig` - Template rendering

### ModalController

**Location:** `src/Controllers/ModalController.php`

**Primary Responsibility:** Dynamic modal content generation

**Key Features:**
- App detail modals
- Server information modals
- Log viewer modals
- Configuration modals

**Dependencies:**
- `ServerDataCollector` - Fetches current data
- `ModalRenderer` - Renders modal HTML
- `CacheManager` - Caches modal data

### MetricsController

**Location:** `src/Controllers/MetricsController.php`

**Primary Responsibility:** Server metrics data endpoints

**Key Features:**
- Historical metrics data retrieval
- Chart data preparation
- Time-series data formatting
- Metrics aggregation

**Dependencies:**
- `ServerMetricsQuery` - Database queries for metrics
- `MetricsViewBuilder` - Prepares chart data
- `MetricsRequestValidator` - Validates request parameters

### BaseController

**Location:** `src/Controllers/BaseController.php`

**Primary Responsibility:** Shared controller functionality

**Key Features:**
- Twig environment access
- Application configuration access
- Logger integration
- Common helper methods

## Services

Services contain the core business logic and are organized by responsibility.

### Collection Services

#### ServerDataCollector

**Location:** `src/Services/Collect/ServerDataCollector.php`

**Primary Responsibility:** Core monitoring logic and API communication with remote nodes

**Key Features:**
- Fetches server metrics (CPU, RAM, disk, processes)
- Retrieves application data (status, logs, sessions)
- Handles API communication with timeout and error handling
- Manages cache invalidation and refresh
- Aggregates data from multiple servers

**Example Usage:**
```php
// Collect data from all configured servers
$serversData = $serverDataCollector->collectAllServersData();

// Collect data from specific server
$serverData = $serverDataCollector->collectServerData('production-web-server');

// Force refresh (bypass cache)
$serverData = $serverDataCollector->collectServerData('production-web-server', true);
```

**Cache Integration:**
```php
// Uses CacheManager with configured TTL values
$ttl = $this->cacheManager->getTtl('serverData'); // 10 seconds
$cachedData = $this->cacheManager->get($cacheKey, $ttl);
```

#### MockServerDataCollector

**Location:** `src/Services/Collect/MockServerDataCollector.php`

**Primary Responsibility:** Mock implementation for testing

**Key Features:**
- Simulates API responses
- Provides predictable test data
- No external dependencies

### Monitoring Services

#### ServerMonitor

**Location:** `src/Services/Monitor/ServerMonitor.php`

**Primary Responsibility:** Server metrics monitoring and threshold evaluation

**Key Features:**
- Evaluates server metrics against configured thresholds
- Determines severity levels (good, warn, critical, alarm)
- Calculates status indicators
- Processes disk space, CPU, RAM, and file count metrics

**Threshold Configuration:**
```json
{
  "server_data": {
    "total_system_filecount": {
      "good": 500000,
      "warn": 700000,
      "critical": 850000,
      "alarm": 900000
    },
    "disk_space_usage_percent": {
      "good": 70,
      "warn": 80,
      "critical": 85,
      "alarm": 90
    }
  }
}
```

**Example Usage:**
```php
// Evaluate disk space status
$diskStatus = $serverMonitor->evaluateMetric(
    $diskUsagePercent,
    'disk_space_usage_percent',
    'server_data'
);
// Returns: 'good', 'warn', 'critical', or 'alarm'
```

#### AppMonitor

**Location:** `src/Services/Monitor/AppMonitor.php`

**Primary Responsibility:** Application health monitoring

**Key Features:**
- HTTP ping monitoring
- Error log analysis
- Session tracking
- Maintenance mode detection
- Environment detection (dev/staging/production)

**Health Check Process:**
```php
// Check application health
$healthStatus = $appMonitor->checkApplicationHealth($appData);

// Returns array with:
// - 'status': 'healthy', 'degraded', 'down'
// - 'issues': array of detected problems
// - 'metrics': performance metrics
```

#### ServerMetricsHealthMonitor

**Location:** `src/Services/Monitor/ServerMetricsHealthMonitor.php`

**Primary Responsibility:** Database metrics health monitoring

**Key Features:**
- Monitors metrics data freshness
- Detects stale data issues
- Validates data collection health
- Tracks cron job execution

### Foundation Services

#### CacheManager

**Location:** `src/Services/Foundation/CacheManager.php`

**Primary Responsibility:** Centralized cache management

**Key Features:**
- TTL-based cache expiration
- Hierarchical directory structure (host/method/path)
- Cache statistics and monitoring
- Stale cache fallback on API failures
- Force refresh support

**Cache Configuration (`ttl.json`):**
```json
{
  "ping": 360,
  "apps": 60,
  "serverData": 10,
  "databases": 10,
  "appLogs": 30,
  "serverProcesses": 30,
  "readme": 300,
  "errorlog": 60
}
```

**Example Usage:**
```php
// Get cached data
$data = $cacheManager->get($cacheKey, $ttl);

// Set cached data
$cacheManager->set($cacheKey, $data);

// Get TTL for specific cache type
$ttl = $cacheManager->getTtl('apps');

// Reset cache (force refresh)
$cacheManager->resetCacheTtl();
```

**Cache Storage Structure:**
```
temp/api_cache/
├── production-server.com/
│   ├── GET/
│   │   ├── apps.cache
│   │   ├── server-data.cache
│   │   └── databases.cache
│   └── POST/
└── staging-server.com/
    └── GET/
        └── apps.cache
```

#### EventManager

**Location:** `src/Services/Foundation/EventManager.php`

**Primary Responsibility:** Alert generation and processing

**Key Features:**
- Threshold-based alert creation
- Alert severity classification
- Alert deduplication
- Integration with EventRecorder
- Alert aggregation

**Alert Levels:**
- **Info** - Informational events
- **Warning** - Potential issues
- **Critical** - Serious problems requiring attention
- **Alarm** - Emergency situations requiring immediate action

**Example Usage:**
```php
// Create alert for high disk usage
$eventManager->createAlert(
    'disk_space_critical',
    'Disk space usage above 85%',
    'critical',
    ['server' => 'production-web-server', 'usage' => 87.5]
);
```

#### NotificationManager

**Location:** `src/Services/Foundation/NotificationManager.php`

**Primary Responsibility:** Alert notification delivery

**Key Features:**
- Email notification sending (SMTP)
- Notification templating
- Batch notification support
- Error handling and retry logic
- Notification history tracking

**Configuration (`mail.config.env`):**
```env
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=alerts@example.com
MAIL_PASSWORD=secret
MAIL_FROM_ADDRESS=alerts@example.com
MAIL_FROM_NAME="MATA Alerts"
```

**Example Usage:**
```php
// Send alert notification
$notificationManager->sendAlert([
    'subject' => 'Critical: Disk Space Alert',
    'message' => 'Server disk usage: 87.5%',
    'severity' => 'critical',
    'recipients' => ['admin@example.com']
]);
```

#### LogAnalyzer

**Location:** `src/Services/Foundation/LogAnalyzer.php`

**Primary Responsibility:** Application log analysis and processing

**Key Features:**
- PHP error log parsing
- Error pattern detection
- Log severity classification
- Recent error identification
- Log file size monitoring

**Detected Error Types:**
- PHP Fatal Errors
- PHP Warnings
- PHP Notices
- Uncaught Exceptions
- Database Errors

**Example Usage:**
```php
// Analyze application logs
$analysis = $logAnalyzer->analyzeLogs($logContent);

// Returns:
// - 'errorCount': total errors found
// - 'recentErrors': errors from last 24 hours
// - 'severityBreakdown': errors by severity
// - 'topErrors': most frequent errors
```

#### ApiClient

**Location:** `src/Services/Foundation/ApiClient.php`

**Primary Responsibility:** HTTP client for API communications

**Key Features:**
- Guzzle HTTP client wrapper
- Timeout configuration
- Error handling
- Request retry logic
- API key authentication

**Example Usage:**
```php
// Make API request
$response = $apiClient->get($url, [
    'headers' => [
        'X-API-Key' => $apiKey,
        'Accept' => 'application/json'
    ],
    'timeout' => 10
]);
```

#### ConfigDatabaseSync

**Location:** `src/Services/Foundation/ConfigDatabaseSync.php`

**Primary Responsibility:** Synchronizes configuration from files to database

**Key Features:**
- Server configuration sync
- Threshold configuration sync
- TTL configuration sync
- Change detection
- Version tracking

#### IdResolver

**Location:** `src/Services/Foundation/IdResolver.php`

**Primary Responsibility:** Resolves server and application identifiers

**Key Features:**
- Server ID lookup by hostname
- Application ID resolution
- Configuration mapping
- Identifier validation

### Cache Services

#### MetricsCacheService

**Location:** `src/Services/Cache/MetricsCacheService.php`

**Primary Responsibility:** Specialized caching for metrics data

**Key Features:**
- Time-series data caching
- Aggregation caching
- Query result caching
- Cache invalidation strategies

#### ModalCacheService

**Location:** `src/Services/Cache/ModalCacheService.php`

**Primary Responsibility:** Modal content caching

**Key Features:**
- Dynamic modal content caching
- User-specific cache keys
- Short TTL for fresh data

#### CacheTypeResolver

**Location:** `src/Services/Cache/CacheTypeResolver.php`

**Primary Responsibility:** Determines cache type from request context

**Key Features:**
- URL pattern matching
- Cache type classification
- TTL selection

### View Services

#### ServerViewBuilder

**Location:** `src/Services/View/ServerViewBuilder.php`

**Primary Responsibility:** Prepares view data for server components

**Key Features:**
- Dashboard view data preparation
- Server status aggregation
- Icon and color mapping
- Template variable structuring

**Example Usage:**
```php
// Build dashboard view data
$viewData = $serverViewBuilder->buildDashboardViewData($serversData);

// Returns structured data:
// - 'servers': array of server view models
// - 'stats': overall statistics
// - 'alerts': active alerts
// - 'config': UI configuration
```

#### MetricsViewBuilder

**Location:** `src/Services/View/MetricsViewBuilder.php`

**Primary Responsibility:** Prepares chart and metrics data for visualization

**Key Features:**
- Chart.js data formatting
- Time-series data preparation
- Aggregation handling
- Dataset configuration

#### ChartViewBuilder

**Location:** `src/Services/View/ChartViewBuilder.php`

**Primary Responsibility:** Chart configuration and data formatting

**Key Features:**
- Chart type selection
- Dataset configuration
- Color schemes
- Axis configuration

### Validation Services

#### MetricsRequestValidator

**Location:** `src/Services/Validation/MetricsRequestValidator.php`

**Primary Responsibility:** Validates metrics API requests

**Key Features:**
- Parameter validation
- Date range validation
- Server ID validation
- Metric type validation

## Models

Models handle data persistence and business logic related to data.

### EventRecorder

**Location:** `src/Models/EventRecorder.php`

**Primary Responsibility:** Event logging and alarm management

**Key Features:**
- Event recording to database
- Alarm deduplication
- Configurable retention periods
- Severity-based filtering
- Event history retrieval

**Database Tables:**
- `events` - All monitoring events
- `alarms` - Deduplicated notifications

**Event Types:**
- Server connectivity failures
- Resource threshold violations
- Application errors
- Configuration issues
- Security events

**Example Usage:**
```php
// Record event
$eventRecorder->recordEvent(
    'disk_space_critical',
    'critical',
    ['server' => 'production', 'usage' => 87.5]
);

// Check if alarm should be sent (deduplication)
$shouldNotify = $eventRecorder->shouldCreateAlarm(
    'disk_space_critical',
    'production'
);
```

**Retention Configuration:**
```php
// Events older than 30 days are automatically cleaned up
$retentionDays = 30;
```

### ConfigSyncRepository

**Location:** `src/Models/ConfigSyncRepository.php`

**Primary Responsibility:** Configuration persistence

**Key Features:**
- Server configuration storage
- Threshold configuration storage
- Version tracking
- Change history

### ServerMetrics Models

**Location:** `src/Models/ServerMetrics/`

**Primary Responsibility:** Server metrics data models

**Key Components:**
- `ServerMetricsRepository` - Database access
- `ServerMetricsQuery` - Query builder
- `ServerMetricsDto` - Data transfer objects

## Views

View components render HTML for specific UI sections.

### ServerRenderer

**Location:** `src/Views/ServerRenderer.php`

**Primary Responsibility:** Server card and container rendering

**Key Features:**
- Server status cards
- Resource usage indicators
- Alert badges
- Status colors and icons

**Template Variables:**
```php
[
    'server.title' => 'Production Server',
    'server.cpu_usage' => 45.2,
    'server.primary_color' => '#28a745',
    'storage.ram_used' => '8.5 GB',
    'storage.disk_capacity' => '500 GB'
]
```

### DatabaseRenderer

**Location:** `src/Views/DatabaseRenderer.php`

**Primary Responsibility:** Database card and container rendering

**Key Features:**
- Database connection status
- Database size information
- Table statistics
- Query performance metrics

### LibrariesRenderer

**Location:** `src/Views/LibrariesRenderer.php`

**Primary Responsibility:** PHP libraries and dependencies rendering

**Key Features:**
- Composer package listing
- Version information
- Security vulnerability detection

### CronjobRenderer

**Location:** `src/Views/CronjobRenderer.php`

**Primary Responsibility:** Cron job status rendering

**Key Features:**
- Cron execution history
- Success/failure indicators
- Last run timestamps
- Health status

### LogRenderer

**Location:** `src/Views/LogRenderer.php`

**Primary Responsibility:** Log viewer rendering

**Key Features:**
- Log file content display
- Error highlighting
- Log filtering
- Download links

### ModalRenderer

**Location:** `src/Views/ModalRenderer.php`

**Primary Responsibility:** Modal dialog rendering

**Key Features:**
- Dynamic modal content
- Various modal types (info, confirm, form)
- HTMX integration

### SettingsRenderer

**Location:** `src/Views/SettingsRenderer.php`

**Primary Responsibility:** Settings interface rendering

**Key Features:**
- Configuration forms
- Threshold editors
- Server management interface

### MetricsRenderer

**Location:** `src/Views/MetricsRenderer.php`

**Primary Responsibility:** Metrics charts rendering

**Key Features:**
- Chart.js integration
- Real-time updates
- Historical data display

### BaseRenderer

**Location:** `src/Views/BaseRenderer.php`

**Primary Responsibility:** Shared rendering functionality

**Key Features:**
- Common template utilities
- Icon resolution
- Color helpers
- Status mapping

## Core Components

Core components provide fundamental application functionality.

### App

**Location:** `src/Core/App.php`

**Primary Responsibility:** Main application container

**Key Features:**
- Dependency injection container
- Authentication integration
- Rate limiting
- Request lifecycle management

**Example Usage:**
```php
// Initialize application
$app = new App($config);

// Access services
$db = $app->getDatabase();
$auth = $app->getAuth();
```

### Auth

**Location:** `src/Core/Auth.php`

**Primary Responsibility:** Session-based authentication

**Key Features:**
- User login/logout
- Session management
- Password verification (bcrypt)
- Account lockout protection
- Timing attack prevention

**User Storage (`users.json`):**
```json
{
  "admin": {
    "password": "$2y$10$...",
    "email": "admin@example.com",
    "role": "admin"
  }
}
```

**Example Usage:**
```php
// Login user
$success = $auth->login($username, $password);

// Check authentication
if ($auth->isAuthenticated()) {
    // User is logged in
}

// Get current user
$user = $auth->getCurrentUser();

// Logout
$auth->logout();
```

### Database

**Location:** `src/Core/Database.php`

**Primary Responsibility:** MySQL connection wrapper

**Key Features:**
- PDO wrapper
- Prepared statements
- Connection management
- Query helpers

**Example Usage:**
```php
// Execute query
$results = $db->query("SELECT * FROM events WHERE severity = ?", ['critical']);

// Insert data
$db->execute(
    "INSERT INTO events (type, severity, data) VALUES (?, ?, ?)",
    ['disk_space', 'critical', json_encode($data)]
);
```

### DatabaseInterface

**Location:** `src/Core/DatabaseInterface.php`

**Primary Responsibility:** Database abstraction interface

**Key Features:**
- Contract for database implementations
- Enables testing with mock databases
- Consistent API

### NullDatabase

**Location:** `src/Core/NullDatabase.php`

**Primary Responsibility:** Null object pattern for database

**Key Features:**
- No-op database operations
- Used when database unavailable
- Enables graceful degradation

### ExceptionHandler

**Location:** `src/Core/ExceptionHandler.php`

**Primary Responsibility:** Global exception handling

**Key Features:**
- Exception logging
- User-friendly error pages
- Debug mode support
- Error notifications

## Configuration

Configuration components load and manage application settings.

### EnvConfigLoader

**Location:** `src/Config/EnvConfigLoader.php`

**Primary Responsibility:** Environment configuration loading

**Key Features:**
- Loads .env files
- Returns DTOs (Data Transfer Objects)
- Type validation
- Default values

**Configuration Files:**
- `config.env` - Main application configuration
- `mail.config.env` - Email settings
- `mysql.mata.env` - Database configuration (optional)

**Example Usage:**
```php
// Load configuration
$config = EnvConfigLoader::load();

// Access configuration
$dbHost = $config->database->host;
$appDebug = $config->app->debug;
```

### MonitoringSettingsLoader

**Location:** `src/Config/MonitoringSettingsLoader.php`

**Primary Responsibility:** Monitoring settings management

**Key Features:**
- Loads JSON configuration files
- Returns arrays (not DTOs)
- Configuration validation
- Default values

**Configuration Files:**
- `thresholds.json` - Alert thresholds
- `ttl.json` - Cache TTL configuration
- `servers.json` - Server endpoints and API keys
- `data_retention.json` - Metrics retention settings

**Example Usage:**
```php
// Load thresholds
$thresholds = MonitoringSettingsLoader::loadThresholds();

// Load TTL configuration
$ttlConfig = MonitoringSettingsLoader::loadTtlConfig();

// Load server configuration
$servers = MonitoringSettingsLoader::loadServers();
```

### DatabaseSetup

**Location:** `src/Config/DatabaseSetup.php`

**Primary Responsibility:** Database initialization

**Key Features:**
- Table creation
- Schema migrations
- Index management
- Integrity checks

### DatabaseIntegrityChecker

**Location:** `src/Config/DatabaseIntegrityChecker.php`

**Primary Responsibility:** Database health validation

**Key Features:**
- Table existence checks
- Column validation
- Index verification
- Constraint validation

## HTTP Layer

HTTP components handle routing, security, and responses.

### RouterSetup

**Location:** `src/Http/Routing/RouterSetup.php`

**Primary Responsibility:** Router configuration and initialization

**Key Features:**
- Route registration
- Middleware setup
- Controller binding
- Route parameter validation

### Endpoints

**Location:** `src/Http/Routing/Endpoints.php`

**Primary Responsibility:** API endpoint definitions

**Key Features:**
- Centralized route constants
- URL generation helpers
- Endpoint documentation

**Example Endpoints:**
```php
const DASHBOARD = '/';
const LOGIN = '/login';
const LOGOUT = '/logout';
const API_SERVERS = '/api/servers';
const API_REFRESH = '/api/refresh';
const API_METRICS = '/api/metrics';
```

### SecurityHeaders

**Location:** `src/Http/SecurityHeaders.php`

**Primary Responsibility:** HTTP security headers management

**Key Features:**
- Content Security Policy
- X-Frame-Options
- X-Content-Type-Options
- Strict-Transport-Security

**Example Headers:**
```php
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'self'
Strict-Transport-Security: max-age=31536000
```

### Response Classes

**Location:** `src/Http/Responses/`

**Response Types:**
- `HtmlResponse` - HTML content
- `JsonResponse` - JSON data
- `RedirectResponse` - HTTP redirects
- `Response` - Base response class

## Utilities

Utility classes provide common functionality across the application.

### PathResolver

**Location:** `src/Utils/PathResolver.php`

**Primary Responsibility:** Centralized path management

**Key Features:**
- Absolute path resolution
- Directory constants
- Cross-platform compatibility

**Example Paths:**
```php
PathResolver::getConfigPath('servers.json')
PathResolver::getCachePath('api_cache')
PathResolver::getTemplatePath('pages/dashboard.html.twig')
```

### Cronjob

**Location:** `src/Utils/Cronjob.php`

**Primary Responsibility:** Base class for cron scripts

**Key Features:**
- Bootstrap integration
- Logging setup
- Error handling
- Execution tracking

**Example Cron Script:**
```php
class CollectServerMetrics extends Cronjob
{
    public function run(): void
    {
        $this->log('Starting metrics collection');

        // Collection logic here

        $this->log('Metrics collection completed');
    }
}
```

### LogHandler

**Location:** `src/Utils/LogHandler.php`

**Primary Responsibility:** Logging utilities

**Key Features:**
- Monolog integration
- Multiple log handlers
- Context injection
- Log rotation

### Markdown

**Location:** `src/Utils/Markdown.php`

**Primary Responsibility:** Markdown processing utilities

**Key Features:**
- CommonMark parser
- Syntax highlighting
- Link processing
- Safe HTML rendering

### Tools

**Location:** `src/Utils/Tools.php`

**Primary Responsibility:** General utility functions

**Key Features:**
- Data formatting
- String manipulation
- Array operations
- Date/time helpers

**Example Functions:**
```php
// Format bytes
Tools::formatBytes(1024); // "1 KB"

// Format percentage
Tools::formatPercent(87.523); // "87.5%"

// Truncate string
Tools::truncate($longString, 50); // "This is a long string that will be..."
```

### TypeConverter

**Location:** `src/Utils/TypeConverter.php`

**Primary Responsibility:** Type conversion utilities

**Key Features:**
- String to integer conversion
- String to float conversion
- Boolean parsing
- Null handling

### FileStateStore

**Location:** `src/Utils/FileStateStore.php`

**Primary Responsibility:** File-based state persistence

**Key Features:**
- Key-value storage
- JSON file format
- Atomic writes
- Lock handling

## Component Relationships

### Request Flow

```
HTTP Request
    │
    ▼
SecurityHeaders (add headers)
    │
    ▼
Router (match route)
    │
    ▼
Auth Middleware (check authentication)
    │
    ▼
Controller (handle request)
    │
    ├─▶ Services (business logic)
    │   │
    │   ├─▶ ServerDataCollector ─▶ ApiClient ─▶ Remote Node API
    │   │
    │   ├─▶ AppMonitor ─▶ LogAnalyzer
    │   │
    │   ├─▶ ServerMonitor ─▶ Threshold evaluation
    │   │
    │   └─▶ CacheManager ─▶ File System
    │
    ├─▶ Models (data access)
    │   │
    │   ├─▶ EventRecorder ─▶ Database
    │   │
    │   └─▶ ServerMetricsRepository ─▶ Database
    │
    └─▶ Views (rendering)
        │
        ├─▶ ServerRenderer ─▶ Twig Templates
        │
        └─▶ MetricsRenderer ─▶ Twig Templates
    │
    ▼
Response (HTML/JSON)
```

### Data Collection Flow

```
Cron Job (every 5 minutes)
    │
    ▼
ServerDataCollector
    │
    ├─▶ ApiClient.get('/api/server-data')
    │   │
    │   └─▶ Remote Node (returns JSON)
    │
    ├─▶ CacheManager.set() (cache response)
    │
    ├─▶ ServerMonitor.evaluate() (check thresholds)
    │   │
    │   └─▶ EventManager.createAlert() (if threshold exceeded)
    │       │
    │       └─▶ EventRecorder.recordEvent()
    │           │
    │           ├─▶ Database (store event)
    │           │
    │           └─▶ NotificationManager.sendAlert() (if needed)
    │
    └─▶ ServerMetricsRepository.store() (persist metrics)
```

### Authentication Flow

```
Login Request
    │
    ▼
LoginController.login()
    │
    ▼
Auth.login($username, $password)
    │
    ├─▶ Load users.json
    │
    ├─▶ Find user by username
    │
    ├─▶ Verify password (bcrypt)
    │
    ├─▶ Create session
    │
    └─▶ Return success/failure
    │
    ▼
Dashboard (redirect on success)
```

### Cache Flow

```
DashboardController.refresh()
    │
    ▼
ServerDataCollector.collectServerData()
    │
    ▼
CacheManager.get($cacheKey, $ttl)
    │
    ├─▶ Check cache file exists
    │
    ├─▶ Check cache age < TTL
    │
    ├─▶ Return cached data (if valid)
    │
    └─▶ Fetch fresh data (if expired)
        │
        ├─▶ ApiClient.get() (fetch from API)
        │
        ├─▶ CacheManager.set() (store in cache)
        │
        └─▶ Return fresh data
```

### Alert Flow

```
ServerMonitor.evaluate()
    │
    ├─▶ Compare metric with thresholds
    │
    ├─▶ Determine severity level
    │
    └─▶ If >= 'critical':
        │
        ▼
    EventManager.createAlert()
        │
        ▼
    EventRecorder.recordEvent()
        │
        ├─▶ Insert into 'events' table
        │
        └─▶ EventRecorder.shouldCreateAlarm()
            │
            ├─▶ Check last alarm time (deduplication)
            │
            └─▶ If should notify:
                │
                ▼
            NotificationManager.sendAlert()
                │
                ├─▶ Format email template
                │
                ├─▶ Send via SMTP
                │
                └─▶ Insert into 'alarms' table
```

## Dependency Injection

Controllers receive dependencies through constructor injection:

```php
class DashboardController extends BaseController
{
    public function __construct(
        Environment $twig,
        ApplicationConfig $config,
        Logger $logger,
        ServerDataCollector $serverDataCollector,
        AppMonitor $appMonitor,
        ServerMonitor $serverMonitor,
        CacheManager $cacheManager,
        EventManager $eventManager,
        EventRecorder $eventRecorder,
        ServerRenderer $serverRenderer,
        // ... other dependencies
    ) {
        parent::__construct($twig, $config, $logger);
        $this->serverDataCollector = $serverDataCollector;
        $this->appMonitor = $appMonitor;
        // ... assign other dependencies
    }
}
```

This approach provides:
- **Testability** - Easy to inject mock dependencies
- **Flexibility** - Can swap implementations without changing controllers
- **Clarity** - Dependencies are explicit and documented

## Testing

The application includes comprehensive testing support:

### Test Structure

```
src/Tests/
├── Unit/               # Unit tests for individual classes
│   ├── Controllers/    # Controller tests
│   ├── Services/       # Service tests
│   └── Utils/          # Utility tests
├── Integration/        # Integration tests
└── Feature/           # Feature/end-to-end tests
```

### Mock Implementations

Several components have mock implementations for testing:
- `MockServerDataCollector` - Simulates API responses
- `MockAppMonitor` - Provides test monitoring data
- `NullDatabase` - No-op database for tests

## Configuration Management

The application uses multiple configuration layers:

### Environment Configuration (.env files)
- Loaded by `EnvConfigLoader`
- Returns typed DTOs
- Validated on load
- Used for: database, mail, app settings

### JSON Configuration (config/*.json)
- Loaded by `MonitoringSettingsLoader`
- Returns arrays
- Used for: servers, thresholds, TTL, data retention

### Runtime Configuration
- Managed by services
- Can be overridden per-request
- Cached for performance

## Best Practices

### Service Layer
- Services should be stateless where possible
- Use dependency injection for all dependencies
- Keep services focused on single responsibility
- Use interfaces for testability

### Controllers
- Keep controllers thin - delegate to services
- Return Response objects consistently
- Use view builders to prepare template data
- Handle errors gracefully

### Models
- Models should handle data persistence
- Keep business logic in services
- Use repositories for database access
- Return DTOs instead of raw arrays

### Views/Renderers
- Renderers prepare data for templates
- Keep template logic minimal
- Use Twig filters for formatting
- Structure data with clear naming conventions

## Performance Considerations

### Caching Strategy
- API responses cached based on TTL configuration
- Cache warming on startup
- Stale cache fallback on errors
- Cache invalidation on force refresh

### Database Optimization
- Prepared statements for all queries
- Indexes on frequently queried columns
- Event cleanup based on retention policy
- Query result caching

### API Communication
- Connection pooling
- Timeout configuration
- Retry logic with exponential backoff
- Parallel requests where possible

## Security Considerations

### Input Validation
- All user input sanitized
- Type checking enforced
- Whitelist-based validation

### Authentication
- Bcrypt password hashing
- Session-based authentication
- Account lockout on failed attempts
- Timing attack prevention

### API Security
- API key authentication
- Encrypted communication (HTTPS)
- Rate limiting per IP
- Read-only node operations

### File System
- Restrictive permissions
- Input path validation
- No user-controlled file operations

## Monitoring and Logging

### Application Logging
- Monolog integration
- Multiple log levels (debug, info, warning, error)
- Context injection
- Log rotation

### Event Recording
- All monitoring events logged to database
- Configurable retention
- Severity-based filtering
- Event history for auditing

### Performance Monitoring
- Cache hit rates tracked
- API response times logged
- Database query performance
- Memory usage monitoring

---

This comprehensive component documentation should be used alongside the [Technology Choices](./technology-choices.md) and [Overview](./overview.md) documents for a complete understanding of the MATA Dashboard architecture.
