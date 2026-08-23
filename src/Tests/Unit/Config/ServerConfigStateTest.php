<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\ServerConfigState;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class ServerConfigStateTest extends TestCase
{
    /** @param array<string,mixed> $config */
    #[DataProvider('serverStateProvider')]
    public function testOnlyBooleanDisabledControlsServerState(array $config, bool $expected): void
    {
        $this->assertSame($expected, ServerConfigState::isDisabled($config));
    }

    /** @return iterable<string,array{array<string,mixed>,bool}> */
    public static function serverStateProvider(): iterable
    {
        yield 'disabled' => [['disabled' => true], true];

        yield 'enabled' => [['disabled' => false], false];

        yield 'default' => [[], false];

        yield 'legacy active' => [['active' => false], false];

        yield 'legacy enabled' => [['enabled' => false], false];

        yield 'legacy monitoring enabled' => [['monitoring_enabled' => false], false];
    }
}
