<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Exception;
use InvalidArgumentException;
use Mata\Models\AppFatalStateRepository;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\IdResolver;
use Mata\Services\Foundation\LogSizeEvaluator;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\Dto\AppFatalErrorPolicy;
use Mata\Services\Monitor\Dto\AppLogSizePolicy;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\Dto\ServerMonitoringResult;
use Mata\Services\Monitor\Dto\ServerMonitoringServices;
use Mata\Utils\NumberFormatter;
use Mata\Utils\TypeConverter;
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
        private ApiResponseTransformer $dataTransformer,
        private ProbeClient $probeClient,
        private AppReachabilityPolicy $policy,
        private IdResolver $idResolver,
        private AppFatalStateRepository $fatalStateRepository,
        private LogSizeEvaluator $logSizeEvaluator,
    ) {}

    /** @param array<string,mixed> $servers */
    public function createLiveAll(array $servers, int $apiTimeoutSeconds): ServerMonitoringResult
    {
        return $this->createAll(
            $servers,
            fn (string $server, array $serverConfig): ServerMonitoringServices => $this->createLive(
                $server,
                $serverConfig,
                $apiTimeoutSeconds,
            ),
        );
    }

    /** @param array<string,mixed> $servers */
    public function createDemoAll(array $servers): ServerMonitoringResult
    {
        return $this->createAll(
            $servers,
            fn (string $server, array $serverConfig): ServerMonitoringServices => $this->createDemo(
                $server,
                $serverConfig,
            ),
        );
    }

    /**
     * @param array<string,mixed>                                           $servers
     * @param callable(string,array<string,mixed>):ServerMonitoringServices $creator
     */
    private function createAll(array $servers, callable $creator): ServerMonitoringResult
    {
        $servicesByServer = [];
        $failures = [];

        foreach ($servers as $server => $serverConfig) {
            $server = (string) $server;

            try {
                if (!is_array($serverConfig)) {
                    throw new InvalidArgumentException("Server {$server} configuration must be an array");
                }

                $servicesByServer[$server] = $creator($server, $serverConfig);
            } catch (Exception $e) {
                $this->logger->error("Failed to initialize monitoring for server {$server}: " . $e->getMessage(), ['exception' => $e]);
                $failures[$server] = $e->getMessage();
            }
        }

        return new ServerMonitoringResult($servicesByServer, $failures);
    }

    /** @param array<string,mixed> $serverConfig */
    public function createLive(string $server, array $serverConfig, int $apiTimeoutSeconds): ServerMonitoringServices
    {
        $apiUrl = $serverConfig['api_url'] ?? null;
        $apiKey = $serverConfig['api_key'] ?? null;
        if (!is_string($apiUrl) || trim($apiUrl) === '' || !is_string($apiKey) || trim($apiKey) === '') {
            throw new InvalidArgumentException("Server {$server} missing required api_url or api_key config");
        }

        $apiClient = new ApiClient(
            logger: $this->logger,
            apiBase: $apiUrl,
            server: $server,
            apiKey: $apiKey,
            timeout: $apiTimeoutSeconds,
            verifySSL: true,
            allowHttp: TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false,
            suppressHttpWarning: TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false,
        );

        return new ServerMonitoringServices(
            collector: new ServerDataCollector(
                $this->logger,
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $this->dataTransformer,
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

    /** @param array<string,mixed> $serverConfig */
    public function createDemo(string $server, array $serverConfig): ServerMonitoringServices
    {
        return new ServerMonitoringServices(
            collector: new MockServerDataCollector(
                $this->logger,
                $this->ttlResolver,
                $this->dataTransformer,
                $server,
                $serverConfig,
            ),
            reachabilityMonitor: new MockAppReachabilityMonitor(),
            errorMonitor: new MockAppErrorMonitor(),
            healthMonitor: new MockNodeHealthMonitor(),
        );
    }
}
