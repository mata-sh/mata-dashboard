<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\Loader\ProbesConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\ThresholdsConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\MonitoringConfig;
use Mata\Config\Monitoring\MonitoringConfigLoader;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Models\AlarmRepository;
use Mata\Models\AppFatalStateRepository;
use Mata\Models\ConfigSyncRepository;
use Mata\Models\EventRepository;
use Mata\Services\Alarm\AlarmCreator;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\AlarmQueueFailureThrottle;
use Mata\Services\Alarm\AlarmThresholdCooldownService;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Runtime\DashboardEnvironmentFactory;

/**
 * Monitoring configuration and event/alarm services for a cron job.
 *
 * Builds on the shared infrastructure initialized by {@see CronRuntime}.
 * Omits web-only menu and chart configuration and server config sync.
 * Disables server fallback so cron fails rather than processing no servers.
 */
final readonly class CronMonitoringRuntime
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
        public EventRecorderService $eventRecorder,
        public EventAggregatorServiceInterface $eventAggregator,
        public EventManager $eventManager,
        public MonitoringConfig $monitoringConfig,
    ) {}

    /** Create the monitoring runtime from an initialized cron runtime. */
    public static function fromRuntime(CronRuntime $runtime): self
    {
        $db = $runtime->db;
        $log = $runtime->log;
        $paths = $runtime->paths;
        $appConfig = $runtime->appConfig;

        // Repositories
        $alarmRepository = new AlarmRepository($db);
        $deliveryRepository = new AlarmDeliveryRepository($db);
        $alarmCreator = new AlarmCreator($db, $alarmRepository, $deliveryRepository);
        $configSyncRepository = new ConfigSyncRepository($db);
        $fatalStateRepository = new AppFatalStateRepository($db);
        $eventRepository = new EventRepository($db);

        // Early initialization for config loaders.
        // Event aggregator and monitoring config are attached once they exist.
        $idResolver = new ServerApplicationIdResolver($configSyncRepository);
        $eventRecorder = new EventRecorderService($db, $idResolver);
        $eventManager = new EventManager(appConfig: $appConfig, eventRecorder: $eventRecorder, logger: $log);

        // Alarm limits and queue-failure throttling
        $queueFailureThrottle = new AlarmQueueFailureThrottle($paths->tempPath . '/alarm-locks', $log);
        $alarmLimits = (new AlarmRateLimitsConfigLoader($eventManager))->load($paths->appPath . '/config');

        // Direct alarm processing
        $alarmDirectProcessor = new AlarmDirectProcessor(
            $alarmCreator,
            $alarmLimits,
            $queueFailureThrottle,
            $log,
        );
        $eventManager->setAlarmDirectProcessor($alarmDirectProcessor);

        // Event aggregation
        $eventAggregator = (new DashboardEnvironmentFactory(
            $appConfig->environment->env,
            $eventRepository,
            $alarmRepository,
            $idResolver,
            $log,
            $paths->appPath . '/config/demo/events.json',
        ))->createEventAggregator();
        $eventManager->setEventAggregator($eventAggregator);

        // Monitoring configuration
        $monitoringConfig = (new MonitoringConfigLoader(
            servers: new ServersConfigLoader(events: $eventManager, fallbackOnFailure: false),
            ttl: new TtlConfigLoader(events: $eventManager, fallbackOnFailure: true),
            dataRetention: new DataRetentionConfigLoader(events: $eventManager, fallbackOnFailure: true),
            intervals: new IntervalsConfigLoader(events: $eventManager, fallbackOnFailure: true),
            probes: new ProbesConfigLoader(events: $eventManager, fallbackOnFailure: true),
            notificationRules: new NotificationRulesConfigLoader(events: $eventManager, fallbackOnFailure: true),
            thresholds: new ThresholdsConfigLoader(events: $eventManager, fallbackOnFailure: true),
        ))->load($paths->appPath . '/config', $alarmLimits);

        $eventManager->setMonitoringConfig($monitoringConfig);
        $idResolver->refreshFromRepository($monitoringConfig->servers->values);

        // Alarm evaluation
        $alarmEvaluator = new AlarmThresholdCooldownService($eventAggregator, $eventRecorder, $appConfig);
        $alarmEvaluator->setMonitoringConfig($monitoringConfig);

        return new self(
            alarmRepository: $alarmRepository,
            deliveryRepository: $deliveryRepository,
            alarmCreator: $alarmCreator,
            configSyncRepository: $configSyncRepository,
            fatalStateRepository: $fatalStateRepository,
            eventRepository: $eventRepository,
            queueFailureThrottle: $queueFailureThrottle,
            alarmRateLimits: $alarmLimits,
            alarmDirectProcessor: $alarmDirectProcessor,
            alarmEvaluator: $alarmEvaluator,
            idResolver: $idResolver,
            eventRecorder: $eventRecorder,
            eventAggregator: $eventAggregator,
            eventManager: $eventManager,
            monitoringConfig: $monitoringConfig,
        );
    }
}
