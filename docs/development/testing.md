# Testing

This guide covers testing strategies and practices for MATA Dashboard.

## Current Testing Status

MATA Dashboard currently uses **manual testing** rather than automated tests. This document provides guidance for manual testing and outlines a path for future test automation.

## Manual Testing

### Pre-Deployment Testing Checklist

Before deploying changes, verify:

#### Functionality Testing
- [ ] Core features work as expected
- [ ] New features function correctly
- [ ] Bug fixes resolve the issue
- [ ] No regressions in existing features

#### Browser Testing
- [ ] Chrome/Chromium
- [ ] Firefox
- [ ] Safari
- [ ] Edge

#### Server Compatibility
- [ ] Apache 2.4
- [ ] Nginx 1.18+
- [ ] PHP built-in server (dev only)

#### PHP Version Compatibility
- [ ] PHP 8.3
- [ ] PHP 8.4 (if available)

#### Database Compatibility
- [ ] MySQL 8.0
- [ ] MariaDB 10.5+

### Feature Testing

#### Authentication Testing

**Login Flow:**

```bash
1. Navigate to /login
2. Enter valid credentials
3. Verify redirect to dashboard
4. Check session created
5. Verify logout works
6. Test invalid credentials
7. Verify error message shown
```

**Session Management:**

```bash
1. Login successfully
2. Close browser
3. Reopen and verify session persists
4. Wait for session timeout
5. Verify redirect to login
```

#### Dashboard Testing

**Server Monitoring:**

```bash
1. View all servers on dashboard
2. Verify metrics displayed correctly
3. Check color coding matches thresholds
4. Test force refresh button
5. Verify HTMX updates work
6. Check modal content loads
```

**Application Monitoring:**

```bash
1. Click on a server card
2. Verify applications list loads
3. Check application status indicators
4. View error logs modal
5. Verify README modal loads
6. Test session count display
```

#### Alert Testing

**Threshold Violations:**

```bash
1. Lower threshold in config
2. Run cron job manually
3. Verify event created
4. Check email notification sent
5. Verify alarm not duplicated
6. Restore threshold
```

### Security Testing

#### XSS Prevention

Test input sanitization:

```bash
# Test in server name
1. Add server with name: <script>alert('XSS')</script>
2. View dashboard
3. Verify script not executed
4. Check HTML escaped

# Test in application name
1. Create app with malicious name
2. View application list
3. Verify no script execution
```

#### SQL Injection

Test parameter handling:

```bash
# Test server ID
1. Navigate to /server/1' OR '1'='1
2. Verify error or safe handling
3. Check no SQL errors in logs

# Test event filtering
1. Use malicious filter value
2. Verify safe parameter binding
```

#### Authentication Bypass

Test authorization:

```bash
# Test unauthenticated access
1. Logout
2. Navigate to /dashboard
3. Verify redirect to login

# Test session tampering
1. Login
2. Modify session cookie
3. Verify logout/error
```

### Performance Testing

#### Load Time Testing

```bash
# Dashboard load
1. Clear browser cache
2. Navigate to dashboard
3. Measure load time (< 2 seconds ideal)
4. Check number of requests
5. Verify CSS/JS cached

# API Response Time
1. Test /api/refresh endpoint
2. Measure response time (< 1 second ideal)
3. Test with cache
4. Test without cache
```

#### Cache Testing

```bash
# Test cache hit
1. Load server data
2. Note timestamp
3. Reload within TTL
4. Verify cached data used

# Test cache miss
1. Wait for TTL expiration
2. Reload page
3. Verify fresh data fetched
4. Check new timestamp
```

### Error Handling Testing

#### API Failures

```bash
# Simulate node unavailable
1. Stop node server
2. Trigger data collection
3. Verify graceful degradation
4. Check error logged
5. Verify stale cache used if available

# Simulate database failure
1. Stop database
2. Attempt dashboard access
3. Verify error message
4. Check no sensitive data exposed
```

#### Invalid Configuration

```bash
# Test missing config file
1. Rename servers.json
2. Load dashboard
3. Verify error handling
4. Check error message clarity

# Test invalid JSON
1. Add syntax error to servers.json
2. Load dashboard
3. Verify parsing error caught
4. Restore valid JSON
```

