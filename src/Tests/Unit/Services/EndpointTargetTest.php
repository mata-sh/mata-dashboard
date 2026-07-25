<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Monitor\Dto\EndpointTarget;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\EndpointTarget
 *
 * @internal
 */
final class EndpointTargetTest extends TestCase
{
    public function testFromArrayNormalizesTargetMetadata(): void
    {
        $target = EndpointTarget::fromArray([
            'url' => ' https://app.example.test ',
            'server_id' => '12',
            'application_id' => '8',
            'server_name' => ' node-a ',
            'status_url' => ' https://app.example.test/status ',
            'region' => 'eu',
        ]);

        self::assertNotNull($target);
        self::assertSame('https://app.example.test', $target->url);
        self::assertSame(12, $target->serverId);
        self::assertSame(8, $target->applicationId);
        self::assertSame('node-a', $target->serverName);
        self::assertSame('https://app.example.test/status', $target->statusUrl);
        self::assertSame('https://app.example.test/status', $target->resolveProbeUrl());
        self::assertSame('eu', $target->resolveMetadataValue('region'));
        self::assertSame('default', $target->resolveMetadataValue('missing', 'default'));
    }

    public function testFromRawAppDataUsesTheAppKeyAsApplicationCode(): void
    {
        $targets = EndpointTarget::fromRawAppData([
            'shop' => ['url' => 'https://shop.example.test'],
        ], 'node-a');

        self::assertSame('shop', $targets['shop']->applicationCode);
        self::assertSame('node-a', $targets['shop']->serverName);
    }

    public function testFromRawAppDataDropsAppsWithReachabilityMonitoringDisabled(): void
    {
        $targets = EndpointTarget::fromRawAppData([
            'shop' => ['url' => 'https://shop.example.test'],
            'admin' => ['url' => 'https://admin.example.test', 'monitoring' => ['reachability' => false]],
            'blog' => ['url' => 'https://blog.example.test', 'monitoring' => ['sessions' => false]],
        ], 'node-a');

        self::assertSame(['shop', 'blog'], array_keys($targets));
    }

    public function testFromRawAppDataKeepsAppsWithAMalformedMonitoringBlock(): void
    {
        $targets = EndpointTarget::fromRawAppData([
            'shop' => ['url' => 'https://shop.example.test', 'monitoring' => ['reachability' => 'off']],
            'admin' => ['url' => 'https://admin.example.test', 'monitoring' => 'nonsense'],
        ]);

        self::assertSame(['shop', 'admin'], array_keys($targets));
    }

    public function testFromArrayRejectsNonPositiveIdsAndBlankOptionalStrings(): void
    {
        $target = EndpointTarget::fromArray([
            'url' => 'https://app.example.test',
            'server_id' => 0,
            'application_id' => -1,
            'server_name' => ' ',
            'status_url' => '',
        ]);

        self::assertNotNull($target);
        self::assertNull($target->serverId);
        self::assertNull($target->applicationId);
        self::assertNull($target->serverName);
        self::assertNull($target->statusUrl);
    }
}
