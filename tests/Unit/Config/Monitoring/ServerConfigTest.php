<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config\Monitoring;

use Mata\Config\Monitoring\Section\ServerConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\Monitoring\Section\ServerConfig
 *
 * @internal
 */
final class ServerConfigTest extends TestCase
{
    /** The settings page lists `options`; the merged secret must never reach it. */
    public function testOptionsLeaveOutTheApiKeyAndNonScalarValues(): void
    {
        $config = ServerConfig::fromArray('web', [
            'title' => 'Web',
            'api_url' => 'https://node.example',
            'api_key' => 'secret',
            'region' => 'eu-1',
            'tags' => ['a'],
        ]);

        $this->assertSame('secret', $config->apiKey);
        $this->assertSame(['title' => 'Web', 'api_url' => 'https://node.example', 'region' => 'eu-1'], $config->options);
    }
}
