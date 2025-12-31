<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use DateTimeImmutable;
use Watcher\Tests\TestCase;
use Watcher\Utils\ChannelLockManager;

/**
 * @covers \Watcher\Utils\ChannelLockManager
 *
 * @internal
 */
final class ChannelLockManagerTest extends TestCase
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
        $manager = new ChannelLockManager($this->tempDir);
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
        $manager = new ChannelLockManager($this->tempDir);

        $this->assertNull($manager->getLastTriggeredAt('srv', 'email'));

        $this->assertTrue($manager->tryAcquire('srv', 'email', 5));

        $timestamp = $manager->getLastTriggeredAt('srv', 'email');
        $this->assertInstanceOf(DateTimeImmutable::class, $timestamp);

        $manager->release('srv', 'email');
        $this->assertNull($manager->getLastTriggeredAt('srv', 'email'));
    }
}
