<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use DateTimeImmutable;
use Mata\Tests\TestCase;
use Mata\Services\Alarm\AlarmQueueFailureThrottle;

/**
 * @covers \Mata\Services\Alarm\AlarmQueueFailureThrottle
 *
 * @internal
 */
final class AlarmQueueFailureThrottleTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = $this->createTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles([$this->tempDir]);
        parent::tearDown();
    }

    public function testTryAcquireRespectsSpacing(): void
    {
        $manager = new AlarmQueueFailureThrottle($this->tempDir);
        $spacingSeconds = 10;

        $this->assertTrue($manager->tryAcquire('Server-Alpha', 'Email', $spacingSeconds));
        $this->assertFalse($manager->tryAcquire('Server-Alpha', 'Email', $spacingSeconds));

        $lockFile = $this->tempDir . '/server-alpha/email.lock';
        $this->assertFileExists($lockFile);

        file_put_contents($lockFile, (string) (time() - ($spacingSeconds + 1)));
        $this->assertTrue($manager->tryAcquire('Server-Alpha', 'Email', $spacingSeconds));
    }

    public function testGetLastTriggeredAtAndRelease(): void
    {
        $manager = new AlarmQueueFailureThrottle($this->tempDir);

        $this->assertNull($manager->getLastTriggeredAt('srv', 'email'));

        $this->assertTrue($manager->tryAcquire('srv', 'email', 5));

        $timestamp = $manager->getLastTriggeredAt('srv', 'email');
        $this->assertInstanceOf(DateTimeImmutable::class, $timestamp);

        $manager->release('srv', 'email');
        $this->assertNull($manager->getLastTriggeredAt('srv', 'email'));
    }
}
