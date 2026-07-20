<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Dto\CliSecurityConfig;
use Mata\Config\Dto\DashboardConfig;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\MailConfig;
use Mata\Config\Dto\PhpConfig;
use Mata\Config\Dto\RateLimitingConfig;
use Mata\Config\Dto\TemplateConfig;
use Mata\Services\Alarm\AlarmThresholdCooldownService;
use Mata\Services\Event\Dto\EventContext;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventRecorderService;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\AlarmThresholdCooldownService
 *
 * @internal
 */
final class AlarmThresholdCooldownServiceTest extends TestCase
{
    public function testCooldownHashIgnoresVolatileMetricFields(): void
    {
        $service = new AlarmThresholdCooldownService(
            $this->createStub(EventAggregatorServiceInterface::class),
            $this->createStub(EventRecorderService::class),
            $this->config(),
        );

        $first = $service->generateCooldownHash(
            'disk_event',
            'srv-1',
            null,
            EventContext::fromArray([
                'metric' => 'disk_space_usage_percent',
                'threshold' => 90,
                'actual_value' => 91,
                'additional_data' => ['total' => 100],
            ]),
            'email',
        );
        $second = $service->generateCooldownHash(
            'disk_event',
            'srv-1',
            null,
            EventContext::fromArray([
                'metric' => 'disk_space_usage_percent',
                'threshold' => 90,
                'actual_value' => 99,
                'additional_data' => ['total' => 120],
            ]),
            'email',
        );

        $this->assertSame($first, $second);
    }

    private function config(): ApplicationConfig
    {
        return new ApplicationConfig(
            environment: new EnvironmentConfig('prod', 'example.com', '/srv/app', ''),
            general: new GeneralConfig('Test', false, false, [], true, true),
            dashboard: new DashboardConfig(3, 1200),
            template: new TemplateConfig(false),
            php: new PhpConfig(0, 'error'),
            logging: new LoggingConfig('/tmp/app.log', 'debug'),
            database: new DatabaseConfig('localhost', 'user', 'pass', 'db', '3306'),
            mail: new MailConfig(null, null, null, [], [], [], 'mailer', 'mailer', 'UTF-8', 78, null),
            cliSecurity: new CliSecurityConfig(false, ['root']),
            rateLimiting: new RateLimitingConfig(false, 'file', '/tmp/rate_limit_test.json', null, 6379, null, 0, 5, 300, 60, 60, 100, 60),
        );
    }
}
