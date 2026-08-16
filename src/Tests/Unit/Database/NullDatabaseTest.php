<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Database;

use Mata\Database\NullDatabase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Database\NullDatabase
 *
 * @internal
 */
final class NullDatabaseTest extends TestCase
{
    public function testItReturnsEmptyResultsForReadAndDefaultQueries(): void
    {
        $database = new NullDatabase(new Logger('test'));

        self::assertSame([], $database->runQuery('SELECT * FROM users'));
        self::assertSame([], $database->runQuery('CREATE TABLE users (id INTEGER)'));
    }

    public function testItReturnsZeroForInsertsWithLeadingWhitespace(): void
    {
        $database = new NullDatabase(new Logger('test'));

        self::assertSame(0, $database->runQuery("\n\t INSERT INTO users (name) VALUES (?)", ['Alice']));
    }

    public function testItLogsOnlyParameterTypes(): void
    {
        $handler = new TestHandler();
        $database = new NullDatabase(new Logger('test', [$handler]));

        $database->runQuery('INSERT INTO users (name, active) VALUES (?, ?)', [
            'private-value',
            true,
        ]);

        self::assertTrue($handler->hasDebug([
            'message' => 'Skipped database operation: runQuery',
            'context' => [
                'sql' => 'INSERT INTO users (name, active) VALUES (?, ?)',
                'params' => ['string', 'bool'],
            ],
        ]));
    }
}
