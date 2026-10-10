<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Closure;
use Mata\Config\Monitoring\Loader\AbstractJsonConfigLoader;
use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Config\Monitoring\Loader\ChartsConfigLoader;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\MenuConfigLoader;
use Mata\Config\Monitoring\Loader\MonitoringConfigLoadException;
use Mata\Config\Monitoring\Loader\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\Loader\ProbesConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\ThresholdsConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\ChartsConfig;
use Mata\Config\Monitoring\Section\DataRetentionConfig;
use Mata\Config\Monitoring\Section\IntervalsConfig;
use Mata\Config\Monitoring\Section\MenuConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Config\Monitoring\Section\TtlConfig;
use Mata\Models\Dto\EventRecordResult;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use Mata\Tests\TestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Behaviour every JSON config loader must share, exercised through the public load().
 *
 * A typo in a config file must never take down web or cron at boot: with fallback enabled the
 * loader returns usable config, with fallback disabled the only exception is
 * MonitoringConfigLoadException, and bad input is always reported, never silently accepted; if the event cannot be stored it
 * is logged instead.
 *
 * @internal
 *
 * @coversNothing
 */
final class MonitoringConfigLoaderContractTest extends TestCase
{
    private int $reports = 0;

    public function testEveryJsonConfigLoaderIsCoveredByTheContract(): void
    {
        $loaderDirectory = dirname((string) (new ReflectionClass(AbstractJsonConfigLoader::class))->getFileName());
        $concreteLoaders = [];
        foreach (glob($loaderDirectory . '/*.php') ?: [] as $file) {
            $class = 'Mata\Config\Monitoring\Loader\\' . basename($file, '.php');
            if (is_subclass_of($class, AbstractJsonConfigLoader::class)
                && !(new ReflectionClass($class))->isAbstract()) {
                $concreteLoaders[] = $class;
            }
        }
        $covered = array_map(
            static fn (array $case): string => $case['class'],
            array_values(self::loaderCases()),
        );
        sort($concreteLoaders);
        sort($covered);

        $this->assertSame(
            $concreteLoaders,
            $covered,
            'Add every new AbstractJsonConfigLoader subclass to loaderCases().',
        );
    }

    #[DataProvider('badInputs')]
    public function testBadInputYieldsUsableConfigWithFallbackEnabled(string $loader, ?string $contents): void
    {
        $case = self::loaderCases()[$loader];
        $default = $case['default']();

        $config = $this->loadWith($case, $contents, fallbackOnFailure: true);

        $this->assertInstanceOf($default::class, $config);
    }

    #[DataProvider('badInputs')]
    public function testBadInputOnlyThrowsTheLoadExceptionWithFallbackDisabled(string $loader, ?string $contents): void
    {
        $case = self::loaderCases()[$loader];
        $default = $case['default']();

        try {
            $config = $this->loadWith($case, $contents, fallbackOnFailure: false);
        } catch (MonitoringConfigLoadException) {
            $this->assertTrue($case['hasFallbackSwitch'], "{$loader} has no fallback switch and must never throw.");

            return;
        }

        $this->assertInstanceOf($default::class, $config);
    }

    #[DataProvider('badInputs')]
    public function testBadInputIsReported(string $loader, ?string $contents): void
    {
        $case = self::loaderCases()[$loader];

        $this->loadWith($case, $contents, fallbackOnFailure: true);

        if ($contents === null && $case['silentWhenMissing']) {
            $this->assertSame(0, $this->reports, "A missing {$case['file']} is not a problem and must not be reported.");

            return;
        }
        $this->assertGreaterThan(0, $this->reports, "{$loader} accepted bad input without reporting it.");
    }

