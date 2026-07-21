<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Exception;
use InvalidArgumentException;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\Dto\MonitoringPolicy;
use Mata\Services\Monitor\Dto\ServerMonitoringResult;
use Mata\Services\Monitor\Dto\ServerMonitoringServices;
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
        private MonitoringPolicy $policy,
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
            appMonitor: new AppMonitor(
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $this->probeClient,
                $this->eventManager,
                $this->logger,
                $this->policy,
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
            appMonitor: new MockAppMonitor(),
            healthMonitor: new MockNodeHealthMonitor(),
        );
    }
}
