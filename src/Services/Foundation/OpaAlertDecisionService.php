<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Watcher\Services\Foundation\Dto\AlertDecision;
use Watcher\Services\Foundation\Dto\OpaAlertDecisionConfig;

final class OpaAlertDecisionService implements AlertDecisionServiceInterface
{
    private Client $client;
    private OpaAlertDecisionConfig $config;

    public function __construct(OpaAlertDecisionConfig $config, ?Client $client = null)
    {
        $this->config = $config;
        $this->client = $client ?? new Client([
            'base_uri' => $this->config->baseUri,
            'timeout' => $this->config->timeoutSeconds,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $event
     * @param array<int,string>   $supportedChannels
     *
     * @throws GuzzleException
     */
    public function decide(array $event, array $supportedChannels = ['email']): AlertDecision
    {
        $payload = [
            'input' => $this->buildInput($event),
        ];

        $response = $this->client->post(ltrim($this->config->decisionPath, '/'), ['json' => $payload]);
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('OPA response is not valid JSON.');
        }

        if (!array_key_exists('result', $decoded)) {
            throw new RuntimeException('OPA response missing result payload.');
        }

        return $this->normalizeDecision($decoded, $event, $supportedChannels);
    }

    public function getBatchLimit(): int
    {
        return $this->config->batchLimit;
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function buildInput(array $event): array
    {
        $context = null;
        if (is_string($event['context'] ?? null) && $event['context'] !== '') {
            $decoded = json_decode((string) $event['context'], true);
            $context = json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $event['context'];
        } elseif ($event['context'] ?? null) {
            $context = $event['context'];
        }

        return [
            'event' => [
                'id' => (int) ($event['id'] ?? 0),
                'type' => (string) ($event['type'] ?? ''),
                'message' => (string) ($event['message'] ?? ''),
                'level' => (string) ($event['level'] ?? 'info'),
                'context' => $context,
                'datetime' => (string) ($event['datetime'] ?? ''),
            ],
            'server' => [
                'id' => isset($event['server_id']) ? (int) $event['server_id'] : null,
                'name' => $event['server_name'] ?? null,
            ],
            'application' => [
                'id' => isset($event['application_id']) ? (int) $event['application_id'] : null,
                'code' => $event['app_code'] ?? null,
                'name' => $event['app_name'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $event
     * @param array<int,string>   $supportedChannels
     */
    private function normalizeDecision(
        array $response,
        array $event,
        array $supportedChannels
    ): AlertDecision {
        $result = $response['result'] ?? null;
        $data = is_array($result) ? $result : [];
        $reason = null;

        $allow = match (true) {
            is_bool($result) => $result,
            array_key_exists('allow', $data) => (bool) $data['allow'],
            array_key_exists('enqueue', $data) => (bool) $data['enqueue'],
            array_key_exists('alert', $data) => (bool) $data['alert'],
            array_key_exists('should_alert', $data) => (bool) $data['should_alert'],
            default => false,
        };

        $channels = [];
        if (is_array($data['channels'] ?? null)) {
            foreach ($data['channels'] as $channel) {
                if (is_string($channel) && $channel !== '') {
                    $channels[] = strtolower(trim($channel));
                }
            }
        } elseif (is_string($data['channel'] ?? null) && $data['channel'] !== '') {
            $channels[] = strtolower(trim((string) $data['channel']));
        }

        if ($channels === [] && $allow) {
            $fallback = is_string($event['alarm_channel'] ?? null) ? (string) $event['alarm_channel'] : 'email';
            $channels = [strtolower(trim($fallback))];
        }

        $channels = array_values(array_unique(array_intersect($channels, $supportedChannels)));

        $priority = isset($data['priority']) && is_numeric($data['priority'])
            ? (int) $data['priority']
            : 100;

        $enrich = false;
        if (array_key_exists('enrich', $data)) {
            $enrich = (bool) $data['enrich'];
        } elseif (array_key_exists('llm_enrich', $data)) {
            $enrich = (bool) $data['llm_enrich'];
        }

        if ($allow && $channels === []) {
            $allow = false;
            $reason = 'no_supported_channels';
        }

        return new AlertDecision($allow, $channels, $priority, $enrich, $reason);
    }
}
