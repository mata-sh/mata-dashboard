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

use Watcher\Config\AlertLimitsLoader;
use Watcher\Config\MonitoringSettingsLoader;
use Watcher\Models\AlertQueueRepository;
use Watcher\Models\ConfigSyncRepository;
use Watcher\Models\EventRepository;
use Watcher\Services\Foundation\AlertEvaluationService;
use Watcher\Services\Foundation\AlertManager;
use Watcher\Services\Foundation\EventAggregatorService;
use Watcher\Services\Foundation\EventManager;
use Watcher\Services\Foundation\EventRecorderService;
use Watcher\Services\Foundation\IdResolver;
use Watcher\Utils\ChannelLockManager;

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
$lockManager = new ChannelLockManager($paths->tempPath . '/alert_locks', $log);
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
$eventAggregator = new EventAggregatorService($eventRepository, $idResolver);

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