## Testing Tools

### Browser Developer Tools

**Chrome DevTools:**

```bash
# Network tab
- Monitor API requests
- Check response times
- Verify caching headers

# Console tab
- Check for JavaScript errors
- Monitor HTMX events
- Verify no warnings

# Application tab
- Inspect session storage
- Check cookies
- View cache storage
```

### Command Line Testing

**cURL for API Testing:**

```bash
# Test server ping
curl -X GET https://server.com/mata-node/api/ping \
  -H "Authorization: Bearer YOUR_API_KEY"

# Test with invalid key
curl -X GET https://server.com/mata-node/api/server-data \
  -H "Authorization: Bearer invalid_key" \
  -v

# Test rate limiting
for i in {1..100}; do
  curl https://server.com/api/refresh
done
```

**Database Testing:**

```bash
# Check data integrity
mysql -u user -p -D mata_dashboard

# Verify events logged
SELECT COUNT(*) FROM events WHERE timestamp > NOW() - INTERVAL 1 HOUR;

# Check metrics collection
SELECT server_id, COUNT(*) FROM server_metrics_raw
GROUP BY server_id;

# Verify aggregation
SELECT * FROM server_metrics_hourly
ORDER BY hour_timestamp DESC LIMIT 10;
```

### Log Analysis

```bash
# Check application logs
tail -f temp/logs/application.log

# Monitor cron execution
tail -f temp/logs/cron-collect.log

# Check web server logs
tail -f /var/log/apache2/error.log
tail -f /var/log/nginx/error.log

# Filter for errors
grep ERROR temp/logs/application.log
grep CRITICAL temp/logs/application.log
```

## Code Quality Testing

### Static Analysis

```bash
# PHPStan - Find bugs
vendor/bin/phpstan analyse

# PHP CodeSniffer - Code style
vendor/bin/phpcs

# PHP Mess Detector - Code quality
vendor/bin/phpmd src/ text ruleset.xml
```

### Security Scanning

```bash
# Check for known vulnerabilities in dependencies
composer audit

# Update dependencies
composer update --dry-run
```

## Regression Testing

### After Updates

When updating dependencies or PHP version:

```bash
1. Run code quality tools
2. Test authentication flow
3. Verify dashboard loads
4. Check data collection works
5. Test alerting
6. Verify email sending
7. Check cron jobs run
8. Review error logs
```

### Before Releases

Complete testing checklist:

```bash
Authentication:
[ ] Login works
[ ] Logout works
[ ] Session management
[ ] Password validation

Dashboard:
[ ] Server list displays
[ ] Metrics update
[ ] Force refresh works
[ ] Modals load correctly

Monitoring:
[ ] Data collection runs
[ ] Metrics stored correctly
[ ] Thresholds evaluated
[ ] Alerts generated

Email:
[ ] SMTP connection works
[ ] Alert emails sent
[ ] Email format correct

Cron Jobs:
[ ] Collection cron runs
[ ] Aggregation cron runs
[ ] Logs written correctly

API:
[ ] Node communication works
[ ] Rate limiting functions
[ ] Error handling works

Security:
[ ] XSS prevention works
[ ] SQL injection prevented
[ ] Authentication required
[ ] CSRF protection active
```

## Future: Automated Testing

### Testing Framework Recommendations

For future automated testing:

**Unit Testing:**
- **PHPUnit**: Industry standard for PHP
- **Mockery**: Mocking dependencies

**Integration Testing:**
- **PHPUnit**: Database integration tests
- **DBUnit**: Database testing extensions

**End-to-End Testing:**
- **Codeception**: Full-stack testing
- **Selenium**: Browser automation

### Example Test Structure

