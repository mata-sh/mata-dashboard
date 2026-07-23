<?php

declare(strict_types=1);

/**
 * Event & Alarm Management Bootstrap for Cronjobs.
 *
 * Provides common event/alarm infrastructure used by multiple cronjobs.
 * Include this AFTER bootstrap.php for cronjobs that need EventManager.
 *
 * Exports:
 * - $deliveryRepository  Alarm delivery persistence
 * - $configSyncRepository  Config synchronization
 * - $fatalStateRepository  Application fatal-state persistence
 * - $eventRepository       Event persistence
 * - $queueFailureThrottle  Queue-failure throttling for direct alarms
 * - $alarmLimits           Rate limiting configuration
 * - $alarmDirectProcessor  Forced direct-alarm processor
 * - $alarmEvaluator        Alarm evaluation service (alarm decision logic)
 * - $idResolver            Server/app ID resolution
 * - $eventRecorder         Event recording service
 * - $eventAggregator       Event querying service
 * - $eventManager          Event management facade (fully configured)
 * - $monitoringSettings    Loaded monitoring configuration
 *
 * Usage:
 *   extract(require_once __DIR__ . '/bootstrap.php');
 *   extract(require_once __DIR__ . '/bootstrap_events.php');
 */

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
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Persistence\Identity\ServerApplicationIdResolver;
use Mata\Services\Runtime\DashboardEnvironmentFactory;

// Ensure bootstrap.php was loaded first
if (!isset($db, $appConfig, $paths, $log)) {
    throw new RuntimeException('bootstrap_events.php requires bootstrap.php to be loaded first');
}

// ************************************************************************************************
// Repositories
// ************************************************************************************************
$alarmRepository = new AlarmRepository($db);
$deliveryRepository = new AlarmDeliveryRepository($db);
$alarmCreator = new AlarmCreator($db, $alarmRepository, $deliveryRepository);
$configSyncRepository = new ConfigSyncRepository($db);
$fatalStateRepository = new AppFatalStateRepository($db);
$eventRepository = new EventRepository($db);

// ************************************************************************************************
// Limits & Queue-Failure Throttling
// ************************************************************************************************
$queueFailureThrottle = new AlarmQueueFailureThrottle($paths->tempPath . '/alarm-locks', $log);
$limitsLoader = new AlarmLimitsConfigLoader($log);
$alarmLimits = $limitsLoader->load($paths->appPath . '/config/limits.json');

// ************************************************************************************************
// Direct Alarm Processor
// ************************************************************************************************
$alarmDirectProcessor = new AlarmDirectProcessor(
    $alarmCreator,
    $alarmLimits,
    $queueFailureThrottle,
    $log
);

// ************************************************************************************************
// Resolvers & Event Services
// ************************************************************************************************
$idResolver = new ServerApplicationIdResolver($configSyncRepository);
$eventRecorder = new EventRecorderService($db, $idResolver);
$dashboardEnvironmentFactory = new DashboardEnvironmentFactory(
    $appConfig->environment->env,
    $eventRepository,
    $alarmRepository,
    $idResolver,
    $log,
    $paths->appPath . '/config/demo/events.json',
);
$eventAggregator = $dashboardEnvironmentFactory->createEventAggregator();
$eventManager = new EventManager(
    appConfig: $appConfig,
    alarmDirectProcessor: $alarmDirectProcessor,
    eventRecorder: $eventRecorder,
    eventAggregator: $eventAggregator,
);

// ************************************************************************************************
// Monitoring Settings
// ************************************************************************************************
$monitoringConfigManager = new MonitoringConfigManager([
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
]);
$monitoringSettings = $monitoringConfigManager->loadAllConfigs(
    $paths->appPath . '/config',
    $appConfig->environment->env
);
$eventManager->setMonitoringSettings($monitoringSettings);
$idResolver->refreshFromRepository($monitoringSettings['servers'] ?? []);

// ************************************************************************************************
// Alarm Evaluation Service
// ************************************************************************************************
$alarmEvaluator = new AlarmThresholdCooldownService(
    $eventAggregator,
    $eventRecorder,
    $appConfig
);
$alarmEvaluator->setMonitoringSettings($monitoringSettings);

return compact(
    'deliveryRepository',
    'alarmRepository',
    'alarmCreator',
    'configSyncRepository',
    'fatalStateRepository',
    'eventRepository',
    'queueFailureThrottle',
    'alarmLimits',
    'alarmDirectProcessor',
    'alarmEvaluator',
    'idResolver',
    'eventRecorder',
    'eventAggregator',
    'eventManager',
    'monitoringSettings'
);
