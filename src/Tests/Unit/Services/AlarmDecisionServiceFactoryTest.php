<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\Evaluation\AlarmDecisionServiceFactory;
use Mata\Services\Alarm\Evaluation\BuiltinAlarmDecisionService;
use Mata\Services\Alarm\Evaluation\OpaAlarmDecisionService;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use Mata\Tests\TestCase;
use RuntimeException;

/**
 * @covers \Mata\Services\Alarm\Evaluation\AlarmDecisionServiceFactory
 *
 * @internal
 */
final class AlarmDecisionServiceFactoryTest extends TestCase
{
    public function testOpaDriverResolvesToOpa(): void
    {
        $service = AlarmDecisionServiceFactory::fromConfig(
            ApplicationConfigFactory::createAlarmDecisionConfig(batchLimit: 25),
        );

        $this->assertInstanceOf(OpaAlarmDecisionService::class, $service);
        $this->assertSame(25, $service->getBatchLimit());
    }

    public function testBuiltinDriverResolvesToBuiltinService(): void
    {
        $service = AlarmDecisionServiceFactory::fromConfig(
            ApplicationConfigFactory::createAlarmDecisionConfig(driver: 'builtin', batchLimit: 250),
        );

        $this->assertInstanceOf(BuiltinAlarmDecisionService::class, $service);
        $this->assertSame(250, $service->getBatchLimit());
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown');
        $this->expectExceptionMessage('opa, builtin');

        AlarmDecisionServiceFactory::fromConfig(
            ApplicationConfigFactory::createAlarmDecisionConfig(driver: 'unknown'),
        );
    }
}
