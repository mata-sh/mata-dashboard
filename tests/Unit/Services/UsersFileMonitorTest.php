<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\UsersFileMonitor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Monitor\UsersFileMonitor
 *
 * @internal
 */
final class UsersFileMonitorTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $usersFile;

    private string $snapshotFile;

    protected function setUp(): void
    {
        $prefix = sys_get_temp_dir() . '/mata-users-monitor-' . bin2hex(random_bytes(6));
        $this->usersFile = $prefix . '.json';
        $this->snapshotFile = $prefix . '-snapshot.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->usersFile);
        @unlink($this->snapshotFile);
    }

    public function testSecureFileWithoutLockedAccountsEmitsNoEvents(): void
    {
        $this->writeUsers([
            ['username' => 'alice'],
            ['username' => 'bob', 'locked_until' => self::NOW - 1],
        ], 0o600);

        self::assertSame([], $this->check());
    }

    public function testInsecurePermissionsEmitCriticalEvent(): void
    {
        $this->writeUsers([['username' => 'alice']], 0o640);

        $events = $this->check();

        self::assertCount(1, $events);
        self::assertSame('users_file_insecure', $events[0]->type);
        self::assertSame('critical', $events[0]->level);
        self::assertSame(['mode' => '0640'], $events[0]->context);
    }

    public function testEachActiveLockoutEmitsWarningKeyedByUsername(): void
    {
        $this->writeUsers([
            ['username' => 'alice', 'locked_until' => self::NOW + 600],
            ['username' => 'bob', 'locked_until' => self::NOW],
            ['username' => 'carol', 'locked_until' => self::NOW + 1],
            ['username' => 'dave', 'locked_until' => 'soon'],
        ], 0o600);

        $events = $this->check();

        self::assertSame(['user_account_locked', 'user_account_locked'], array_map(
            static fn (EventCommand $event): string => $event->type,
            $events
        ));
        self::assertSame('warning', $events[0]->level);
        self::assertSame(['username' => 'alice'], $events[0]->context);
        self::assertSame(['username' => 'carol'], $events[1]->context);
    }

    public function testMissingFileEmitsNoEvents(): void
    {
        self::assertSame([], $this->check());
    }

    public function testInvalidJsonStillReportsInsecurePermissions(): void
    {
        file_put_contents($this->usersFile, '{not json');
        chmod($this->usersFile, 0o644);

        $events = $this->check();

        self::assertSame(['users_file_insecure'], array_map(
            static fn (EventCommand $event): string => $event->type,
            $events
        ));
    }

    public function testFirstRunRecordsBaselineWithoutChangeEvents(): void
    {
        $this->writeUsers([['username' => 'alice', 'role' => 'admin']], 0o600);

        self::assertSame([], $this->check());
        self::assertSame('{"alice":"admin"}', file_get_contents($this->snapshotFile));
        self::assertSame(0o600, fileperms($this->snapshotFile) & 0o777);
    }

    public function testAccountChangesSincePreviousRunEmitWarnings(): void
    {
        $this->writeUsers([
            ['username' => 'alice', 'role' => 'admin'],
            ['username' => 'bob', 'role' => 'viewer'],
            ['username' => 'carol', 'role' => 'viewer'],
        ], 0o600);
        $this->check();

        $this->writeUsers([
            ['username' => 'alice', 'role' => 'admin'],
            ['username' => 'bob', 'role' => 'admin'],
            ['username' => 'mallory', 'role' => 'admin'],
        ], 0o600);
        $events = $this->check();

        self::assertSame(['user_account_changed'], array_values(array_unique(array_map(
            static fn (EventCommand $event): string => $event->type,
            $events
        ))));
        self::assertSame([
            'Account mallory was created with role admin',
            'Account carol was deleted',
            'Account bob role changed from viewer to admin',
        ], array_map(static fn (EventCommand $event): string => $event->message, $events));
        self::assertSame('warning', $events[0]->level);
        self::assertSame(
            ['username' => 'bob', 'change' => 'role_changed', 'role' => 'admin', 'previous_role' => 'viewer'],
            $events[2]->context
        );

        self::assertSame([], $this->check());
    }

    public function testNumericUsernameIsNotReportedAsCreatedOnEveryRun(): void
    {
        $this->writeUsers([['username' => '1234', 'role' => 'viewer']], 0o600);

        $this->check();

        self::assertSame([], $this->check());
    }

    public function testCorruptSnapshotRecordsNewBaselineWithoutEvents(): void
    {
        $this->writeUsers([['username' => 'alice', 'role' => 'admin']], 0o600);
        file_put_contents($this->snapshotFile, '{not json');

        self::assertSame([], $this->check());
        self::assertSame('{"alice":"admin"}', file_get_contents($this->snapshotFile));
    }

    public function testUnreadableUsersFileKeepsPreviousSnapshot(): void
    {
        $this->writeUsers([['username' => 'alice', 'role' => 'admin']], 0o600);
        $this->check();
        file_put_contents($this->usersFile, '{not json');

        self::assertSame([], $this->check());
        self::assertSame('{"alice":"admin"}', file_get_contents($this->snapshotFile));
    }

    /** @param list<array<string,mixed>> $users */
    private function writeUsers(array $users, int $mode): void
    {
        file_put_contents($this->usersFile, json_encode($users, JSON_THROW_ON_ERROR));
        chmod($this->usersFile, $mode);
    }

    /** @return list<EventCommand> */
    private function check(): array
    {
        $events = [];
        $eventManager = $this->createStub(EventManager::class);
        $eventManager->method('handle')
            ->willReturnCallback(static function (EventCommand $event) use (&$events): array {
                $events[] = $event;

                return ['eventRecorded' => true, 'eventId' => count($events)];
            });

        (new UsersFileMonitor($this->usersFile, $this->snapshotFile, $eventManager, new NullLogger()))->check(self::NOW);

        return $events;
    }
}
