<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Exception;
use Mata\Services\Audit\AuditAggregator;
use Mata\Services\Audit\AuditRowEnricher;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\View\LibrariesViewBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\View\LibrariesViewBuilder
 *
 * @internal
 */
final class LibrariesViewBuilderTest extends TestCase
{
    public function testBuildsLibraryRows(): void
    {
        $collector = $this->collector([
            'result' => ['app' => [[
                'name' => 'vendor/package',
                'version' => '1.0.0',
                'type' => 'library',
                'description' => 'Package',
            ]]],
        ]);
        $collector->method('getCachedAppComposerAudit')->willReturn(null);

        $result = $this->builder()->buildLibrariesViewData(['server-a' => $collector], ['server-a' => []]);

        $this->assertSame([
            'data' => [[
                'server' => 'server-a',
                'app' => 'app',
                'name' => 'vendor/package',
                'version' => '1.0.0',
                'type' => 'library',
                'description' => 'Package',
                'audited' => false,
                'vulns' => 0,
                'severity' => null,
            ]],
            'errors' => [],
        ], $result);
    }

    public function testUnavailableServerAddsTheExpectedError(): void
    {
        $this->assertSame([
            'data' => [],
            'errors' => [['server' => 'server-a', 'message' => 'Libraries service is unavailable.']],
        ], $this->builder()->buildLibrariesViewData([], ['server-a' => []]));
    }

    public function testEndpointErrorMessagePassesThrough(): void
    {
        $collector = $this->collector(['error' => ['message' => 'Node unavailable']]);

        $result = $this->builder()->buildLibrariesViewData(['server-a' => $collector], ['server-a' => []]);

        $this->assertSame([['server' => 'server-a', 'message' => 'Node unavailable']], $result['errors']);
    }

    public function testNonArrayResultAddsTheExpectedError(): void
    {
        $collector = $this->collector(['result' => null]);

        $result = $this->builder()->buildLibrariesViewData(['server-a' => $collector], ['server-a' => []]);

        $this->assertSame([['server' => 'server-a', 'message' => 'Failed to fetch libraries data via API.']], $result['errors']);
    }

    public function testAuditExceptionLogsAndContinuesWithOtherServers(): void
    {
        $failing = $this->collector(['result' => ['app' => []]]);
        $failing->method('getCachedAppComposerAudit')->willThrowException(new Exception('Audit failed'));
        $working = $this->collector(['result' => ['app' => [['name' => 'vendor/package']]]]);
        $working->method('getCachedAppComposerAudit')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Rendering error in LibrariesData: Audit failed',
            ['server' => 'failing', 'component' => 'LibrariesData'],
        );

        $result = $this->builder($logger)->buildLibrariesViewData(
            ['failing' => $failing, 'working' => $working],
            ['failing' => [], 'working' => []],
        );

        $this->assertSame([['server' => 'failing', 'message' => 'Audit failed']], $result['errors']);
        $this->assertSame('working', $result['data'][0]['server']);
    }

    public function testEmptyLibraryArrayStillRequestsAudit(): void
    {
        $collector = $this->collector(['result' => ['app' => []]], true);
        $collector->expects($this->once())->method('getCachedAppComposerAudit')->with('app')->willReturn(null);

        $this->assertSame(['data' => [], 'errors' => []], $this->builder()->buildLibrariesViewData(['server-a' => $collector], ['server-a' => []]));
    }

    public function testMultipleRowsForOneAppRequestAuditOnce(): void
    {
        $collector = $this->collector(['result' => ['app' => [
            ['name' => 'one'],
            ['name' => 'two'],
        ]]], true);
        $collector->expects($this->once())->method('getCachedAppComposerAudit')->with('app')->willReturn(null);

        $this->builder()->buildLibrariesViewData(['server-a' => $collector], ['server-a' => []]);
    }

    public function testCallsServersAndAuditsInExistingOrder(): void
    {
        $calls = [];
        $first = $this->collector(['result' => ['one' => [], 'two' => []]]);
        $first->method('getAppsLibraries')->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'libraries:first';

            return ['result' => ['one' => [], 'two' => []]];
        });
        $first->method('getCachedAppComposerAudit')->willReturnCallback(function (string $app) use (&$calls): ?array {
            $calls[] = "audit:first:{$app}";

            return null;
        });
        $second = $this->collector(['result' => ['three' => []]]);
        $second->method('getAppsLibraries')->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'libraries:second';

            return ['result' => ['three' => []]];
        });
        $second->method('getCachedAppComposerAudit')->willReturnCallback(function (string $app) use (&$calls): ?array {
            $calls[] = "audit:second:{$app}";

            return null;
        });

        $this->builder()->buildLibrariesViewData(['first' => $first, 'second' => $second], ['first' => [], 'second' => []]);

        $this->assertSame([
            'libraries:first',
            'audit:first:one',
            'audit:first:two',
            'libraries:second',
            'audit:second:three',
        ], $calls);
    }

    public function testFullOutputPreservesJsonKeyOrderAndConditionalAdvisories(): void
    {
        $collector = $this->collector(['result' => ['app' => [
            ['name' => 'vulnerable', 'version' => '1', 'type' => 'library', 'description' => 'Vulnerable'],
            ['name' => 'safe', 'version' => '2', 'type' => 'library', 'description' => 'Safe'],
        ]]]);
        $collector->method('getCachedAppComposerAudit')->willReturn(['result' => [
            'status' => 'ok',
            'advisories' => [['package' => 'vulnerable', 'severity' => 'high', 'id' => 'CVE-1']],
        ]]);

        $result = $this->builder()->buildLibrariesViewData(['server-a' => $collector], ['server-a' => []]);

        $this->assertSame(
            '{"data":[{"server":"server-a","app":"app","name":"vulnerable","version":"1","type":"library","description":"Vulnerable","audited":true,"vulns":1,"severity":"high","advisories":[{"package":"vulnerable","severity":"high","id":"CVE-1"}]},{"server":"server-a","app":"app","name":"safe","version":"2","type":"library","description":"Safe","audited":true,"vulns":0,"severity":null}],"errors":[]}',
            json_encode($result),
        );
    }

    /** @param null|array<string,mixed> $response */
    private function collector(?array $response, bool $mock = false): ServerDataCollectorInterface
    {
        $collector = $mock
            ? $this->createMock(ServerDataCollectorInterface::class)
            : $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getAppsLibraries')->willReturn($response);

        return $collector;
    }

    private function builder(?LoggerInterface $logger = null): LibrariesViewBuilder
    {
        return new LibrariesViewBuilder($logger ?? new NullLogger(), new AuditRowEnricher(new AuditAggregator()));
    }
}
