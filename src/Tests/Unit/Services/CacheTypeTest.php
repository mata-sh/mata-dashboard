<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Cache\CacheType;
use Mata\Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class CacheTypeTest extends TestCase
{
    /**
     * Backing values are config/ttl.json keys, so a rename silently drops whatever TTL
     * an operator configured under the old name and falls back to 0 (no caching).
     */
    public function testBackingValuesAreStable(): void
    {
        $this->assertSame([
            'none',
            'serverData',
            'serverInfo',
            'serverProcesses',
            'serverMetricsChart',
            'apps',
            'libraries',
            'appLogs',
            'sessions',
            'ping',
            'health',
            'readme',
            'databases',
            'cronjobs',
            'audit',
        ], CacheType::values());
    }

    public function testTtlConfigKeysExcludeTheNoneSentinel(): void
    {
        $this->assertNotContains('none', CacheType::ttlConfigKeys());
        $this->assertContains('apps', CacheType::ttlConfigKeys());
        $this->assertCount(count(CacheType::cases()) - 1, CacheType::ttlConfigKeys());
    }

    public function testTtlConfigKeysIsAList(): void
    {
        $this->assertSame(
            range(0, count(CacheType::cases()) - 2),
            array_keys(CacheType::ttlConfigKeys()),
        );
    }
}
