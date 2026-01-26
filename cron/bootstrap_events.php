<?php

declare(strict_types=1);

/**
 * Event & Alert Management Bootstrap for Cronjobs.
 *
 * Provides common event/alert infrastructure used by multiple cronjobs.
 * Include this AFTER bootstrap.php for cronjobs that need EventManager.
 *
 * Exports:
 * - $alertQueueRepository  Alert queue persistence
 * - $configSyncRepository  Config synchronization
 * - $eventRepository       Event persistence
 * - $lockManager           Channel-based locking for alerts
 * - $alertLimits           Rate limiting configuration
 * - $alertManager          Alert delivery orchestrator
 * - $alertEvaluator        Alert evaluation service (alarm decision logic)
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

use Mata\Config\AlertLimitsLoader;
use Mata\Config\MonitoringSettingsLoader;
use Mata\Models\AlertQueueRepository;
use Mata\Models\ConfigSyncRepository;
use Mata\Models\EventRepository;
use Mata\Services\Alert\AlertEvaluationService;
use Mata\Services\Alert\AlertManager;
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
$alertQueueRepository = new AlertQueueRepository($db);
$configSyncRepository = new ConfigSyncRepository($db);
$eventRepository = new EventRepository($db);

// ************************************************************************************************
// Limits & Locking
// ************************************************************************************************
$lockManager = new ChannelLockManager($paths->tempPath . '/alert-locks', $log);
$limitsLoader = new AlertLimitsLoader($log);
$alertLimits = $limitsLoader->load($paths->appPath . '/config/limits.json');

// ************************************************************************************************
// Alert Manager
// ************************************************************************************************
$alertManager = new AlertManager(
    $alertQueueRepository,
    $alertLimits,
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
    $eventAggregator = new EventAggregatorService($eventRepository, $idResolver, $log);
}

$eventManager = (new EventManager($appConfig, $alertManager))
    ->setEventRecorder($eventRecorder)
    ->setEventAggregator($eventAggregator);

// ************************************************************************************************
// Alert Evaluation Service
// ************************************************************************************************
$alertEvaluator = new AlertEvaluationService(
    $eventAggregator,
    $eventRecorder,
    $appConfig
);

// ************************************************************************************************
// Monitoring Settings
// ************************************************************************************************
$monitoringSettingsLoader = new MonitoringSettingsLoader($eventRecorder, $alertManager);
$monitoringSettings = $monitoringSettingsLoader->loadAllConfigs(
    $paths->appPath . '/config',
    $appConfig->environment->env
);
$eventManager->setMonitoringSettings($monitoringSettings);
$alertEvaluator->setMonitoringSettings($monitoringSettings);

// ************************************************************************************************
// Final ID Resolution Sync
// ************************************************************************************************
$idResolver->refreshFromRepository($monitoringSettings['servers'] ?? []);

return compact(
    'alertQueueRepository',
    'configSyncRepository',
    'eventRepository',
    'lockManager',
    'alertLimits',
    'alertManager',
    'alertEvaluator',
    'idResolver',
    'eventRecorder',
    'eventAggregator',
    'eventManager',
    'monitoringSettings'
);
