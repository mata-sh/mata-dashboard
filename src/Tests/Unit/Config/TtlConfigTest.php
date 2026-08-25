<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\TtlConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\Monitoring\Section\TtlConfig
 *
 * @internal
 */
final class TtlConfigTest extends TestCase
{
    #[DataProvider('invalidTtls')]
    public function testRejectsUnsupportedIntegerTypes(mixed $ttl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ttl.apps must be an integer.');

        TtlConfig::fromArray(['apps' => $ttl]);
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidTtls(): iterable
    {
        yield 'integral-looking float' => [60.0];

        yield 'fractional float' => [60.9];

        yield 'numeric string' => ['60'];

        yield 'boolean' => [true];

        yield 'array' => [[]];

        yield 'object' => [(object) []];

        yield 'null' => [null];
    }

    public function testRetainsIntegersAndAddsDefaultsForOmittedKeys(): void
    {
        $config = TtlConfig::fromArray(['apps' => 60, 'readme' => -1]);

        $this->assertSame(60, $config->get('apps'));
        $this->assertSame(-1, $config->get('readme'));
        $this->assertSame(86400, $config->get('serverInfo'));
    }

    public function testEmptyHasNoValues(): void
    {
        $this->assertSame([], TtlConfig::empty()->values);
    }
}
