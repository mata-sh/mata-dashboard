# Code Quality

MATA Dashboard maintains high code quality standards using automated tools and best practices.

## Code Quality Tools

### PHP CodeSniffer (PHPCS)

Enforces PSR-12 coding standards.

**Run code sniffer:**

```bash
# Check all files
vendor/bin/phpcs

# Check specific directory
vendor/bin/phpcs src/Controllers/

# Check specific file
vendor/bin/phpcs src/Controllers/DashboardController.php
```

**Auto-fix issues:**

```bash
# Fix all auto-fixable issues
vendor/bin/phpcbf

# Fix specific directory
vendor/bin/phpcbf src/Controllers/

# Fix specific file
vendor/bin/phpcbf src/Controllers/DashboardController.php
```

**Custom rules** are defined in `phpcs.xml`:

```xml
<?xml version="1.0"?>
<ruleset name="MATA">
    <description>MATA Dashboard coding standard</description>

    <!-- Include PSR-12 standard -->
    <rule ref="PSR12"/>

    <!-- Check these directories -->
    <file>src</file>
    <file>cron</file>

    <!-- Exclude vendor and temp -->
    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>temp/*</exclude-pattern>
</ruleset>
```

### PHPStan

Static analysis tool for finding bugs.

**Run PHPStan:**

```bash
# Analyze all files
vendor/bin/phpstan analyse

# Analyze specific directory
vendor/bin/phpstan analyse src/Controllers/

# Analyze at specific level (0-9)
vendor/bin/phpstan analyse --level=6
```

**Configuration** in `phpstan.neon`:

```neon
parameters:
    level: 5
    paths:
        - src
        - cron
    excludePaths:
        - vendor
        - temp
    checkMissingIterableValueType: false
```

**Common PHPStan issues and fixes:**

```php
// ❌ Bad: Undefined variable
public function process()
{
    if ($condition) {
        $result = 'value';
    }
    return $result; // PHPStan error
}

// ✅ Good: Initialize variable
public function process()
{
    $result = null;
    if ($condition) {
        $result = 'value';
    }
    return $result;
}

// ❌ Bad: Missing type hints
public function calculate($value)
{
    return $value * 2;
}

// ✅ Good: Type hints present
public function calculate(int $value): int
{
    return $value * 2;
}
```

### PHP Mess Detector (PHPMD)

Detects code smells and potential problems.

**Run PHPMD:**

```bash
# Analyze with text output
vendor/bin/phpmd src/ text ruleset.xml

# Analyze with HTML report
vendor/bin/phpmd src/ html ruleset.xml > phpmd-report.html

# Analyze with XML report
vendor/bin/phpmd src/ xml ruleset.xml
```

**Configuration** in `ruleset.xml`:

```xml
<?xml version="1.0"?>
<ruleset name="MATA PHPMD Rules">
    <!-- Code Size Rules -->
    <rule ref="rulesets/codesize.xml">
        <exclude name="TooManyMethods"/>
    </rule>

    <!-- Clean Code Rules -->
    <rule ref="rulesets/cleancode.xml">
        <exclude name="StaticAccess"/>
    </rule>

    <!-- Design Rules -->
    <rule ref="rulesets/design.xml"/>

    <!-- Naming Rules -->
    <rule ref="rulesets/naming.xml">
        <exclude name="ShortVariable"/>
    </rule>

    <!-- Unused Code Rules -->
    <rule ref="rulesets/unusedcode.xml"/>
</ruleset>
```

## Coding Standards

### PSR-12: Extended Coding Style

MATA Dashboard follows PSR-12 coding standards.

#### File Structure

```php
<?php

declare(strict_types=1);

namespace IDS\Controllers;

use IDS\Core\Database;
use IDS\Services\Foundation\CacheManager;

/**
 * Dashboard controller
 */
class DashboardController extends BaseController
{
    public function __construct(
        private Database $database,
        private CacheManager $cacheManager
    ) {
        parent::__construct();
    }

    public function index(): void
    {
        // Implementation
    }
}
```

#### Indentation

- Use **4 spaces** (not tabs)
- Continuation lines indented 4 spaces

```php
// ✅ Good
$result = $this->service->processData(
    $parameter1,
    $parameter2,
    $parameter3
);

// ❌ Bad
$result = $this->service->processData($parameter1,
$parameter2,
$parameter3);
```

#### Line Length

- Keep lines under **120 characters** when possible
- Break long lines logically

