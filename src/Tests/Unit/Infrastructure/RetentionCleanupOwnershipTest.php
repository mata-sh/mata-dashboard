<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Infrastructure;

use Mata\Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class RetentionCleanupOwnershipTest extends TestCase
{
    public function testNotificationCleanupRunsOnlyFromRetentionCron(): void
    {
        $index = file_get_contents($this->projectRoot() . '/public/index.php');
        $retentionJob = file_get_contents($this->projectRoot() . '/cron/retention_cleanup.php');

        self::assertNotFalse($index);
        self::assertNotFalse($retentionJob);

        foreach (['cleanupOldNotifications', 'cleanupResolvedConfigWarnings'] as $method) {
            self::assertStringNotContainsString($method, $index);
            self::assertStringContainsString($method, $retentionJob);
        }
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
