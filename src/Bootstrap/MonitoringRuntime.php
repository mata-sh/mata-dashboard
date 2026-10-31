<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\MonitoringConfigLoadException;
use Mata\Config\Monitoring\Loader\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\Loader\ProbesConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\ThresholdsConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\MonitoringConfig;
use Mata\Config\Monitoring\MonitoringConfigLoader;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Database\DatabaseInterface;
use Mata\Models\Alarm\AlarmDeliveryRepository;
use Mata\Models\Alarm\AlarmRepository;
use Mata\Models\Event\EventRepository;
use Mata\Models\Monitor\AppFatalStateRepository;
use Mata\Models\Sync\ConfigSyncRepository;
use Mata\Services\Alarm\AlarmCreator;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\AlarmQueueFailureThrottle;
use Mata\Services\Alarm\Evaluation\AlarmThresholdCooldownService;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Runtime\DashboardEnvironmentFactory;
use Mata\Services\Sync\AppInventorySync;
use Mata\Services\Sync\ServerConfigDatabaseSync;
use Mata\Utils\PathResolver;
use Psr\Log\LoggerInterface;

/**
 * Monitoring configuration and the event/alarm services built on it, shared by the web
 * dashboard ({@see DashboardRuntime::monitoring()}) and the cron jobs.
 *
 * The default config loader is the cron one: it omits the web-only menu and chart sections
 * and disables the servers fallback so a job fails rather than processing no servers.
 */
final readonly class MonitoringRuntime
{
    public function __construct(
        public AlarmRepository $alarmRepository,
        public AlarmDeliveryRepository $deliveryRepository,
        public AlarmCreator $alarmCreator,
        public ConfigSyncRepository $configSyncRepository,
        public AppFatalStateRepository $fatalStateRepository,
        public EventRepository $eventRepository,
        public AlarmQueueFailureThrottle $queueFailureThrottle,
        public AlarmRateLimitsConfig $alarmRateLimits,
        public AlarmDirectProcessor $alarmDirectProcessor,
        public AlarmThresholdCooldownService $alarmEvaluator,
        public ServerApplicationIdResolver $idResolver,
        public AppInventorySync $appInventorySync,
        public EventRecorderService $eventRecorder,
        public EventAggregatorServiceInterface $eventAggregator,
        public DashboardEnvironmentFactory $environmentFactory,
        public EventManager $eventManager,
        public MonitoringConfig $monitoringConfig,
    ) {}

    /**
     * $configLoader defaults to {@see cronConfigLoader()}. With $syncServerConfig, servers.json is
     * persisted to the database before server IDs are resolved from it: the web request does this,
     * cron reads what it wrote.
     *
     * @throws MonitoringConfigLoadException when a loader without fallback rejects its file, after recording its problems
     */
    public static function build(
        PathResolver $paths,
        ApplicationConfig $appConfig,
        LoggerInterface $log,
        DatabaseInterface $db,
        ?MonitoringConfigLoader $configLoader = null,
        bool $syncServerConfig = false,
    ): self {
        // Repositories
        $alarmRepository = new AlarmRepository($db);
        $deliveryRepository = new AlarmDeliveryRepository($db);
        $alarmCreator = new AlarmCreator($db, $alarmRepository, $deliveryRepository);
        $configSyncRepository = new ConfigSyncRepository($db);
        $fatalStateRepository = new AppFatalStateRepository($db);
        $eventRepository = new EventRepository($db);

        // Event aggregator and monitoring config are attached once they exist.
        $idResolver = new ServerApplicationIdResolver($configSyncRepository);
        $appInventorySync = new AppInventorySync($configSyncRepository, $idResolver, $log);
        $eventRecorder = new EventRecorderService($db, $idResolver);
        $eventManager = new EventManager(appConfig: $appConfig, eventRecorder: $eventRecorder, logger: $log);

        // Alarm limits and queue-failure throttling
        $queueFailureThrottle = new AlarmQueueFailureThrottle($paths->tempPath . '/alarm-locks', $log);
        $loadedLimits = (new AlarmRateLimitsConfigLoader())->load($paths->configPath);
        $eventManager->recordConfigProblems($loadedLimits->problems);
        $alarmRateLimits = $loadedLimits->section;

        // Direct alarm processing
        $alarmDirectProcessor = new AlarmDirectProcessor(
            $alarmCreator,
            $alarmRateLimits,
            $queueFailureThrottle,
            $log,
        );
        $eventManager->setAlarmDirectProcessor($alarmDirectProcessor);

        // Event aggregation
        $environmentFactory = new DashboardEnvironmentFactory(
            $appConfig->environment->env,
            $eventRepository,
            $alarmRepository,
            $idResolver,
            $log,
            $paths->getConfigPath('demo/events.json'),
        );
        $eventAggregator = $environmentFactory->createEventAggregator();
        $eventManager->setEventAggregator($eventAggregator);

        // Monitoring configuration. Stops on a broken servers config, after recording its alarm.
        try {
            $monitoringConfigResult = ($configLoader ?? self::cronConfigLoader())->load($paths->configPath, $alarmRateLimits);
            $eventManager->recordConfigProblems($monitoringConfigResult->problems);
        } catch (MonitoringConfigLoadException $exception) {
            $eventManager->recordConfigProblems($exception->problems);

            throw $exception;
        }
        $monitoringConfig = $monitoringConfigResult->section;

        $eventManager->setEventIntervals($monitoringConfig->intervals);

        if ($syncServerConfig) {
            (new ServerConfigDatabaseSync($db, $log, $eventManager, $configSyncRepository))
                ->syncIfNeeded($paths->configPath . '/servers.json', $monitoringConfig->servers->values);
        }
        $idResolver->refreshFromRepository($monitoringConfig->servers->values);

        // Alarm evaluation
        $alarmEvaluator = new AlarmThresholdCooldownService($eventAggregator, $eventRecorder, $appConfig);
        $alarmEvaluator
            ->setAlarmRules($monitoringConfig->thresholds)
            ->setAlarmIntervals($monitoringConfig->intervals);

        return new self(
            alarmRepository: $alarmRepository,
            deliveryRepository: $deliveryRepository,
            alarmCreator: $alarmCreator,
            configSyncRepository: $configSyncRepository,
            fatalStateRepository: $fatalStateRepository,
            eventRepository: $eventRepository,
            queueFailureThrottle: $queueFailureThrottle,
            alarmRateLimits: $alarmRateLimits,
            alarmDirectProcessor: $alarmDirectProcessor,
            alarmEvaluator: $alarmEvaluator,
            idResolver: $idResolver,
            appInventorySync: $appInventorySync,
            eventRecorder: $eventRecorder,
            eventAggregator: $eventAggregator,
            environmentFactory: $environmentFactory,
            eventManager: $eventManager,
            monitoringConfig: $monitoringConfig,
        );
    }

    /**
     * Loaders for jobs outside the web dashboard: no menu or charts, and no servers fallback.
     */
    public static function cronConfigLoader(): MonitoringConfigLoader
    {
        return new MonitoringConfigLoader(
            servers: new ServersConfigLoader(fallbackOnFailure: false),
            ttl: new TtlConfigLoader(fallbackOnFailure: true),
            dataRetention: new DataRetentionConfigLoader(fallbackOnFailure: true),
            intervals: new IntervalsConfigLoader(fallbackOnFailure: true),
            probes: new ProbesConfigLoader(fallbackOnFailure: true),
            notificationRules: new NotificationRulesConfigLoader(fallbackOnFailure: true),
            thresholds: new ThresholdsConfigLoader(fallbackOnFailure: true),
        );
    }
}