```php
// ✅ Good
$data = $this->serverDataCollector->collect(
    $serverId,
    $includeApplications,
    $forceRefresh
);

// ❌ Bad
$data = $this->serverDataCollector->collect($serverId, $includeApplications, $forceRefresh);
```

#### Braces

- Opening brace on same line for control structures
- Opening brace on new line for classes and methods

```php
// ✅ Good
if ($condition) {
    // code
} else {
    // code
}

public function method(): void
{
    // code
}

// ❌ Bad
if ($condition)
{
    // code
}

public function method(): void {
    // code
}
```

### Type Declarations

Always use type declarations for parameters and return types.

```php
// ✅ Good
public function processServer(
    string $serverId,
    bool $forceRefresh = false
): array {
    // Implementation
}

// ❌ Bad
public function processServer($serverId, $forceRefresh = false)
{
    // Implementation
}
```

### Visibility

Always declare visibility for properties and methods.

```php
// ✅ Good
class MyClass
{
    private string $property;

    public function method(): void
    {
        // Implementation
    }
}

// ❌ Bad
class MyClass
{
    $property;

    function method()
    {
        // Implementation
    }
}
```

### Property Promotion (PHP 8.0+)

Use constructor property promotion where appropriate.

```php
// ✅ Good
class Service
{
    public function __construct(
        private Database $database,
        private CacheManager $cache
    ) {
    }
}

// ❌ Verbose (but still valid)
class Service
{
    private Database $database;
    private CacheManager $cache;

    public function __construct(Database $database, CacheManager $cache)
    {
        $this->database = $database;
        $this->cache = $cache;
    }
}
```

## Documentation Standards

### DocBlocks

Use DocBlocks for classes and complex methods.

```php
/**
 * Collects server data from remote nodes
 *
 * This service handles communication with remote monitoring nodes,
 * fetches server metrics, and caches the results.
 */
class ServerDataCollector
{
    /**
     * Collect data from a specific server
     *
     * @param string $serverId Server identifier
     * @param bool $forceRefresh Bypass cache if true
     * @return array Server data including metrics and status
     * @throws \RuntimeException If server is unreachable
     */
    public function collect(string $serverId, bool $forceRefresh = false): array
    {
        // Implementation
    }
}
```

### Inline Comments

Use inline comments to explain complex logic.

```php
// ✅ Good
// Calculate weighted average to smooth out spikes
$average = array_sum($values) / count($values) * 0.8;

// Fallback to stale cache if API fails (graceful degradation)
if ($apiError && $staleCache) {
    return $staleCache;
}

// ❌ Bad (stating the obvious)
// Set variable to 5
$count = 5;

// Loop through array
foreach ($items as $item) {
    // Process item
    $this->process($item);
}
```

## Security Best Practices

### Input Validation

Always validate and sanitize input.

```php
// ✅ Good
public function getServer(string $serverId): array
{
    // Validate input
    if (!preg_match('/^[a-z0-9-]+$/', $serverId)) {
        throw new \InvalidArgumentException('Invalid server ID');
    }

    return $this->fetchServer($serverId);
}

// ❌ Bad
public function getServer(string $serverId): array
{
    return $this->fetchServer($serverId);
}
```

### SQL Injection Prevention

Use prepared statements always.

```php
// ✅ Good
$stmt = $this->database->prepare(
    "SELECT * FROM events WHERE server_id = ?"
);
$stmt->execute([$serverId]);

// ❌ Bad (SQL injection vulnerability)
$query = "SELECT * FROM events WHERE server_id = '$serverId'";
$result = $this->database->query($query);
```

### XSS Prevention

Escape output in templates.

```twig
{# ✅ Good - Auto-escaped #}
{{ server.title }}

{# ✅ Good - Explicit escaping #}
{{ server.description|escape }}

{# ❌ Bad - Unescaped (only use for trusted content) #}
{{ server.html|raw }}
```

### CSRF Protection

Use CSRF tokens for forms.

```php
// Generate token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Validate token
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    throw new \RuntimeException('Invalid CSRF token');
}
```

## Performance Best Practices

### Caching

Use caching to reduce load.

```php
// ✅ Good
public function getServerData(string $serverId): array
{
    $cacheKey = "server_data_{$serverId}";

    if ($cached = $this->cache->get($cacheKey)) {
        return $cached;
    }

    $data = $this->fetchFromApi($serverId);
    $this->cache->set($cacheKey, $data, 300);

    return $data;
}
```

### Database Queries

