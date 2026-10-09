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
    public function testRunSelectReturnsNoRows(): void
    {
        $database = new NullDatabase(new Logger('test'));

        self::assertSame([], $database->runSelect('SELECT * FROM users'));
    }

    public function testWritesReturnZero(): void
    {
        $database = new NullDatabase(new Logger('test'));

        self::assertSame(0, $database->runInsert('INSERT INTO users (name) VALUES (?)', ['Alice']));
        self::assertSame(0, $database->runStatement('DELETE FROM users'));
    }

    public function testItLogsOnlyParameterTypes(): void
    {
        $handler = new TestHandler();
        $database = new NullDatabase(new Logger('test', [$handler]));

        $database->runInsert('INSERT INTO users (name, active) VALUES (?, ?)', [
            'private-value',
            true,
        ]);

        self::assertTrue($handler->hasDebug([
            'message' => 'Skipped database operation: runInsert',
            'context' => [
                'sql' => 'INSERT INTO users (name, active) VALUES (?, ?)',
                'params' => ['string', 'bool'],
            ],
        ]));
    }
}
