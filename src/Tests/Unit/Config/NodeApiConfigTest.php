<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\Dto\NodeApiConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\Dto\NodeApiConfig
 *
 * @internal
 */
final class NodeApiConfigTest extends TestCase
{
    public function testConstructorRejectsTimeoutBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('node_api.timeout_seconds must be at least 1.');

        new NodeApiConfig(0);
    }
}
