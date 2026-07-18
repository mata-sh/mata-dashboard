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

use Mata\Config\AlarmLimitsLoader;
use Mata\Config\MonitoringSettingsLoader;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Models\AlarmRepository;
use Mata\Models\ConfigSyncRepository;
use Mata\Models\EventRepository;
use Mata\Services\Alarm\AlarmThresholdCooldownService;
use Mata\Services\Alarm\AlarmCreator;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Event\EventAggregatorService;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Event\MockEventAggregatorService;
use Mata\Services\Foundation\IdResolver;
use Mata\Services\Alarm\AlarmQueueFailureThrottle;

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
$eventRepository = new EventRepository($db);

// ************************************************************************************************
// Limits & Queue-Failure Throttling
// ************************************************************************************************
$queueFailureThrottle = new AlarmQueueFailureThrottle($paths->tempPath . '/alarm-locks', $log);
$limitsLoader = new AlarmLimitsLoader($log);
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
$idResolver = new IdResolver($configSyncRepository);
$eventRecorder = new EventRecorderService($db, $idResolver);

$useMockEvents = (getenv('MATA_EVENTS_MOCK') === '1' || $appConfig->environment->env === 'demo');
if ($useMockEvents) {
    $demoDataPath = getenv('MATA_EVENTS_MOCK_DATA_PATH') ?: $paths->appPath . '/config/demo/events.json';
    $demoData = MockEventAggregatorService::loadDemoData($demoDataPath, $log);
    $eventAggregator = new MockEventAggregatorService($log, $demoData);
    $log->info('Using mock event aggregator for demo data.', ['path' => $demoDataPath]);
} else {
    $eventAggregator = new EventAggregatorService($eventRepository, $alarmRepository, $idResolver, $log);
}

$eventManager = (new EventManager($appConfig, $alarmDirectProcessor))
    ->setEventRecorder($eventRecorder)
    ->setEventAggregator($eventAggregator);

// ************************************************************************************************
// Alarm Evaluation Service
// ************************************************************************************************
$alarmEvaluator = new AlarmThresholdCooldownService(
    $eventAggregator,
    $eventRecorder,
    $appConfig
);

// ************************************************************************************************
// Monitoring Settings
// ************************************************************************************************
$monitoringSettingsLoader = new MonitoringSettingsLoader($eventRecorder, $alarmDirectProcessor);
$monitoringSettings = $monitoringSettingsLoader->loadAllConfigs(
    $paths->appPath . '/config',
    $appConfig->environment->env
);
$eventManager->setMonitoringSettings($monitoringSettings);
$alarmEvaluator->setMonitoringSettings($monitoringSettings);

// ************************************************************************************************
// Final ID Resolution Sync
// ************************************************************************************************
$idResolver->refreshFromRepository($monitoringSettings['servers'] ?? []);

return compact(
    'deliveryRepository',
    'alarmRepository',
    'alarmCreator',
    'configSyncRepository',
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
