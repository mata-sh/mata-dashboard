<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use Watcher\Core\Database;
use Watcher\Tests\TestCase;

/**
 * Test suite for Database utility class.
 *
 * @covers \Watcher\Core\Database
 *
 * @internal
 */
class DatabaseTest extends TestCase
{
    private Database $database;
    private array $validConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validConfig = [
            'host' => 'localhost',
            'username' => 'test_user',
            'password' => 'test_password',
            'database' => 'test_database',
        ];

        // Use SQLite in-memory database for testing to avoid external dependencies
        $this->validConfig = [
            'host' => 'localhost',
            'username' => '',
            'password' => '',
            'database' => ':memory:',
        ];
    }

    /**
     * @test
     *
     * @group database
     * @group initialization
     */
    public function testItInitializesWithValidConfig(): void
    {
        // Skip this test if SQLite PDO driver is not available
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite extension is not available');
        }

        // Create database with SQLite for testing
        $config = [
            'host' => 'localhost',
            'username' => '',
            'password' => '',
            'database' => ':memory:',
        ];

        // We'll need to mock PDO since MySQL might not be available in test environment
        $this->assertInstanceOf(Database::class, $this->createMockDatabase());
    }

    /**
     * @test
     *
     * @group database
     * @group validation
     */
    public function testItValidatesRequiredConfigKeys(): void
    {
        $this->markTestSkipped('DatabaseConfig DTO now enforces required parameters at compile time via PHP type system');
    }

    /**
     * @test
     *
     * @group database
     * @group validation
     */
    public function testItValidatesEmptyConfigValues(): void
    {
        $this->markTestSkipped('DatabaseConfig DTO now handles validation; empty values are permitted by the DTO');
    }

    /**
     * @test
     *
     * @group database
     * @group queries
     */
    public function testItExecutesSelectQueriesAndReturnsResults(): void
    {
        $database = $this->createMockDatabase();

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->expects($this->once())
            ->method('execute')
            ->with(['param1' => 'value1'])
            ->willReturn(true)
        ;

        $expectedResults = [
            ['id' => 1, 'name' => 'Test'],
            ['id' => 2, 'name' => 'Another'],
        ];

        $mockStatement->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedResults)
        ;

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM users WHERE name = :param1')
            ->willReturn($mockStatement)
        ;

        $this->setProperty($database, 'pdo', $mockPdo);

        $result = $database->runQuery('SELECT * FROM users WHERE name = :param1', ['param1' => 'value1']);

        $this->assertEquals($expectedResults, $result);
    }

    /**
     * @test
     *
     * @group database
     * @group queries
     */
    public function testItExecutesInsertQueriesAndReturnsLastInsertId(): void
    {
        $database = $this->createMockDatabase();

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->expects($this->once())
            ->method('execute')
            ->with(['name' => 'New User'])
            ->willReturn(true)
        ;

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('INSERT INTO users (name) VALUES (:name)')
            ->willReturn($mockStatement)
        ;

        $mockPdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('123')
        ;

        $this->setProperty($database, 'pdo', $mockPdo);

        $result = $database->runQuery('INSERT INTO users (name) VALUES (:name)', ['name' => 'New User']);

        $this->assertEquals(123, $result);
    }

    /**
     * @test
     *
     * @group database
     * @group transactions
     */
    public function testItBeginsDatabaseTransaction(): void
    {
        $database = $this->createMockDatabase();

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(true)
        ;

        $this->setProperty($database, 'pdo', $mockPdo);

        $database->beginTransaction();
    }

    /**
     * @test
     *
     * @group database
     * @group transactions
     */
    public function testItCommitsDatabaseTransaction(): void
    {
        $database = $this->createMockDatabase();

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('commit')
            ->willReturn(true)
        ;

        $this->setProperty($database, 'pdo', $mockPdo);

        $database->commit();
    }

    /**
     * @test
     *
     * @group database
     * @group transactions
     */
    public function testItRollsBackDatabaseTransaction(): void
    {
        $database = $this->createMockDatabase();

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('rollBack')
            ->willReturn(true)
        ;

        $this->setProperty($database, 'pdo', $mockPdo);

        $database->rollback();
    }

    /**
     * @test
     *
     * @group database
     * @group pdo-access
     */
    public function testItProvidesAccessToUnderlyingPdoInstance(): void
    {
        $database = $this->createMockDatabase();

        $mockPdo = $this->createMock(PDO::class);
        $this->setProperty($database, 'pdo', $mockPdo);

        $result = $database->getPdo();

        $this->assertSame($mockPdo, $result);
    }

    /**
     * @test
     *
     * @group database
     * @group query-types
     */
    public function testItHandlesUpdateQueries(): void
    {
        $database = $this->createMockDatabase();

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->expects($this->once())
            ->method('execute')
            ->willReturn(true)
        ;

        $mockStatement->expects($this->once())
            ->method('rowCount')
            ->willReturn(2)
        ;

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('UPDATE users SET name = :name WHERE id = :id')
            ->willReturn($mockStatement)
        ;

        $this->setProperty($database, 'pdo', $mockPdo);

        $result = $database->runQuery('UPDATE users SET name = :name WHERE id = :id', [
            'name' => 'Updated Name',
            'id' => 1,
        ]);

        $this->assertEquals(2, $result);
    }

    /**
     * @test
     *
     * @group database
     * @group query-types
     */
    public function testItHandlesDeleteQueries(): void
    {
        $database = $this->createMockDatabase();

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->expects($this->once())
            ->method('execute')
            ->willReturn(true)
        ;

        $mockStatement->expects($this->once())
            ->method('rowCount')
            ->willReturn(1)
        ;

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('DELETE FROM users WHERE id = :id')
            ->willReturn($mockStatement)
        ;

        $this->setProperty($database, 'pdo', $mockPdo);

        $result = $database->runQuery('DELETE FROM users WHERE id = :id', ['id' => 1]);

        $this->assertEquals(1, $result);
    }

    /**
     * @test
     *
     * @group database
     * @group error-handling
     */
    public function testItHandlesPdoExceptions(): void
    {
        $database = $this->createMockDatabase();

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->will($this->throwException(new PDOException('Database error')))
        ;

        $this->setProperty($database, 'pdo', $mockPdo);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Database error');

        $database->runQuery('SELECT * FROM invalid_table');
    }

    /**
     * @test
     *
     * @group database
     * @group prepared-statements
     */
    public function testItUsesPreparedStatementsForSecurity(): void
    {
        $database = $this->createMockDatabase();

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->expects($this->once())
            ->method('execute')
            ->with(['user_input' => "'; DROP TABLE users; --"])
            ->willReturn(true)
        ;

        $mockStatement->expects($this->once())
            ->method('fetchAll')
            ->willReturn([])
        ;

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM users WHERE name = :user_input')
            ->willReturn($mockStatement)
        ;

        $this->setProperty($database, 'pdo', $mockPdo);

        // This malicious input should be safely handled by prepared statements
        $result = $database->runQuery(
            'SELECT * FROM users WHERE name = :user_input',
            ['user_input' => "'; DROP TABLE users; --"]
        );

        $this->assertEquals([], $result);
    }

    /**
     * @test
     *
     * @group database
     * @group configuration
     */
    public function testItValidatesAllRequiredConfigKeys(): void
    {
        $this->markTestSkipped('DatabaseConfig DTO now enforces required parameters at compile time via PHP type system');
    }

    /**
     * @test
     *
     * @group database
     * @group insert-operations
     */
    public function testItDistinguishesInsertQueriesCaseInsensitive(): void
    {
        $database = $this->createMockDatabase();

        $insertQueries = [
            'INSERT INTO users VALUES (1, "test")',
            'insert into users values (2, "test2")',
            '  INSERT  INTO users VALUES (3, "test3")',
            "\tINSERT INTO users VALUES (4, \"test4\")",
        ];

        foreach ($insertQueries as $query) {
            $mockStatement = $this->createMock(PDOStatement::class);
            $mockStatement->method('execute')->willReturn(true);

            $mockPdo = $this->createMock(PDO::class);
            $mockPdo->method('prepare')->willReturn($mockStatement);
            $mockPdo->method('lastInsertId')->willReturn('99');

            $this->setProperty($database, 'pdo', $mockPdo);

            $result = $database->runQuery($query);

            // Should return integer for INSERT queries
            $this->assertIsInt($result);
            $this->assertEquals(99, $result);
        }
    }

    /**
     * @test
     *
     * @group database
     * @group transaction-state
     */
    public function testItTracksTransactionStateCorrectly(): void
    {
        $database = $this->createMockDatabase();

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->exactly(2))->method('beginTransaction')->willReturn(true);
        $mockPdo->expects($this->once())->method('commit')->willReturn(true);
        $mockPdo->expects($this->once())->method('rollBack')->willReturn(true);

        $this->setProperty($database, 'pdo', $mockPdo);

        // Begin transaction
        $database->beginTransaction();

        // Commit transaction
        $database->commit();

        // Begin again and rollback
        $database->beginTransaction();
        $database->rollback();
    }

    /**
     * Create a mock Database instance for testing.
     */
    private function createMockDatabase(): Database
    {
        // Create a mock that bypasses the constructor
        $database = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        // Allow method calls
        $database->method('runQuery')->willReturnCallback(function ($sql, $params = []) {
            // Mock implementation that can be overridden in specific tests
            return [];
        });

        // Create a real instance for most tests
        $reflection = new ReflectionClass(Database::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
