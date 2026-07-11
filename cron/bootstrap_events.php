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
 * - $lockManager           Channel-based locking for alarms
 * - $alarmLimits           Rate limiting configuration
 * - $alarmManager          Alarm delivery orchestrator
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
use Mata\Services\Alarm\AlarmEvaluationService;
use Mata\Services\Alarm\AlarmCreator;
use Mata\Services\Alarm\AlarmManager;
use Mata\Services\Event\EventAggregatorService;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Event\MockEventAggregatorService;
use Mata\Services\Foundation\IdResolver;
use Mata\Utils\ChannelLockManager;

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
// Limits & Locking
// ************************************************************************************************
$lockManager = new ChannelLockManager($paths->tempPath . '/alarm-locks', $log);
$limitsLoader = new AlarmLimitsLoader($log);
$alarmLimits = $limitsLoader->load($paths->appPath . '/config/limits.json');

// ************************************************************************************************
// Alarm Manager
// ************************************************************************************************
$alarmManager = new AlarmManager(
    $alarmCreator,
    $alarmLimits,
    $lockManager,
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

$eventManager = (new EventManager($appConfig, $alarmManager))
    ->setEventRecorder($eventRecorder)
    ->setEventAggregator($eventAggregator);

// ************************************************************************************************
// Alarm Evaluation Service
// ************************************************************************************************
$alarmEvaluator = new AlarmEvaluationService(
    $eventAggregator,
    $eventRecorder,
    $appConfig
);

// ************************************************************************************************
// Monitoring Settings
// ************************************************************************************************
$monitoringSettingsLoader = new MonitoringSettingsLoader($eventRecorder, $alarmManager);
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
    'lockManager',
    'alarmLimits',
    'alarmManager',
    'alarmEvaluator',
    'idResolver',
    'eventRecorder',
    'eventAggregator',
    'eventManager',
    'monitoringSettings'
);
