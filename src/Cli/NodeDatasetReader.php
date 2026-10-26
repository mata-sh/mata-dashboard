<?php

declare(strict_types=1);

namespace Mata\Cli;

use Closure;
use DateTimeImmutable;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Utils\TypeConverter;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Node datasets as the dashboard shows them: from the API cache that cron and the dashboard keep
 * warm, or with $live from the node itself. Demo mode generates the data, as the dashboard does.
 */
final class NodeDatasetReader
{
    private readonly bool $demo;

    public function __construct(private readonly CliConfig $cli, private readonly ApplicationConfig $config)
    {
        $this->demo = $config->environment->env === 'demo';
    }

    /**
     * The servers of servers.json by name. Keys starting with an underscore, like `_README`,
     * document the file and are left out.
     *
     * @return array<string,array<string,mixed>>
     */
    public function servers(): array
    {
        $servers = [];
        foreach ((new ServersConfigLoader(fallbackOnFailure: true))->load($this->cli->configPath)->section->values as $name => $server) {
            if (!str_starts_with((string) $name, '_') && is_array($server)) {
                $servers[(string) $name] = $server;
            }
        }

        return $servers;
    }

    /**
     * One dataset of one server. $fetch calls the collector; with $live it must pass
     * CacheType::None, so the cache is bypassed.
     *
     * @param array<string,mixed>                                         $serverConfig
     * @param string                                                      $datasetName  names the data in errors and in the `source`, e.g. 'logs'
     * @param Closure(ServerDataCollectorInterface): ?array<string,mixed> $fetch
     */
    public function read(string $server, array $serverConfig, bool $live, string $datasetName, Closure $fetch): NodeDatasetResult
    {
        if ($live && !$this->demo && trim((string) ($serverConfig['api_key'] ?? '')) === '') {
            return NodeDatasetResult::failed("Server '{$server}' has no api_key in servers.secrets.json.");
        }

        try {
            $collector = $this->collector($server, $serverConfig, $live);
        } catch (RuntimeException $exception) {
            // e.g. an http:// api_url without allow_http
            return NodeDatasetResult::failed($exception->getMessage());
        }
        $response = $fetch($collector);
        if ($response === null) {
            return NodeDatasetResult::failed($live
                ? "The node of '{$server}' did not answer. Check it with: bin/mata server test {$server}"
                : "No cached copy of the {$datasetName} of '{$server}' yet. Pass --live to ask the node.");
        }

        $provenance = Provenance::fromNode($server, $datasetName, $response, $this->demo, new DateTimeImmutable());
        $nodeError = $response['error'] ?? null;
        if (is_array($nodeError)) {
            $message = is_string($nodeError['message'] ?? null) ? $nodeError['message'] : 'The node returned an error.';

            return NodeDatasetResult::failed($message, $provenance, $nodeError);
        }
        $notice = is_array($response['notice'] ?? null) ? $response['notice'] : null;

        return NodeDatasetResult::succeeded($provenance, $response['result'] ?? null, $notice);
    }

    /** @param array<string,mixed> $serverConfig */
    private function collector(string $server, array $serverConfig, bool $live): ServerDataCollectorInterface
    {
        $logger = new NullLogger();
        $ttl = new CacheTtlResolver((new TtlConfigLoader(fallbackOnFailure: true))->load($this->cli->configPath)->section);
        $normalizer = new NodeResponseNormalizer($logger);

        if ($this->demo) {
            return new MockServerDataCollector($logger, $ttl, $normalizer, $server, $serverConfig, $this->config->demo);
        }

        return new ServerDataCollector(
            $logger,
            new FileCacheStore($logger, $this->cli->paths()->apiCachePath),
            new ApiCacheKeyFactory(),
            $ttl,
            new NodeApiClient(
                logger: $logger,
                apiBase: (string) ($serverConfig['api_url'] ?? ''),
                server: $server,
                apiKey: (string) ($serverConfig['api_key'] ?? ''),
                timeout: $this->config->nodeApi->timeoutSeconds,
                allowHttp: TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false,
                suppressHttpWarning: TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false,
            ),
            $normalizer,
            cacheOnly: !$live,
        );
    }
}
