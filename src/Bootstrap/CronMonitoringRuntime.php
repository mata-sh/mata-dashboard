<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Mata\Config\Dto\AlarmLimitsConfig;
use Mata\Config\Monitoring\AlarmLimitsConfigLoader;
use Mata\Config\Monitoring\DataRetentionConfigLoader;
use Mata\Config\Monitoring\IntervalsConfigLoader;
use Mata\Config\Monitoring\MonitoringConfigManager;
use Mata\Config\Monitoring\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\ProbesConfigLoader;
use Mata\Config\Monitoring\ServersConfigLoader;
use Mata\Config\Monitoring\ThresholdsConfigLoader;
use Mata\Config\Monitoring\TtlConfigLoader;
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
 */
final readonly class CronMonitoringRuntime
{
    /** @param array<string,mixed> $monitoringSettings */
    public function __construct(
        public AlarmRepository $alarmRepository,
        public AlarmDeliveryRepository $deliveryRepository,
        public AlarmCreator $alarmCreator,
        public ConfigSyncRepository $configSyncRepository,
        public AppFatalStateRepository $fatalStateRepository,
        public EventRepository $eventRepository,
        public AlarmQueueFailureThrottle $queueFailureThrottle,
        public AlarmLimitsConfig $alarmLimits,
        public AlarmDirectProcessor $alarmDirectProcessor,
        public AlarmThresholdCooldownService $alarmEvaluator,
        public ServerApplicationIdResolver $idResolver,
        public EventRecorderService $eventRecorder,
        public EventAggregatorServiceInterface $eventAggregator,
        public EventManager $eventManager,
        public array $monitoringSettings,
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

        // Alarm limits and queue-failure throttling
        $queueFailureThrottle = new AlarmQueueFailureThrottle($paths->tempPath . '/alarm-locks', $log);
        $alarmLimits = (new AlarmLimitsConfigLoader($log))->load($paths->appPath . '/config/limits.json');

        // Direct alarm processing
        $alarmDirectProcessor = new AlarmDirectProcessor(
            $alarmCreator,
            $alarmLimits,
            $queueFailureThrottle,
            $log,
        );

        // Event services
        $idResolver = new ServerApplicationIdResolver($configSyncRepository);
        $eventRecorder = new EventRecorderService($db, $idResolver);
        $eventAggregator = (new DashboardEnvironmentFactory(
            $appConfig->environment->env,
            $eventRepository,
            $alarmRepository,
            $idResolver,
            $log,
            $paths->appPath . '/config/demo/events.json',
        ))->createEventAggregator();

        $eventManager = new EventManager(
            appConfig: $appConfig,
            alarmDirectProcessor: $alarmDirectProcessor,
            eventRecorder: $eventRecorder,
            eventAggregator: $eventAggregator,
        );

        // Monitoring settings
        $monitoringSettings = (new MonitoringConfigManager([
            new ServersConfigLoader(
                eventRecorder: $eventRecorder,
                alarmDirectProcessor: $alarmDirectProcessor,
                fallbackOnFailure: false,
            ),
            new TtlConfigLoader(eventRecorder: $eventRecorder, fallbackOnFailure: true),
            new DataRetentionConfigLoader(eventRecorder: $eventRecorder, fallbackOnFailure: true),
            new IntervalsConfigLoader(eventRecorder: $eventRecorder, fallbackOnFailure: true),
            new ProbesConfigLoader(eventRecorder: $eventRecorder, fallbackOnFailure: true),
            new NotificationRulesConfigLoader(eventRecorder: $eventRecorder, fallbackOnFailure: true),
            new ThresholdsConfigLoader(
                eventRecorder: $eventRecorder,
                alarmDirectProcessor: $alarmDirectProcessor,
                fallbackOnFailure: true,
            ),
        ]))->loadAllConfigs($paths->appPath . '/config', $appConfig->environment->env);

        $eventManager->setMonitoringSettings($monitoringSettings);
        $idResolver->refreshFromRepository($monitoringSettings['servers'] ?? []);

        // Alarm evaluation
        $alarmEvaluator = new AlarmThresholdCooldownService($eventAggregator, $eventRecorder, $appConfig);
        $alarmEvaluator->setMonitoringSettings($monitoringSettings);

        return new self(
            alarmRepository: $alarmRepository,
            deliveryRepository: $deliveryRepository,
            alarmCreator: $alarmCreator,
            configSyncRepository: $configSyncRepository,
            fatalStateRepository: $fatalStateRepository,
            eventRepository: $eventRepository,
            queueFailureThrottle: $queueFailureThrottle,
            alarmLimits: $alarmLimits,
            alarmDirectProcessor: $alarmDirectProcessor,
            alarmEvaluator: $alarmEvaluator,
            idResolver: $idResolver,
            eventRecorder: $eventRecorder,
            eventAggregator: $eventAggregator,
            eventManager: $eventManager,
            monitoringSettings: $monitoringSettings,
        );
    }

    /**
     * Return a monitoring section, or an empty array if it is absent or invalid.
     *
     * @return array<string,mixed>
     */
    public function settingsSection(string $key): array
    {
        $section = $this->monitoringSettings[$key] ?? [];

        return is_array($section) ? $section : [];
    }
}