```php
<?php
// tests/Unit/Services/CacheManagerTest.php

use PHPUnit\Framework\TestCase;
use IDS\Services\Foundation\CacheManager;

class CacheManagerTest extends TestCase
{
    private CacheManager $cacheManager;

    protected function setUp(): void
    {
        $this->cacheManager = new CacheManager([
            'serverData' => 10
        ]);
    }

    public function testGetTtlReturnsCorrectValue(): void
    {
        $ttl = $this->cacheManager->getTtl('serverData');
        $this->assertEquals(10, $ttl);
    }

    public function testCacheStoresAndRetrievesData(): void
    {
        $key = 'test_key';
        $data = ['foo' => 'bar'];

        $this->cacheManager->set($key, $data);
        $retrieved = $this->cacheManager->get($key);

        $this->assertEquals($data, $retrieved);
    }
}
```

### Integration Test Example

```php
<?php
// tests/Integration/ServerDataCollectorTest.php

use PHPUnit\Framework\TestCase;
use IDS\Services\Collection\ServerDataCollector;

class ServerDataCollectorTest extends TestCase
{
    public function testCollectServerDataReturnsValidStructure(): void
    {
        $collector = new ServerDataCollector(/* dependencies */);
        $data = $collector->collect('test-server');

        $this->assertArrayHasKey('cpu_usage', $data);
        $this->assertArrayHasKey('memory_used', $data);
        $this->assertArrayHasKey('disk_usage', $data);
    }

    public function testCollectWithInvalidServerThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);

        $collector = new ServerDataCollector(/* dependencies */);
        $collector->collect('non-existent-server');
    }
}
```

### End-to-End Test Example

```php
<?php
// tests/E2E/DashboardTest.php

use Codeception\Test\Unit;

class DashboardTest extends Unit
{
    public function testUserCanLoginAndViewDashboard(): void
    {
        $I = $this->tester;

        $I->amOnPage('/login');
        $I->fillField('username', 'admin');
        $I->fillField('password', 'password');
        $I->click('Login');

        $I->seeCurrentUrlEquals('/dashboard');
        $I->see('Server Monitoring');
    }

    public function testDashboardDisplaysServers(): void
    {
        $I = $this->tester;

        $I->amLoggedIn();
        $I->amOnPage('/dashboard');

        $I->see('Production Server');
        $I->see('CPU:');
        $I->see('RAM:');
        $I->see('Disk:');
    }
}
```

## Testing Documentation

When adding features, document testing:

```markdown
## Testing

### Manual Testing

1. Navigate to feature page
2. Perform action X
3. Verify result Y

### Automated Tests

Run tests:
```bash
vendor/bin/phpunit tests/Feature/MyFeatureTest.php
```

### Edge Cases

- Test with empty data
- Test with maximum values
- Test with invalid input
```

## Continuous Integration (Future)

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: mata_test

    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.3

      - name: Install dependencies
        run: composer install

      - name: Run PHPStan
        run: vendor/bin/phpstan analyse

      - name: Run PHPCS
        run: vendor/bin/phpcs

      - name: Run tests
        run: vendor/bin/phpunit
```

## Best Practices

### Test-Driven Development (Future)

When adding automated tests:

1. **Write test first** (red)
2. **Implement feature** (green)
3. **Refactor code** (refactor)

### Test Coverage

Aim for coverage of:

- **Critical paths**: Authentication, data collection
- **Business logic**: Threshold evaluation, alerting
- **Security**: Input validation, authorization
- **Edge cases**: Empty data, max values, errors

### Test Organization

```
tests/
├── Unit/              # Unit tests
│   ├── Services/
│   ├── Models/
│   └── Utils/
├── Integration/       # Integration tests
│   ├── Database/
│   └── API/
├── E2E/              # End-to-end tests
│   ├── Authentication/
│   └── Dashboard/
└── Fixtures/         # Test data
    └── servers.json
```

## Troubleshooting Tests

### Common Issues

**Database connection:**
```bash
# Verify test database exists
mysql -u root -p -e "CREATE DATABASE mata_test"

# Update test configuration
cp config/config.env config/config.test.env
```

**Cache conflicts:**
```bash
# Clear test cache
rm -rf temp/test_cache/*
```

**Permission errors:**
```bash
# Fix permissions
chmod -R 775 temp/
```

## Next Steps

- [Code Quality](code-quality.md) - Code standards and tools
- [Contributing](contributing.md) - Contribution guidelines
- [Cron Jobs](cron-jobs.md) - Testing cron jobs
