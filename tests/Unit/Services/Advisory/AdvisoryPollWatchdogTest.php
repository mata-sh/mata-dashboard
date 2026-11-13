<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Advisory;

use Mata\Config\Monitoring\Section\AdvisoriesConfig;
use Mata\Services\Advisory\AdvisoryPollWatchdog;
use Mata\Services\Cron\CronJob;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Tests\TestCase;

/**
 * A stopped poller must not look like sources without news.
 *
 * @covers \Mata\Services\Advisory\AdvisoryPollWatchdog
 *
 * @internal
 */
final class AdvisoryPollWatchdogTest extends TestCase
{
    private string $heartbeatDir;

    protected function setUp(): void
    {
        $this->heartbeatDir = sys_get_temp_dir() . '/mata-advisory-watchdog-' . bin2hex(random_bytes(4));
        mkdir($this->heartbeatDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->heartbeatDir . '/*') ?: []);
        rmdir($this->heartbeatDir);
    }

    public function testAStaleHeartbeatRecordsAWarning(): void
    {
        $now = time();
        $this->heartbeat($now - 400 * 60);
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handle')->with($this->callback(
            static fn (EventCommand $event): bool => $event->type === AdvisoryPollWatchdog::EVENT_POLL_STALLED && $event->level === 'warning',
        ));

        $this->assertSame(400, $this->watchdog($events)->check($now));
    }

    public function testARecentHeartbeatOrAPollerThatNeverRanStaysQuiet(): void
    {
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('handle');

        $this->assertNull($this->watchdog($events)->check(time()), 'A fresh install has no heartbeat yet.');
        $this->heartbeat(time() - 60);
        $this->assertNull($this->watchdog($events)->check(time()));
    }

    private function watchdog(EventManager $events): AdvisoryPollWatchdog
    {
        $config = AdvisoriesConfig::fromArray(
            ['stale_after_minutes' => 360, 'sources' => ['feed' => ['type' => 'feed', 'url' => 'https://example.org/rss']]],
            static fn (): ?string => null,
            static fn (): null => null,
        );

        return new AdvisoryPollWatchdog($config, $events, $this->heartbeatDir);
    }

    private function heartbeat(int $mtime): void
    {
        $file = CronJob::heartbeatFile($this->heartbeatDir, AdvisoryPollWatchdog::POLL_JOB);
        file_put_contents($file, "{}\n");
        touch($file, $mtime);
    }
}
