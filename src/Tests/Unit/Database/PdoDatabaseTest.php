<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Database;

use Mata\Database\PdoDatabase;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Database\PdoDatabase
 *
 * @internal
 */
final class PdoDatabaseTest extends TestCase
{
    private PDO $pdo;
    private PdoDatabase $database;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->database = new PdoDatabase(
            config: ApplicationConfigFactory::createDatabaseConfig(),
            pdo: $this->pdo,
        );
        $this->database->runQuery('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    public function testInjectedPdoReceivesSecureDefaults(): void
    {
        self::assertSame(PDO::ERRMODE_EXCEPTION, $this->pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::FETCH_ASSOC, $this->pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        self::assertSame($this->pdo, $this->database->getPdo());
    }

    public function testItExecutesSelectQueries(): void
    {
        $this->pdo->exec("INSERT INTO users (name) VALUES ('Alice')");

        self::assertSame([['id' => 1, 'name' => 'Alice']], $this->database->runQuery("\n SELECT * FROM users"));
    }

    public function testItExecutesInsertQueries(): void
    {
        self::assertSame(1, $this->database->runQuery("\n\t INSERT INTO users (name) VALUES (:name)", ['name' => 'Alice']));
    }

    public function testItExecutesUpdateQueries(): void
    {
        $this->pdo->exec("INSERT INTO users (name) VALUES ('Alice')");

        self::assertSame(1, $this->database->runQuery("\r\n UPDATE users SET name = 'Bob' WHERE id = 1"));
    }

    public function testItExecutesDeleteQueries(): void
    {
        $this->pdo->exec("INSERT INTO users (name) VALUES ('Alice')");

        self::assertSame(1, $this->database->runQuery("\t DELETE FROM users WHERE id = 1"));
    }

    public function testItSupportsTransactions(): void
    {
        $this->database->beginTransaction();
        $this->database->runQuery("INSERT INTO users (name) VALUES ('Alice')");
        $this->database->rollback();
        self::assertSame([], $this->database->runQuery('SELECT * FROM users'));

        $this->database->beginTransaction();
        $this->database->runQuery("INSERT INTO users (name) VALUES ('Bob')");
        $this->database->commit();
        self::assertCount(1, $this->database->runQuery('SELECT * FROM users'));
    }

    public function testPdoExceptionsPropagate(): void
    {
        $this->expectException(PDOException::class);

        $this->database->runQuery('SELECT * FROM missing_table');
    }
}
