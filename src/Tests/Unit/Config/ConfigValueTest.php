<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\ConfigValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\ConfigValue
 *
 * @internal
 */
final class ConfigValueTest extends TestCase
{
    #[DataProvider('unsupportedIntegerValues')]
    public function testIntegerRejectsEveryUnsupportedJsonType(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('section.option must be an integer.');

        ConfigValue::integer($value, 'section.option');
    }

    /** @return iterable<string,array{mixed}> */
    public static function unsupportedIntegerValues(): iterable
    {
        yield 'float' => [1.0];

        yield 'numeric string' => ['1'];

        yield 'boolean' => [true];

        yield 'list' => [[1]];

        yield 'object' => [(object) ['value' => 1]];

        yield 'null' => [null];
    }

    public function testIntegerAcceptsBounds(): void
    {
        $this->assertSame(0, ConfigValue::integer(0, 'value', minimum: 0, maximum: 1));
        $this->assertSame(1, ConfigValue::integer(1, 'value', minimum: 0, maximum: 1));
    }

    public function testIntegerRejectsValuesOutsideBounds(): void
    {
        try {
            ConfigValue::integer(-1, 'section.minimum', minimum: 0);
            self::fail('Expected minimum validation to fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('section.minimum must be at least 0.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('section.maximum must be at most 10.');
        ConfigValue::integer(11, 'section.maximum', maximum: 10);
    }

    #[DataProvider('invalidIntegerStrings')]
    public function testIntegerStringRejectsUndocumentedSyntax(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConfigValue::integerString($value, 'env.VALUE');
    }

    /** @return iterable<string,array{string}> */
    public static function invalidIntegerStrings(): iterable
    {
        yield 'float' => ['1.0'];

        yield 'scientific' => ['1e2'];

        yield 'leading plus' => ['+1'];

        yield 'leading zero' => ['01'];

        yield 'whitespace' => [' 1'];

        yield 'empty' => [''];
    }
}