Optimize database queries.

```php
// ✅ Good - Use indexes
CREATE INDEX idx_server_timestamp ON events(server_id, timestamp);

// ✅ Good - Limit results
SELECT * FROM events
WHERE server_id = ?
ORDER BY timestamp DESC
LIMIT 100

// ❌ Bad - No limit, no indexes
SELECT * FROM events
WHERE server_id = ?
ORDER BY timestamp DESC
```

### Avoid N+1 Queries

Fetch related data efficiently.

```php
// ✅ Good - Single query
$servers = $this->database->query(
    "SELECT s.*, COUNT(e.id) as event_count
     FROM servers s
     LEFT JOIN events e ON s.id = e.server_id
     GROUP BY s.id"
);

// ❌ Bad - N+1 queries
$servers = $this->database->query("SELECT * FROM servers");
foreach ($servers as $server) {
    $server['event_count'] = $this->database->query(
        "SELECT COUNT(*) FROM events WHERE server_id = ?",
        [$server['id']]
    );
}
```

## Error Handling

### Exceptions

Use exceptions for error handling.

```php
// ✅ Good
public function getServer(string $serverId): array
{
    $server = $this->findServer($serverId);

    if (!$server) {
        throw new \RuntimeException("Server not found: {$serverId}");
    }

    return $server;
}

// ❌ Bad
public function getServer(string $serverId): ?array
{
    $server = $this->findServer($serverId);

    if (!$server) {
        return null; // Unclear error handling
    }

    return $server;
}
```

### Logging

Log errors appropriately.

```php
use Monolog\Logger;

try {
    $data = $this->fetchFromApi($serverId);
} catch (\Exception $e) {
    $this->logger->error('Failed to fetch server data', [
        'server_id' => $serverId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    throw $e;
}
```

## Testing Guidelines

While MATA Dashboard doesn't currently have automated tests, follow these guidelines when adding features:

### Manual Testing Checklist

- [ ] Test happy path
- [ ] Test error conditions
- [ ] Test edge cases
- [ ] Test with different PHP versions
- [ ] Test with different web servers
- [ ] Test with different databases
- [ ] Verify no security vulnerabilities

### Writing Testable Code

```php
// ✅ Good - Testable
class ServerMonitor
{
    public function __construct(
        private Database $database,
        private array $thresholds
    ) {
    }

    public function evaluate(array $metrics): bool
    {
        return $metrics['cpu'] < $this->thresholds['cpu'];
    }
}

// ❌ Bad - Hard to test
class ServerMonitor
{
    public function evaluate(array $metrics): bool
    {
        global $thresholds; // Hard-coded dependency
        return $metrics['cpu'] < $thresholds['cpu'];
    }
}
```

## Code Review Checklist

When reviewing code (or self-reviewing):

### Functionality
- [ ] Code solves the intended problem
- [ ] No obvious bugs
- [ ] Edge cases handled
- [ ] Error handling present

### Code Quality
- [ ] Follows PSR-12 standards
- [ ] Passes PHPStan analysis
- [ ] No PHPMD violations
- [ ] Well-documented

### Security
- [ ] Input validated
- [ ] Output escaped
- [ ] No SQL injection risks
- [ ] No XSS vulnerabilities
- [ ] No sensitive data exposed

### Performance
- [ ] No N+1 query problems
- [ ] Appropriate caching
- [ ] Database queries optimized
- [ ] No unnecessary loops

### Maintainability
- [ ] Clear variable names
- [ ] Logical code organization
- [ ] DRY principle followed
- [ ] Single responsibility principle

## Continuous Improvement

### Regular Tasks

**Weekly:**
- Run all code quality tools
- Review and fix warnings
- Update dependencies

**Monthly:**
- Audit security dependencies
- Review performance metrics
- Update documentation

**Quarterly:**
- Major dependency updates
- Code quality retrospective
- Refactoring opportunities

## Tools Summary

| Tool | Purpose | Command |
|------|---------|---------|
| PHPCS | Code style | `vendor/bin/phpcs` |
| PHPCBF | Auto-fix style | `vendor/bin/phpcbf` |
| PHPStan | Static analysis | `vendor/bin/phpstan analyse` |
| PHPMD | Code smells | `vendor/bin/phpmd src/ text ruleset.xml` |

## Next Steps

- [Contributing Guide](contributing.md) - How to contribute
- [Project Structure](project-structure.md) - Code organization
- [Architecture](../architecture/overview.md) - System architecture
