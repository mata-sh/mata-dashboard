<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\Decision\AlarmDecisionServiceFactory;
use Mata\Services\Alarm\Decision\BuiltinAlarmDecisionService;
use Mata\Services\Alarm\Decision\OpaAlarmDecisionService;
use Mata\Tests\TestCase;
use RuntimeException;

/**
 * @covers \Mata\Services\Alarm\Decision\AlarmDecisionServiceFactory
 *
 * @internal
 */
final class AlarmDecisionServiceFactoryTest extends TestCase
{
    /** @var array<string,array{bool,mixed}> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ALARM_DECISION_DRIVER', 'ALARM_DECISION_BATCH_LIMIT'] as $key) {
            $this->originalEnvironment[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $key => [$exists, $value]) {
            if ($exists) {
                $_ENV[$key] = $value;
            } else {
                unset($_ENV[$key]);
            }
        }

        parent::tearDown();
    }

    public function testDefaultDriverResolvesToOpa(): void
    {
        $_ENV['ALARM_DECISION_DRIVER'] = '';

        $this->assertInstanceOf(OpaAlarmDecisionService::class, AlarmDecisionServiceFactory::fromEnv());
    }

    public function testBuiltinDriverResolvesToBuiltinService(): void
    {
        $_ENV['ALARM_DECISION_DRIVER'] = 'builtin';
        $_ENV['ALARM_DECISION_BATCH_LIMIT'] = '250';

        $service = AlarmDecisionServiceFactory::fromEnv();

        $this->assertInstanceOf(BuiltinAlarmDecisionService::class, $service);
        $this->assertSame(250, $service->getBatchLimit());
    }

    public function testUnknownDriverThrows(): void
    {
        $_ENV['ALARM_DECISION_DRIVER'] = 'unknown';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown');
        $this->expectExceptionMessage('opa, builtin');

        AlarmDecisionServiceFactory::fromEnv();
    }
}
