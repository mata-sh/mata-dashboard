<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use InvalidArgumentException;
use Mata\Services\Monitor\Dto\AppMonitoringSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @covers \Mata\Services\Monitor\Dto\AppMonitoringSettings
 *
 * @internal
 */
final class AppMonitoringSettingsTest extends TestCase
{
    public function testEverythingIsEnabledByDefault(): void
    {
        foreach ([AppMonitoringSettings::allEnabled(), new AppMonitoringSettings()] as $settings) {
            foreach (AppMonitoringSettings::SETTINGS as $setting) {
                $this->assertTrue($settings->isEnabled($setting), $setting);
            }
        }
    }

    public function testKnownSettingNames(): void
    {
        $this->assertSame(
            ['loganalysis', 'logsize', 'sessions', 'reachability', 'alarms', 'events'],
            AppMonitoringSettings::SETTINGS
        );
    }

    public function testAbsentMonitoringBlockEnablesEverything(): void
    {
        $settings = AppMonitoringSettings::fromAppPayload(['title' => 'Shop']);

        $this->assertTrue($settings->loganalysis);
        $this->assertTrue($settings->logsize);
        $this->assertTrue($settings->sessions);
        $this->assertTrue($settings->reachability);
        $this->assertTrue($settings->alarms);
        $this->assertTrue($settings->events);
    }

    public function testEmptyMonitoringBlockEnablesEverything(): void
    {
        $settings = AppMonitoringSettings::fromAppPayload(['monitoring' => []]);

        foreach (AppMonitoringSettings::SETTINGS as $setting) {
            $this->assertTrue($settings->isEnabled($setting), $setting);
        }
    }

    public function testSparseBlockOnlyDisablesTheListedSettings(): void
    {
        $settings = AppMonitoringSettings::fromAppPayload([
            'monitoring' => ['loganalysis' => false, 'sessions' => false],
        ]);

        $this->assertFalse($settings->loganalysis);
        $this->assertFalse($settings->isEnabled('loganalysis'));
        $this->assertFalse($settings->sessions);
        $this->assertTrue($settings->logsize);
        $this->assertTrue($settings->reachability);
        $this->assertTrue($settings->alarms);
        $this->assertTrue($settings->events);
    }

    public function testExplicitTrueIsHonored(): void
    {
        $settings = AppMonitoringSettings::fromAppPayload([
            'monitoring' => ['logsize' => true, 'events' => false],
        ]);

        $this->assertTrue($settings->logsize);
        $this->assertFalse($settings->events);
    }

    public function testEverySettingCanBeDisabledIndependently(): void
    {
        foreach (AppMonitoringSettings::SETTINGS as $disabled) {
            $settings = AppMonitoringSettings::fromAppPayload(['monitoring' => [$disabled => false]]);

            foreach (AppMonitoringSettings::SETTINGS as $setting) {
                $this->assertSame($setting !== $disabled, $settings->isEnabled($setting), "{$disabled}/{$setting}");
            }
        }
    }

    #[DataProvider('garbageMonitoringBlocks')]
    public function testFailsOpenOnGarbageMonitoringBlock(mixed $monitoring): void
    {
        $settings = AppMonitoringSettings::fromAppPayload(['monitoring' => $monitoring]);

        foreach (AppMonitoringSettings::SETTINGS as $setting) {
            $this->assertTrue($settings->isEnabled($setting), $setting);
        }
    }

    /** @return iterable<string,array{mixed}> */
    public static function garbageMonitoringBlocks(): iterable
    {
        yield 'null' => [null];

        yield 'false' => [false];

        yield 'true' => [true];

        yield 'string' => ['loganalysis'];

        yield 'int' => [0];

        yield 'float' => [1.5];

        yield 'object' => [new stdClass()];

        yield 'sequential list' => [['loganalysis', 'logsize']];

        yield 'unknown key' => [['logsizes' => false]];

        yield 'stringified false' => [['loganalysis' => 'false']];

        yield 'stringified true' => [['loganalysis' => 'true']];

        yield 'zero' => [['loganalysis' => 0]];

        yield 'null value' => [['loganalysis' => null]];

        yield 'empty array value' => [['loganalysis' => []]];

        yield 'nested array value' => [['loganalysis' => ['enabled' => false]]];
    }

    public function testGarbageIsIsolatedFromValidNeighbourKeys(): void
    {
        $settings = AppMonitoringSettings::fromAppPayload([
            'monitoring' => ['loganalysis' => 'false', 'logsizes' => false, 'sessions' => false],
        ]);

        $this->assertTrue($settings->loganalysis, 'a non-bool value must resolve to enabled');
        $this->assertTrue($settings->logsize, 'an unknown key must not disable a real setting');
        $this->assertFalse($settings->sessions, 'valid neighbours are still applied');
    }

    public function testNonArrayBlockIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Ignoring non-object application monitoring block', ['type' => 'string']);

        AppMonitoringSettings::fromAppPayload(['monitoring' => 'off'], $logger);
    }

    public function testUnknownKeyIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Ignoring unknown application monitoring setting', ['setting' => 'logsizes']);

        AppMonitoringSettings::fromAppPayload(['monitoring' => ['logsizes' => false]], $logger);
    }

    public function testNonBooleanValueIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Ignoring non-boolean application monitoring setting', ['setting' => 'logsize', 'type' => 'int']);

        AppMonitoringSettings::fromAppPayload(['monitoring' => ['logsize' => 0]], $logger);
    }

    public function testParsingNeverThrows(): void
    {
        foreach (self::garbageMonitoringBlocks() as $case) {
            $this->assertInstanceOf(
                AppMonitoringSettings::class,
                AppMonitoringSettings::fromAppPayload(['monitoring' => $case[0]])
            );
        }
    }

    public function testUnknownSettingNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown monitoring setting: logsizes');

        AppMonitoringSettings::allEnabled()->isEnabled('logsizes');
    }
}
