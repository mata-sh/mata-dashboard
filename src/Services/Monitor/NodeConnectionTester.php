<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Monitor\Dto\NodeConnectionResult;
use Mata\Services\Monitor\Dto\NodeHealthResult;
use Mata\Utils\TypeConverter;
use Psr\Log\NullLogger;
use Throwable;
use UnexpectedValueException;

/**
 * Checks a configured server the way the dashboard talks to it: the unauthenticated /health
 * probe, then one HMAC-signed request. Nothing is cached or recorded.
 */
final class NodeConnectionTester
{
    /** Cheap authenticated endpoint; any signed endpoint proves server ID and key match. */
    private const AUTH_PATH = 'server/info';

    public function __construct(
        private HttpProbeClient $probeClient,
        private int $timeoutSeconds,
    ) {}

    /** @param array<string,mixed> $serverConfig servers.json entry */
    public function test(string $name, array $serverConfig, ?string $apiKey): NodeConnectionResult
    {
        $apiUrl = TypeConverter::toString($serverConfig['api_url'] ?? null) ?? '';
        $health = $this->probeClient->probeNodeHealthAsync(rtrim($apiUrl, '/') . '/health', $this->timeoutSeconds)->wait();
        if (!$health instanceof NodeHealthResult) {
            throw new UnexpectedValueException('Node health probe returned an invalid result.');
        }
        $healthStatus = $health->status->value;
        $healthError = $health->error;

        if ($apiKey === null || $apiKey === '') {
            return new NodeConnectionResult($name, $apiUrl, $healthStatus, $healthError, 'skipped', 'No api_key in servers.secrets.json');
        }

        try {
            $client = new NodeApiClient(
                logger: new NullLogger(),
                apiBase: $apiUrl,
                server: $name,
                apiKey: $apiKey,
                timeout: $this->timeoutSeconds,
                allowHttp: TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false,
                suppressHttpWarning: true,
            );
            $client->get(self::AUTH_PATH);
        } catch (ConnectException $exception) {
            return new NodeConnectionResult($name, $apiUrl, $healthStatus, $healthError, 'failed', $exception->getMessage(), 'The node is not reachable from this host: check api_url, DNS, firewall and TLS.');
        } catch (RequestException $exception) {
            $code = $exception->getResponse()?->getStatusCode();

            return new NodeConnectionResult($name, $apiUrl, $healthStatus, $healthError, 'failed', "HTTP {$code} from " . self::AUTH_PATH, $this->hintForStatus($name, $code));
        } catch (Throwable $exception) {
            return new NodeConnectionResult($name, $apiUrl, $healthStatus, $healthError, 'failed', $exception->getMessage());
        }

        return new NodeConnectionResult($name, $apiUrl, $healthStatus, $healthError, 'ok');
    }

    private function hintForStatus(string $name, ?int $code): ?string
    {
        return match ($code) {
            401 => "The node rejected the signature: its DASHBOARD_SERVER_ID must be '{$name}' and its DASHBOARD_API_KEY must equal this server's api_key. Clocks must agree within the node's token window.",
            403 => "The node refused this dashboard: check the node's IP allowlist for this host's address.",
            429 => 'The node rate-limited the request; retry later.',
            503 => 'The node is in maintenance mode or not configured yet; see its /health checks.',
            default => null,
        };
    }
}
