<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Mata\Config\Monitoring\Section\WebhookChannel;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * POSTs alarm payloads to one webhook channel.
 *
 * Guzzle messages embed the request URL, which carries the channel's secret, and the
 * dispatcher persists failure messages. Failures are therefore rethrown redacted and
 * without the original exception.
 */
final class AlarmWebhookSender implements AlarmChannelSenderInterface
{
    private const RESPONSE_EXCERPT_LENGTH = 300;

    private LoggerInterface $logger;

    public function __construct(
        private WebhookChannel $channel,
        private AlarmWebhookPayloadFactory $payloadFactory,
        private ClientInterface $http,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function send(AlarmDeliveryView $view): void
    {
        $payload = $this->payloadFactory->create($view, $this->channel);

        $this->logger->debug('Sending alarm webhook.', [
            'alarm_id' => $view->alarmId,
            'type' => $view->alarm->type,
            'level' => $view->alarm->level->value,
            'server' => $view->alarm->server,
            'app' => $view->alarm->app,
            'channel' => $this->channel->name,
        ]);

        try {
            $response = $this->http->request('POST', $this->channel->url, [
                'json' => $payload,
                'timeout' => $this->channel->timeoutSeconds,
                'connect_timeout' => $this->channel->timeoutSeconds,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $exception) {
            throw $this->failure('request failed: ' . $exception->getMessage());
        }

        // Redirects are not followed, so a 3xx fails like any other non-2xx status.
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $body = preg_replace('/\s+/', ' ', trim((string) $response->getBody())) ?? '';
            if (mb_strlen($body) > self::RESPONSE_EXCERPT_LENGTH) {
                $body = mb_substr($body, 0, self::RESPONSE_EXCERPT_LENGTH) . '…';
            }

            throw $this->failure("returned HTTP {$status}" . ($body === '' ? '.' : ": {$body}"));
        }
    }

    private function failure(string $detail): RuntimeException
    {
        $url = $this->channel->url;
        $parts = parse_url($url) ?: [];
        $host = $parts['host'] ?? '';
        // The full URL first, then its path and query alone, since either may hold the secret.
        $secrets = array_filter(
            [$url, $parts['path'] ?? '', $parts['query'] ?? ''],
            static fn (string $secret): bool => strlen($secret) > 1,
        );

        return new RuntimeException(str_replace($secrets, '[redacted]', "Webhook channel {$this->channel->name} ({$host}) {$detail}"));
    }
}
