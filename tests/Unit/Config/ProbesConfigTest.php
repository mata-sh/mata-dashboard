<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\ProbesConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\Monitoring\Section\ProbesConfig
 *
 * @internal
 */
final class ProbesConfigTest extends TestCase
{
    public function testOwnsDefaultsAndAcceptsBoundaryValues(): void
    {
        $default = ProbesConfig::default();
        $config = ProbesConfig::fromArray([
            'timeout_seconds' => 1,
            'max_retries' => 0,
            'backoff_base_ms' => 900,
            'backoff_max_ms' => 900,
        ]);

        $this->assertSame(5, $default->timeoutSeconds);
        $this->assertSame(1, $default->maxRetries);
        $this->assertSame([
            'timeout_seconds' => 1,
            'max_retries' => 0,
            'backoff_base_ms' => 900,
            'backoff_max_ms' => 900,
        ], $config->toArray());
    }

    #[DataProvider('invalidIntegerValues')]
    public function testRejectsUnsupportedIntegerTypes(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('probes.max_retries must be an integer.');

        ProbesConfig::fromArray(['max_retries' => $value]);
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidIntegerValues(): iterable
    {
        yield 'float' => [1.0];

        yield 'numeric string' => ['1'];

        yield 'boolean' => [true];

        yield 'array' => [[]];

        yield 'object' => [(object) []];

        yield 'null' => [null];
    }

    public function testRejectsOutOfRangeAndInconsistentValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('probes.backoff_max_ms must be at least probes.backoff_base_ms.');

        ProbesConfig::fromArray(['backoff_base_ms' => 900, 'backoff_max_ms' => 899]);
    }
}
