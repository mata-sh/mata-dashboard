<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Exception;
use Mata\Database\DatabaseInterface;
use Mata\Database\NullDatabase;
use Mata\Services\Notification\NotificationManager;
use Mata\Tests\TestCase;
use Mata\Utils\PathResolver;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

/**
 * Test suite for NotificationManager service.
 *
 * @covers \Mata\Services\Notification\NotificationManager
 *
 * @internal
 */
class NotificationManagerTest extends TestCase
{
    private NotificationManager $notificationManager;
    private DatabaseInterface $mockDb;
    private Logger $logger;
    private string $testTempDir;
    private array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createStub(DatabaseInterface::class);
        $this->logger = new Logger('test');
        $this->logger->pushHandler(new NullHandler());
        $this->testTempDir = $this->createTempDirectory();
        $this->tempPaths[] = $this->testTempDir;

        $mockPaths = $this->createStub(PathResolver::class);
        $mockPaths->tempPath = $this->testTempDir;

        $this->notificationManager = new NotificationManager($this->mockDb, $this->logger, $mockPaths);
    }

    private function useMockDatabase(): void
    {
        $this->mockDb = $this->createMock(DatabaseInterface::class);
        $paths = $this->createStub(PathResolver::class);
        $paths->tempPath = $this->testTempDir;
        $this->notificationManager = new NotificationManager($this->mockDb, $this->logger, $paths);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles($this->tempPaths);
        parent::tearDown();
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group initialization
     */
    public function testItInitializesWithDependencies(): void
    {
        $this->assertInstanceOf(NotificationManager::class, $this->notificationManager);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group add-notification
     */
    public function testItAddsNotificationSuccessfully(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->with($this->stringContains('SELECT id FROM notifications'), $this->anything())
            ->willReturn([])
        ;
        $this->mockDb->expects($this->once())
            ->method('runInsert')
            ->willReturnCallback(function ($sql, $params) {
                $this->assertStringContainsString('INSERT INTO notifications', $sql);
                $this->assertSame('warning', $params[1]);
                $this->assertSame('Test Title', $params[2]);
                $this->assertSame('Test Message', $params[3]);

                return 1;
            })
        ;

        $result = $this->notificationManager->addNotification(
            'warning',
            'Test Title',
            'Test Message',
            24,
            true
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group add-notification
     */
    public function testItHandlesAddNotificationFailure(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->with($this->stringContains('SELECT id FROM notifications'), $this->anything())
            ->willReturn([])
        ;
        $this->mockDb->expects($this->once())
            ->method('runInsert')
            ->with($this->stringContains('INSERT INTO notifications'), $this->anything())
            ->willThrowException(new Exception('Database error'))
        ;

        $result = $this->notificationManager->addNotification(
            'error',
            'Test Title',
            'Test Message'
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group add-notification
     */
    public function testItPreventsDuplicateNotifications(): void
    {
        // First call checks for duplicates
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->with(
                $this->stringContains('SELECT id FROM notifications'),
                $this->anything()
            )
            ->willReturn([['id' => 1]]) // Duplicate exists
        ;
        $this->mockDb->expects($this->never())->method('runInsert');

        $result = $this->notificationManager->addNotification(
            'info',
            'Duplicate Title',
            'Duplicate Message'
        );

        // Should return true but not insert (duplicate prevented)
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group add-or-update
     */
    public function testItAddsNotificationWithIdentifier(): void
    {
        // First check if exists
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->with($this->stringContains('SELECT * FROM notifications'), $this->anything())
            ->willReturn([])
        ;
        $this->mockDb->expects($this->once())
            ->method('runInsert')
            ->with($this->stringContains('INSERT INTO notifications'), $this->anything())
            ->willReturn(1)
        ;

        $result = $this->notificationManager->addNotification(
            'info',
            'Config Warning',
            'Missing configuration',
            24,
            true,
            'config.missing_file'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group add-or-update
     */
    public function testItUpdatesExistingNotificationByIdentifier(): void
    {
        // First call: Check if identifier exists
        // Second call: Update the notification
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->with($this->stringContains('SELECT * FROM notifications'), $this->anything())
            ->willReturn([['id' => 1, 'identifier' => 'config.test', 'deactivated_at' => null]])
        ;
        $this->mockDb->expects($this->once())
            ->method('runStatement')
            ->with($this->stringContains('UPDATE notifications'), $this->anything())
            ->willReturn(1)
        ;

        $result = $this->notificationManager->addNotification(
            'warning',
            'Updated Title',
            'Updated Message',
            48,
            true,
            'config.test'
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group remove-notification
     */
    public function testItRemovesNotificationByIdentifier(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runStatement')
            ->with(
                $this->stringContains('UPDATE notifications SET deactivated_at'),
                ['config.resolved']
            )
        ;

        $result = $this->notificationManager->removeNotificationByIdentifier('config.resolved');
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group remove-notification
     */
    public function testItRemovesMultipleNotificationsByIdentifiers(): void
    {
        $identifiers = ['config.issue1', 'config.issue2', 'config.issue3'];

        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runStatement')
            ->with(
                $this->stringContains('WHERE identifier IN (?,?,?)'),
                $identifiers
            )
        ;

        $result = $this->notificationManager->removeNotificationsByIdentifiers($identifiers);
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group remove-notification
     */
    public function testItHandlesEmptyIdentifiersArrayGracefully(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->never())
            ->method('runStatement')
        ;

        $result = $this->notificationManager->removeNotificationsByIdentifiers([]);
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group get-notifications
     */
    public function testItRetrievesActiveNotifications(): void
    {
        $expectedNotifications = [
            [
                'id' => 1,
                'type' => 'warning',
                'title' => 'Test Warning',
                'message' => 'This is a warning',
                'created_at' => '2024-01-01 12:00:00',
                'dismissed_at' => null,
                'show_interval' => 24,
            ],
            [
                'id' => 2,
                'type' => 'error',
                'title' => 'Test Error',
                'message' => 'This is an error',
                'created_at' => '2024-01-01 13:00:00',
                'dismissed_at' => null,
                'show_interval' => 0,
            ],
        ];

        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->willReturn($expectedNotifications)
        ;

        $notifications = $this->notificationManager->getActiveNotifications();

        $this->assertCount(2, $notifications);
        $this->assertEquals('warning', $notifications[0]['type']);
        $this->assertEquals('Test Warning', $notifications[0]['title']);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group get-notifications
     */
    public function testItHandlesDatabaseErrorWhenRetrievingNotifications(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->willThrowException(new Exception('Database error'))
        ;

        $notifications = $this->notificationManager->getActiveNotifications();

        // Should return empty array on error (not throw exception)
        $this->assertIsArray($notifications);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group mark-as-read
     */
    public function testItMarksNotificationAsRead(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runStatement')
            ->with(
                $this->stringContains('UPDATE notifications SET dismissed_at'),
                [123]
            )
        ;

        $result = $this->notificationManager->markAsRead(123);
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group deactivate
     */
    public function testItDeactivatesNotification(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runStatement')
            ->with(
                $this->stringContains('UPDATE notifications SET deactivated_at'),
                [456]
            )
        ;

        $result = $this->notificationManager->deactivateNotification(456);
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group cleanup
     */
    public function testItCleansUpOldNotifications(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runStatement')
            ->with(
                $this->stringContains('DELETE FROM notifications'),
                [30]
            )
        ;

        $result = $this->notificationManager->cleanupOldNotifications(30);
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group cleanup
     */
    public function testItUsesDefaultCleanupDays(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runStatement')
            ->with(
                $this->anything(),
                [30] // Default value
            )
        ;

        $this->notificationManager->cleanupOldNotifications();
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group unread-count
     */
    public function testItRetrievesUnreadCountByType(): void
    {
        $dbResults = [
            ['type' => 'warning', 'count' => 3],
            ['type' => 'error', 'count' => 2],
            ['type' => 'alarm', 'count' => 1],
        ];

        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->willReturn($dbResults)
        ;

        $counts = $this->notificationManager->getUnreadCount();

        $this->assertArrayHasKey('warning', $counts);
        $this->assertArrayHasKey('error', $counts);
        $this->assertArrayHasKey('alarm', $counts);
        $this->assertEquals(3, $counts['warning']);
        $this->assertEquals(2, $counts['error']);
        $this->assertEquals(1, $counts['alarm']);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group unread-count
     */
    public function testItHandlesEmptyUnreadCount(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->willReturn([])
        ;

        $counts = $this->notificationManager->getUnreadCount();

        $this->assertEmpty($counts);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group config-warnings
     */
    public function testItAddsConfigWarning(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->willReturn([]) // getNotificationByIdentifier returns empty
        ;
        $this->mockDb->expects($this->once())
            ->method('runInsert')
            ->willReturn(1)
        ;

        $this->notificationManager->addConfigWarning(
            'config.missing_database',
            'Database configuration file is missing'
        );

        // Assert that the method completes without throwing exceptions
        $this->assertTrue(true);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group config-warnings
     */
    public function testItRetrievesConfigWarningIdentifiers(): void
    {
        $dbResults = [
            ['identifier' => 'config.missing_file'],
            ['identifier' => 'config.invalid_format'],
            ['identifier' => 'config.permission_issue'],
        ];

        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->willReturn($dbResults)
        ;

        $identifiers = $this->notificationManager->getConfigWarningIdentifiers();

        $this->assertCount(3, $identifiers);
        $this->assertContains('config.missing_file', $identifiers);
        $this->assertContains('config.invalid_format', $identifiers);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group config-warnings
     */
    public function testItCleansUpResolvedConfigWarnings(): void
    {
        $currentWarnings = [
            ['identifier' => 'config.issue1', 'message' => 'Issue 1'],
            ['identifier' => 'config.issue2', 'message' => 'Issue 2'],
        ];

        $existingIdentifiers = [
            ['identifier' => 'config.issue1'],
            ['identifier' => 'config.issue2'],
            ['identifier' => 'config.issue3'], // This one is resolved
        ];

        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->willReturn($existingIdentifiers) // getConfigWarningIdentifiers
        ;
        $this->mockDb->expects($this->once())
            ->method('runStatement') // removeNotificationsByIdentifiers
            ->willReturn(1)
        ;

        $result = $this->notificationManager->cleanupResolvedConfigWarnings($currentWarnings);
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group offline-notice
     */
    public function testItShowsOfflineNoticeWhenUsingNullDatabase(): void
    {
        $nullDb = new NullDatabase($this->logger);
        $mockPaths = $this->createStub(PathResolver::class);
        $mockPaths->tempPath = $this->testTempDir;

        $manager = new NotificationManager($nullDb, $this->logger, $mockPaths);

        $notifications = $manager->getActiveNotifications();

        $this->assertNotEmpty($notifications);
        $this->assertEquals('db-connection-unavailable', $notifications[0]['id'] ?? null);
        $this->assertEquals('warning', $notifications[0]['type'] ?? null);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group offline-notice
     */
    public function testItAcknowledgesOfflineNoticeSnooze(): void
    {
        $nullDb = new NullDatabase($this->logger);
        $mockPaths = $this->createStub(PathResolver::class);
        $mockPaths->tempPath = $this->testTempDir;

        $manager = new NotificationManager($nullDb, $this->logger, $mockPaths);

        $result = $manager->acknowledgeOfflineNotice('snooze');
        $this->assertTrue($result);

        // After snoozing, notice should not appear
        $notifications = $manager->getActiveNotifications();
        $this->assertEmpty($notifications);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group offline-notice
     */
    public function testItAcknowledgesOfflineNoticeNever(): void
    {
        $nullDb = new NullDatabase($this->logger);
        $mockPaths = $this->createStub(PathResolver::class);
        $mockPaths->tempPath = $this->testTempDir;

        $manager = new NotificationManager($nullDb, $this->logger, $mockPaths);

        $result = $manager->acknowledgeOfflineNotice('never');
        $this->assertTrue($result);

        // After dismissing permanently, notice should not appear
        $notifications = $manager->getActiveNotifications();
        $this->assertEmpty($notifications);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group offline-notice
     */
    public function testItResetsOfflineNoticeState(): void
    {
        $nullDb = new NullDatabase($this->logger);
        $mockPaths = $this->createStub(PathResolver::class);
        $mockPaths->tempPath = $this->testTempDir;

        $manager = new NotificationManager($nullDb, $this->logger, $mockPaths);

        // First dismiss it
        $manager->acknowledgeOfflineNotice('never');

        // Reset state
        $result = $manager->acknowledgeOfflineNotice('reset');
        $this->assertTrue($result);

        // After reset, notice should appear again
        $notifications = $manager->getActiveNotifications();
        $this->assertNotEmpty($notifications);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group offline-notice
     */
    public function testItHandlesInvalidAcknowledgmentAction(): void
    {
        $nullDb = new NullDatabase($this->logger);
        $mockPaths = $this->createStub(PathResolver::class);
        $mockPaths->tempPath = $this->testTempDir;

        $manager = new NotificationManager($nullDb, $this->logger, $mockPaths);

        $result = $manager->acknowledgeOfflineNotice('invalid_action');
        $this->assertFalse($result);
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group error-handling
     */
    public function testItHandlesDatabaseExceptionsGracefully(): void
    {
        foreach (['runSelect', 'runInsert', 'runStatement'] as $method) {
            $this->mockDb->method($method)
                ->willThrowException(new Exception('Connection lost'))
            ;
        }

        // These should not throw exceptions but return false/empty results
        $this->assertFalse($this->notificationManager->addNotification('info', 'Title', 'Message'));
        $this->assertFalse($this->notificationManager->markAsRead(1));
        $this->assertFalse($this->notificationManager->deactivateNotification(1));
        $this->assertFalse($this->notificationManager->cleanupOldNotifications());
        $this->assertEmpty($this->notificationManager->getUnreadCount());
    }

    /**
     * @test
     *
     * @group notification-manager
     * @group edge-cases
     */
    public function testItHandlesNotificationWithZeroShowInterval(): void
    {
        $this->useMockDatabase();

        $this->mockDb->expects($this->once())
            ->method('runSelect')
            ->with($this->stringContains('SELECT id FROM notifications'), $this->anything())
            ->willReturn([])
        ;
        $this->mockDb->expects($this->once())
            ->method('runInsert')
            ->willReturnCallback(function ($sql, $params) {
                $this->assertStringContainsString('INSERT INTO notifications', $sql);
                $this->assertSame(0, $params[4]);

                return 1;
            })
        ;

        $this->notificationManager->addNotification(
            'info',
            'One-time notification',
            'This will only show once',
            0, // show_interval = 0
            true
        );
    }
}
