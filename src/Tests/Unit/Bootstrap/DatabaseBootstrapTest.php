<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Bootstrap;

use Exception;
use Mata\Bootstrap\DatabaseBootstrap;
use Mata\Config\DatabaseIntegrityChecker;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Core\DatabaseInterface;
use Mata\Core\NullDatabase;
use Mata\Tests\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @internal
 *
 * @covers \Mata\Bootstrap\DatabaseBootstrap
 */
final class DatabaseBootstrapTest extends TestCase
{
    private DatabaseConfig $config;

    protected function setUp(): void
    {
        $this->config = new DatabaseConfig('localhost', 'user', 'password', 'database', '3306');
    }

    public function testHappyPathReturnsDatabase(): void
    {
        $database = $this->createStub(DatabaseInterface::class);
        $checker = $this->createMock(DatabaseIntegrityChecker::class);
        $checker->expects(self::once())->method('assertReady');

        $bootstrap = new DatabaseBootstrap(
            $this->config,
            new NullLogger(),
            static fn (DatabaseConfig $config): DatabaseInterface => $database,
            static fn (DatabaseInterface $db): DatabaseIntegrityChecker => $checker,
        );

        self::assertSame($database, $bootstrap->initialize());
    }

    public function testIntegrityCheckFailureReturnsNullDatabase(): void
    {
        $probeException = new RuntimeException('probe failed');
        $database = $this->createStub(DatabaseInterface::class);
        $checker = $this->createMock(DatabaseIntegrityChecker::class);
        $checker->expects(self::once())->method('assertReady')->willThrowException($probeException);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Database quick probe failed; falling back to NullDatabase. probe failed');
        $logger->expects(self::once())
            ->method('debug')
            ->with('Database quick probe failure details', ['exception' => $probeException]);

        $bootstrap = new DatabaseBootstrap(
            $this->config,
            $logger,
            static fn (DatabaseConfig $config): DatabaseInterface => $database,
            static fn (DatabaseInterface $db): DatabaseIntegrityChecker => $checker,
        );

        self::assertInstanceOf(NullDatabase::class, $bootstrap->initialize());
    }

    public function testDatabaseConstructionFailureReturnsNullDatabase(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Failed to initialize DB Connection: connection failed');
        $logger->expects(self::never())->method('debug');

        $bootstrap = new DatabaseBootstrap(
            $this->config,
            $logger,
            static fn (DatabaseConfig $config): DatabaseInterface => throw new Exception('connection failed'),
            static fn (DatabaseInterface $db): DatabaseIntegrityChecker => throw new RuntimeException('not called'),
        );

        self::assertInstanceOf(NullDatabase::class, $bootstrap->initialize());
    }
}
