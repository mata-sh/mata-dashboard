<?php

declare(strict_types=1);

namespace Mata\Services\Runtime;

use Mata\Models\AlarmRepository;
use Mata\Models\EventRepository;
use Mata\Services\Event\EventAggregatorService;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\MockEventAggregatorService;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\Dto\ServerMonitoringResult;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Psr\Log\LoggerInterface;

/** Builds dashboard dependencies for the configured environment. */
final readonly class DashboardEnvironmentFactory
{
    private const LIVE_API_TIMEOUT_SECONDS = 10;

    public function __construct(
        private string $environment,
        private EventRepository $eventRepository,
        private AlarmRepository $alarmRepository,
        private ServerApplicationIdResolver $idResolver,
        private LoggerInterface $logger,
        private string $demoEventDataPath,
    ) {}

    public function createEventAggregator(): EventAggregatorServiceInterface
    {
        if ($this->environment !== 'demo') {
            return new EventAggregatorService($this->eventRepository, $this->alarmRepository, $this->idResolver);
        }

        $demoData = MockEventAggregatorService::loadDemoData($this->demoEventDataPath, $this->logger);
        $demoData = MockEventAggregatorService::withNotificationShowcase($demoData);
        $this->logger->info('Using demo dashboard environment.', ['event_data_path' => $this->demoEventDataPath]);

        return new MockEventAggregatorService($this->logger, $demoData);
    }

    /** @param array<string,mixed> $serverSettings */
    public function createMonitoring(
        array $serverSettings,
        ServerMonitoringFactory $monitoringFactory,
    ): ServerMonitoringResult {
        if ($this->environment === 'demo') {
            return $monitoringFactory->createDemoAll($serverSettings);
        }

        return $monitoringFactory->createLiveAll($serverSettings, self::LIVE_API_TIMEOUT_SECONDS);
    }
}