    #[DataProvider('badInputs')]
    public function testBadInputStillLoadsAndIsLoggedWhenTheDatabaseFails(string $loader, ?string $contents): void
    {
        $case = self::loaderCases()[$loader];
        $recorder = $this->createStub(EventRecorderService::class);
        $recorder->method('recordEvent')->willThrowException(new PDOException('server has gone away'));
        $logged = 0;
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('log')->willReturnCallback(function () use (&$logged): void {
            ++$logged;
        });
        $events = new EventManager(ApplicationConfigFactory::create(), eventRecorder: $recorder, logger: $logger);

        $config = $this->loadIn($case, $contents, $events, fallbackOnFailure: true);

        $this->assertInstanceOf($case['default']()::class, $config);
        if ($contents === null && $case['silentWhenMissing']) {
            $this->assertSame(0, $logged);

            return;
        }
        $this->assertGreaterThan(0, $logged, "{$loader} lost a config problem when the database failed.");
    }

    /** @return iterable<string,array{string,?string}> */
    public static function badInputs(): iterable
    {
        yield from self::unreadableFiles();
        foreach (self::loaderCases() as $loader => $case) {
            yield "{$loader}: wrong-typed value" => [$loader, $case['wrongType']];
        }
    }

    #[DataProvider('unreadableFiles')]
    public function testUnreadableFileFallsBackToDefaults(string $loader, ?string $contents): void
    {
        $case = self::loaderCases()[$loader];

        $config = $this->loadWith($case, $contents, fallbackOnFailure: true);

        $this->assertEquals($case['default'](), $config);
    }

    /** @return iterable<string,array{string,?string}> */
    public static function unreadableFiles(): iterable
    {
        foreach (array_keys(self::loaderCases()) as $loader) {
            yield "{$loader}: missing file" => [$loader, null];

            yield "{$loader}: malformed JSON" => [$loader, '{"broken":'];

            yield "{$loader}: top-level list" => [$loader, '[1, 2]'];
        }
    }

