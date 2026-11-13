<?php

declare(strict_types=1);

namespace Mata\Services\Runtime;

use Mata\Config\Monitoring\Section\ServerConfig;
use Mata\Models\Alarm\AlarmRepository;
use Mata\Models\Event\EventRepository;
use Mata\Services\Event\EventAggregatorService;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\MockEventAggregatorService;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\Dto\ServerMonitoringResult;
use Mata\Services\Monitor\Dto\ServerMonitoringServices;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Psr\Log\LoggerInterface;

/** Builds dashboard dependencies for the configured environment. */
final readonly class DashboardEnvironmentFactory
{
    private const LIVE_API_TIMEOUT_SECONDS = 10;

    public function __construct(
        private bool $demo,
        private EventRepository $eventRepository,
        private AlarmRepository $alarmRepository,
        private ServerApplicationIdResolver $idResolver,
        private LoggerInterface $logger,
        private string $demoEventDataPath,
    ) {}

    public function createEventAggregator(): EventAggregatorServiceInterface
    {
        if (!$this->demo) {
            return new EventAggregatorService($this->eventRepository, $this->alarmRepository, $this->idResolver);
        }

        $demoData = MockEventAggregatorService::loadDemoData($this->demoEventDataPath, $this->logger);
        $demoData = MockEventAggregatorService::withNotificationShowcase($demoData);
        $this->logger->info('Using demo dashboard environment.', ['event_data_path' => $this->demoEventDataPath]);

        return new MockEventAggregatorService($this->logger, $demoData);
    }

    /** @param array<string,ServerConfig> $serversConfig */
    public function createMonitoring(
        array $serversConfig,
        ServerMonitoringFactory $monitoringFactory,
    ): ServerMonitoringResult {
        if ($this->demo) {
            return $monitoringFactory->createDemoAll($serversConfig);
        }

        return $monitoringFactory->createLiveAll($serversConfig, self::LIVE_API_TIMEOUT_SECONDS);
    }

    /**
     * The monitoring services of one server; cron builds them one at a time so a server that
     * fails to initialize does not stop the others.
     */
    public function createServerMonitoring(
        string $server,
        ServerConfig $serverConfig,
        ServerMonitoringFactory $monitoringFactory,
        int $apiTimeoutSeconds,
    ): ServerMonitoringServices {
        if ($this->demo) {
            return $monitoringFactory->createDemo($server, $serverConfig);
        }

        return $monitoringFactory->createLive($server, $serverConfig, $apiTimeoutSeconds);
    }
}
