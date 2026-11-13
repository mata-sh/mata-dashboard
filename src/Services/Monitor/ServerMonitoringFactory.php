<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Exception;
use InvalidArgumentException;
use Mata\Config\Dto\DemoConfig;
use Mata\Config\Monitoring\Section\ServerConfig;
use Mata\Models\Monitor\AppFatalStateRepository;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Event\EventManager;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\Dto\AppFatalErrorPolicy;
use Mata\Services\Monitor\Dto\AppLogSizePolicy;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\Dto\ServerMonitoringResult;
use Mata\Services\Monitor\Dto\ServerMonitoringServices;
use Mata\Views\Support\NumberFormatter;
use Psr\Log\LoggerInterface;

/** Builds monitoring dependencies for one configured server. */
final readonly class ServerMonitoringFactory
{
    public function __construct(
        private LoggerInterface $logger,
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
        private EventManager $eventManager,
        private NodeResponseNormalizer $responseNormalizer,
        private HttpProbeClient $probeClient,
        private AppReachabilityPolicy $policy,
        private ServerApplicationIdResolver $idResolver,
        private AppFatalStateRepository $fatalStateRepository,
        private LogSizeEvaluator $logSizeEvaluator,
        private DemoConfig $demo = new DemoConfig(),
    ) {}

    /** @param array<string,ServerConfig> $servers */
    public function createLiveAll(array $servers, int $apiTimeoutSeconds): ServerMonitoringResult
    {
        return $this->createAll(
            $servers,
            fn (string $server, ServerConfig $serverConfig): ServerMonitoringServices => $this->createLive(
                $server,
                $serverConfig,
                $apiTimeoutSeconds,
            ),
        );
    }

    /** @param array<string,ServerConfig> $servers */
    public function createDemoAll(array $servers): ServerMonitoringResult
    {
        return $this->createAll(
            $servers,
            fn (string $server, ServerConfig $serverConfig): ServerMonitoringServices => $this->createDemo(
                $server,
                $serverConfig,
            ),
        );
    }

    /**
     * @param array<string,ServerConfig>                             $servers
     * @param callable(string,ServerConfig):ServerMonitoringServices $creator
     */
    private function createAll(array $servers, callable $creator): ServerMonitoringResult
    {
        $servicesByServer = [];
        $failures = [];

        foreach ($servers as $server => $serverConfig) {
            $server = (string) $server;

            try {
                $servicesByServer[$server] = $creator($server, $serverConfig);
            } catch (Exception $e) {
                $this->logger->error("Failed to initialize monitoring for server {$server}: " . $e->getMessage(), ['exception' => $e]);
                $failures[$server] = $e->getMessage();
            }
        }

        return new ServerMonitoringResult($servicesByServer, $failures);
    }

    public function createLive(string $server, ServerConfig $serverConfig, int $apiTimeoutSeconds): ServerMonitoringServices
    {
        if (trim($serverConfig->apiUrl) === '' || trim($serverConfig->apiKey) === '') {
            throw new InvalidArgumentException("Server {$server} missing required api_url or api_key config");
        }

        $apiClient = new NodeApiClient(
            logger: $this->logger,
            apiBase: $serverConfig->apiUrl,
            server: $server,
            apiKey: $serverConfig->apiKey,
            timeout: $apiTimeoutSeconds,
            verifySSL: true,
            allowHttp: $serverConfig->allowHttp,
            suppressHttpWarning: $serverConfig->suppressHttpWarning,
        );

        return new ServerMonitoringServices(
            collector: new ServerDataCollector(
                $this->logger,
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $this->responseNormalizer,
            ),
            reachabilityMonitor: new AppReachabilityMonitor(
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $this->probeClient,
                $this->eventManager,
                $this->logger,
                $this->policy,
            ),
            errorMonitor: new AppErrorMonitor(
                $this->eventManager,
                $this->idResolver,
                $this->fatalStateRepository,
                AppFatalErrorPolicy::fromServerConfig($serverConfig),
                AppLogSizePolicy::fromServerConfig($serverConfig),
                $this->logSizeEvaluator,
                $this->logger,
                new NumberFormatter(),
            ),
            healthMonitor: new NodeHealthMonitor(
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $this->probeClient,
                $this->eventManager,
                $this->logger,
                $this->policy->probe,
            ),
        );
    }

    public function createDemo(string $server, ServerConfig $serverConfig): ServerMonitoringServices
    {
        return new ServerMonitoringServices(
            collector: new MockServerDataCollector(
                $this->logger,
                $this->ttlResolver,
                $this->responseNormalizer,
                $server,
                $serverConfig,
                $this->demo,
            ),
            reachabilityMonitor: new MockAppReachabilityMonitor(),
            errorMonitor: new MockAppErrorMonitor(),
            healthMonitor: new MockNodeHealthMonitor(),
        );
    }
}