    /**
     * `silentWhenMissing` marks the files docs/guides/configuration.md documents as optional:
     * setup does not create them, so their absence is normal and not reported.
     *
     * `wrongType` puts a string where the section expects a number, boolean or object, as deep
     * as practical, so section validation (not just JSON decoding) is exercised.
     *
     * @return array<string,array{
     *     class: class-string<AbstractJsonConfigLoader>,
     *     file: string,
     *     silentWhenMissing: bool,
     *     hasFallbackSwitch: bool,
     *     wrongType: string,
     *     default: Closure(): object,
     *     load: Closure(EventManager, bool, string): object,
     * }>
     */
    private static function loaderCases(): array
    {
        return [
            'servers' => [
                'class' => ServersConfigLoader::class,
                'file' => 'servers.json',
                'silentWhenMissing' => false,
                'hasFallbackSwitch' => true,
                'wrongType' => '{"s1": {"title": "S", "api_url": 123, "api_key": "k"}}',
                'default' => static fn (): object => ServersConfig::empty(),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new ServersConfigLoader(events: $events, fallbackOnFailure: $fallback))->load($root),
            ],
            'thresholds' => [
                'class' => ThresholdsConfigLoader::class,
                'file' => 'thresholds.json',
                'silentWhenMissing' => false,
                'hasFallbackSwitch' => true,
                'wrongType' => '{"alarms": {"app_unreachable": {"required_count": "abc"}}}',
                'default' => static fn (): object => ThresholdsConfig::default(),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new ThresholdsConfigLoader(events: $events, fallbackOnFailure: $fallback))->load($root),
            ],
            'ttl' => [
                'class' => TtlConfigLoader::class,
                'file' => 'ttl.json',
                'silentWhenMissing' => false,
                'hasFallbackSwitch' => true,
                'wrongType' => '{"apps": "abc"}',
                'default' => static fn (): object => TtlConfig::default(),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new TtlConfigLoader(events: $events, fallbackOnFailure: $fallback))->load($root),
            ],
            'intervals' => [
                'class' => IntervalsConfigLoader::class,
                'file' => 'intervals.json',
                'silentWhenMissing' => false,
                'hasFallbackSwitch' => true,
                'wrongType' => '{"events": {"min_event_interval_minutes": {"_default": "abc"}}}',
                'default' => static fn (): object => IntervalsConfig::default(),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new IntervalsConfigLoader(events: $events, fallbackOnFailure: $fallback))->load($root),
            ],
            'data_retention' => [
                'class' => DataRetentionConfigLoader::class,
                'file' => 'data_retention.json',
                'silentWhenMissing' => false,
                'hasFallbackSwitch' => true,
                'wrongType' => '{"metrics": {"raw_retention_days": "abc"}}',
                'default' => static fn (): object => DataRetentionConfig::default(),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new DataRetentionConfigLoader(events: $events, fallbackOnFailure: $fallback))->load($root),
            ],
            'probes' => [
                'class' => ProbesConfigLoader::class,
                'file' => 'probes.json',
                'silentWhenMissing' => true,
                'hasFallbackSwitch' => true,
                'wrongType' => '{"max_retries": "abc"}',
                'default' => static fn (): object => ProbesConfig::default(),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new ProbesConfigLoader(events: $events, fallbackOnFailure: $fallback))->load($root),
            ],
            'notification_rules' => [
                'class' => NotificationRulesConfigLoader::class,
                'file' => 'notification_rules.json',
                'silentWhenMissing' => true,
                'hasFallbackSwitch' => true,
                'wrongType' => '{"defaults": {"enabled": "yes", "channels": ["email"]}}',
                'default' => static fn (): object => NotificationRulesConfig::default(),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new NotificationRulesConfigLoader(events: $events, fallbackOnFailure: $fallback))->load($root),
            ],
            'menu' => [
                'class' => MenuConfigLoader::class,
                'file' => 'menu.json',
                'silentWhenMissing' => true,
                'hasFallbackSwitch' => true,
                'wrongType' => '{"events": "yes"}',
                'default' => static fn (): object => MenuConfig::empty(),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new MenuConfigLoader(events: $events, validKeys: MenuBuilder::MENU_KEYS, fallbackOnFailure: $fallback))->load($root),
            ],
            'charts' => [
                'class' => ChartsConfigLoader::class,
                'file' => 'charts.json',
                'silentWhenMissing' => true,
                'hasFallbackSwitch' => true,
                'wrongType' => '{"dashboard": {"metrics": "abc"}}',
                'default' => static fn (): object => ChartsConfig::empty(),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new ChartsConfigLoader(
                    events: $events,
                    metricCatalog: new MetricCatalog(),
                    queryPolicy: new MetricQueryPolicy(),
                    fallbackOnFailure: $fallback,
                ))->load($root),
            ],
            // Loaded before the aggregate (bootstrap cycle) and never throws.
            'limits' => [
                'class' => AlarmRateLimitsConfigLoader::class,
                'file' => 'limits.json',
                'silentWhenMissing' => true,
                'hasFallbackSwitch' => false,
                'wrongType' => '{"channels": {"default": {"per_minute": "abc"}}}',
                'default' => static fn (): object => AlarmRateLimitsConfig::fromArray([], static function (string $message): void {}),
                'load' => static fn (EventManager $events, bool $fallback, string $root): object => (new AlarmRateLimitsConfigLoader($events))->load($root),
            ],
        ];
    }

    /** @param array{file: string, load: Closure(EventManager, bool, string): object} $case */
    private function loadWith(array $case, ?string $contents, bool $fallbackOnFailure): object
    {
        $recorder = $this->createStub(EventRecorderService::class);
        $recorder->method('recordEvent')->willReturnCallback(function (): EventRecordResult {
            ++$this->reports;

            return new EventRecordResult(1, null, null, null, null, null);
        });

        return $this->loadIn($case, $contents, $this->eventManagerFor($recorder), $fallbackOnFailure);
    }

    /** @param array{file: string, load: Closure(EventManager, bool, string): object} $case */
    private function loadIn(array $case, ?string $contents, EventManager $events, bool $fallbackOnFailure): object
    {
        $configRoot = $this->createTempDirectory();
        $path = $configRoot . '/' . $case['file'];
        if ($contents !== null) {
            file_put_contents($path, $contents);
        }

        try {
            return $case['load']($events, $fallbackOnFailure, $configRoot);
        } finally {
            $this->cleanupTempFiles([$path, $configRoot]);
        }
    }
}
