# Technology Choices and Rationale

This document explains the technology decisions made in MATA Dashboard, the reasoning behind each choice, and how they align with the project's goals and constraints.

## Table of Contents

- [Design Philosophy](#design-philosophy)
- [Core Technology Stack](#core-technology-stack)
- [Authentication Strategy](#authentication-strategy)
- [Database Approach](#database-approach)
- [Frontend Architecture](#frontend-architecture)
- [Template System](#template-system)
- [Caching Strategy](#caching-strategy)
- [Dependency Management](#dependency-management)
- [Use Case Fit](#use-case-fit)
- [Scaling Path](#scaling-path)
- [Trade-offs and Limitations](#trade-offs-and-limitations)

## Design Philosophy

**Core Principles:**
- **Simplicity First** - Avoid unnecessary complexity and over-engineering
- **Clarity Over Cleverness** - Explicit code that's easy to understand and maintain
- **Resilience** - System should gracefully handle failures without cascading issues
- **Real-World Focus** - Solve actual monitoring needs without enterprise bloat

**Target Audience:**
- Small to medium teams (1-20 members)
- Infrastructure with 1-20 monitored servers
- Organizations needing quick deployment
- Environments where database reliability is a concern

## Core Technology Stack

### PHP 8.3

**Choice:** Modern PHP with strict types and latest features

**Rationale:**
- **Type Safety** - Strong typing prevents runtime errors and improves code quality
- **Performance** - PHP 8.3 offers significant performance improvements over older versions
- **Modern Features** - Enums, attributes, readonly properties, and constructor property promotion
- **Ecosystem** - Mature package ecosystem with Composer

**Benefits:**
```php
// Type safety with strict types
declare(strict_types=1);

// Constructor property promotion (PHP 8.0+)
public function __construct(
    private readonly Logger $logger,
    private readonly CacheManager $cacheManager,
) {}

// Null-safe operator (PHP 8.0+)
$value = $config?->database?->host ?? 'localhost';

// Named arguments (PHP 8.0+)
$result = $this->analyze(
    logContent: $logs,
    severity: 'critical',
    limit: 100
);
```

**Trade-offs:**
- Requires PHP 8.3+ on hosting environment
- May need to update older PHP code during migration
- Some shared hosting providers may not support latest PHP

### MySQL/MariaDB

**Choice:** Relational database for event logging and metrics storage

**Rationale:**
- **Wide Availability** - Nearly universal hosting support
- **Reliability** - Battle-tested for decades
- **Performance** - Excellent for read-heavy monitoring queries
- **Simplicity** - Straightforward schema without complex relationships

**Schema Design:**

```sql
-- Events table for all monitoring events
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    severity ENUM('info', 'warning', 'critical', 'alarm') NOT NULL,
    server_id VARCHAR(100) NOT NULL,
    message TEXT,
    data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_severity (severity),
    INDEX idx_server (server_id),
    INDEX idx_created (created_at)
);

-- Alarms table for deduplicated notifications
CREATE TABLE alarms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    server_id VARCHAR(100) NOT NULL,
    last_sent TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    count INT DEFAULT 1,
    UNIQUE KEY unique_alarm (event_type, server_id)
);

-- Server metrics for historical data
CREATE TABLE server_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id VARCHAR(100) NOT NULL,
    metric_type VARCHAR(50) NOT NULL,
    value DECIMAL(10,2),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_server_metric (server_id, metric_type),
    INDEX idx_timestamp (timestamp)
);
```

**Benefits:**
- Efficient querying with proper indexes
- JSON support for flexible data storage
- Transaction support for data consistency
- Easy backup and replication

## Authentication Strategy

### JSON File Storage

**Choice:** User credentials stored in `config/users.json` instead of database

**Rationale:**

#### 1. Resilience During Database Failures
```
Database Down Scenario:
┌─────────────────────────────────────┐
│  Database Unavailable               │
│  ✗ Cannot access monitoring data    │
│  ✓ Can still authenticate          │
│  ✓ Dashboard remains accessible    │
│  ✓ Can troubleshoot database       │
└─────────────────────────────────────┘
```

**Real-World Example:**
```
Database fails at 3 AM due to disk space issue.
With JSON auth: Admin can login, see the alert, diagnose the problem.
With DB auth: Admin is locked out, cannot access dashboard, problem escalates.
```

#### 2. Optimal Scale
- **Sweet Spot:** 1-20 users
- **File System Performance:** Reading a small JSON file is faster than a database query
- **No Connection Overhead:** No connection pool management needed

#### 3. Security Implementation

**Password Hashing (Bcrypt):**
```php
// Secure password hashing
$hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

// Verification with timing attack prevention
if (password_verify($inputPassword, $storedHash)) {
    // Valid password
}
```

**Account Lockout Protection:**
```php
class Auth
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 900; // 15 minutes

    private array $loginAttempts = [];

    public function login(string $username, string $password): bool
    {
        // Check if account is locked
        if ($this->isLocked($username)) {
            return false;
        }

        // Verify password
        if (!$this->verifyPassword($username, $password)) {
            $this->recordFailedAttempt($username);
            return false;
        }

        // Clear attempts on success
        $this->clearAttempts($username);
        return true;
    }
}
```

**Timing Attack Prevention:**
```php
// Constant-time comparison
if (hash_equals($storedHash, $inputHash)) {
    // Valid credentials
}

// Always execute same operations regardless of user existence
$user = $this->findUser($username);
if ($user === null) {
    // Create fake hash to maintain timing consistency
    password_verify($password, '$2y$10$fakehashforconsistenttiming');
    return false;
}
```

**User File Format (`config/users.json`):**
```json
{
  "admin": {
    "password": "$2y$10$abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGHIJK",
    "email": "admin@example.com",
    "role": "admin",
    "created_at": "2025-01-15T10:30:00Z"
  },
  "operator": {
    "password": "$2y$10$zyxwvutsrqponmlkjihgfedcba0987654321ZYXWVUTSRQ",
    "email": "ops@example.com",
    "role": "viewer",
    "created_at": "2025-02-20T14:45:00Z"
  }
}
```

**File Permissions:**
```bash
# Restrict access to authentication file
chmod 600 config/users.json
chown www-data:www-data config/users.json
```

#### 4. Migration Path

When scale requires database authentication:

```php
interface AuthStorageInterface
{
    public function findUser(string $username): ?array;
    public function verifyPassword(string $username, string $password): bool;
}

class JsonAuthStorage implements AuthStorageInterface
{
    // Current implementation
}

class DatabaseAuthStorage implements AuthStorageInterface
{
    // Future implementation
    public function findUser(string $username): ?array
    {
        return $this->db->query(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        )[0] ?? null;
    }
}

// Easy swap without changing Auth class
$authStorage = new DatabaseAuthStorage($db);
$auth = new Auth($authStorage);
```

**Trade-offs:**
- ✓ Dashboard stays accessible during DB failures
- ✓ Simple to understand and maintain
- ✓ Fast for small user counts
- ✓ No database dependency for auth
- ✗ Not suitable for 100+ users
- ✗ No granular permissions out-of-box
- ✗ Manual user management

## Database Approach

### Lightweight PDO Wrapper

**Choice:** Custom Database class instead of ORM (Doctrine, Eloquent)

**Rationale:**

#### 1. No ORM Overhead

**Performance Comparison:**
```php
// ORM Approach (Eloquent)
$events = Event::where('severity', 'critical')
    ->where('created_at', '>', now()->subDays(7))
    ->with('server')
    ->orderBy('created_at', 'desc')
    ->limit(100)
    ->get();

// Generated SQL + multiple queries for eager loading
// Overhead: Model instantiation, relationship loading, magic methods

// Custom PDO Wrapper
$events = $db->query(
    "SELECT * FROM events
     WHERE severity = ?
     AND created_at > ?
     ORDER BY created_at DESC
     LIMIT 100",
    ['critical', $date]
);

// Direct SQL execution, minimal overhead
```

**Benefits:**
- No hidden queries or N+1 problems
- Predictable performance
- Full control over SQL optimization
- Smaller memory footprint

#### 2. Explicit is Better Than Implicit

**No Magic Methods:**
```php
// ORM Magic (unclear what actually happens)
$server->metrics()->where('type', 'cpu')->latest()->first();

// Custom Wrapper (explicit query)
$db->query(
    "SELECT * FROM server_metrics
     WHERE server_id = ? AND type = ?
     ORDER BY timestamp DESC
     LIMIT 1",
    [$serverId, 'cpu']
);
```

**Benefits:**
- Easy to debug (SQL is visible)
- No learning curve for SQL developers
- Performance issues are obvious
- Works with any database schema

#### 3. Read-Heavy Optimization

**Monitoring Query Pattern:**
```php
class Database
{
    // Optimized for read operations
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Single write method with transaction support
    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
}
```

**Usage Example:**
```php
// Most monitoring queries are reads
$metrics = $db->query(
    "SELECT * FROM server_metrics
     WHERE server_id = ?
     AND timestamp > ?",
    [$serverId, $startTime]
);

// Occasional writes for events
$db->execute(
    "INSERT INTO events (type, severity, data) VALUES (?, ?, ?)",
    ['disk_space', 'critical', json_encode($data)]
);
```

#### 4. Prepared Statements by Default

**Security Built-In:**
```php
class Database
{
    public function query(string $sql, array $params = []): array
    {
        // ALWAYS uses prepared statements
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Impossible to forget SQL injection protection
$results = $db->query(
    "SELECT * FROM events WHERE server_id = ?",
    [$_GET['server']] // Automatically escaped
);
```

**Trade-offs:**
- ✓ No ORM complexity or magic
- ✓ Predictable performance
- ✓ Easy to optimize queries
- ✓ Minimal dependencies
- ✗ No model relationships
- ✗ Manual query writing
- ✗ Less convenient for complex joins

## Frontend Architecture

### HTMX + Bulma CSS

**Choice:** HTMX for dynamic updates, Bulma for styling

**Rationale:**

#### 1. No JavaScript Framework Required

**Traditional SPA Approach (React/Vue):**
```
Problems:
- 100+ KB JavaScript bundle
- Complex state management
- Build process required
- Client-side routing
- API serialization overhead
```

**HTMX Approach:**
```html
<!-- Dynamic refresh without JavaScript -->
<button
    hx-get="/api/servers/refresh"
    hx-target="#server-list"
    hx-trigger="click"
    hx-indicator="#loading">
    Refresh
</button>

<!-- Server renders HTML, HTMX swaps it in -->
<div id="server-list">
    <!-- Updated content -->
</div>
```

**Benefits:**
- **Zero Build Step** - No webpack, rollup, or vite
- **Tiny Footprint** - HTMX is 14 KB minified
- **Progressive Enhancement** - Works without JS
- **Simple Mental Model** - HTML attributes control behavior

#### 2. Real-World Example

**Dashboard Refresh Implementation:**

```html
<!-- Dashboard page -->
<div class="dashboard">
    <!-- Auto-refresh every 30 seconds -->
    <div
        hx-get="/api/dashboard/refresh"
        hx-trigger="every 30s"
        hx-swap="outerHTML">

        <!-- Server cards -->
        {% for server in servers %}
            <div class="server-card">
                <h3>{{ server.title }}</h3>
                <p>CPU: {{ server.cpu_usage }}%</p>
                <p>RAM: {{ server.ram_usage }}%</p>
            </div>
        {% endfor %}
    </div>
</div>
```

**Controller:**
```php
class DashboardController
{
    public function refresh(): Response
    {
        // Collect fresh data
        $serversData = $this->serverDataCollector->collectAllServersData();

        // Build view data
        $viewData = $this->serverViewBuilder->buildDashboardViewData($serversData);

        // Return HTML fragment
        return new HtmlResponse(
            $this->twig->render('partials/dashboard-content.html.twig', $viewData)
        );
    }
}
```

**Result:**
- Server renders HTML
- HTMX requests update via AJAX
- New HTML swaps in smoothly
- No JSON parsing or client-side templates
- No state synchronization

#### 3. Bulma CSS Framework

**Choice:** Pre-compiled Bulma CSS (committed to repo)

**Rationale:**
- **No Runtime Dependencies** - CSS is pre-built, just include it
- **Responsive by Default** - Mobile-first design
- **Modern and Clean** - Professional look without custom CSS
- **Flexbox-Based** - Easy layouts without floats or hacks

**Custom Build Process:**
```bash
# Build custom Bulma with only needed components
npm run build-bulma

# Optimize and purge unused CSS
npx postcss public/assets/css/bulma.css -o public/assets/css/bulma.purged.min.css
```

**Result:**
- ~30 KB CSS (purged and minified)
- Committed to repo (no build needed in production)
- Customizable via SCSS variables

**Trade-offs:**
- ✓ No JavaScript framework complexity
- ✓ Tiny footprint (14 KB HTMX + 30 KB CSS)
- ✓ Progressive enhancement
- ✓ Server renders everything
- ✗ Not suitable for highly interactive UIs
- ✗ Full page loads for major navigation
- ✗ Limited offline capabilities

## Template System

### Twig Templating Engine

**Choice:** Twig instead of native PHP templates

**Rationale:**

#### 1. Automatic XSS Protection

**Problem with PHP Templates:**
```php
<!-- Vulnerable to XSS -->
<h1><?php echo $title; ?></h1>
<p><?php echo $userInput; ?></p>

<!-- Must manually escape -->
<h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
```

**Twig Solution:**
```twig
{# Automatically escaped #}
<h1>{{ title }}</h1>
<p>{{ userInput }}</p>

{# Explicitly mark safe HTML #}
<div>{{ content | raw }}</div>
```

**Benefits:**
- Secure by default
- Cannot forget to escape
- Explicit opt-in for raw HTML
- Protects against XSS attacks

#### 2. Template Inheritance

**Layout System:**
```twig
{# layouts/main.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}MATA Dashboard{% endblock %}</title>
    <link rel="stylesheet" href="/assets/css/bulma.min.css">
</head>
<body>
    <nav>{% block navigation %}{% endblock %}</nav>

    <main>{% block content %}{% endblock %}</main>

    <footer>{% block footer %}{% endblock %}</footer>
</body>
</html>

{# pages/dashboard.html.twig #}
{% extends "layouts/main.html.twig" %}

{% block title %}Dashboard - MATA{% endblock %}

{% block content %}
    <div class="dashboard">
        {% for server in servers %}
            {% include "partials/server-card.html.twig" %}
        {% endfor %}
    </div>
{% endblock %}
```

**Benefits:**
- DRY principle (Don't Repeat Yourself)
- Consistent layouts
- Easy to override sections
- Reusable components

#### 3. Readable Syntax

**Comparison:**

```php
<!-- PHP Template -->
<?php if (!empty($servers)): ?>
    <?php foreach ($servers as $server): ?>
        <div class="card">
            <h3><?php echo htmlspecialchars($server['title']); ?></h3>
            <?php if ($server['status'] === 'online'): ?>
                <span class="badge success">Online</span>
            <?php else: ?>
                <span class="badge danger">Offline</span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <p>No servers configured.</p>
<?php endif; ?>
```

```twig
{# Twig Template #}
{% if servers is not empty %}
    {% for server in servers %}
        <div class="card">
            <h3>{{ server.title }}</h3>
            {% if server.status == 'online' %}
                <span class="badge success">Online</span>
            {% else %}
                <span class="badge danger">Offline</span>
            {% endif %}
        </div>
    {% endfor %}
{% else %}
    <p>No servers configured.</p>
{% endif %}
```

**Benefits:**
- More readable and maintainable
- Cleaner syntax without PHP tags
- Built-in filters and functions
- Better IDE support

#### 4. Template Caching

**Performance Optimization:**
```php
// Twig configuration
$loader = new FilesystemLoader('/path/to/templates');
$twig = new Environment($loader, [
    'cache' => '/path/to/cache',
    'auto_reload' => true, // Recompile if template changes
]);
```

**How It Works:**
1. First request: Twig compiles template to PHP
2. Subsequent requests: Use compiled template (no parsing)
3. On change: Auto-recompile
4. Result: Fast template rendering

**Trade-offs:**
- ✓ Automatic XSS protection
- ✓ Template inheritance
- ✓ Readable syntax
- ✓ Built-in caching
- ✓ Extensive filter library
- ✗ Small learning curve
- ✗ Dependency on Twig library
- ✗ Slightly slower than raw PHP (first render)

## Caching Strategy

### File-Based Caching

**Choice:** File system cache instead of Redis/Memcached

**Rationale:**

#### 1. Zero Infrastructure

**Comparison:**

| Approach | Requirements | Complexity |
|----------|--------------|------------|
| **File Cache** | File system only | Low |
| **Redis** | Redis server, PHP extension | Medium |
| **Memcached** | Memcached server, PHP extension | Medium |
| **APCu** | PHP extension, per-process | Low |

**File Cache Advantages:**
- ✓ No additional services to manage
- ✓ Works on any hosting environment
- ✓ Easy to inspect and debug
- ✓ Survives PHP restarts
- ✓ Simple backup (just copy files)

#### 2. Performance at Scale

**Capacity Analysis:**

```
Use Case: 10 servers × 5 endpoints = 50 cache keys
File Size: ~10 KB per cache entry
Total Storage: 500 KB

File System Performance:
- Read: <1ms on SSD
- Write: <2ms on SSD

Concurrent Users: <50
Requests/minute: <500

Conclusion: File cache is sufficient
```

**When to Upgrade:**

```
Threshold for Redis:
- 100+ concurrent users
- 1000+ requests/minute
- Sub-second refresh requirements
- Multi-server deployment
```

#### 3. Implementation

**Cache Manager:**
```php
class CacheManager
{
    private string $cacheDir = '/path/to/cache';
    private array $ttlConfig;

    public function get(string $key, int $ttl): ?array
    {
        $cacheFile = $this->getCacheFilePath($key);

        // Check if file exists
        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if cache is fresh
        $age = time() - filemtime($cacheFile);
        if ($age > $ttl) {
            return null; // Expired
        }

        // Return cached data
        return json_decode(file_get_contents($cacheFile), true);
    }

    public function set(string $key, array $data): void
    {
        $cacheFile = $this->getCacheFilePath($key);

        // Ensure directory exists
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Write atomically
        $tempFile = $cacheFile . '.tmp';
        file_put_contents($tempFile, json_encode($data));
        rename($tempFile, $cacheFile);
    }

    private function getCacheFilePath(string $key): string
    {
        // Hierarchical structure: host/method/path
        return $this->cacheDir . '/' . $key . '.cache';
    }
}
```

**Cache Directory Structure:**
```
temp/api-cache/
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

#### 4. TTL Configuration

**Configurable Cache Timeouts (`ttl.json`):**
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

**Usage:**
```php
// Get TTL for specific cache type
$ttl = $this->cacheManager->getTtl('apps'); // 60 seconds

// Cache API response
$cacheKey = "{$host}/GET/apps";
$data = $this->cacheManager->get($cacheKey, $ttl);

if ($data === null) {
    // Fetch from API
    $data = $this->apiClient->get($url);

    // Store in cache
    $this->cacheManager->set($cacheKey, $data);
}
```

#### 5. Graceful Degradation

**Stale Cache Fallback:**
```php
public function collectServerData(string $serverId, bool $forceRefresh = false): array
{
    $cacheKey = "server-data/{$serverId}";
    $ttl = $this->cacheManager->getTtl('serverData');

    // Try to get fresh cache
    if (!$forceRefresh) {
        $cached = $this->cacheManager->get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
    }

    // Fetch fresh data
    try {
        $data = $this->apiClient->get($apiUrl);
        $this->cacheManager->set($cacheKey, $data);
        return $data;
    } catch (Exception $e) {
        // On API failure, use stale cache if available
        $staleCache = $this->cacheManager->getStale($cacheKey);
        if ($staleCache !== null) {
            $this->logger->warning("Using stale cache for {$serverId}");
            return $staleCache;
        }

        throw $e;
    }
}
```

**Benefits:**
- Dashboard stays functional during API outages
- Reduces load on monitored servers
- Graceful degradation instead of hard failures

**Trade-offs:**
- ✓ No infrastructure required
- ✓ Handles < 50 concurrent users
- ✓ Easy to debug
- ✓ Survives restarts
- ✗ Not suitable for multi-server deployments
- ✗ No cache invalidation across servers
- ✗ Slower than in-memory caches

## Dependency Management

### Composer with Dev Dependencies

**Choice:** Modern Composer with dev dependencies split

**Configuration (`composer.json`):**
```json
{
  "require": {
    "symfony/mailer": "^7.2",
    "guzzlehttp/guzzle": "8.0.x-dev",
    "monolog/monolog": "^3.0@dev",
    "bramus/router": "dev-master",
    "league/commonmark": "^2.8@dev",
    "spatie/commonmark-highlighter": "dev-main",
    "vlucas/phpdotenv": "^5.6@dev",
    "twig/twig": "^3.0"
  },
  "require-dev": {
    "squizlabs/php_codesniffer": "^4.0",
    "phpmd/phpmd": "^2.15",
    "phpstan/phpstan": "^2.1",
    "phpunit/phpunit": "^12.4@dev"
  }
}
```

**Benefits:**
- Production installs: `composer install --no-dev` (smaller footprint)
- Development installs: `composer install` (includes testing tools)
- Automatic autoloading via PSR-4

## Use Case Fit

### Ideal Scenarios

**✅ Perfect For:**

1. **Small to Medium Infrastructure**
   - 1-20 monitored servers
   - 1-20 team members
   - Internal/private monitoring

2. **Quick Deployment Needs**
   - Minimal infrastructure
   - Standard PHP hosting
   - No Redis/RabbitMQ required

3. **Database Reliability Concerns**
   - JSON auth stays accessible during DB outages
   - Critical for troubleshooting

4. **Developer-Friendly**
   - Simple codebase to understand
   - Easy to extend and customize
   - Clear separation of concerns

5. **Security-Focused Teams**
   - Read-only node operations
   - API key authentication
   - Rate limiting built-in

### Non-Ideal Scenarios

**❌ Not Recommended For:**

1. **Large Scale Deployments**
   - 100+ monitored servers
   - 100+ concurrent users
   - High-frequency updates (<30 seconds)

2. **Public-Facing Dashboards**
   - No multi-tenancy
   - Limited permission system
   - Session-based auth only

3. **Complex Permission Requirements**
   - No role-based access control (RBAC)
   - No granular permissions
   - Basic authentication only

4. **Real-Time Requirements**
   - Sub-minute monitoring intervals
   - Live streaming metrics
   - Instant alerting (<5 seconds)

5. **Multi-Region Deployments**
   - File-based cache doesn't sync
   - No distributed locking
   - Single-server assumption

## Scaling Path

MATA Dashboard provides a clear scaling path as your needs grow:

### Stage 1: Zero Infrastructure (Current)

**Configuration:**
- JSON authentication
- File-based caching
- Single database server
- PHP-FPM with Apache/Nginx

**Capacity:**
- 1-20 servers monitored
- 1-20 users
- <50 concurrent requests

**Advantages:**
- Minimal setup
- Easy to maintain
- Low cost

### Stage 2: Database Authentication

**When to Upgrade:**
- 20+ users needed
- Granular permissions required
- Audit trail needed for user actions

**Migration:**
```php
// 1. Create users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

// 2. Migrate users from JSON
$users = json_decode(file_get_contents('config/users.json'), true);
foreach ($users as $username => $userData) {
    $db->execute(
        "INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)",
        [$username, $userData['password'], $userData['email'], $userData['role']]
    );
}

// 3. Swap authentication implementation
$authStorage = new DatabaseAuthStorage($db);
$auth = new Auth($authStorage);
```

### Stage 3: In-Memory Caching

**When to Upgrade:**
- 50+ concurrent users
- 1000+ requests per minute
- Multi-server deployment needed

**Implementation:**
```php
// 1. Install Redis
sudo apt-get install redis-server
sudo systemctl start redis

// 2. Install PHP extension
sudo apt-get install php-redis

// 3. Update CacheManager
class RedisCacheManager implements CacheManagerInterface
{
    private Redis $redis;

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
    }

    public function get(string $key, int $ttl): ?array
    {
        $data = $this->redis->get($key);
        return $data ? json_decode($data, true) : null;
    }

    public function set(string $key, array $data, int $ttl = 60): void
    {
        $this->redis->setex($key, $ttl, json_encode($data));
    }
}
```

### Stage 4: Load Balancing

**When to Upgrade:**
- 100+ concurrent users
- 50+ monitored servers
- High availability requirements

**Architecture:**
```
                    ┌─────────────────┐
                    │  Load Balancer  │
                    │   (Nginx/HAProxy) │
                    └────────┬────────┘
                             │
                ┌────────────┼────────────┐
                │            │            │
         ┌──────▼─────┐ ┌───▼──────┐ ┌──▼───────┐
         │  MATA #1  │ │ MATA #2 │ │ MATA #3 │
         │ (PHP-FPM)  │ │(PHP-FPM) │ │(PHP-FPM) │
         └──────┬─────┘ └───┬──────┘ └──┬───────┘
                │            │           │
                └────────────┼───────────┘
                             │
                    ┌────────▼────────┐
                    │     Redis       │
                    │  (Shared Cache) │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │     MySQL       │
                    │ (Primary/Replica)│
                    └─────────────────┘
```

### Stage 5: Enterprise Deployment

**When to Upgrade:**
- 1000+ concurrent users
- 100+ monitored servers
- Multi-region deployment
- SLA requirements

**Considerations:**
- Consider commercial monitoring solutions (Datadog, New Relic)
- MATA may not be cost-effective at this scale
- Rewrite with microservices architecture might be warranted

## Trade-offs and Limitations

### Documented Limitations

#### 1. Concurrent User Capacity

**Limit:** <50 concurrent users

**Reason:**
- File-based caching
- Single-server assumption
- PHP-FPM connection pool

**Impact:**
- 50+ users may experience slowdowns
- Cache contention possible

**Solution:**
- Upgrade to Redis (Stage 3)
- Add load balancer (Stage 4)

#### 2. Monitoring Interval

**Limit:** Minimum 30-second refresh

**Reason:**
- HTTP polling model
- Server load considerations
- API rate limiting

**Impact:**
- Not suitable for sub-minute requirements
- 5-minute cron intervals recommended

**Solution:**
- WebSocket implementation for real-time
- Dedicated monitoring agent (not web-based)

#### 3. Permission System

**Limit:** Basic authentication, no RBAC

**Reason:**
- Simplicity focus
- JSON auth limitations
- Small team assumption

**Impact:**
- All authenticated users have full access
- No view-only roles
- No per-server permissions

**Solution:**
- Migrate to database auth (Stage 2)
- Implement role system

#### 4. Multi-Tenancy

**Limit:** Single organization only

**Reason:**
- Configuration file approach
- No tenant isolation
- Session-based auth

**Impact:**
- Cannot host multiple organizations
- Not suitable for SaaS

**Solution:**
- Complete rewrite required
- Consider commercial alternatives

### Accepted Trade-offs

**We chose simplicity over:**
- ❌ Complex ORM magic → ✅ Explicit SQL queries
- ❌ SPA complexity → ✅ HTMX simplicity
- ❌ Database auth → ✅ JSON file resilience
- ❌ Redis/Memcached → ✅ File cache simplicity
- ❌ Microservices → ✅ Monolithic clarity

**We chose resilience over:**
- ❌ Maximum performance → ✅ Graceful degradation
- ❌ Real-time updates → ✅ Reliable polling
- ❌ Cutting-edge tech → ✅ Battle-tested tools

## Conclusion

MATA Dashboard makes deliberate technology choices that prioritize:

1. **Simplicity** - Easy to understand, maintain, and extend
2. **Resilience** - Graceful degradation during failures
3. **Practicality** - Solves real-world monitoring needs
4. **Scalability** - Clear path from 1 server to enterprise

The technology stack is carefully chosen to:
- Minimize infrastructure requirements
- Reduce operational complexity
- Enable quick deployment
- Provide upgrade paths when needed

**Remember:** These are tools to solve a problem, not the problem itself. The goal is reliable server monitoring with minimal complexity, and these technology choices support that goal effectively for the target use case.

---

For implementation details of specific components, see [Components](./components.md).
For system architecture overview, see [Overview](./overview.md).
